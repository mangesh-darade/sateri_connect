<?php

declare(strict_types=1);

/**
 * Provider matrix smoke test for Email Manager related logic.
 *
 * Run:
 *   php tests/EmailProviderMatrixSmokeTest.php
 */

define('FCPATH', __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR);
chdir(FCPATH);

require FCPATH . '../app/Config/Paths.php';
$paths = new \Config\Paths();
require $paths->systemDirectory . '/Boot.php';
\CodeIgniter\Boot::bootSpark($paths);

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
    echo "[FAIL] {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

echo "=== Email Provider Matrix Smoke Test ===\n\n";

$settings = new \App\Libraries\SettingsService();
$originalProvider = $settings->getEmailProvider();

try {
    foreach ([
        \App\Libraries\SettingsService::EMAIL_PROVIDER_SMTP,
        \App\Libraries\SettingsService::EMAIL_PROVIDER_SENDGRID,
        \App\Libraries\SettingsService::EMAIL_PROVIDER_CHEERIO,
    ] as $provider) {
        $settings->setEmailProvider($provider);
        $mailer = service('emailProvider');
        $resolved = $mailer->getProvider();

        check("provider switch to {$provider}", $resolved === $provider, "got={$resolved}");

        // validation-only paths to avoid actual network sends
        $single = $mailer->send('bad-email', 'Sub', 'Body');
        check("{$provider} single invalid recipient handled", isset($single['ok']) && $single['ok'] === false);

        $campaign = $mailer->sendCampaign([
            'name' => 'matrix-' . $provider,
            'subject' => 'Matrix check',
            // keep recipients empty to exercise validation branch for each provider
            'recipients' => [],
            'html' => '<p>matrix</p>',
        ]);
        check("{$provider} campaign validation handled", isset($campaign['ok']) && is_bool($campaign['ok']));
    }
} finally {
    // restore original provider
    $settings->setEmailProvider($originalProvider);
}

echo "\n=== Result: {$pass} passed, {$fail} failed ===\n";
exit($fail > 0 ? 1 : 0);

