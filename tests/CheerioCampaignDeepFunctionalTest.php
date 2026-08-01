<?php

declare(strict_types=1);

/**
 * Deep functional tests for Cheerio template/send + whatsapp/multiple campaign path.
 *
 * Run: php tests/CheerioCampaignDeepFunctionalTest.php
 */

define('FCPATH', __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR);
chdir(FCPATH);

require FCPATH . '../app/Config/Paths.php';
$paths = new \Config\Paths();
require $paths->systemDirectory . '/Boot.php';
\CodeIgniter\Boot::bootSpark($paths);

$pass = 0;
$fail = 0;
$errors = [];

function check(string $label, bool $condition, string $detail = ''): void
{
    global $pass, $fail, $errors;

    if ($condition) {
        $pass++;
        echo "[PASS] {$label}\n";

        return;
    }

    $fail++;
    $errors[] = $label . ($detail !== '' ? " — {$detail}" : '');
    echo "[FAIL] {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

/**
 * Captures Cheerio Direct API request envelopes without hitting the network.
 */
class CheerioCaptureApi extends \App\Libraries\CheerioDirectAPI
{
    /** @var list<array{method:string,endpoint:string,data:?array,isMultipart:bool}> */
    public array $calls = [];

    public function request(string $method, string $endpoint, ?array $data = null, bool $isMultipart = false): array
    {
        $this->calls[] = [
            'method'      => strtoupper($method),
            'endpoint'    => $endpoint,
            'data'        => $data,
            'isMultipart' => $isMultipart,
        ];

        return [
            'status'  => 0,
            'flag'    => true,
            'message' => 'captured',
            'data'    => ['ok' => true],
        ];
    }

    /**
     * Avoid DB lookups for auto-fill during payload tests.
     *
     * @param list<array<string, mixed>> $components
     *
     * @return list<array<string, mixed>>
     */
    public function ensureTemplateComponents(string $templateName, string $language, array $components): array
    {
        return array_values(array_filter($components, 'is_array'));
    }
}

echo "=== Cheerio Campaign Deep Functional Test ===\n\n";

$settings = new \App\Libraries\SettingsService();
$provider = $settings->getWhatsAppProvider();
echo "Active WhatsApp provider: {$provider}\n\n";

// -------------------------------------------------------------------------
// 1) sendTemplate envelope (Postman: POST /v1/whatsapp/template/send)
// -------------------------------------------------------------------------
echo "-- sendTemplate payload --\n";
$api = new CheerioCaptureApi();

$bodyComponents = [[
    'type'       => 'body',
    'parameters' => [
        ['type' => 'text', 'text' => 'Priam Jain'],
        ['type' => 'text', 'text' => 'Product 1'],
    ],
]];

$result = $api->sendTemplate('+91 97790-03936', 'incoming_lead_website', 'en', $bodyComponents);
check('sendTemplate returns captured success', ($result['flag'] ?? false) === true);
check('sendTemplate one HTTP call', count($api->calls) === 1);

$call = $api->calls[0] ?? [];
check('sendTemplate method POST', ($call['method'] ?? '') === 'POST');
check('sendTemplate endpoint', ($call['endpoint'] ?? '') === 'v1/whatsapp/template/send');

$payload = $call['data'] ?? [];
check('sendTemplate has to (digits only)', ($payload['to'] ?? '') === '919779003936');
check('sendTemplate has data.name', ($payload['data']['name'] ?? '') === 'incoming_lead_website');
check('sendTemplate has data.language.code', ($payload['data']['language']['code'] ?? '') === 'en');
check('sendTemplate has components array', is_array($payload['data']['components'] ?? null));
check(
    'sendTemplate body param text preserved',
    ($payload['data']['components'][0]['parameters'][0]['text'] ?? '') === 'Priam Jain'
);

$emptyThrown = false;
try {
    $api->sendTemplate('', 't', 'en', []);
} catch (\RuntimeException $e) {
    $emptyThrown = str_contains($e->getMessage(), 'phone');
}
check('sendTemplate rejects empty phone', $emptyThrown);

// -------------------------------------------------------------------------
// 2) sendBulkCampaign envelope (Postman: POST /v1/whatsapp/multiple)
// -------------------------------------------------------------------------
echo "\n-- sendBulkCampaign payload --\n";
$api = new CheerioCaptureApi();

$recipients = [];
for ($i = 1; $i <= 5; $i++) {
    $recipients[] = [
        'to'         => '91970000000' . $i,
        'components' => [[
            'type'       => 'body',
            'parameters' => [
                ['type' => 'text', 'text' => 'User ' . $i],
                ['type' => 'text', 'text' => (string) (1000 + $i)],
            ],
        ]],
    ];
}
// invalid rows should be skipped
$recipients[] = ['to' => '', 'components' => []];
$recipients[] = 'not-an-array';

$bulk = $api->sendBulkCampaign('Direct API campaign deep-test', 'support', 'en', $recipients, 2);
check('bulk recipient_count is 5', (int) ($bulk['recipient_count'] ?? 0) === 5);
check('bulk batches with size 2 => 3', (int) ($bulk['batches'] ?? 0) === 3);
check('bulk HTTP calls = batches', count($api->calls) === 3);

$first = $api->calls[0] ?? [];
check('bulk endpoint v1/whatsapp/multiple', ($first['endpoint'] ?? '') === 'v1/whatsapp/multiple');
check('bulk method POST', ($first['method'] ?? '') === 'POST');

$bp = $first['data'] ?? [];
check('bulk campaignName set', ($bp['campaignName'] ?? '') === 'Direct API campaign deep-test');
check('bulk template.name', ($bp['template']['name'] ?? '') === 'support');
check('bulk template.language.code', ($bp['template']['language']['code'] ?? '') === 'en');
check('bulk first batch has 2 rows', is_array($bp['data'] ?? null) && count($bp['data']) === 2);

$row0 = $bp['data'][0] ?? [];
check('bulk row messaging_product', ($row0['messaging_product'] ?? '') === 'whatsapp');
check('bulk row type template', ($row0['type'] ?? '') === 'template');
check('bulk row has to', ($row0['to'] ?? '') === '919700000001');
check('bulk row template.components is list', is_array($row0['template']['components'] ?? null));
check(
    'bulk body text personalized',
    ($row0['template']['components'][0]['parameters'][0]['text'] ?? '') === 'User 1'
);

$last = $api->calls[2]['data'] ?? [];
check('bulk last batch has 1 row', is_array($last['data'] ?? null) && count($last['data']) === 1);

$noName = false;
try {
    (new CheerioCaptureApi())->sendBulkCampaign('  ', 'support', 'en', [['to' => '9197']]);
} catch (\RuntimeException $e) {
    $noName = str_contains($e->getMessage(), 'Campaign name');
}
check('bulk rejects empty campaignName', $noName);

$noTpl = false;
try {
    (new CheerioCaptureApi())->sendBulkCampaign('camp', '', 'en', [['to' => '9197']]);
} catch (\RuntimeException $e) {
    $noTpl = str_contains($e->getMessage(), 'Template name');
}
check('bulk rejects empty templateName', $noTpl);

$noRecipients = false;
try {
    (new CheerioCaptureApi())->sendBulkCampaign('camp', 'support', 'en', [['to' => '']]);
} catch (\RuntimeException $e) {
    $noRecipients = str_contains($e->getMessage(), 'No valid recipients');
}
check('bulk rejects no valid recipients', $noRecipients);

// -------------------------------------------------------------------------
// 3) getCampaignSummary
// -------------------------------------------------------------------------
echo "\n-- getCampaignSummary --\n";
$api = new CheerioCaptureApi();
$api->getCampaignSummary('abc123');
check('summary GET endpoint', ($api->calls[0]['endpoint'] ?? '') === 'v1/analytics/summary/abc123');
check('summary method GET', ($api->calls[0]['method'] ?? '') === 'GET');

$emptyId = false;
try {
    $api->getCampaignSummary('  ');
} catch (\RuntimeException $e) {
    $emptyId = true;
}
check('summary rejects empty id', $emptyId);

// -------------------------------------------------------------------------
// 4) Meta stubs
// -------------------------------------------------------------------------
echo "\n-- Meta provider stubs --\n";
$meta = new \App\Libraries\MetaCloudAPI();
$metaBulk = false;
try {
    $meta->sendBulkCampaign('c', 't', 'en', [['to' => '91']]);
} catch (\RuntimeException $e) {
    $metaBulk = str_contains($e->getMessage(), 'Cheerio-only');
}
check('Meta sendBulkCampaign Cheerio-only', $metaBulk);

$metaSum = false;
try {
    $meta->getCampaignSummary('x');
} catch (\RuntimeException $e) {
    $metaSum = str_contains($e->getMessage(), 'Cheerio-only');
}
check('Meta getCampaignSummary Cheerio-only', $metaSum);

// -------------------------------------------------------------------------
// 5) CampaignService branching + component builder
// -------------------------------------------------------------------------
echo "\n-- CampaignService branching --\n";
$svc = new \App\Libraries\CampaignService();
$ref = new ReflectionClass($svc);

$should = $ref->getMethod('shouldDispatchViaCheerioBulk');
$should->setAccessible(true);

$isCheerio = $settings->isCheerioProvider();
check(
    'template + cheerio => bulk path',
    $should->invoke($svc, ['message_type' => 'template']) === $isCheerio
);
check(
    'non-template never bulk',
    $should->invoke($svc, ['message_type' => 'text']) === false
);
check(
    'interactive never bulk',
    $should->invoke($svc, ['message_type' => 'image']) === false
);

$build = $ref->getMethod('buildTemplateComponents');
$build->setAccessible(true);
$components = $build->invoke($svc, [
    '1' => 'name',
    '2' => 'Hello',
], [
    'name'   => 'Mangesh',
    'mobile' => '917000000001',
    'email'  => 'a@example.com',
]);
check('buildTemplateComponents returns body list', is_array($components) && ($components[0]['type'] ?? '') === 'body');
check(
    'buildTemplateComponents resolves {{name}} field',
    ($components[0]['parameters'][0]['text'] ?? '') === 'Mangesh'
);
check(
    'buildTemplateComponents keeps static text',
    ($components[0]['parameters'][1]['text'] ?? '') === 'Hello'
);

// -------------------------------------------------------------------------
// 6) DB-backed dry run: create draft campaign + contacts, dispatch with capture
// -------------------------------------------------------------------------
echo "\n-- DB dry-run dispatchCheerioBulkCampaign --\n";
$db = db_connect();
$canDb = $db->tableExists('campaigns')
    && $db->tableExists('campaign_contacts')
    && $db->tableExists('contacts')
    && $db->tableExists('templates');

check('campaign tables exist', $canDb);

if ($canDb && $isCheerio) {
    $marker = 'deep_cheerio_' . date('YmdHis');
    $contactIds = [];
    $now = date('Y-m-d H:i:s');

    for ($i = 1; $i <= 2; $i++) {
        $db->table('contacts')->insert([
            'name'       => $marker . '_c' . $i,
            'mobile'     => '91880000' . str_pad((string) $i, 4, '0', STR_PAD_LEFT),
            'email'      => $marker . $i . '@example.test',
            'status'     => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $contactIds[] = (int) $db->insertID();
    }
    // contact without phone → failed
    $db->table('contacts')->insert([
        'name'       => $marker . '_nophone',
        'mobile'     => '',
        'email'      => $marker . '_np@example.test',
        'status'     => 'active',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $noPhoneId = (int) $db->insertID();
    $contactIds[] = $noPhoneId;

    $tplId = null;
    $existingTpl = $db->table('templates')->where('name', 'support')->get()->getRowArray();
    if ($existingTpl) {
        $tplId = (int) $existingTpl['id'];
        $tplName = (string) $existingTpl['name'];
        $tplLang = (string) ($existingTpl['language'] ?? 'en');
    } else {
        $db->table('templates')->insert([
            'name'          => $marker . '_tpl',
            'language'      => 'en',
            'category'      => 'MARKETING',
            'status'        => 'APPROVED',
            'body_text'     => 'Hi {{1}}, order {{2}}',
            'template_type' => 'default',
            'created_at'    => $now,
            'updated_at'    => $now,
        ]);
        $tplId = (int) $db->insertID();
        $tplName = $marker . '_tpl';
        $tplLang = 'en';
    }

    $db->table('campaigns')->insert([
        'name'         => $marker . '_camp',
        'template_id'  => $tplId,
        'status'       => 'draft',
        'message_type' => 'template',
        'payload'      => json_encode([
            'template_name' => $tplName,
            'language'      => $tplLang,
            'name'          => $tplName,
            '_audience'     => ['all' => false, 'contact_ids' => $contactIds, 'tag_ids' => []],
        ]),
        'variables'    => json_encode(['1' => 'name', '2' => 'ORDER-1']),
        'created_at'   => $now,
        'updated_at'   => $now,
    ]);
    $campaignId = (int) $db->insertID();

    // Swap whatsApp service with capture driver via temporary subclass wrapper.
    $capture = new CheerioCaptureApi();
    $facade = new class ($capture) extends \App\Libraries\WhatsAppCloudAPI {
        public function __construct(private CheerioCaptureApi $capture)
        {
            // skip parent construct
        }

        public function getProvider(): string
        {
            return \App\Libraries\SettingsService::PROVIDER_CHEERIO;
        }

        public function __call(string $name, array $arguments): mixed
        {
            return $this->capture->{$name}(...$arguments);
        }

        public function normalizePhone(string $phone): string
        {
            return $this->capture->normalizePhone($phone);
        }
    };

    // Inject via Services override is hard; call dispatch method with reflection
    // after monkey-patching service — use a custom CampaignService that we can
    // still invoke, but replace service() result by binding in Services.
    \Config\Services::injectMock('whatsApp', $facade);

    $dispatch = $ref->getMethod('dispatchCheerioBulkCampaign');
    $dispatch->setAccessible(true);

    try {
        $out = $dispatch->invoke($svc, $campaignId, $contactIds, null, false);
        check('dispatch returns cheerio_bulk', ($out['dispatch'] ?? '') === 'cheerio_bulk');
        check('dispatch contacts=3', (int) ($out['contacts'] ?? 0) === 3);
        check('dispatch queued=2 (skip no phone)', (int) ($out['queued'] ?? 0) === 2);
        check('dispatch failed includes nophone', (int) ($out['failed'] ?? 0) >= 1);
        check('dispatch sent=2 on capture success', (int) ($out['sent'] ?? 0) === 2);
        check('dispatch called whatsapp/multiple', count($capture->calls) >= 1
            && ($capture->calls[0]['endpoint'] ?? '') === 'v1/whatsapp/multiple');

        $camp = $db->table('campaigns')->where('id', $campaignId)->get()->getRowArray();
        check('campaign status running after dispatch', ($camp['status'] ?? '') === 'running');

        $sentRows = $db->table('campaign_contacts')
            ->where('campaign_id', $campaignId)
            ->where('status', 'sent')
            ->countAllResults();
        $failedRows = $db->table('campaign_contacts')
            ->where('campaign_id', $campaignId)
            ->where('status', 'failed')
            ->countAllResults();
        check('campaign_contacts sent=2', $sentRows === 2);
        check('campaign_contacts failed>=1', $failedRows >= 1);

        if (! empty($capture->calls[0]['data'])) {
            $bp = $capture->calls[0]['data'];
            check('DB dispatch campaignName matches', str_contains((string) ($bp['campaignName'] ?? ''), $marker));
            check('DB dispatch template.name set', ($bp['template']['name'] ?? '') === $tplName);
            check('DB dispatch data rows=2', is_array($bp['data'] ?? null) && count($bp['data']) === 2);
        }
    } catch (\Throwable $e) {
        check('dispatchCheerioBulkCampaign ran', false, $e->getMessage());
    }

    // cleanup
    $db->table('campaign_contacts')->where('campaign_id', $campaignId)->delete();
    $db->table('message_queue')->where('campaign_id', $campaignId)->delete();
    $db->table('campaigns')->where('id', $campaignId)->delete();
    $db->table('contacts')->whereIn('id', $contactIds)->delete();
    if (! $existingTpl && $tplId) {
        $db->table('templates')->where('id', $tplId)->delete();
    }
    \Config\Services::resetSingle('whatsApp');
} elseif ($canDb && ! $isCheerio) {
    echo "[SKIP] DB dispatch dry-run (provider is meta, not cheerio)\n";
    check('skip noted for meta provider', true);
}

// -------------------------------------------------------------------------
// 7) Facade method availability when cheerio
// -------------------------------------------------------------------------
echo "\n-- Facade --\n";
$wa = service('whatsApp');
check('facade has sendTemplate', method_exists($wa->getDriver(), 'sendTemplate'));
if ($provider === 'cheerio') {
    check('facade driver has sendBulkCampaign', method_exists($wa->getDriver(), 'sendBulkCampaign'));
    check('facade driver has getCampaignSummary', method_exists($wa->getDriver(), 'getCampaignSummary'));
} else {
    check('meta facade documents unsupported bulk', method_exists($wa->getDriver(), 'sendBulkCampaign'));
}

echo "\n=== Results: {$pass} passed, {$fail} failed ===\n";
if ($fail > 0) {
    echo "Failures:\n- " . implode("\n- ", $errors) . "\n";
}
exit($fail > 0 ? 1 : 0);
