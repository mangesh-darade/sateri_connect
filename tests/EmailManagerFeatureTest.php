<?php

declare(strict_types=1);

/**
 * Seed + smoke test for Email Manager / Analytics.
 *
 * Run:
 *   php tests/EmailManagerFeatureTest.php
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

echo "=== Email Manager Smoke Test ===\n\n";

$db = db_connect();

$tag = 'EMTEST-' . date('Ymd-His');
$userId = (int) (($db->table('users')->select('id')->orderBy('id', 'ASC')->get()->getFirstRow('array')['id'] ?? 0));

$builderModel = model(\App\Models\EmailBuilderModel::class);
$dripModel = model(\App\Models\EmailDripModel::class);
$stepModel = model(\App\Models\EmailDripStepModel::class);
$campaignModel = model(\App\Models\EmailHtmlCampaignModel::class);
$senderModel = model(\App\Models\EmailSenderModel::class);
$verifyModel = model(\App\Models\EmailVerificationModel::class);
$logModel = model(\App\Models\EmailLogModel::class);

$builderId = (int) $builderModel->insert([
    'name' => "{$tag} Welcome Builder",
    'subject' => 'Welcome to our store',
    'html_content' => '<h1>Welcome</h1><p>This is a seeded test builder.</p>',
    'cheerio_builder_id' => 'seed-builder-001',
    'status' => 'active',
    'created_by' => $userId ?: null,
], true);

$dripId = (int) $dripModel->insert([
    'name' => "{$tag} Onboarding Drip",
    'description' => 'Seeded onboarding flow',
    'trigger_type' => 'on_subscribe',
    'trigger_value' => 'newsletter',
    'status' => 'active',
    'created_by' => $userId ?: null,
], true);

$step1Id = (int) $stepModel->insert([
    'drip_id' => $dripId,
    'step_order' => 1,
    'delay_hours' => 0,
    'subject' => 'Welcome Day 0',
    'html_content' => '<p>Welcome email step 1</p>',
    'builder_id' => $builderId,
], true);

$step2Id = (int) $stepModel->insert([
    'drip_id' => $dripId,
    'step_order' => 2,
    'delay_hours' => 24,
    'subject' => 'Tips Day 1',
    'html_content' => '<p>Follow-up email step 2</p>',
    'builder_id' => null,
], true);

$campaignId = (int) $campaignModel->insert([
    'name' => "{$tag} Promo Campaign",
    'subject' => 'Weekend Offer',
    'html_content' => '<h2>Offer</h2><p>Flat 15% off this weekend.</p>',
    'builder_id' => $builderId,
    'cheerio_builder_id' => 'seed-builder-001',
    'mode' => 'recipients',
    'recipients_json' => ['alice@example.com', 'bob@example.com'],
    'status' => 'draft',
    'sent_count' => 0,
    'failed_count' => 0,
    'created_by' => $userId ?: null,
], true);

$senderA = (int) $senderModel->insert([
    'type' => 'sender',
    'name' => "{$tag} Main Sender",
    'email' => 'marketing@example.com',
    'cheerio_id' => 'sender-seed-001',
    'status' => 'verified',
    'notes' => 'Seed sender',
    'is_default' => 1,
], true);

$senderB = (int) $senderModel->insert([
    'type' => 'domain',
    'name' => "{$tag} Main Domain",
    'domain' => 'example.com',
    'cheerio_id' => 'domain-seed-001',
    'status' => 'pending',
    'dns_records' => [
        ['type' => 'TXT', 'host' => '@', 'value' => 'v=spf1 include:_spf.example.com ~all'],
        ['type' => 'CNAME', 'host' => 'dkim._domainkey', 'value' => 'dkim.example.com'],
    ],
    'notes' => 'Add SPF and DKIM',
    'is_default' => 1,
], true);

$verifyA = (int) $verifyModel->insert([
    'email' => 'valid.customer@example.com',
    'status' => 'valid',
    'syntax_ok' => 1,
    'mx_ok' => 1,
    'disposable' => 0,
    'checks_json' => ['syntax' => 'pass', 'mx' => ['pass' => true]],
    'verified_at' => date('Y-m-d H:i:s'),
], true);

$verifyB = (int) $verifyModel->insert([
    'email' => 'temp@mailinator.com',
    'status' => 'risky',
    'syntax_ok' => 1,
    'mx_ok' => 1,
    'disposable' => 1,
    'checks_json' => ['syntax' => 'pass', 'disposable' => 'fail'],
    'verified_at' => date('Y-m-d H:i:s'),
], true);

$logModel->record('campaign', 'sent', 'Weekend Offer', 'alice@example.com', 'cheerio', 'Seed sent', ['seed' => true], $userId ?: null, $builderId, $campaignId, null);
$logModel->record('campaign', 'failed', 'Weekend Offer', 'bob@example.com', 'cheerio', 'Seed failed', ['seed' => true], $userId ?: null, $builderId, $campaignId, null);
$logModel->record('drip', 'queued', 'Welcome Day 0', 'carol@example.com', 'cheerio', 'Queued seed drip', ['seed' => true], $userId ?: null, $builderId, null, $dripId);

check('builder inserted', $builderId > 0);
check('drip inserted', $dripId > 0);
check('drip step 1 inserted', $step1Id > 0);
check('drip step 2 inserted', $step2Id > 0);
check('campaign inserted', $campaignId > 0);
check('sender inserted', $senderA > 0);
check('domain inserted', $senderB > 0);
check('verification rows inserted', $verifyA > 0 && $verifyB > 0);

$drips = $dripModel->withSteps(10);
$seedDrip = null;
foreach ($drips as $drip) {
    if ((int) ($drip['id'] ?? 0) === $dripId) {
        $seedDrip = $drip;
        break;
    }
}
check('withSteps returns seeded drip', is_array($seedDrip));
check('withSteps returns 2 steps', is_array($seedDrip) && count($seedDrip['steps'] ?? []) === 2, is_array($seedDrip) ? 'steps=' . count($seedDrip['steps'] ?? []) : '');

$campaign = $campaignModel->find($campaignId);
check('campaign has recipients array', is_array($campaign) && is_array($campaign['recipients'] ?? null));
check('campaign keeps two recipients', is_array($campaign) && count($campaign['recipients'] ?? []) === 2);

$summary = $logModel->summary(date('Y-m-d 00:00:00'), date('Y-m-d 23:59:59'));
check('email log summary total >= 3', (int) ($summary['total'] ?? 0) >= 3, json_encode($summary));
check('email log summary sent >= 1', (int) ($summary['sent'] ?? 0) >= 1, json_encode($summary));
check('email log summary failed >= 1', (int) ($summary['failed'] ?? 0) >= 1, json_encode($summary));
check('email log summary queued >= 1', (int) ($summary['queued'] ?? 0) >= 1, json_encode($summary));

$daily = $logModel->daily(date('Y-m-d'), date('Y-m-d'));
check('daily returns today row', count($daily) === 1);
check('daily today sent >= 1', (int) (($daily[0]['sent'] ?? 0)) >= 1, json_encode($daily));

$routes = file_get_contents(dirname(FCPATH) . '/app/Config/Routes.php');
check('route email-manager exists', str_contains($routes, "get('email-manager', 'EmailManager::index')"));
check('route analytics exists', str_contains($routes, "get('analytics', 'Analytics::index')"));

$views = [
    'app/Views/email_manager/index.php',
    'app/Views/email_manager/_tab_builder.php',
    'app/Views/email_manager/_tab_drips.php',
    'app/Views/email_manager/_tab_verifier.php',
    'app/Views/email_manager/_tab_campaigns.php',
    'app/Views/email_manager/_tab_senders.php',
    'app/Views/analytics/index.php',
];
foreach ($views as $view) {
    check("view exists {$view}", is_file(dirname(FCPATH) . '/' . $view));
}

echo "\nSeed tag: {$tag}\n";
echo "Open: /email-manager and /analytics?tab=email\n";
echo "\n=== Result: {$pass} passed, {$fail} failed ===\n";

exit($fail > 0 ? 1 : 0);
