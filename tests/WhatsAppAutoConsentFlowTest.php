<?php

declare(strict_types=1);

/**
 * End-to-end auto consent flow with a mocked WhatsApp provider (no Meta calls):
 * template ask outside the window, buttons inside it, cooldown, Agree / Stop taps,
 * blocked sends, campaign dispatch and unapproved-template handling.
 *
 * Run: php tests/WhatsAppAutoConsentFlowTest.php
 */

define('FCPATH', __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR);
chdir(FCPATH);

require FCPATH . '../app/Config/Paths.php';
$paths = new \Config\Paths();
require $paths->systemDirectory . '/Boot.php';
\CodeIgniter\Boot::bootSpark($paths);

use App\Libraries\WhatsAppConsentService as Consent;
use App\Models\ContactModel;
use App\Models\TemplateModel;

helper('whatsapp');

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

final class FakeWhatsApp
{
    /** @var list<array{0: string, 1: array<int, mixed>}> */
    public array $calls = [];

    public function normalizePhone(string $phone): string
    {
        return preg_replace('/\D+/', '', $phone) ?? '';
    }

    public function sendTemplate(string $to, string $name, string $language, array $components = []): array
    {
        $this->calls[] = ['sendTemplate', [$to, $name, $language]];

        return ['messages' => [['id' => 'wamid.FAKE_T_' . count($this->calls)]]];
    }

    public function sendInteractiveButtons(string $to, string $text, array $buttons): array
    {
        $this->calls[] = ['sendInteractiveButtons', [$to, $text, $buttons]];

        return ['messages' => [['id' => 'wamid.FAKE_B_' . count($this->calls)]]];
    }

    public function sendText(string $to, string $text, bool $preview = false): array
    {
        $this->calls[] = ['sendText', [$to, $text]];

        return ['messages' => [['id' => 'wamid.FAKE_X_' . count($this->calls)]]];
    }

    public function last(): ?array
    {
        return $this->calls === [] ? null : $this->calls[count($this->calls) - 1];
    }
}

echo "=== WhatsApp Auto Consent Flow Test ===\n\n";

$db        = db_connect();
$contacts  = model(ContactModel::class);
$templates = model(TemplateModel::class);
$tplName   = 'wa_consent_flow_test';
$mobiles   = ['919000000891', '919000000892', '919000000893'];
$settings  = service('settingsService');
$prevLimit = (string) $settings->get('wa_messaging_limit', '');

$cleanup = static function () use ($db, $tplName, $mobiles): void {
    $db->table('templates')->where('name', $tplName)->delete();
    $ids = array_column($db->table('contacts')->select('id')->whereIn('mobile', $mobiles)->get()->getResultArray(), 'id');
    if ($ids !== []) {
        foreach (['message_queue', 'messages', 'conversations', 'contact_tags', 'campaign_contacts'] as $t) {
            if ($db->tableExists($t)) {
                $db->table($t)->whereIn('contact_id', $ids)->delete();
            }
        }
        $db->table('contacts')->whereIn('id', $ids)->delete();
    }
};
$cleanup();

$config = config(\Config\WhatsApp::class);
$config->consentTemplateName    = $tplName;
$config->autoConsentRequest      = true;
$config->consentRequestResendDays = 7;

$consent = new Consent($config);
// Built before the provider mock: QueueService type-hints the real client.
$svc  = new \App\Libraries\CampaignService(null, null, null, null, null, null, $consent);
$fake = new FakeWhatsApp();
\Config\Services::injectMock('whatsApp', $fake);
\Config\Services::injectMock('whatsAppConsent', $consent);

