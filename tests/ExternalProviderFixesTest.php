<?php

declare(strict_types=1);

/**
 * External (Cheerio) provider fixes: Meta token guard, send retry rules, per-batch bulk results,
 * template language matching. No database and no real provider calls: settings and the template
 * model are fakes, HTTP goes to a throwaway local server started by this script.
 *
 * Run: php tests/ExternalProviderFixesTest.php
 */

define('FCPATH', __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR);
chdir(FCPATH);

require FCPATH . '../app/Config/Paths.php';
$paths = new \Config\Paths();
require $paths->systemDirectory . '/Boot.php';
\CodeIgniter\Boot::bootSpark($paths);

use App\Libraries\CheerioDirectAPI;
use App\Libraries\SettingsService;
use App\Libraries\WhatsAppTemplateSendGuard;
use App\Models\TemplateModel;
use Config\WhatsApp as WhatsAppConfig;

$pass = 0;
$fail = 0;

function check(string $label, bool $condition, string $detail = ''): void
{
    global $pass, $fail;
    if ($condition) {
        $pass++;
        echo "[PASS] {$label}\n";

        return;
    }
    $fail++;
    echo "[FAIL] {$label}" . ($detail !== '' ? " -- {$detail}" : '') . "\n";
}

/** Settings from an array; never touches the database. */
class FakeSettings extends SettingsService
{
    /** @param array<string, mixed> $values */
    public function __construct(public array $values = [])
    {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->values[$key] ?? $default;
    }

    public function set(string $key, mixed $value, string $group = 'general', ?bool $encrypt = null): bool
    {
        $this->values[$key] = $value;

        return true;
    }

    public function getMetaConfig(): array
    {
        return [
            'waba_id'         => (string) ($this->values['meta_waba_id'] ?? ''),
            'phone_number_id' => (string) ($this->values['meta_phone_number_id'] ?? ''),
            'access_token'    => (string) ($this->values['meta_access_token'] ?? ''),
            'app_secret'      => (string) ($this->values['meta_app_secret'] ?? ''),
            'verify_token'    => (string) ($this->values['meta_webhook_verify_token'] ?? ''),
        ];
    }
}

/** Counts HTTP attempts (baseCurlOptions runs once per attempt). */
class CountingApi extends CheerioDirectAPI
{
    public int $attempts = 0;

    protected function baseCurlOptions(): array
    {
        $this->attempts++;

        return ['timeout' => 5, 'connect_timeout' => 2, 'http_errors' => false, 'verify' => false];
    }
}

/** Bulk client whose chosen batches fail, without any HTTP. */
class FlakyBulkApi extends CheerioDirectAPI
{
    public int $calls = 0;

    /** @param list<int> $failOn 1-based batch numbers that fail */
    public function __construct(public array $failOn, SettingsService $settings, WhatsAppConfig $config)
    {
        parent::__construct($settings, $config);
    }

    public function request(string $method, string $endpoint, ?array $data = null, bool $isMultipart = false): array
    {
        $this->calls++;
        if (in_array($this->calls, $this->failOn, true)) {
            throw new RuntimeException('External WhatsApp API error: HTTP 502: bad gateway', 502);
        }

        return ['flag' => true, 'data' => ['ok' => true]];
    }

    public function ensureTemplateComponents(string $templateName, string $language, array $components): array
    {
        return array_values(array_filter($components, 'is_array'));
    }
}

/** Template rows from an array. */
class FakeTemplateModel extends TemplateModel
{
    /** @var array<string, mixed> */
    private array $filters = [];

    /** @param list<array<string, mixed>> $rows */
    public function __construct(public array $rows = [])
    {
    }

    public function where($key, $value = null)
    {
        $this->filters[(string) $key] = $value;

        return $this;
    }

    public function orderBy($field, $dir = '')
    {
        return $this;
    }

    public function findAll(?int $limit = null, int $offset = 0)
    {
        $filters       = $this->filters;
        $this->filters = [];

        return array_values(array_filter($this->rows, static function (array $row) use ($filters): bool {
            foreach ($filters as $k => $v) {
                if (($row[$k] ?? null) !== $v) {
                    return false;
                }
            }

            return true;
        }));
    }

    public function find($id = null)
    {
        foreach ($this->rows as $row) {
            if ((int) ($row['id'] ?? 0) === (int) $id) {
                return $row;
            }
        }

        return null;
    }
}

