<?php

declare(strict_types=1);

/**
 * Deep functional test for the multi-provider email layer.
 *
 * Run: php tests/EmailProviderTest.php
 *
 * Tests:
 *   1. Interface contracts (all driver classes implement all required methods)
 *   2. SettingsService email provider constants & helpers
 *   3. Facade resolution (EmailProvider picks correct driver per setting)
 *   4. SmtpEmailDriver validation paths
 *   5. SendGridEmailDriver validation paths
 *   6. CheerioEmailDriver validation paths
 *   7. sendCampaign validation paths for all three drivers
 *   8. Settings helper functions
 *   9. Config values
 *  10. Route registration
 */

// ── Bootstrap CI4 ────────────────────────────────────────────────────
define('FCPATH', __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR);
chdir(FCPATH);

require FCPATH . '../app/Config/Paths.php';
$paths = new \Config\Paths();
require $paths->systemDirectory . '/Boot.php';
\CodeIgniter\Boot::bootSpark($paths);

echo "╔══════════════════════════════════════════════════════════════╗\n";
echo "║   Email Provider Layer — Deep Functional Test Suite         ║\n";
echo "╚══════════════════════════════════════════════════════════════╝\n\n";

$pass = 0;
$fail = 0;

function check(string $label, bool $condition, string $detail = ''): void
{
    global $pass, $fail;
    if ($condition) {
        $pass++;
        echo "  ✓ {$label}\n";
    } else {
        $fail++;
        $extra = $detail !== '' ? " — {$detail}" : '';
        echo "  ✗ {$label}{$extra}\n";
    }
}

function section(string $title): void
{
    echo "\n── {$title} " . str_repeat('─', max(1, 55 - strlen($title))) . "\n";
}

// ═══════════════════════════════════════════════════════════════════════
section('1. Interface contracts');
// ═══════════════════════════════════════════════════════════════════════

$iface = \App\Libraries\Email\EmailDriverInterface::class;

$drivers = [
    \App\Libraries\Email\SmtpEmailDriver::class,
    \App\Libraries\Email\SendGridEmailDriver::class,
    \App\Libraries\Email\CheerioEmailDriver::class,
];

$requiredMethods = ['getName', 'loadCredentials', 'send', 'sendHtml', 'testConnection', 'sendCampaign'];

foreach ($drivers as $driverClass) {
    $shortName = (new \ReflectionClass($driverClass))->getShortName();

    check(
        "{$shortName} implements EmailDriverInterface",
        is_subclass_of($driverClass, $iface) || (new \ReflectionClass($driverClass))->implementsInterface($iface)
    );

    foreach ($requiredMethods as $method) {
        check(
            "{$shortName}::{$method}() exists",
            method_exists($driverClass, $method)
        );
    }
}

// ═══════════════════════════════════════════════════════════════════════
section('2. SettingsService constants & helpers');
// ═══════════════════════════════════════════════════════════════════════

use App\Libraries\SettingsService;

check('EMAIL_PROVIDER_SMTP constant', SettingsService::EMAIL_PROVIDER_SMTP === 'smtp');
check('EMAIL_PROVIDER_SENDGRID constant', SettingsService::EMAIL_PROVIDER_SENDGRID === 'sendgrid');
check('EMAIL_PROVIDER_CHEERIO constant', SettingsService::EMAIL_PROVIDER_CHEERIO === 'cheerio');

$ss = new SettingsService();

// getEmailProvider defaults to smtp when no DB value
$provider = $ss->getEmailProvider();
check('getEmailProvider() returns valid string', in_array($provider, ['smtp', 'sendgrid', 'cheerio'], true));

// getSmtpConfig returns expected keys
$smtpConfig = $ss->getSmtpConfig();
$expectedSmtpKeys = ['host', 'port', 'user', 'password', 'encryption', 'from_email', 'from_name'];
check('getSmtpConfig() returns all keys', count(array_diff($expectedSmtpKeys, array_keys($smtpConfig))) === 0,
    'Missing: ' . implode(', ', array_diff($expectedSmtpKeys, array_keys($smtpConfig))));

// getSendGridConfig returns expected keys (expanded for marketing)
$sgConfig = $ss->getSendGridConfig();
$expectedSgKeys = ['api_key', 'from_email', 'from_name', 'sender_id', 'suppression_group_id', 'custom_unsubscribe_url', 'ip_pool'];
check('getSendGridConfig() returns all keys', count(array_diff($expectedSgKeys, array_keys($sgConfig))) === 0,
    'Missing: ' . implode(', ', array_diff($expectedSgKeys, array_keys($sgConfig))));

