<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * WhatsApp transport configuration (Cheerio Direct API + Meta Graph).
 *
 * Credentials are stored encrypted in the settings table and loaded via SettingsService.
 * Active provider is selected with settings key `whatsapp_provider` = cheerio|meta.
 */
class WhatsApp extends BaseConfig
{
    /**
     * Cheerio Direct APIs base URL (no trailing slash required).
     */
    public string $baseUrl = 'https://newprod.api.cheerio.in/direct-apis';

    /**
     * Meta Graph API base URL.
     */
    public string $graphBaseUrl = 'https://graph.facebook.com';

    /**
     * Default Meta Graph API version when not set in settings.
     */
    public string $graphApiVersion = 'v25.0';

    /**
     * Default HTTP timeout in seconds.
     */
    public int $defaultTimeout = 30;

    /**
     * Maximum number of HTTP retries for transient failures (5xx / network).
     */
    public int $maxRetries = 3;

    /**
     * Seconds to wait between retries (exponential backoff base).
     */
    public int $retryDelaySeconds = 1;

    /**
     * SSL certificate verification for HTTPS calls.
     *
     * - true: use PHP/cURL defaults
     * - false: disable verification (local debugging only — never in production)
     * - string: absolute path to a CA bundle (cacert.pem)
     * - null: auto-detect (php.ini → env → writable/certs/cacert.pem → true)
     */
    public bool|string|null $sslVerify = null;

    /**
     * Whole-message keywords (case/punctuation-insensitive) that opt a contact out
     * of business-initiated WhatsApp messages (Meta Business Messaging Policy).
     *
     * @var list<string>
     */
    public array $optOutKeywords = [
        'stop', 'stop all', 'stopall', 'unsubscribe', 'unsub', 'opt out', 'optout',
        'stop promotions', 'no more messages', 'dont message', 'do not message',
        'थांबा', 'बंद करा', 'बंद', 'band karo', 'mat bhejo', 'रोकें', 'बंद करो',
    ];

    /**
     * Whole-message keywords that record an explicit WhatsApp opt-in.
     *
     * @var list<string>
     */
    public array $optInKeywords = [
        'start', 'subscribe', 'opt in', 'optin', 'unstop', 'yes subscribe', 'सुरू करा', 'शुरू करें',
    ];

    /**
     * Quick-reply button labels / payload ids on a consent-request message.
     * Only matched for button taps, so a typed "yes" never counts as opt-in.
     * Use payload ids WA_CONSENT_YES / WA_CONSENT_NO in templates for exact matching.
     *
     * @var list<string>
     */
    public array $optInButtonKeywords = [
        'wa_consent_yes', 'agree', 'i agree', 'yes i agree', 'subscribe', 'मान्य', 'सहमत', 'मी सहमत आहे',
    ];

    /** @var list<string> */
    public array $optOutButtonKeywords = [
        'wa_consent_no', 'unsubscribe', 'stop', 'stop promotions', 'बंद करा', 'unsubscribe me',
    ];

    /**
     * Whole-message keywords that escalate an automated chat to a human agent
     * (policy: automation must offer a clear escalation path).
     *
     * @var list<string>
     */
    public array $humanAgentKeywords = [
        'agent', 'human', 'talk to agent', 'talk to human', 'live agent', 'representative',
        'customer care', 'real person', 'call me',
    ];

    public string $optOutReply = 'You have been unsubscribed and will no longer receive WhatsApp updates from us. Reply START to subscribe again.';

    public string $optInReply = 'Thank you! You are now subscribed to WhatsApp updates from us. Reply STOP anytime to unsubscribe.';

    public string $humanAgentReply = 'Thanks! A team member will reply to you here shortly.';

    /** Consent request sent inside the 24h window (buttons carry WA_CONSENT_YES / WA_CONSENT_NO). */
    public string $consentRequestText = 'Would you like to receive offers and updates from us on WhatsApp? You can stop anytime by replying STOP.';

    public string $consentAgreeLabel = 'Agree';

    public string $consentStopLabel = 'Stop';

    /**
     * Default for automatically asking contacts who have neither agreed nor stopped (buttons inside
     * the 24h window, otherwise the MARKETING consent template). Off: unsolicited consent templates
     * hurt quality rating. Per tenant via settings key `wa_auto_consent_request`; even when on, each
     * contact is asked at most once automatically. The manual "Request opt-in" action always works.
     */
    public bool $autoConsentRequest = false;

    /** Auto-created per tenant on Meta; quick replies use the Agree / Stop labels above. */
    public string $consentTemplateName = 'wa_consent_request';

    public string $consentTemplateLanguage = 'en_US';

    public string $consentTemplateBody = 'Hello! Would you like to receive updates and offers from us on WhatsApp? Tap Agree to subscribe or Stop to opt out. You can unsubscribe anytime.';

    /** Minimum days between manual "Request opt-in" re-asks to the same contact (repeat asks get numbers blocked). */
    public int $consentRequestResendDays = 7;

    /** Max consent requests per bulk action (import / campaign start). 0 = no cap. */
    public int $consentRequestBatchLimit = 200;

    /**
     * Minutes before a catch-all "any incoming message → reply" automation (no keyword
     * filter, no condition) replies again to the same contact. Stops reply spam and
     * bot-to-bot loops. 0 disables (replies every time).
     */
    public int $catchAllAutoReplyCooldownMinutes = 0;

    /**
     * Max campaign (broadcast) messages per contact in a rolling 24h. 0 disables the cap.
     * Override per tenant with settings key `wa_campaign_daily_cap`.
     */
    public int $campaignDailyCapPerContact = 1;

    /**
     * Minimum days between MARKETING template messages to the same contact (campaigns, automations,
     * API). 1 = at most one per rolling 24h. 0 disables. Override via settings `wa_marketing_frequency_cap_days`.
     */
    public int $marketingFrequencyCapDays = 1;

    /**
     * Days to stop business-initiated sends after Meta reports a number undeliverable (131026).
     */
    public int $undeliverableSuppressDays = 30;

    /**
     * Queue throughput cap (messages per second per worker). 0 disables pacing.
     * Meta's default is 80 mps per number; Cheerio/BSP plans are often lower.
     */
    public int $queueMaxPerSecond = 20;

    /**
     * Back-off for throughput errors (130429 / 80007 / 4 account-level, 131056 per-recipient pair).
     * Delay = base * 2^(attempt-1), capped at max. Rate-limited items get extra attempts.
     */
    public int $rateLimitBackoffSeconds = 60;

    public int $rateLimitBackoffMaxSeconds = 1800;

    public int $rateLimitMaxAttempts = 6;

    /**
     * Back-off for other retryable errors (network / 5xx).
     */
    public int $retryBackoffSeconds = 30;

    /**
     * Default quiet hours for marketing (campaign) sends, tenant-local time "HH:MM".
     * Empty start or end disables. Override per tenant via Settings → WhatsApp.
     */
    public string $quietHoursStart = '';

    public string $quietHoursEnd = '';
}