echo "=== External provider fixes test ===\n";

// ---------------------------------------------------------------------------------------------
echo "\n-- 1) Meta token is never used as the External API key --\n";
$cfg = static fn (array $v): array => (new FakeSettings($v))->getCheerioConfig();

check('External active, no External key, Meta token saved => no key',
    $cfg(['whatsapp_provider' => 'cheerio', 'meta_access_token' => 'EAAGm0PX4ZCpsBAKexample'])['api_key'] === '');
check('lowercase "eaa" token is also refused',
    $cfg(['whatsapp_provider' => 'cheerio', 'meta_access_token' => 'eaaGm0PX4'])['api_key'] === '');
check('legacy External key stored under meta_access_token still works',
    $cfg(['whatsapp_provider' => 'cheerio', 'meta_access_token' => 'ck_legacy_123'])['api_key'] === 'ck_legacy_123');
check('own External key wins over the Meta token',
    $cfg(['whatsapp_provider' => 'cheerio', 'cheerio_api_key' => 'ck_real', 'meta_access_token' => 'EAAG'])['api_key'] === 'ck_real');
check('Meta active => Meta token is not offered as External key',
    $cfg(['whatsapp_provider' => 'meta', 'meta_access_token' => 'ck_legacy_123'])['api_key'] === '');

$waConfig                    = new WhatsAppConfig();
$waConfig->maxRetries        = 3;
$waConfig->retryDelaySeconds = 0;

$api = new CountingApi(new FakeSettings(['whatsapp_provider' => 'cheerio', 'meta_access_token' => 'EAAGtoken']), $waConfig);
$msg = '';
try {
    $api->sendText('919700000001', 'hi');
} catch (Throwable $e) {
    $msg = $e->getMessage();
}
check('send with only a Meta token stops before any HTTP call',
    str_contains($msg, 'not configured') && $api->attempts === 0, $msg . ' attempts=' . $api->attempts);

// ---------------------------------------------------------------------------------------------
echo "\n-- 2) Retry rules (local test server) --\n";
$port    = 8917;
$tmp     = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sc_ext_test_' . getmypid();
@mkdir($tmp);
$hitFile = $tmp . DIRECTORY_SEPARATOR . 'hits.json';
$router  = $tmp . DIRECTORY_SEPARATOR . 'router.php';
file_put_contents($hitFile, '{}');
file_put_contents($router, '<?php
$f = ' . var_export($hitFile, true) . ';
$path = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);
$hits = json_decode((string) file_get_contents($f), true) ?: [];
$n = $hits[$path] = ($hits[$path] ?? 0) + 1;
file_put_contents($f, json_encode($hits));
$plan = [
    "/v1/whatsapp/direct/send"   => [500, 500, 500, 500, 500],
    "/v1/whatsapp/template/send" => [429, 200],
    "/v1/whatsapp/multiple"      => [502, 502, 502, 502, 502],
    "/v1/getAllTemplates"        => [500, 503, 200],
    "/v1/whatsapp/media"         => [503, 200],
];
$code = $plan[$path][$n - 1] ?? 200;
http_response_code($code);
header("Content-Type: application/json");
echo $code === 200 ? json_encode(["flag" => true, "data" => []]) : json_encode(["message" => "simulated " . $code]);
');

$proc = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, $router], [1 => ['file', $tmp . DIRECTORY_SEPARATOR . 'out.log', 'w'], 2 => ['file', $tmp . DIRECTORY_SEPARATOR . 'err.log', 'w']], $pipes);
$up   = false;
for ($i = 0; $i < 40 && ! $up; $i++) {
    usleep(100000);
    $sock = @fsockopen('127.0.0.1', $port, $en, $es, 0.2);
    if ($sock) {
        fclose($sock);
        $up = true;
    }
}
check('local test server started', $up);

