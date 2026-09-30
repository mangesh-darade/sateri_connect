<?php

/**
 * WhatsApp Business Messaging Policy guards: opt-in / opt-out eligibility,
 * STOP / START / agent keywords, non-retryable Meta errors, audience split.
 */

define('FCPATH', dirname(__DIR__) . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Libraries\WhatsAppConsentService as Consent;

$pass = 0;
$fail = 0;

function check(string $label, bool $ok): void
{
    global $pass, $fail;
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $label . PHP_EOL;
    $ok ? $pass++ : $fail++;
}

echo "=== WhatsApp Consent Service Unit Test ===\n\n";

final class TestConsent extends Consent
{
    /** @var array<int, int> */
    public array $recent = [];
    public int $cap = 1;

    public function campaignDailyCap(): int
    {
        return $this->cap;
    }

    public function recentCampaignCounts(array $contactIds, ?int $excludeCampaignId = null): array
    {
        return array_intersect_key($this->recent, array_flip($contactIds));
    }
}

$setColumns = static function (?bool $value): void {
    $prop = new ReflectionProperty(Consent::class, 'hasColumns');
    $prop->setAccessible(true);
    $prop->setValue(null, $value);
};

$config = (new ReflectionClass(\Config\WhatsApp::class))->newInstanceWithoutConstructor();
$svc    = new TestConsent($config);
$setColumns(true);

$optedIn  = ['id' => 1, 'mobile' => '919000000001', 'status' => 'active', 'wa_opt_in' => 1];
$noOptIn  = ['id' => 2, 'mobile' => '919000000002', 'status' => 'active', 'wa_opt_in' => 0];
$optedOut = ['id' => 3, 'mobile' => '919000000003', 'status' => 'active', 'wa_opt_in' => 0, 'wa_opted_out_at' => '2026-09-01 10:00:00'];
$paused   = ['id' => 4, 'mobile' => '919000000004', 'status' => 'active', 'wa_opt_in' => 1, 'wa_suppressed_until' => date('Y-m-d H:i:s', time() + 3600)];
$expired  = ['id' => 5, 'mobile' => '919000000005', 'status' => 'active', 'wa_opt_in' => 1, 'wa_suppressed_until' => date('Y-m-d H:i:s', time() - 3600)];
$blocked  = ['id' => 6, 'mobile' => '919000000006', 'status' => 'blocked', 'wa_opt_in' => 1];

// --- Eligibility ---------------------------------------------------------
check('opted-in contact can get campaign', $svc->eligibility($optedIn, Consent::KIND_CAMPAIGN)['ok']);
check('no opt-in blocks campaign', $svc->eligibility($noOptIn, Consent::KIND_CAMPAIGN)['reason'] === 'no_opt_in');
check('opted-out blocks campaign', $svc->eligibility($optedOut, Consent::KIND_CAMPAIGN)['reason'] === 'opted_out');
check('opted-out blocks automated session reply', $svc->eligibility($optedOut, Consent::KIND_SESSION)['reason'] === 'opted_out');
check('opted-out still allows human agent reply', $svc->eligibility($optedOut, Consent::KIND_AGENT)['ok']);
check('suppressed number blocks campaign', $svc->eligibility($paused, Consent::KIND_CAMPAIGN)['reason'] === 'suppressed');
check('expired suppression allows campaign', $svc->eligibility($expired, Consent::KIND_CAMPAIGN)['ok']);
check('blocked contact is refused', $svc->eligibility($blocked, Consent::KIND_CAMPAIGN)['reason'] === 'blocked');
check('template without opt-in allowed inside 24h window', $svc->eligibility($noOptIn, Consent::KIND_TEMPLATE, true)['ok']);
check('template without opt-in blocked outside 24h window', $svc->eligibility($noOptIn, Consent::KIND_TEMPLATE, false)['reason'] === 'no_opt_in');
check('session reply to non-opted-in contact allowed', $svc->eligibility($noOptIn, Consent::KIND_SESSION)['ok']);

$threw = false;
try {
    $svc->assertEligible($noOptIn, Consent::KIND_CAMPAIGN);
} catch (RuntimeException $e) {
    $threw = str_starts_with($e->getMessage(), Consent::POLICY_PREFIX) && $e->getCode() === 422;
}
check('assertEligible throws policy error 422', $threw);

$setColumns(false);
check('missing consent columns block business-initiated sends', $svc->eligibility($optedIn, Consent::KIND_CAMPAIGN)['reason'] === 'schema');
check('missing consent columns keep session replies working', $svc->eligibility($optedIn, Consent::KIND_SESSION)['ok']);
$setColumns(true);

// --- Keywords ------------------------------------------------------------
check('STOP detected', $svc->detectIntent('STOP') === Consent::INTENT_OPT_OUT);
check('"Stop!" with punctuation detected', $svc->detectIntent(' Stop! ') === Consent::INTENT_OPT_OUT);
check('unsubscribe detected', $svc->detectIntent('Unsubscribe') === Consent::INTENT_OPT_OUT);
check('Marathi थांबा detected', $svc->detectIntent('थांबा') === Consent::INTENT_OPT_OUT);
check('START detected', $svc->detectIntent('start') === Consent::INTENT_OPT_IN);
check('human agent request detected', $svc->detectIntent('talk to agent') === Consent::INTENT_HUMAN);
check('"stop" inside a sentence is not an opt-out', $svc->detectIntent('please do not stop my order delivery') === null);
check('empty text has no intent', $svc->detectIntent('') === null);

// --- Consent-request button taps ----------------------------------------
check('button "Agree" = opt-in', $svc->detectButtonIntent('Agree') === Consent::INTENT_OPT_IN);
check('button "I Agree" = opt-in', $svc->detectButtonIntent('I Agree') === Consent::INTENT_OPT_IN);
check('button "Unsubscribe" = opt-out', $svc->detectButtonIntent('Unsubscribe') === Consent::INTENT_OPT_OUT);
check('Meta "Stop promotions" button = opt-out', $svc->detectButtonIntent('Stop promotions') === Consent::INTENT_OPT_OUT);
check('payload WA_CONSENT_YES wins over label', $svc->detectButtonIntent('Ho, chalel', 'WA_CONSENT_YES') === Consent::INTENT_OPT_IN);
check('payload WA_CONSENT_NO wins over label', $svc->detectButtonIntent('Nako', 'WA_CONSENT_NO') === Consent::INTENT_OPT_OUT);
check('appointment "No" button is not an opt-out', $svc->detectButtonIntent('No', 'APPT_NO') === null);
check('appointment "Yes" button is not an opt-in', $svc->detectButtonIntent('Yes', 'APPT_YES') === null);
check('typed "agree" text is not an opt-in', $svc->detectIntent('agree') === null);

// --- Error codes ---------------------------------------------------------
check('extract code from [Meta #131049]', Consent::extractErrorCode('Limit reached [Meta #131049]') === '131049');
check('extract code from (#131026)', Consent::extractErrorCode('(#131026) Message undeliverable') === '131026');
check('extract bare 13xxxx code', Consent::extractErrorCode('error 131050 user stopped') === '131050');
check('no code in plain message', Consent::extractErrorCode('timeout') === null);
check('131049 marketing cap is not retried', ! Consent::isRetryableError('x [Meta #131049]'));
check('131050 user stopped marketing is not retried', ! Consent::isRetryableError('x [Meta #131050]'));
check('131026 undeliverable is not retried', ! Consent::isRetryableError('(#131026) undeliverable'));
check('policy block is not retried', ! Consent::isRetryableError(Consent::POLICY_PREFIX . ' no opt-in'));
check('24h window error is not retried', ! Consent::isRetryableError('Outside the 24-hour customer service window'));
check('network timeout is retried', Consent::isRetryableError('cURL error 28: timeout'));
check('rate limit 130429 is retried', Consent::isRetryableError('x [Meta #130429]'));

// --- Messaging limit tiers ----------------------------------------------
check('TIER_250 = 250', Consent::parseMessagingLimit('TIER_250') === 250);
check('TIER_2K = 2000', Consent::parseMessagingLimit('TIER_2K') === 2000);
check('TIER_100K = 100000', Consent::parseMessagingLimit('TIER_100K') === 100000);
check('webhook numeric "10000" = 10000', Consent::parseMessagingLimit('10000') === 10000);
check('UNLIMITED = no cap', Consent::parseMessagingLimit('TIER_UNLIMITED') === null && Consent::parseMessagingLimit('UNLIMITED') === null);
check('unknown limit = no cap', Consent::parseMessagingLimit('') === null);

// --- Audience split ------------------------------------------------------
$dup = $optedIn;
$dup['id'] = 7;
$noPhone = ['id' => 8, 'mobile' => '', 'status' => 'active', 'wa_opt_in' => 1];
$recentOne = ['id' => 9, 'mobile' => '919000000009', 'status' => 'active', 'wa_opt_in' => 1];
$svc->recent = [9 => 1];

$split = $svc->splitCampaignAudience([$optedIn, $dup, $noOptIn, $optedOut, $paused, $noPhone, $recentOne], 50);
check('only opted-in unique contacts are eligible', array_column($split['eligible'], 'id') === [1]);
check('duplicate mobile excluded', ($split['excluded']['duplicate'] ?? 0) === 1);
check('no opt-in excluded', ($split['excluded']['no_opt_in'] ?? 0) === 1);
check('opted-out excluded', ($split['excluded']['opted_out'] ?? 0) === 1);
check('suppressed excluded', ($split['excluded']['suppressed'] ?? 0) === 1);
check('missing mobile excluded', ($split['excluded']['no_phone'] ?? 0) === 1);
check('24h frequency cap excluded', ($split['excluded']['frequency_cap'] ?? 0) === 1);
check('excluded total adds up', $split['excluded_total'] === 6);

$svc->cap = 0;
$noCap = $svc->splitCampaignAudience([$optedIn, $recentOne]);
check('cap 0 disables frequency cap', count($noCap['eligible']) === 2);

$text = Consent::describeExclusions(['no_opt_in' => 3, 'opted_out' => 1, 'duplicate' => 0]);
check('exclusion summary is human readable', str_contains($text, '4 skipped') && str_contains($text, '3 no whatsapp opt-in recorded'));
check('empty exclusion summary', Consent::describeExclusions([]) === '');

// --- Wiring (source checks) ----------------------------------------------
$root = dirname(__DIR__);
$queue = file_get_contents($root . '/app/Libraries/QueueService.php');
check('queue dispatch enforces consent', str_contains($queue, '->eligibility(') && str_contains($queue, 'denialWithConsentRequest'));
check('queue retry skips non-retryable errors', str_contains($queue, 'isRetryableError'));
$hook = file_get_contents($root . '/app/Controllers/Webhooks.php');
check('webhook handles STOP/START before bots', str_contains($hook, 'handleConsentKeyword'));
check('webhook handles account/quality events', str_contains($hook, 'handleAccountWebhook'));
check('webhook suppresses on delivery error codes', str_contains($hook, 'applyDeliveryFailure'));
$meta = file_get_contents($root . '/app/Libraries/MetaCloudAPI.php');
check('Meta webhook subscribes to phone_number_quality_update', str_contains($meta, 'phone_number_quality_update'));
check('Meta webhook subscribes to account_update', str_contains($meta, 'account_update'));
$campaign = file_get_contents($root . '/app/Libraries/CampaignService.php');
check('campaign start checks number health', str_contains($campaign, 'assertCanStartCampaign'));
check('campaign audience filtered by consent', str_contains($campaign, 'splitCampaignAudience'));
check('API send runs policy check', str_contains(file_get_contents($root . '/app/Controllers/Api/Messages.php'), 'policyCheck'));

echo "\n=== Result: {$pass} passed, {$fail} failed ===\n";
exit($fail > 0 ? 1 : 0);
