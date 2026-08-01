<?php

declare(strict_types=1);

/**
 * Deep syntax + wiring checks for Emails feature.
 *
 * Run: php tests/EmailsFeatureTest.php
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
    } else {
        $fail++;
        echo "[FAIL] {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
    }
}

echo "=== Emails Feature Deep Test ===\n\n";

$root = dirname(FCPATH);

$files = [
    'app/Controllers/Emails.php',
    'app/Views/emails/index.php',
    'app/Views/emails/single.php',
    'app/Views/emails/bulk.php',
    'public/assets/js/emails.js',
    'app/Commands/TestEmailSend.php',
];

foreach ($files as $rel) {
    check("exists {$rel}", is_file($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel)));
}

$controller = file_get_contents($root . '/app/Controllers/Emails.php');
check('Emails has index()', str_contains($controller, 'function index('));
check('Emails has single()', str_contains($controller, 'function single('));
check('Emails has bulk()', str_contains($controller, 'function bulk('));
check('Emails has sendSingle()', str_contains($controller, 'function sendSingle('));
check('Emails has sendBulk()', str_contains($controller, 'function sendBulk('));
check('Emails uses emailProvider', str_contains($controller, "service('emailProvider')"));
check('Emails uses sendCampaign for bulk', str_contains($controller, 'sendCampaign('));
check('Emails caps bulk recipients', str_contains($controller, 'MAX_BULK_RECIPIENTS'));
check('Emails default test email set', str_contains($controller, 'sateri.mangesh@gmail.com'));

$routesFile = file_get_contents($root . '/app/Config/Routes.php');
check('route GET emails', str_contains($routesFile, "get('emails', 'Emails::index')"));
check('route GET emails/send', str_contains($routesFile, "get('emails/send', 'Emails::single')"));
check('route POST emails/send', str_contains($routesFile, "post('emails/send', 'Emails::sendSingle'"));
check('route GET emails/bulk', str_contains($routesFile, "get('emails/bulk', 'Emails::bulk')"));
check('route POST emails/bulk', str_contains($routesFile, "post('emails/bulk', 'Emails::sendBulk'"));

$nav = file_get_contents($root . '/app/Views/layouts/main.php');
check('nav emails.view gate', str_contains($nav, "can('emails.view')"));
check('nav emails link', str_contains($nav, "site_url('emails/send')") && str_contains($nav, "site_url('emails/bulk')"));

$seeder = file_get_contents($root . '/app/Database/Seeds/PermissionSeeder.php');
check('permission emails.view seeded', str_contains($seeder, "'emails.view'"));
check('permission emails.send seeded', str_contains($seeder, "'emails.send'"));

$js = file_get_contents($root . '/public/assets/js/emails.js');
check('JS bindSingle', str_contains($js, 'bindSingle'));
check('JS bindBulk', str_contains($js, 'bindBulk'));
check('JS posts JSON', str_contains($js, 'application/json'));

$mailer = service('emailProvider');
check('emailProvider resolves', $mailer instanceof \App\Libraries\EmailProvider);
check('emailProvider has send', method_exists($mailer, 'send'));
check('emailProvider has sendCampaign', method_exists($mailer, 'sendCampaign'));

$ref = new ReflectionClass(\App\Controllers\Emails::class);
check('Emails controller class loads', $ref->isInstantiable());
foreach (['index', 'single', 'bulk', 'sendSingle', 'sendBulk'] as $method) {
    check("Emails::{$method} exists", $ref->hasMethod($method));
}

$db = db_connect();
$viewPerm = $db->table('permissions')->where('slug', 'emails.view')->countAllResults();
$sendPerm = $db->table('permissions')->where('slug', 'emails.send')->countAllResults();
check('DB permission emails.view', $viewPerm === 1);
check('DB permission emails.send', $sendPerm === 1);

$adminHas = $db->table('role_permissions rp')
    ->join('roles r', 'r.id = rp.role_id')
    ->join('permissions p', 'p.id = rp.permission_id')
    ->where('r.slug', 'admin')
    ->whereIn('p.slug', ['emails.view', 'emails.send'])
    ->countAllResults();
check('admin role has emails perms', $adminHas === 2, "got {$adminHas}");

$settings = new \App\Libraries\SettingsService();
$provider = $settings->getEmailProvider();
check('email provider configured', in_array($provider, ['smtp', 'sendgrid', 'cheerio'], true), $provider);

// Validation paths (no live send)
$smtp = new \App\Libraries\Email\SmtpEmailDriver($settings);
$bad = $smtp->send('', 'x', 'y');
check('SMTP rejects empty recipient', ($bad['ok'] ?? true) === false);

$cheerio = new \App\Libraries\Email\CheerioEmailDriver($settings);
$badBulk = $cheerio->sendCampaign(['subject' => 'Hi', 'html' => '<p>x</p>']);
check('Cheerio bulk needs label or recipients', ($badBulk['ok'] ?? true) === false);

echo "\n=== Result: {$pass} passed, {$fail} failed ===\n";
exit($fail > 0 ? 1 : 0);