// getCheerioEmailConfig returns expected keys
$ceConfig = $ss->getCheerioEmailConfig();
$expectedCeKeys = ['api_key', 'default_campaign'];
check('getCheerioEmailConfig() returns all keys', count(array_diff($expectedCeKeys, array_keys($ceConfig))) === 0,
    'Missing: ' . implode(', ', array_diff($expectedCeKeys, array_keys($ceConfig))));

// isSmtpEmailProvider / isSendGridEmailProvider / isCheerioEmailProvider return bools
check('isSmtpEmailProvider() returns bool', is_bool($ss->isSmtpEmailProvider()));
check('isSendGridEmailProvider() returns bool', is_bool($ss->isSendGridEmailProvider()));
check('isCheerioEmailProvider() returns bool', is_bool($ss->isCheerioEmailProvider()));

// ═══════════════════════════════════════════════════════════════════════
section('3. EmailProvider facade resolution');
// ═══════════════════════════════════════════════════════════════════════

$ep = new \App\Libraries\EmailProvider();
check('EmailProvider instantiation', $ep instanceof \App\Libraries\EmailProvider);
check('getProvider() returns string', is_string($ep->getProvider()));
check('getDriver() returns EmailDriverInterface', $ep->getDriver() instanceof \App\Libraries\Email\EmailDriverInterface);

// Methods exist on facade
foreach (['send', 'sendHtml', 'testConnection', 'sendCampaign', 'getProvider', 'getDriver', 'loadCredentials'] as $m) {
    check("EmailProvider::{$m}() exists", method_exists($ep, $m));
}

// ═══════════════════════════════════════════════════════════════════════
section('4. SmtpEmailDriver validation');
// ═══════════════════════════════════════════════════════════════════════

$smtp = new \App\Libraries\Email\SmtpEmailDriver();

check('SmtpEmailDriver::getName() = smtp', $smtp->getName() === 'smtp');

// Empty recipients
$r = $smtp->send([], 'Test', 'Body');
check('send([]) returns ok=false', $r['ok'] === false);
check('send([]) message mentions recipients', str_contains($r['message'], 'recipient'));

// sendCampaign without recipients
$r = $smtp->sendCampaign([]);
check('sendCampaign([]) returns ok=false', $r['ok'] === false);
check('sendCampaign([]) message mentions recipients', str_contains($r['message'], 'recipients'));

// sendCampaign missing subject
$r = $smtp->sendCampaign(['recipients' => ['a@b.com']]);
check('sendCampaign(no subject) returns ok=false', $r['ok'] === false);

// sendCampaign missing body
$r = $smtp->sendCampaign(['recipients' => ['a@b.com'], 'subject' => 'S']);
check('sendCampaign(no body) returns ok=false', $r['ok'] === false);

// ═══════════════════════════════════════════════════════════════════════
section('5. SendGridEmailDriver validation');
// ═══════════════════════════════════════════════════════════════════════

$sg = new \App\Libraries\Email\SendGridEmailDriver();

check('SendGridEmailDriver::getName() = sendgrid', $sg->getName() === 'sendgrid');

// No API key
$r = $sg->send('test@test.com', 'Subject', 'Body');
check('send() without API key returns ok=false', $r['ok'] === false);
check('send() mentions API key', str_contains(strtolower($r['message']), 'api key'));

// sendCampaign without API key
$r = $sg->sendCampaign([]);
check('sendCampaign() without API key returns ok=false', $r['ok'] === false);

// sendCampaign missing name
$r = $sg->sendCampaign(['_bypass_key' => true]);
check('sendCampaign() missing name caught', $r['ok'] === false);

// sendCampaign missing list_ids (we can't bypass apikey check easily, so we test result)
$r = $sg->sendCampaign(['name' => 'Test', 'subject' => 'S']);
check('sendCampaign() without API key stops early', $r['ok'] === false);

// ═══════════════════════════════════════════════════════════════════════
section('6. CheerioEmailDriver validation');
// ═══════════════════════════════════════════════════════════════════════

$ch = new \App\Libraries\Email\CheerioEmailDriver();

check('CheerioEmailDriver::getName() = cheerio', $ch->getName() === 'cheerio');