if ($up) {
    $waConfig->baseUrl = 'http://127.0.0.1:' . $port;
    $settings          = new FakeSettings(['whatsapp_provider' => 'cheerio', 'cheerio_api_key' => 'ck_test']);
    $hits              = static fn (): array => json_decode((string) file_get_contents($hitFile), true) ?: [];
    $try               = static function (callable $fn): string {
        try {
            $fn();

            return 'ok';
        } catch (Throwable $e) {
            return $e->getMessage();
        }
    };

    $api = new CountingApi($settings, $waConfig);

    $r = $try(static fn () => $api->sendText('919700000001', 'hello'));
    check('text send on HTTP 500 fails', $r !== 'ok' && str_contains($r, '500'), $r);
    check('text send on HTTP 500 is sent only once', ($hits()['/v1/whatsapp/direct/send'] ?? 0) === 1, json_encode($hits()));

    $r = $try(static fn () => $api->sendTemplate('919700000001', 'no_such_local_tpl_zz', 'en', [['type' => 'body', 'parameters' => []]]));
    check('template send on 429 is repeated and then succeeds', $r === 'ok' && ($hits()['/v1/whatsapp/template/send'] ?? 0) === 2, $r . ' ' . json_encode($hits()));

    $r = $try(static fn () => $api->request('POST', 'v1/whatsapp/multiple', ['data' => []]));
    check('bulk send on HTTP 502 is sent only once', $r !== 'ok' && ($hits()['/v1/whatsapp/multiple'] ?? 0) === 1, json_encode($hits()));

    $r = $try(static fn () => $api->request('GET', 'v1/getAllTemplates', ['limit' => 10]));
    check('GET on 500/503 is repeated until it succeeds', $r === 'ok' && ($hits()['/v1/getAllTemplates'] ?? 0) === 3, $r . ' ' . json_encode($hits()));

    $r = $try(static fn () => $api->getMediaUrl('123456'));
    check('media lookup (POST) on 503 is still repeated', $r === 'ok' && ($hits()['/v1/whatsapp/media'] ?? 0) === 2, $r . ' ' . json_encode($hits()));

    // Connection refused: nothing listens on the next port.
    $waConfig->baseUrl = 'http://127.0.0.1:' . ($port + 1);
    $dead              = new CountingApi($settings, $waConfig);
    $r                 = $try(static fn () => $dead->sendText('919700000001', 'hello'));
    check('text send on a connection error is tried only once', $r !== 'ok' && $dead->attempts === 1, 'attempts=' . $dead->attempts);

    $dead = new CountingApi($settings, $waConfig);
    $r    = $try(static fn () => $dead->request('GET', 'v1/getAllTemplates'));
    check('GET on a connection error is tried 1 + maxRetries times', $r !== 'ok' && $dead->attempts === 4, 'attempts=' . $dead->attempts);
}

if (is_resource($proc)) {
    proc_terminate($proc);
    proc_close($proc);
}
foreach (glob($tmp . DIRECTORY_SEPARATOR . '*') ?: [] as $f) {
    @unlink($f);
}
@rmdir($tmp);

// ---------------------------------------------------------------------------------------------
echo "\n-- 3) Bulk campaign: per-batch result --\n";
$settings   = new FakeSettings(['whatsapp_provider' => 'cheerio', 'cheerio_api_key' => 'ck_test']);
$recipients = [];
for ($i = 1; $i <= 5; $i++) {
    $recipients[] = ['to' => '91970000000' . $i, 'components' => []];
}

$ok   = new FlakyBulkApi([], $settings, $waConfig);
$bulk = $ok->sendBulkCampaign('camp', 'support', 'en', $recipients, 2);
check('all batches ok: 3 batches, 5 sent, none failed',
    $bulk['batches'] === 3 && $bulk['sent_count'] === 5 && $bulk['failed'] === [] && $bulk['recipient_count'] === 5, json_encode($bulk));

$flaky = new FlakyBulkApi([2], $settings, $waConfig);
$bulk  = $flaky->sendBulkCampaign('camp', 'support', 'en', $recipients, 2);
check('middle batch fails: the other batches still go out', $flaky->calls === 3);
check('middle batch fails: 3 sent', $bulk['sent_count'] === 3 && $bulk['sent_phones'] === ['919700000001', '919700000002', '919700000005'], json_encode($bulk['sent_phones']));
check('middle batch fails: only its 2 numbers are failed',
    array_map('strval', array_keys($bulk['failed'])) === ['919700000003', '919700000004'], json_encode(array_keys($bulk['failed'])));
check('failed numbers carry the provider error', str_contains((string) reset($bulk['failed']), '502'));

