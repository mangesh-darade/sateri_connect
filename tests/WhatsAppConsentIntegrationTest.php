<?php

declare(strict_types=1);

/**
 * DB-level WhatsApp policy flow: opt-in, STOP / START keywords, operator cannot
 * override STOP, pending queue cancelled on opt-out, delivery-error suppression,
 * queue dispatch refuses non-consented template sends (no provider call).
 *
 * Run: php tests/WhatsAppConsentIntegrationTest.php
 */

define('FCPATH', __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR);
chdir(FCPATH);

require FCPATH . '../app/Config/Paths.php';
$paths = new \Config\Paths();
require $paths->systemDirectory . '/Boot.php';
\CodeIgniter\Boot::bootSpark($paths);

use App\Libraries\WhatsAppConsentService as Consent;
use App\Models\ContactModel;

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

echo "=== WhatsApp Consent Integration Test ===\n\n";

$db      = db_connect();
$model   = model(ContactModel::class);
$consent = service('whatsAppConsent');
$mobile  = '919000000881';

$cleanup = static function () use ($db, $mobile): void {
    $ids = array_column($db->table('contacts')->select('id')->where('mobile', $mobile)->get()->getResultArray(), 'id');
    if ($ids !== []) {
        $db->table('message_queue')->whereIn('contact_id', $ids)->delete();
        $db->table('contacts')->whereIn('id', $ids)->delete();
    }
};

$cleanup();
Consent::resetSchemaCache();
check('consent columns detected', $consent->hasConsentColumns());

$id = (int) $model->insert([
    'name'   => 'Consent Test',
    'mobile' => $mobile,
    'status' => 'active',
]);
$fresh = static fn (): array => (array) $model->find($id);

try {
    check('new contact has no opt-in by default', (int) ($fresh()['wa_opt_in'] ?? 0) === 0);
    check('campaign blocked without opt-in', $consent->eligibility($fresh(), Consent::KIND_CAMPAIGN)['reason'] === 'no_opt_in');

    // Queue must refuse a business-initiated template before reaching the provider.
    $queue    = service('queueService');
    $dispatch = new ReflectionMethod($queue, 'dispatch');
    $dispatch->setAccessible(true);
    $blocked = '';
    try {
        $dispatch->invoke($queue, ['contact_id' => $id, 'message_type' => 'template', 'payload' => json_encode(['template_name' => 'x'])]);
    } catch (RuntimeException $e) {
        $blocked = $e->getMessage();
    }
    check('queue dispatch refuses template to non-opted-in contact', str_starts_with($blocked, Consent::POLICY_PREFIX), $blocked);
    check('policy block is not retryable', ! Consent::isRetryableError($blocked));

    $consent->optIn($id, 'website_form');
    $row = $fresh();
    check('opt-in stored with source + timestamp', (int) $row['wa_opt_in'] === 1 && $row['wa_opt_in_source'] === 'website_form' && ! empty($row['wa_opt_in_at']));
    check('campaign allowed after opt-in', $consent->eligibility($row, Consent::KIND_CAMPAIGN)['ok']);
    check('unknown source normalised to other', $consent->normalizeSource('facebook-scrape') === 'other');

    $db->table('message_queue')->insert([
        'contact_id'   => $id,
        'message_type' => 'template',
        'payload'      => json_encode(['template_name' => 'x']),
        'status'       => 'pending',
        'created_at'   => date('Y-m-d H:i:s'),
    ]);

    $intent = $consent->handleConsentKeyword($fresh(), 'STOP', null, false);
    $row    = $fresh();
    check('STOP keyword handled', $intent === Consent::INTENT_OPT_OUT);
    check('STOP records opt-out and clears opt-in', ! empty($row['wa_opted_out_at']) && (int) $row['wa_opt_in'] === 0);
    $pending = $db->table('message_queue')->where('contact_id', $id)->where('status', 'pending')->countAllResults();
    $cancel  = $db->table('message_queue')->where('contact_id', $id)->where('status', 'cancelled')->countAllResults();
    check('pending queued sends cancelled on STOP', $pending === 0 && $cancel === 1);
    check('automated session reply blocked after STOP', ! $consent->eligibility($row, Consent::KIND_SESSION)['ok']);
    check('human agent can still reply after STOP', $consent->eligibility($row, Consent::KIND_AGENT)['ok']);

    $consent->applyOperatorConsent($id, true, 'in_store');
    check('operator cannot override customer STOP', ! empty($fresh()['wa_opted_out_at']));
    check('bulk opt-in skips opted-out contact', $consent->bulkSetConsent([$id], true, 'import') === 0);

    $threw = false;
    try {
        $consent->assertTestRecipientAllowed($mobile);
    } catch (RuntimeException $e) {
        $threw = true;
    }
    check('test send to opted-out number blocked', $threw);

    $intent = $consent->handleConsentKeyword($fresh(), 'start', null, false);
    $row    = $fresh();
    check('START re-subscribes the customer', $intent === Consent::INTENT_OPT_IN && empty($row['wa_opted_out_at']) && (int) $row['wa_opt_in'] === 1);
    check('START source recorded', $row['wa_opt_in_source'] === 'whatsapp_keyword');

    $intent = $consent->handleConsentKeyword($fresh(), 'Unsubscribe', null, false, true, 'WA_CONSENT_NO');
    $row    = $fresh();
    check('Unsubscribe button tap opts out', $intent === Consent::INTENT_OPT_OUT && ! empty($row['wa_opted_out_at']));
    check('opt-out reason records button', str_contains((string) $row['wa_suppress_reason'], 'whatsapp_button: Unsubscribe'));

    $intent = $consent->handleConsentKeyword($fresh(), 'Agree', null, false, true, 'WA_CONSENT_YES');
    $row    = $fresh();
    check('Agree button tap opts in', $intent === Consent::INTENT_OPT_IN && (int) $row['wa_opt_in'] === 1 && empty($row['wa_opted_out_at']));
    check('opt-in source = whatsapp_button', $row['wa_opt_in_source'] === 'whatsapp_button');
    check('unrelated "No" button leaves consent unchanged', $consent->handleConsentKeyword($fresh(), 'No', null, false, true, 'APPT_NO') === null && (int) $fresh()['wa_opt_in'] === 1);

    $consent->applyDeliveryFailure($id, '131026', 'Message undeliverable');
    $row = $fresh();
    check('131026 pauses the number', $consent->isSuppressed($row) && $consent->eligibility($row, Consent::KIND_CAMPAIGN)['reason'] === 'suppressed');
    check('131026 pause is ~30 days', strtotime((string) $row['wa_suppressed_until']) > time() + 29 * 86400);

    $consent->clearSuppression($id);
    $consent->applyDeliveryFailure($id, '131049', 'Marketing limit');
    $until = strtotime((string) $fresh()['wa_suppressed_until']);
    check('131049 pauses for ~24h only', $until > time() + 23 * 3600 && $until < time() + 25 * 3600);

    $consent->applyDeliveryFailure($id, '131050', 'User stopped marketing');
    check('131050 records opt-out', ! empty($fresh()['wa_opted_out_at']));

    $consent->applyOperatorConsent($id, false, 'other');
    check('operator uncheck keeps opt-out intact', ! empty($fresh()['wa_opted_out_at']));

    $health = $consent->getHealth();
    check('health status readable', in_array($health['status'], [Consent::HEALTH_OK, Consent::HEALTH_FLAGGED, Consent::HEALTH_RESTRICTED], true));
} finally {
    $cleanup();
}

echo "\n=== Result: {$pass} passed, {$fail} failed ===\n";
exit($fail > 0 ? 1 : 0);
