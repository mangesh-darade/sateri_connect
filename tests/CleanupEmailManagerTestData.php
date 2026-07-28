<?php

declare(strict_types=1);

/**
 * Cleanup seeded Email Manager test data.
 *
 * Run:
 *   php tests/CleanupEmailManagerTestData.php
 */

define('FCPATH', __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR);
chdir(FCPATH);

require FCPATH . '../app/Config/Paths.php';
$paths = new \Config\Paths();
require $paths->systemDirectory . '/Boot.php';
\CodeIgniter\Boot::bootSpark($paths);

$db = db_connect();

$builders = $db->table('email_builders')->like('name', 'EMTEST-', 'after')->get()->getResultArray();
$drips = $db->table('email_drips')->like('name', 'EMTEST-', 'after')->get()->getResultArray();
$campaigns = $db->table('email_html_campaigns')->like('name', 'EMTEST-', 'after')->get()->getResultArray();

$builderIds = array_map(static fn (array $row): int => (int) $row['id'], $builders);
$dripIds = array_map(static fn (array $row): int => (int) $row['id'], $drips);
$campaignIds = array_map(static fn (array $row): int => (int) $row['id'], $campaigns);

if ($dripIds !== []) {
    $db->table('email_drip_steps')->whereIn('drip_id', $dripIds)->delete();
}
if ($campaignIds !== []) {
    $db->table('email_logs')->whereIn('html_campaign_id', $campaignIds)->delete();
}
if ($dripIds !== []) {
    $db->table('email_logs')->whereIn('drip_id', $dripIds)->delete();
}
if ($builderIds !== []) {
    $db->table('email_logs')->whereIn('builder_id', $builderIds)->delete();
}

$db->table('email_logs')->like('message', 'Seed ', 'after')->delete();
$db->table('email_senders')->like('name', 'EMTEST-', 'after')->delete();
$db->table('email_verifications')->whereIn('email', ['valid.customer@example.com', 'temp@mailinator.com'])->delete();
$db->table('email_html_campaigns')->like('name', 'EMTEST-', 'after')->delete();
$db->table('email_drips')->like('name', 'EMTEST-', 'after')->delete();
$db->table('email_builders')->like('name', 'EMTEST-', 'after')->delete();

$remain = [
    'builders' => $db->table('email_builders')->like('name', 'EMTEST-', 'after')->countAllResults(),
    'drips' => $db->table('email_drips')->like('name', 'EMTEST-', 'after')->countAllResults(),
    'campaigns' => $db->table('email_html_campaigns')->like('name', 'EMTEST-', 'after')->countAllResults(),
    'senders' => $db->table('email_senders')->like('name', 'EMTEST-', 'after')->countAllResults(),
];

echo json_encode(['ok' => true, 'remaining' => $remain], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