// Same bookkeeping CampaignService does with this result.
$contactByPhone = [];
foreach ($recipients as $n => $r) {
    $contactByPhone[$r['to']] = 100 + $n;
}
$sent = count($contactByPhone) - count(array_intersect_key($bulk['failed'], $contactByPhone));
check('campaign counters from the result: 3 sent, 2 failed', $sent === 3 && count($contactByPhone) - $sent === 2);
$marked = [];
foreach ($contactByPhone as $phone => $contactId) {
    $marked[$contactId] = isset($bulk['failed'][$phone]) ? 'failed' : 'sent';
}
check('per-contact marks follow the batches',
    $marked === [100 => 'sent', 101 => 'sent', 102 => 'failed', 103 => 'failed', 104 => 'sent'], json_encode($marked));

$allFail = new FlakyBulkApi([1, 2, 3], $settings, $waConfig);
$thrown  = '';
try {
    $allFail->sendBulkCampaign('camp', 'support', 'en', $recipients, 2);
} catch (RuntimeException $e) {
    $thrown = $e->getMessage();
}
check('every batch fails: an error is raised (campaign marks all failed)', str_contains($thrown, '502'), $thrown);

// ---------------------------------------------------------------------------------------------
echo "\n-- 4) Template language matching --\n";
$same = [WhatsAppTemplateSendGuard::class, 'sameLanguage'];
check('en = en_US', $same('en', 'en_US') && $same('en_US', 'en'));
check('en-us = en_US (case and dash)', $same('en-us', 'en_US') && $same('EN_us', 'en_US'));
check('en_US != en_GB', ! $same('en_US', 'en_GB'));
check('hi != en, empty never matches', ! $same('hi', 'en') && ! $same('', 'en') && ! $same('en', ''));
check('"e" is not a family of "en"', ! $same('e', 'en'));

$guard = static fn (array $rows): WhatsAppTemplateSendGuard => new WhatsAppTemplateSendGuard(new FakeSettings([]), new FakeTemplateModel($rows));
$row   = static fn (int $id, string $name, string $lang, string $status = 'APPROVED'): array => ['id' => $id, 'name' => $name, 'language' => $lang, 'status' => $status];
$err   = static function (callable $fn): string {
    try {
        $fn();

        return '';
    } catch (RuntimeException $e) {
        return $e->getCode() . ':' . $e->getMessage();
    }
};

$tpl = $guard([$row(1, 'order_update', 'en')])->resolveApprovedTemplate(null, 'order_update', 'en_US');
check('API default en_US finds an External "en" template', (int) $tpl['id'] === 1);

$tpl = $guard([$row(1, 'order_update', 'en_US')])->resolveApprovedTemplate(null, 'order_update', 'en');
check('"en" finds a Meta "en_US" template', (int) $tpl['id'] === 1);

$tpl = $guard([$row(1, 'order_update', 'en_GB'), $row(2, 'order_update', 'en_US')])->resolveApprovedTemplate(null, 'order_update', 'en_US');
check('exact language wins over an earlier family row', (int) $tpl['id'] === 2);

$tpl = $guard([$row(1, 'order_update', 'en_GB'), $row(2, 'order_update', 'en_US')])->resolveApprovedTemplate(null, 'order_update', 'en_GB');
check('en_GB request never returns the en_US row', (int) $tpl['id'] === 1);

$tpl = $guard([$row(1, 'order_update', 'hi'), $row(2, 'order_update', 'en')])->resolveApprovedTemplate(null, 'order_update', null);
check('no language given => newest row', (int) $tpl['id'] === 1);

$e = $err(static fn () => $guard([$row(1, 'order_update', 'hi')])->resolveApprovedTemplate(null, 'order_update', 'en_US'));
check('other language is still "not found" (404)', str_starts_with($e, '404:'), $e);

$e = $err(static fn () => $guard([$row(1, 'order_update', 'en_GB')])->resolveApprovedTemplate(null, 'order_update', 'en_US'));
check('en_US request does not fall back to en_GB', str_starts_with($e, '404:'), $e);

$e = $err(static fn () => $guard([$row(1, 'other_name', 'en')])->resolveApprovedTemplate(null, 'order_update', 'en'));
check('unknown template name is "not found"', str_starts_with($e, '404:'), $e);

$e = $err(static fn () => $guard([$row(1, 'order_update', 'en', 'PENDING')])->resolveApprovedTemplate(null, 'order_update', 'en_US'));
check('pending template is still refused (422)', str_starts_with($e, '422:'), $e);

