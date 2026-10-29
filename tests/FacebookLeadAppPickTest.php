<?php

/**
 * Pure-logic checks for which Meta app Facebook Lead Ads uses (tenant's own app or the platform app).
 * No database and no network.
 *
 * Run: php tests/FacebookLeadAppPickTest.php
 */

define('FCPATH', dirname(__DIR__) . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Libraries\FacebookLeadConnectService;

$pass = 0;
$fail = 0;

function check(string $label, bool $ok): void
{
    global $pass, $fail;
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $label . PHP_EOL;
    $ok ? $pass++ : $fail++;
}

$platform = [
    'app_id'       => '1389328076628792',
    'secret'       => 'platform-secret',
    'config_id'    => '2307752123406286',
    'version'      => 'v25.0',
    'verify_token' => 'platform-verify',
];
$gulfApp = ['app_id' => '1749543326338442', 'secret' => 'gulf-secret', 'config_id' => '', 'version' => 'v25.0'];

echo "=== Facebook Lead app pick unit test ===\n\n";

// Gulf Pharmacy: own WhatsApp app saved, no lead configuration of its own.
$r = FacebookLeadConnectService::pickLeadApp($gulfApp, $platform);
check('tenant with its own app but no lead config uses the platform app', $r['app_id'] === '1389328076628792' && $r['source'] === 'platform');
check('platform app brings the platform config id and secret', $r['config_id'] === '2307752123406286' && $r['secret'] === 'platform-secret');
check('platform app brings the platform verify token', $r['verify_token'] === 'platform-verify');

// Gulf Pharmacy with the platform config id typed into its Settings (the case that failed).
$r = FacebookLeadConnectService::pickLeadApp(['config_id' => '2307752123406286'] + $gulfApp, $platform);
check('a tenant config equal to the platform one is treated as the platform config', $r['app_id'] === '1389328076628792' && $r['source'] === 'platform');

// A tenant that really runs its own app and its own configuration.
$r = FacebookLeadConnectService::pickLeadApp(['config_id' => '99887766554433'] + $gulfApp, $platform);
check('tenant app plus its own config keeps the tenant app', $r['app_id'] === '1749543326338442' && $r['source'] === 'tenant');
check('tenant app plus its own config keeps the tenant config', $r['config_id'] === '99887766554433' && $r['secret'] === 'gulf-secret');

// A tenant with no app of its own.
$r = FacebookLeadConnectService::pickLeadApp(['app_id' => '', 'secret' => '', 'config_id' => '', 'version' => ''], $platform);
check('tenant with no app uses the platform app', $r['app_id'] === '1389328076628792' && $r['source'] === 'platform');

// Platform not configured for Lead Ads: old behaviour.
$noLead = ['config_id' => ''] + $platform;
$r      = FacebookLeadConnectService::pickLeadApp($gulfApp, $noLead);
check('platform without a lead config falls back to the tenant app', $r['app_id'] === '1749543326338442' && $r['source'] === 'tenant');
$r = FacebookLeadConnectService::pickLeadApp(['app_id' => '', 'secret' => '', 'config_id' => '', 'version' => ''], $noLead);
check('no tenant app and no platform lead config still falls back to the platform app id', $r['app_id'] === '1389328076628792' && $r['config_id'] === '');

// Platform with no app at all.
$empty = ['app_id' => '', 'secret' => '', 'config_id' => '', 'version' => '', 'verify_token' => ''];
$r     = FacebookLeadConnectService::pickLeadApp($gulfApp, $empty);
check('nothing on the platform leaves the tenant app untouched', $r['app_id'] === '1749543326338442' && $r['source'] === 'tenant' && $r['verify_token'] === '');

// Config id and version are validated.
$r = FacebookLeadConnectService::pickLeadApp(['config_id' => 'not-a-number'] + $gulfApp, $platform);
check('a malformed tenant config id is ignored', $r['config_id'] === '2307752123406286' && $r['source'] === 'platform');
$r = FacebookLeadConnectService::pickLeadApp($gulfApp, ['version' => 'junk'] + $platform);
check('a malformed version falls back to v21.0', $r['version'] === 'v21.0');

echo PHP_EOL . "Passed: {$pass}, failed: {$fail}" . PHP_EOL;
exit($fail > 0 ? 1 : 0);
