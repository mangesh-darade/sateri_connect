<?php

declare(strict_types=1);

/**
 * Meta policy gaps: messaging-limit guard, template status / category webhooks,
 * non-approved template block, customer data erasure.
 *
 * Run: php tests/WhatsAppPolicyGapsIntegrationTest.php
 */

define('FCPATH', __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR);
chdir(FCPATH);

require FCPATH . '../app/Config/Paths.php';
$paths = new \Config\Paths();
require $paths->systemDirectory . '/Boot.php';
\CodeIgniter\Boot::bootSpark($paths);

use App\Libraries\ContactErasureService;
use App\Libraries\WhatsAppConsentService as Consent;
use App\Models\CampaignModel;
use App\Models\ContactModel;
use App\Models\TemplateModel;

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

echo "=== WhatsApp Policy Gaps Integration Test ===\n\n";

$db        = db_connect();
$consent   = service('whatsAppConsent');
$settings  = service('settingsService');
$templates = model(TemplateModel::class);
$campaigns = model(CampaignModel::class);
$contacts  = model(ContactModel::class);

$tplName   = 'policy_gap_test_tpl';
$mobile    = '919000000882';
$prevLimit = (string) $settings->get('wa_messaging_limit', '');

$cleanup = static function () use ($db, $tplName, $mobile): void {
    $tplIds = array_column($db->table('templates')->select('id')->where('name', $tplName)->get()->getResultArray(), 'id');
    if ($tplIds !== []) {
        $db->table('campaigns')->whereIn('template_id', $tplIds)->delete();
        $db->table('templates')->whereIn('id', $tplIds)->delete();
    }
    $ids = array_column($db->table('contacts')->select('id')->whereIn('mobile', [$mobile, '919000000883'])->get()->getResultArray(), 'id');
    if ($ids !== []) {
        foreach (['message_queue', 'messages', 'conversations', 'contact_tags', 'internal_notes'] as $t) {
            if ($db->tableExists($t)) {
                $db->table($t)->whereIn('contact_id', $ids)->delete();
            }
        }
        $db->table('contacts')->whereIn('id', $ids)->delete();
    }
};

$cleanup();