$tpl = $guard([$row(7, 'order_update', 'en')])->resolveApprovedTemplate(7, null, 'en_US');
check('by id with a family language passes', (int) $tpl['id'] === 7);

$e = $err(static fn () => $guard([$row(7, 'order_update', 'hi')])->resolveApprovedTemplate(7, null, 'en_US'));
check('by id with another language is refused (422)', str_starts_with($e, '422:'), $e);

// ---------------------------------------------------------------------------------------------
echo "\n-- 5) Webhook status link guard (source check only) --\n";
$src = (string) file_get_contents(FCPATH . '../app/Controllers/Webhooks.php');
check('campaign status link runs only for messages we did not store',
    str_contains($src, "if (\$message === null && \$ccModel->where('wa_message_id', \$waId)->countAllResults() === 0)"));

// ---------------------------------------------------------------------------------------------
echo "\n-- 6) Delivery ticks: which stored sends are polled on External --\n";
$isMeta      = [\App\Libraries\WhatsAppDeliveryStatus::class, 'isMetaSendPayload'];
$extResponse = ['status' => 200, 'flag' => true, 'messaging_product' => 'whatsapp', 'messages' => [['id' => 'wamid.X']]];
$metaResp    = ['messaging_product' => 'whatsapp', 'contacts' => [['wa_id' => '9197']], 'messages' => [['id' => 'wamid.M']]];

check('chat send on External is polled', ! $isMeta($extResponse));
check('workflow / queue send on External ({request, response}) is polled', ! $isMeta(['request' => ['text' => 'hi'], 'response' => $extResponse]));
check('same payload as a JSON string is polled', ! $isMeta(json_encode(['request' => [], 'response' => $extResponse])));
check('keyword bot send on External (provider=cheerio) is polled', ! $isMeta(['keyword_id' => 5, 'provider' => 'cheerio', 'response' => $metaResp]));
check('consent send on External ({..., response}) is polled', ! $isMeta(['kind' => 'consent', 'response' => $extResponse]));
check('API send without a stored payload is polled', ! $isMeta(null) && ! $isMeta(''));
check('unknown shape is polled', ! $isMeta(['request' => ['text' => 'hi'], 'response' => ['ok' => true]]));
check('Meta chat send is skipped', $isMeta($metaResp));
check('Meta queue send ({request, response}) is skipped', $isMeta(['request' => [], 'response' => $metaResp]));
check('keyword bot send on Meta (provider=meta) is skipped', $isMeta(['provider' => 'meta', 'response' => $extResponse]));

// ---------------------------------------------------------------------------------------------
echo "\n-- 7) Changing a sent message --\n";
/** Provider double: records calls, edit and send can each fail. */
class FakeReviseApi
{
    /** @var list<string> */
    public array $calls = [];

    public function __construct(public string $edit = 'unsupported', public string $send = 'ok')
    {
    }

    public function editText(string $to, string $messageId, string $text): array
    {
        $this->calls[] = 'edit';

        return match ($this->edit) {
            'same'     => ['messages' => [['id' => $messageId]]],
            'empty'    => ['flag' => true],
            'replaced' => ['messages' => [['id' => 'wamid.NEW']]],
            default    => throw new RuntimeException('External WhatsApp API error: HTTP 400: The parameter status is required. | type=OAuthException | code=100', 400),
        };
    }

    public function sendText(string $to, string $text): array
    {
        $this->calls[] = 'send';
        if ($this->send !== 'ok') {
            throw new RuntimeException('External WhatsApp API error: HTTP 400: (#131047) Re-engagement message', 400);
        }

        return ['flag' => true, 'messages' => [['id' => 'wamid.SENT']]];
    }
}
$reviser = new \App\Libraries\WhatsAppMessageReviser();

$apiR = new FakeReviseApi('unsupported');
$r    = $reviser->revise($apiR, '971563110791', 'wamid.OLD', 'Price: 143.11 AED', true, true);
check('the reported case: edit refused (400) => sent as a new message, no error',
    $r['ok'] && $r['data']['mode'] === 'resent' && $r['data']['external_id'] === 'wamid.SENT' && $apiR->calls === ['edit', 'send'], json_encode($r));
check('the raw provider error is not shown', ! str_contains($r['message'], 'OAuthException') && $r['message'] === 'Sent again as a new message.');