try {
    $settings->set('wa_messaging_limit', 'TIER_UNLIMITED', 'whatsapp');

    // --- A. Template not approved yet --------------------------------------
    $tplId = (int) $templates->insert(['name' => $tplName, 'language' => 'en_US', 'category' => 'MARKETING', 'status' => 'PENDING', 'body' => 'Consent?']);
    $aId   = (int) $contacts->insert(['name' => 'Flow Outside', 'mobile' => $mobiles[0], 'status' => 'active']);
    $a     = $contacts->find($aId);

    check('A1 pending template: nothing sent', $consent->requestConsentIfPending($a) === null && $fake->calls === []);
    check('A2 reason explains PENDING template', str_contains($consent->lastConsentError(), 'PENDING'), $consent->lastConsentError());
    check('A3 failed ask not recorded (retried next time)', empty($contacts->find($aId)['wa_consent_requested_at']));
    check('A4 contact-create note shows reason', str_contains($consent->describeConsentRequest(null), 'not sent'));

    // --- B. Approved template, outside 24h window ---------------------------
    $templates->update($tplId, ['status' => 'APPROVED']);
    $how = $consent->requestConsentIfPending($contacts->find($aId));
    $last = $fake->last();
    check('B1 outside window → consent template sent', $how === 'template' && ($last[0] ?? '') === 'sendTemplate' && ($last[1][1] ?? '') === $tplName, json_encode($last));
    check('B2 sent to the contact mobile', ($last[1][0] ?? '') === $mobiles[0]);
    check('B3 asked time recorded', ! empty($contacts->find($aId)['wa_consent_requested_at']));
    $msg = $db->table('messages')->where('contact_id', $aId)->orderBy('id', 'DESC')->get()->getRowArray();
    check('B4 outbound template stored in chat', ($msg['direction'] ?? '') === 'outbound' && ($msg['message_type'] ?? '') === 'template' && ($msg['content'] ?? '') === $tplName);
    check('B5 success note for operator', $consent->describeConsentRequest($how) === 'WhatsApp consent request (Agree / Stop) sent.');

    // --- C. Cooldown ---------------------------------------------------------
    $before = count($fake->calls);
    check('C1 second ask within 7 days skipped', $consent->requestConsentIfPending($contacts->find($aId)) === null && count($fake->calls) === $before);
    $a      = $contacts->find($aId);
    $denial = $consent->denialWithConsentRequest($a, $consent->eligibility($a, Consent::KIND_TEMPLATE, false));
    check('C2 blocked template send says waiting for Agree', str_contains($denial, 'waiting for the customer to tap Agree'), $denial);
    check('C3 still blocked for campaigns until Agree', ! $consent->eligibility($a, Consent::KIND_CAMPAIGN)['ok']);

    // --- D. Customer taps Agree on the template (webhook button) ------------
    $intent = $consent->handleConsentKeyword($a, 'Agree', null, true, true, 'Agree');
    $a      = $contacts->find($aId);
    check('D1 Agree tap → opt-in', $intent === Consent::INTENT_OPT_IN && (int) $a['wa_opt_in'] === 1);
    check('D2 source = whatsapp_button', ($a['wa_opt_in_source'] ?? '') === 'whatsapp_button');
    check('D3 thank-you reply sent', ($fake->last()[0] ?? '') === 'sendText' && str_contains((string) $fake->last()[1][1], 'subscribed'));
    check('D4 campaigns now allowed', $consent->eligibility($a, Consent::KIND_CAMPAIGN)['ok']);
    check('D5 never asked again', ! $consent->needsConsentRequest($a));

    // --- E. Customer taps Stop -----------------------------------------------
    $intent = $consent->handleConsentKeyword($a, 'Stop', null, true, true, 'Stop');
    $a      = $contacts->find($aId);
    check('E1 Stop tap → opted out', $intent === Consent::INTENT_OPT_OUT && ! empty($a['wa_opted_out_at']) && (int) $a['wa_opt_in'] === 0);
    check('E2 reason saved with label', str_contains((string) $a['wa_suppress_reason'], 'Stop'), (string) $a['wa_suppress_reason']);
    check('E3 unsubscribe reply sent', str_contains((string) $fake->last()[1][1], 'unsubscribed'));
    check('E4 campaigns blocked', ! $consent->eligibility($a, Consent::KIND_CAMPAIGN)['ok']);
    check('E5 never asked again after Stop', ! $consent->needsConsentRequest($a));

    // --- F. Customer messaged first (inside 24h window) ---------------------
    $bId = (int) $contacts->insert(['name' => 'Flow Inside', 'mobile' => $mobiles[1], 'status' => 'active', 'last_reply_at' => date('Y-m-d H:i:s')]);
    $how = $consent->requestConsentIfPending($contacts->find($bId));
    $last = $fake->last();
    check('F1 inside window → interactive buttons', $how === 'buttons' && ($last[0] ?? '') === 'sendInteractiveButtons');
    $ids = array_column($last[1][2] ?? [], 'id');
    check('F2 buttons carry WA_CONSENT_YES / WA_CONSENT_NO', $ids === ['WA_CONSENT_YES', 'WA_CONSENT_NO'], json_encode($ids));
    check('F3 interactive button payload maps to opt-in', $consent->detectButtonIntent('Agree', 'WA_CONSENT_YES') === Consent::INTENT_OPT_IN);

    // --- G. Campaign dispatch asks pending recipients ------------------------
    $cId = (int) $contacts->insert(['name' => 'Flow Campaign', 'mobile' => $mobiles[2], 'status' => 'active']);
    $resolve = new ReflectionMethod($svc, 'resolveWhatsAppRecipients');
    $resolve->setAccessible(true);
    $count = new ReflectionProperty($svc, 'lastConsentRequested');
    $count->setAccessible(true);

    $before   = count($fake->calls);
    $eligible = $resolve->invoke($svc, null, [$cId], null, false, false);
    check('G1 campaign preview never sends consent', $eligible === [] && count($fake->calls) === $before && $count->getValue($svc) === 0);

    $eligible = $resolve->invoke($svc, null, [$cId], null, false, true);
    check('G2 campaign dispatch sends consent to pending recipient', $eligible === [] && $count->getValue($svc) === 1 && ($fake->last()[0] ?? '') === 'sendTemplate');

    $assert = new ReflectionMethod($svc, 'assertHasEligibleRecipients');
    $assert->setAccessible(true);
    $err = '';
    try {
        $assert->invoke($svc, ['contacts' => 0, 'excluded' => ['no_opt_in' => 1]]);
    } catch (RuntimeException $e) {
        $err = $e->getMessage();
    }
    check('G3 operator told consent was sent', str_contains($err, 'consent request (Agree / Stop) sent to 1'), $err);

    $before = count($fake->calls);
    $resolve->invoke($svc, null, [$cId], null, false, true);
    check('G4 re-running campaign does not re-ask within cooldown', count($fake->calls) === $before);

    // --- H. Kill switch -----------------------------------------------------
    $config->autoConsentRequest = false;
    $contacts->update($cId, ['wa_consent_requested_at' => null]);
    check('H1 autoConsentRequest=false disables asking', ! $consent->needsConsentRequest($contacts->find($cId)));
    $config->autoConsentRequest = true;
} finally {
    $settings->set('wa_messaging_limit', $prevLimit, 'whatsapp');
    \Config\Services::resetSingle('whatsApp');
    \Config\Services::resetSingle('whatsAppConsent');
    $cleanup();
}

echo "\n=== Result: {$pass} passed, {$fail} failed ===\n";
exit($fail > 0 ? 1 : 0);