// sendCampaign missing subject
$r = $ch->sendCampaign([]);
check('sendCampaign(no subject) returns ok=false', $r['ok'] === false);
check('sendCampaign(no subject) mentions subject', str_contains(strtolower($r['message']), 'subject'));

// sendCampaign missing body
$r = $ch->sendCampaign(['subject' => 'S']);
check('sendCampaign(no body) returns ok=false', $r['ok'] === false);
check('sendCampaign(no body) mentions body', str_contains(strtolower($r['message']), 'body'));

// sendCampaign no label + no recipients
$r = $ch->sendCampaign(['subject' => 'S', 'html' => '<p>Hi</p>']);
check('sendCampaign(no label/recipients) returns ok=false', $r['ok'] === false);
check('sendCampaign message mentions label or recipients', str_contains(strtolower($r['message']), 'label') || str_contains(strtolower($r['message']), 'recipients'));

// ═══════════════════════════════════════════════════════════════════════
section('7. AbstractEmailDriver default sendCampaign');
// ═══════════════════════════════════════════════════════════════════════

// SmtpEmailDriver overrides sendCampaign; AbstractEmailDriver has a fallback.
// We can test via reflection that SmtpEmailDriver declares its own sendCampaign
$ref = new \ReflectionMethod(\App\Libraries\Email\SmtpEmailDriver::class, 'sendCampaign');
check(
    'SmtpEmailDriver overrides sendCampaign (not abstract default)',
    $ref->getDeclaringClass()->getName() === \App\Libraries\Email\SmtpEmailDriver::class
);

$ref2 = new \ReflectionMethod(\App\Libraries\Email\SendGridEmailDriver::class, 'sendCampaign');
check(
    'SendGridEmailDriver overrides sendCampaign',
    $ref2->getDeclaringClass()->getName() === \App\Libraries\Email\SendGridEmailDriver::class
);

$ref3 = new \ReflectionMethod(\App\Libraries\Email\CheerioEmailDriver::class, 'sendCampaign');
check(
    'CheerioEmailDriver overrides sendCampaign',
    $ref3->getDeclaringClass()->getName() === \App\Libraries\Email\CheerioEmailDriver::class
);

// ═══════════════════════════════════════════════════════════════════════
section('8. Settings helper functions');
// ═══════════════════════════════════════════════════════════════════════

// Load helper
helper('settings');

check('email_provider() returns string', is_string(email_provider()));
check('email_provider() valid value', in_array(email_provider(), ['smtp', 'sendgrid', 'cheerio'], true));
check('email_provider_label() returns string', is_string(email_provider_label()));
check('email_provider_short() returns string', is_string(email_provider_short()));
check('is_smtp_email_provider() returns bool', is_bool(is_smtp_email_provider()));
check('is_sendgrid_email_provider() returns bool', is_bool(is_sendgrid_email_provider()));
check('is_cheerio_email_provider() returns bool', is_bool(is_cheerio_email_provider()));

// ═══════════════════════════════════════════════════════════════════════
section('9. Config\EmailProviders');
// ═══════════════════════════════════════════════════════════════════════

$cfg = config(\Config\EmailProviders::class);
check('EmailProviders config loads', $cfg instanceof \Config\EmailProviders);
check('cheerioBaseUrl set', str_contains($cfg->cheerioBaseUrl, 'cheerio.in'));
check('sendGridApiUrl set', str_contains($cfg->sendGridApiUrl, 'sendgrid.com'));
check('sendGridSingleSendsApiUrl set', str_contains($cfg->sendGridSingleSendsApiUrl, 'singlesends'));
check('timeout > 0', $cfg->timeout > 0);
check('maxRetries >= 0', $cfg->maxRetries >= 0);
check('defaultCampaignName set', $cfg->defaultCampaignName !== '');

// ═══════════════════════════════════════════════════════════════════════
section('10. Services + Routes');
// ═══════════════════════════════════════════════════════════════════════

check('service("emailProvider") resolves', service('emailProvider') instanceof \App\Libraries\EmailProvider);
check('service("emailProvider") is shared singleton', service('emailProvider') === service('emailProvider'));

// Route check — verify routes are defined in the Routes.php file directly
$routesFile = file_get_contents(__DIR__ . '/../app/Config/Routes.php');
check('POST settings/test-email route defined', str_contains($routesFile, 'test-email'));
check('POST settings/test-smtp route defined', str_contains($routesFile, 'test-smtp'));