$apiR = new FakeReviseApi('unsupported');
$r    = $reviser->revise($apiR, '9715', 'wamid.OLD', 'new', false, true);
check('older than 15 minutes: no edit call, sent as new', $r['ok'] && $r['data']['mode'] === 'resent' && $apiR->calls === ['send']);

$apiR = new FakeReviseApi('unsupported');
$r    = $reviser->revise($apiR, '9715', 'wamid.OLD', 'new', true, false);
check('edit refused and 24h window closed: clear 422, nothing sent',
    ! $r['ok'] && $r['status'] === 422 && str_contains($r['message'], 'template') && $apiR->calls === ['edit'], json_encode($r));

$apiR = new FakeReviseApi('unsupported');
$r    = $reviser->revise($apiR, '9715', 'wamid.OLD', 'new', false, false);
check('old message and 24h window closed: 422, no provider call', ! $r['ok'] && $r['status'] === 422 && $apiR->calls === []);

$apiR = new FakeReviseApi('unsupported', 'fail');
$r    = $reviser->revise($apiR, '9715', 'wamid.OLD', 'new', true, true);
check('send also fails: generic 502, no provider text',
    ! $r['ok'] && $r['status'] === 502 && ! str_contains($r['message'], '131047') && ! str_contains($r['message'], 'External'), json_encode($r));

$apiR = new FakeReviseApi('same');
$r    = $reviser->revise($apiR, '9715', 'wamid.OLD', 'new', true, true);
check('provider that can edit: same message updated, nothing new sent', $r['ok'] && $r['data']['mode'] === 'edited' && $apiR->calls === ['edit']);

$apiR = new FakeReviseApi('empty');
$r    = $reviser->revise($apiR, '9715', 'wamid.OLD', 'new', true, true);
check('edit accepted without an id counts as edited', $r['ok'] && $r['data']['mode'] === 'edited');

$apiR = new FakeReviseApi('replaced');
$r    = $reviser->revise($apiR, '9715', 'wamid.OLD', 'new', true, true);
check('provider replaced the message: stored as a new message with the new id',
    $r['ok'] && $r['data']['mode'] === 'resent' && $r['data']['external_id'] === 'wamid.NEW' && $apiR->calls === ['edit']);

$apiR = new FakeReviseApi('same');
$r    = $reviser->revise($apiR, '9715', '', 'new', true, true);
check('message without a WhatsApp id is never edited in place', $r['ok'] && $apiR->calls === ['send']);

$chatSrc = (string) file_get_contents(FCPATH . '../app/Controllers/Chat.php');
check('chat controller uses the reviser and no longer returns the raw edit error',
    str_contains($chatSrc, 'WhatsAppMessageReviser())->revise(') && ! str_contains($chatSrc, "'Chat edit failed"));

// ---------------------------------------------------------------------------------------------
echo "\n-- 8) API key refusal message (LMS chat panel) --\n";
$why = [\App\Filters\ApiAuthFilter::class, 'invalidTokenMessage'];
check('a provider key in the API key field says which key to use',
    str_contains($why('a1b2c3d4-External-key-000'), 'sc_live_') && str_contains($why('a1b2c3d4-External-key-000'), 'provider key'));
check('an unknown sc_live_ key says deleted / expired / other workspace',
    str_contains($why('sc_live_' . str_repeat('ab', 24)), 'not recognised'));
check('a login token keeps the JWT message', $why('aaa.bbb.ccc') === 'Invalid or expired API token / JWT.');
check('the message never repeats the token', ! str_contains($why('SECRETKEY12345'), 'SECRETKEY12345'));

// ---------------------------------------------------------------------------------------------
echo "\n-- 9) Inbox send on External: message id and status answer shapes --\n";
$ext = new CheerioDirectAPI(new FakeSettings(['whatsapp_provider' => 'cheerio', 'cheerio_api_key' => 'ck_test']), $waConfig);
$idOf = static fn (array $r): string => (string) ($ext->withMessageId($r)['messages'][0]['id'] ?? '');

