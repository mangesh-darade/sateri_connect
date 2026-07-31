<?php

declare(strict_types=1);

/**
 * Regression test for Meta Dashboard-equivalent webhook automation.
 *
 * Run: php tests/MetaWebhookAutomationUnitTest.php
 */

define('FCPATH', __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR);
chdir(FCPATH);

require FCPATH . '../app/Config/Paths.php';
$paths = new \Config\Paths();
require $paths->systemDirectory . '/Boot.php';
\CodeIgniter\Boot::bootSpark($paths);

$pass = 0;
$fail = 0;

function metaWebhookCheck(string $label, bool $condition): void
{
    global $pass, $fail;

    if ($condition) {
        $pass++;
        echo "[PASS] {$label}\n";
        return;
    }

    $fail++;
    echo "[FAIL] {$label}\n";
}

final class MetaWebhookFakeSettings extends \App\Libraries\SettingsService
{
    public function __construct()
    {
    }

    public function getMetaConfig(): array
    {
        return [
            'access_token'   => 'system-user-token',
            'phone_number_id'=> 'phone-123',
            'waba_id'        => 'waba-456',
            'verify_token'   => 'verify-789',
            'app_secret'     => 'app-secret',
            'app_id'         => 'app-101',
            'api_version'    => 'v26.0',
        ];
    }

    public function resolveWebhookPublicConfig(?string $requestOrigin = null): array
    {
        return [
            'public_base'     => 'https://example.test',
            'public_callback' => 'https://example.test/webhooks',
        ];
    }

    public function setMetaConfig(array $config): void
    {
    }
}

final class MetaWebhookFakeApi extends \App\Libraries\MetaCloudAPI
{
    /** @var list<array{transport: string, method: string, endpoint: string, data: array<string, mixed>}> */
    public array $calls = [];

    public function request(string $method, string $endpoint, ?array $data = null, bool $isMultipart = false): array
    {
        $this->calls[] = [
            'transport' => 'user',
            'method'    => strtoupper($method),
            'endpoint'  => $endpoint,
            'data'      => $data ?? [],
        ];

        if (strtoupper($method) === 'GET') {
            return ['data' => [['override_callback_uri' => 'https://example.test/webhooks']]];
        }

        return ['success' => true];
    }

    protected function appGraphRequest(
        string $method,
        string $endpoint,
        array $data,
        string $appId,
        string $appSecret
    ): array {
        $this->calls[] = [
            'transport' => 'app',
            'method'    => strtoupper($method),
            'endpoint'  => $endpoint,
            'data'      => $data,
        ];

        if (strtoupper($method) === 'GET') {
            return ['data' => [[
                'object' => 'whatsapp_business_account',
                'fields' => [['name' => 'messages']],
            ]]];
        }

        return ['success' => true];
    }
}

echo "=== Meta Webhook Automation Unit Test ===\n\n";

$api = new MetaWebhookFakeApi(new MetaWebhookFakeSettings());
$result = $api->subscribeWabaWebhook('https://example.test/webhooks', 'verify-789');

$appPost = null;
$plainWabaPost = null;
$overrideWabaPost = null;
foreach ($api->calls as $index => $call) {
    if ($call['transport'] === 'app' && $call['method'] === 'POST') {
        $appPost = ['index' => $index, 'call' => $call];
    }
    if ($call['transport'] === 'user' && $call['method'] === 'POST' && $call['data'] === []) {
        $plainWabaPost = ['index' => $index, 'call' => $call];
    }
    if (isset($call['data']['override_callback_uri'])) {
        $overrideWabaPost = ['index' => $index, 'call' => $call];
    }
}

metaWebhookCheck('app subscription POST runs even when messages already exists', is_array($appPost));
metaWebhookCheck('app callback URL is sent', ($appPost['call']['data']['callback_url'] ?? '') === 'https://example.test/webhooks');
metaWebhookCheck('app verify token is sent', ($appPost['call']['data']['verify_token'] ?? '') === 'verify-789');
metaWebhookCheck('required webhook fields are sent', str_contains((string) ($appPost['call']['data']['fields'] ?? ''), 'messages'));
metaWebhookCheck('WABA is subscribed before applying override', is_array($plainWabaPost) && is_array($overrideWabaPost)
    && $plainWabaPost['index'] < $overrideWabaPost['index']);
metaWebhookCheck('WABA override uses callback URL', ($overrideWabaPost['call']['data']['override_callback_uri'] ?? '') === 'https://example.test/webhooks');
metaWebhookCheck('complete setup is reported', ! empty($result['fully_configured']));

echo "\n=== Result: {$pass} passed, {$fail} failed ===\n";
exit($fail > 0 ? 1 : 0);