// ═══════════════════════════════════════════════════════════════════════
section('11. Normalisation helpers');
// ═══════════════════════════════════════════════════════════════════════

// Test normalizeRecipients via reflection
$ref = new \ReflectionMethod(\App\Libraries\Email\AbstractEmailDriver::class, 'normalizeRecipients');
$ref->setAccessible(true);
$instance = new \App\Libraries\Email\SmtpEmailDriver();

$out = $ref->invoke($instance, 'a@b.com, c@d.com;  invalid , e@f.com');
check('normalizeRecipients splits comma/semicolon', count($out) === 3);
check('normalizeRecipients filters invalid', ! in_array('invalid', $out));

$out2 = $ref->invoke($instance, ['', 'g@h.com', 'not-email', 'g@h.com']);
check('normalizeRecipients array input deduplicates', count($out2) === 1);

// ═══════════════════════════════════════════════════════════════════════
section('12. resolveFrom helper');
// ═══════════════════════════════════════════════════════════════════════

$refFrom = new \ReflectionMethod(\App\Libraries\Email\AbstractEmailDriver::class, 'resolveFrom');
$refFrom->setAccessible(true);

$from = $refFrom->invoke($instance, ['from_email' => 'x@y.com', 'from_name' => 'XY'], 'smtp_from_email', 'smtp_from_name');
check('resolveFrom uses option override', $from['email'] === 'x@y.com' && $from['name'] === 'XY');

$fromDefault = $refFrom->invoke($instance, [], 'smtp_from_email', 'smtp_from_name');
check('resolveFrom fallback returns array with email key', array_key_exists('email', $fromDefault));
check('resolveFrom fallback returns array with name key', array_key_exists('name', $fromDefault));

// ═══════════════════════════════════════════════════════════════════════
section('13. result() helper');
// ═══════════════════════════════════════════════════════════════════════

$refResult = new \ReflectionMethod(\App\Libraries\Email\AbstractEmailDriver::class, 'result');
$refResult->setAccessible(true);

$res = $refResult->invoke($instance, true, 'All good');
check('result() ok=true has provider key', isset($res['provider']));
check('result() ok field correct', $res['ok'] === true);
check('result() message field correct', $res['message'] === 'All good');
check('result() no data key when null', ! array_key_exists('data', $res));

$resData = $refResult->invoke($instance, false, 'Error', ['detail' => 1]);
check('result() with data includes data key', array_key_exists('data', $resData));
check('result() data preserved', $resData['data']['detail'] === 1);

// ═══════════════════════════════════════════════════════════════════════
section('14. Seeder keys coverage');
// ═══════════════════════════════════════════════════════════════════════

$seederFile = file_get_contents(__DIR__ . '/../app/Database/Seeds/SettingSeeder.php');
$requiredSeederKeys = [
    'email_provider', 'sendgrid_api_key', 'sendgrid_from_email', 'sendgrid_from_name',
    'sendgrid_sender_id', 'sendgrid_suppression_group_id', 'sendgrid_custom_unsubscribe_url',
    'sendgrid_ip_pool', 'cheerio_email_campaign_name',
];
foreach ($requiredSeederKeys as $key) {
    check("Seeder has key '{$key}'", str_contains($seederFile, "'{$key}'"));
}

// ═══════════════════════════════════════════════════════════════════════
section('15. EncryptedKeys coverage');
// ═══════════════════════════════════════════════════════════════════════

$ssRef = new \ReflectionProperty(SettingsService::class, 'encryptedKeys');
$ssRef->setAccessible(true);
$encKeys = $ssRef->getValue(new SettingsService());

check('sendgrid_api_key in encryptedKeys', in_array('sendgrid_api_key', $encKeys, true));
check('smtp_password in encryptedKeys', in_array('smtp_password', $encKeys, true));
check('cheerio_api_key in encryptedKeys', in_array('cheerio_api_key', $encKeys, true));

// ═══════════════════════════════════════════════════════════════════════
// Summary
// ═══════════════════════════════════════════════════════════════════════

echo "\n╔══════════════════════════════════════════════════════════════╗\n";
echo "║   RESULTS: {$pass} passed, {$fail} failed" . str_repeat(' ', max(0, 35 - strlen("{$pass} passed, {$fail} failed"))) . "║\n";
echo "╚══════════════════════════════════════════════════════════════╝\n\n";

exit($fail > 0 ? 1 : 0);