check('id already at messages[0].id is kept', $idOf(['flag' => true, 'messages' => [['id' => 'wamid.A']]]) === 'wamid.A');
check('id under data.messages is found', $idOf(['flag' => true, 'data' => ['messages' => [['id' => 'wamid.B']]]]) === 'wamid.B');
check('id under data.response.messages is found', $idOf(['flag' => true, 'data' => ['response' => ['messages' => [['id' => 'wamid.C']]]]]) === 'wamid.C');
check('id as data.messageId is found', $idOf(['flag' => true, 'data' => ['messageId' => 'wamid.D']]) === 'wamid.D');
check('id as top-level wamid is found', $idOf(['status' => 200, 'wamid' => 'wamid.E']) === 'wamid.E');
check('id buried anywhere (wamid. prefix) is found', $idOf(['data' => ['x' => ['y' => [['z' => 'wamid.F']]]]]) === 'wamid.F');
check('no id anywhere => none invented', $idOf(['flag' => true, 'data' => ['ok' => true, 'id' => 55]]) === '');
check('send result is marked as External', ($ext->withMessageId(['flag' => true])['provider'] ?? '') === 'cheerio');
check('External send without a "flag" is still polled (marked provider)',
    ! $isMeta($ext->withMessageId(['messaging_product' => 'whatsapp', 'messages' => [['id' => 'wamid.G']]])));
check('queued External send without a "flag" is still polled',
    ! $isMeta(['request' => [], 'response' => $ext->withMessageId(['messaging_product' => 'whatsapp', 'messages' => [['id' => 'wamid.H']]])]));

$st = static fn (array $r): ?string => $ext->normalizeMessageStatus($r);
$t  = static fn (int $min): string => date('c', 1790000000 + $min * 60);
check('documented shape: delivered', $st(['data' => ['status_updatedAt' => ['sentAt' => $t(0), 'deliveryAt' => $t(1)]]]) === 'delivered');
check('documented shape: read', $st(['data' => ['status_updatedAt' => ['sentAt' => $t(0), 'deliveryAt' => $t(1), 'readAt' => $t(2)]]]) === 'read');
check('stale failedAt older than sentAt does not win', $st(['data' => ['status_updatedAt' => ['failedAt' => $t(-5), 'sentAt' => $t(0)]]]) === 'sent');
check('deliveredAt / seenAt spellings', $st(['data' => ['status_updatedAt' => ['sentAt' => $t(0), 'deliveredAt' => $t(1), 'seenAt' => $t(3)]]]) === 'read');
check('record wrapped once more (data.data)', $st(['status' => 200, 'data' => ['data' => ['status_updatedAt' => ['deliveryAt' => $t(1)]]]]) === 'delivered');
check('one-item list', $st(['data' => [['status' => 'READ']]]) === 'read');
check('plain status word, upper case', $st(['data' => ['status' => 'DELIVERED']]) === 'delivered');
check('"seen" means read', $st(['data' => ['status' => 'seen']]) === 'read');
check('HTTP 200 wrapper is not a delivery state', $st(['status' => 200, 'flag' => true, 'data' => ['foo' => 'bar']]) === null);
check('"success" wrapper is not a delivery state', $st(['status' => 'success', 'data' => []]) === null);

// ---------------------------------------------------------------------------------------------
echo "\n-- 10) External webhooks reach the right client (shared host) --\n";
$routes = static fn (array $v): array => (new FakeSettings($v))->phoneRoutesToPublish();

$r = $routes(['whatsapp_provider' => 'cheerio', 'cheerio_phone_number_id' => '111', 'cheerio_webhook_secret' => 'csec', 'cheerio_webhook_verify_token' => 'ctok']);
check('External-only client publishes its External number id',
    count($r) === 1 && $r[0]['phone_number_id'] === '111' && $r[0]['secret'] === 'csec' && $r[0]['verify_token'] === 'ctok' && $r[0]['provider'] === 'cheerio', json_encode($r));

$r = $routes(['whatsapp_provider' => 'meta', 'meta_phone_number_id' => '222', 'meta_app_secret' => 'msec', 'meta_webhook_verify_token' => 'mtok']);
check('Meta-only client publishes its Meta number id as before',
    count($r) === 1 && $r[0]['phone_number_id'] === '222' && $r[0]['secret'] === 'msec' && $r[0]['provider'] === 'meta', json_encode($r));

$both = ['meta_phone_number_id' => '333', 'meta_app_secret' => 'msec', 'cheerio_phone_number_id' => '333', 'cheerio_webhook_secret' => 'csec'];
$r    = $routes($both + ['whatsapp_provider' => 'cheerio']);
check('same number id on both, External active => one route with the External secret', count($r) === 1 && $r[0]['secret'] === 'csec', json_encode($r));
$r = $routes($both + ['whatsapp_provider' => 'meta']);
check('same number id on both, Meta active => one route with the Meta secret', count($r) === 1 && $r[0]['secret'] === 'msec', json_encode($r));