try {
    // --- 1. Messaging limit --------------------------------------------------
    $settings->set('wa_messaging_limit', 'TIER_250', 'whatsapp');
    $used = $consent->recentBusinessInitiatedContacts();
    $threw = '';
    try {
        $consent->assertWithinMessagingLimit(250 - $used + 1);
    } catch (RuntimeException $e) {
        $threw = $e->getMessage();
    }
    check('audience above remaining Meta limit is blocked', str_contains($threw, 'Meta messaging limit is 250'), $threw);

    $ok = true;
    try {
        $consent->assertWithinMessagingLimit(max(1, 250 - $used));
    } catch (RuntimeException $e) {
        $ok = $used >= 250;
    }
    check('audience within remaining limit is allowed', $ok);

    $settings->set('wa_messaging_limit', 'TIER_UNLIMITED', 'whatsapp');
    $ok = true;
    try {
        $consent->assertWithinMessagingLimit(500000);
    } catch (RuntimeException $e) {
        $ok = false;
    }
    check('unlimited tier never blocks', $ok);

    $consent->handleAccountWebhook('business_capability_update', ['max_daily_conversations_per_business' => '10000']);
    check('business_capability_update stores portfolio limit', (string) $settings->get('wa_messaging_limit', '') === '10000');

    // --- 2. Template status webhook + campaign guard --------------------------
    $tplId = (int) $templates->insert([
        'meta_id'  => '999000111',
        'name'     => $tplName,
        'language' => 'en_US',
        'category' => 'UTILITY',
        'status'   => 'APPROVED',
        'body'     => 'Hello {{1}}',
    ]);
    $campId = (int) $campaigns->insert([
        'name'         => 'Policy gap test',
        'message_type' => 'template',
        'template_id'  => $tplId,
        'status'       => 'running',
    ]);
    check('fixtures created', $tplId > 0 && $campId > 0, json_encode($campaigns->errors()));

    $consent->handleAccountWebhook('message_template_status_update', [
        'event'                     => 'PAUSED',
        'message_template_id'       => '999000111',
        'message_template_name'     => $tplName,
        'message_template_language' => 'en_US',
        'reason'                    => 'LOW_QUALITY',
    ]);
    $tpl = $templates->find($tplId);
    check('PAUSED webhook updates local template status', ($tpl['status'] ?? '') === 'PAUSED');
    check('pause reason stored', ($tpl['rejected_reason'] ?? '') === 'LOW_QUALITY');
    check('running campaign using template is paused', ($campaigns->find($campId)['status'] ?? '') === 'paused');

    $assert = new ReflectionMethod(service('campaignService'), 'assertTemplateSendable');
    $assert->setAccessible(true);
    $blocked = '';
    try {
        $assert->invoke(service('campaignService'), $campaigns->find($campId));
    } catch (RuntimeException $e) {
        $blocked = $e->getMessage();
    }
    check('campaign with PAUSED template cannot start/resume', str_contains($blocked, 'is PAUSED'), $blocked);

    $consent->handleAccountWebhook('message_template_status_update', [
        'event'                 => 'REINSTATED',
        'message_template_id'   => '999000111',
        'message_template_name' => $tplName,
    ]);
    check('REINSTATED webhook restores APPROVED', ($templates->find($tplId)['status'] ?? '') === 'APPROVED');
    $ok = true;
    try {
        $assert->invoke(service('campaignService'), $campaigns->find($campId));
    } catch (RuntimeException $e) {
        $ok = false;
    }
    check('APPROVED template passes the guard', $ok);

    // --- 3. Category webhook ------------------------------------------------
    $consent->handleAccountWebhook('template_category_update', [
        'message_template_id'   => '999000111',
        'message_template_name' => $tplName,
        'previous_category'     => 'UTILITY',
        'new_category'          => 'MARKETING',
    ]);
    check('template_category_update updates local category', ($templates->find($tplId)['category'] ?? '') === 'MARKETING');

    // --- 4. Auto consent request (no provider call) --------------------------
    $pid = (int) $contacts->insert(['name' => 'Pending Consent', 'mobile' => '919000000883', 'status' => 'active']);
    $pending = $contacts->find($pid);
    check('new contact without answer needs consent request', $consent->needsConsentRequest($pending));

    $split = $consent->splitCampaignAudience([$pending]);
    check('campaign split returns no-opt-in contact as pending consent', count($split['pending_consent']) === 1 && $split['eligible'] === []);

    $contacts->update($pid, ['wa_consent_requested_at' => date('Y-m-d H:i:s', time() - 3600)]);
    $asked = $contacts->find($pid);
    check('asked within cooldown is not asked again', ! $consent->needsConsentRequest($asked));
    $denial = $consent->denialWithConsentRequest($asked, $consent->eligibility($asked, Consent::KIND_CAMPAIGN));
    check('blocked send explains consent already requested', str_contains($denial, 'waiting for the customer to tap Agree'), $denial);

    $contacts->update($pid, ['wa_consent_requested_at' => date('Y-m-d H:i:s', time() - 8 * 86400)]);
    check('unanswered request is re-sent after cooldown', $consent->needsConsentRequest($contacts->find($pid)));

    $tpl = $consent->consentTemplate();
    if ($tpl !== null && strtoupper((string) $tpl['status']) !== 'APPROVED') {
        $due    = $contacts->find($pid);
        $denial = $consent->denialWithConsentRequest($due, $consent->eligibility($due, Consent::KIND_CAMPAIGN));
        check('unapproved consent template explains why nothing was sent', str_contains($denial, 'Consent request not sent') && str_contains($denial, (string) $tpl['status']), $denial);
        check('failed consent request is not marked as asked', empty($contacts->find($pid)['wa_consent_requested_at']) || strtotime((string) $contacts->find($pid)['wa_consent_requested_at']) < time() - 86400);
    }

    check('template quick reply "Agree" opts in', $consent->detectButtonIntent('Agree', 'Agree') === Consent::INTENT_OPT_IN);
    check('template quick reply "Stop" opts out', $consent->detectButtonIntent('Stop', 'Stop') === Consent::INTENT_OPT_OUT);

    $consent->optIn($pid, 'whatsapp_button', null, false);
    check('agreed contact is never asked again', ! $consent->needsConsentRequest($contacts->find($pid)));
    $consent->optOut($pid, 'whatsapp_button: Stop');
    check('stopped contact is never asked again', ! $consent->needsConsentRequest($contacts->find($pid)));

    // --- 5. Erasure ---------------------------------------------------------
    $cid = (int) $contacts->insert([
        'name'   => 'Erase Me',
        'mobile' => $mobile,
        'email'  => 'erase@example.com',
        'notes'  => 'private note',
        'status' => 'active',
    ]);
    $consent->optIn($cid, 'website_form', null, false);
    $convId = (int) $db->table('conversations')->insert(['contact_id' => $cid, 'created_at' => date('Y-m-d H:i:s')]) ? (int) $db->insertID() : 0;
    $db->table('messages')->insert([
        'contact_id'      => $cid,
        'conversation_id' => $convId ?: null,
        'direction'       => 'inbound',
        'message_type'    => 'text',
        'content'         => 'my private message',
        'status'          => 'received',
        'created_at'      => date('Y-m-d H:i:s'),
    ]);
    $db->table('message_queue')->insert([
        'contact_id'   => $cid,
        'message_type' => 'text',
        'status'       => 'pending',
        'created_at'   => date('Y-m-d H:i:s'),
    ]);

    $result = (new ContactErasureService())->erase($cid);
    $row    = $contacts->withDeleted()->find($cid);

    check('erase removed linked rows', $result['rows_deleted'] >= 3, json_encode($result['tables']));
    check('messages erased', $db->table('messages')->where('contact_id', $cid)->countAllResults() === 0);
    check('conversations erased', $db->table('conversations')->where('contact_id', $cid)->countAllResults() === 0);
    check('queued sends erased', $db->table('message_queue')->where('contact_id', $cid)->countAllResults() === 0);
    check('name / email / notes wiped', empty($row['name']) && empty($row['email']) && empty($row['notes']));
    check('mobile kept as suppression record', ($row['mobile'] ?? '') === $mobile);
    check('erased number is opted out', ! empty($row['wa_opted_out_at']) && (int) $row['wa_opt_in'] === 0);
    check('erased contact hidden from lists', ! empty($row['deleted_at']) && $contacts->find($cid) === null);
    check('erased number blocked from campaigns', ! $consent->eligibility($row, Consent::KIND_CAMPAIGN)['ok']);
} finally {
    $settings->set('wa_messaging_limit', $prevLimit, 'whatsapp');
    $cleanup();
}

echo "\n=== Result: {$pass} passed, {$fail} failed ===\n";
exit($fail > 0 ? 1 : 0);