$r = $routes(['whatsapp_provider' => 'cheerio', 'meta_phone_number_id' => '444', 'meta_app_secret' => 'msec', 'cheerio_phone_number_id' => '555', 'cheerio_webhook_secret' => 'csec']);
check('different number ids => both routes, each with its own secret',
    count($r) === 2 && $r[0]['phone_number_id'] === '555' && $r[0]['secret'] === 'csec' && $r[1]['phone_number_id'] === '444' && $r[1]['secret'] === 'msec', json_encode($r));

check('nothing saved => nothing published', $routes(['whatsapp_provider' => 'cheerio']) === []);

$norm = [\App\Libraries\WhatsAppWebhookTenantFinder::class, 'normalizeDisplayPhone'];
check('display number is compared on digits only', $norm('+971 56 311-0791') === '971563110791');
check('too short / empty display number is ignored', $norm('12345') === '' && $norm('') === '' && $norm(null) === '');

$hook    = new \App\Controllers\Webhooks();
$extract = new ReflectionMethod($hook, 'extractDisplayPhone');
$extract->setAccessible(true);
$statusPayload = ['object' => 'whatsapp_business_account', 'entry' => [['id' => 'W1', 'changes' => [['field' => 'messages', 'value' => [
    'messaging_product' => 'whatsapp',
    'metadata'          => ['display_phone_number' => '971563110791', 'phone_number_id' => '111'],
    'statuses'          => [['id' => 'wamid.X', 'status' => 'delivered', 'recipient_id' => '971500000001']],
]]]]]];
check('display number is read from a status webhook', $extract->invoke($hook, $statusPayload) === '971563110791');
check('payload without metadata gives no display number', $extract->invoke($hook, ['entry' => [['changes' => [['value' => []]]]]]) === '' && $extract->invoke($hook, []) === '');

$hookSrc = (string) file_get_contents(FCPATH . '../app/Controllers/Webhooks.php');
check('webhook falls back to the display number only when the number id has no route',
    (bool) preg_match('/applyFromPhoneNumberId\(\$phoneNumberId\);\s+if \(! \$routed\) \{.*?useTenantForDisplayPhone/s', $hookSrc));

// Live read-only check against the local master: an unknown number must not switch clients.
if (\App\Libraries\MasterTenantRepository::masterConfigured()) {
    $before = \App\Libraries\TenantContext::get();
    $found  = (new \App\Libraries\WhatsAppWebhookTenantFinder())->useTenantForDisplayPhone('990000000001');
    check('unknown display number matches no client (all local clients scanned)', $found === false);
    check('and the current client is left as it was', \App\Libraries\TenantContext::get() === $before, (string) \App\Libraries\TenantContext::get());

    // A client with a saved External display number must be found by it.
    $master = new \App\Libraries\MasterTenantRepository();
    $conn   = new \App\Libraries\TenantConnection($master);
    $snap   = $conn->capture();
    $sample = null;
    foreach ($master->listActiveTenants() as $tenant) {
        try {
            if ($conn->apply((string) $tenant['key'], 'test') && db_connect()->tableExists('settings')) {
                $d = $norm((string) (new SettingsService())->get('cheerio_display_phone', ''));
                if ($d !== '') {
                    $sample = ['key' => strtolower((string) $tenant['key']), 'phone' => $d];
                    break;
                }
            }
        } catch (Throwable $e) {
        }
    }
    $conn->restore($snap);
    if ($sample !== null) {
        $found = (new \App\Libraries\WhatsAppWebhookTenantFinder())->useTenantForDisplayPhone('+' . $sample['phone']);
        check('a client with a saved External display number is found by it',
            $found === true && \App\Libraries\TenantContext::get() === $sample['key'], (string) \App\Libraries\TenantContext::get());
        $conn->restore($snap);
    } else {
        echo "[SKIP] no local client has an External display number saved\n";
    }
} else {
    echo "[SKIP] master database not configured\n";
}

echo "\n=== {$pass} passed, {$fail} failed ===\n";
exit($fail === 0 ? 0 : 1);
