<?php

declare(strict_types=1);

namespace App\Libraries;

use App\Models\CampaignModel;
use App\Models\ContactModel;
use App\Models\ConversationModel;
use App\Models\MessageModel;
use App\Models\NotificationModel;
use App\Models\TemplateModel;
use Config\WhatsApp as WhatsAppConfig;
use RuntimeException;
use Throwable;

/**
 * WhatsApp Business Messaging Policy enforcement: opt-in / opt-out, delivery-failure
 * suppression, campaign frequency cap and account quality health.
 *
 * Every outbound path (campaigns, queue, API, chat) asks this service before sending.
 */
class WhatsAppConsentService
{
    /** Broadcast / campaign template. Always requires recorded opt-in. */
    public const KIND_CAMPAIGN = 'campaign';

    /** Business-initiated template (automation, sequence, API, agent re-engagement). */
    public const KIND_TEMPLATE = 'template';

    /** Automated free-form reply inside the 24h customer-service window. */
    public const KIND_SESSION = 'session';

    /** Human agent reply inside the 24h customer-service window. */
    public const KIND_AGENT = 'agent';

    public const POLICY_PREFIX = 'Policy blocked:';

    public const HEALTH_OK         = 'ok';
    public const HEALTH_FLAGGED    = 'flagged';
    public const HEALTH_RESTRICTED = 'restricted';

    public const INTENT_OPT_OUT = 'opt_out';
    public const INTENT_OPT_IN  = 'opt_in';
    public const INTENT_HUMAN   = 'human';

    /** @var array<string, string> */
    public const OPT_IN_SOURCES = [
        'direct_input'      => 'Direct input / Admin',
        'website_form'      => 'Website / landing page form',
        'checkout'          => 'Checkout / booking tick box',
        'in_store'          => 'In-store / counter (recorded)',
        'whatsapp_chat'     => 'Customer asked on WhatsApp chat',
        'whatsapp_keyword'  => 'Customer sent START on WhatsApp',
        'whatsapp_button'   => 'Customer tapped Agree on WhatsApp consent message',
        'click_to_whatsapp' => 'Click-to-WhatsApp ad / QR code',
        'phone_call'        => 'Phone call (recorded)',
        'paper_form'        => 'Signed paper form',
        'import'            => 'Imported list with recorded consent',
        'api'               => 'API / external system',
        'other'             => 'Other (documented)',
    ];

    /** @var array<string, string> */
    public const EXCLUSION_LABELS = [
        'no_phone'      => 'No valid mobile',
        'blocked'       => 'Blocked / inactive',
        'opted_out'     => 'Opted out (STOP)',
        'no_opt_in'     => 'No WhatsApp opt-in recorded',
        'suppressed'    => 'Paused after delivery failure',
        'frequency_cap' => 'Already got a campaign in last 24h',
        'marketing_cap' => 'Marketing frequency cap (got a marketing message recently)',
        'duplicate'     => 'Duplicate mobile',
        'schema'        => 'Consent columns missing (run migrations)',
    ];

    /**
     * Meta error codes that must never be retried automatically.
     * 131049 / 131050 / 131026 retries are what drive quality down.
     */
    private const NON_RETRYABLE_CODES = [
        '10', '100', '190', '200', '368',
        '131008', '131009', '131021', '131026', '131031', '131047', '131048', '131049',
        '131050', '131051', '131052', '131053', '130472',
        '132000', '132001', '132005', '132007', '132012', '132015', '132016', '132068', '132069',
        '133010',
    ];

    private static ?bool $hasColumns = null;

    protected WhatsAppConfig $config;

    /** Why the last requestConsentIfPending() send failed ('' when sent or skipped). */
    protected string $lastConsentError = '';

    public function __construct(?WhatsAppConfig $config = null)
    {
        $this->config = $config ?? config(WhatsAppConfig::class);
    }

    // ---------------------------------------------------------------------
    // Eligibility
    // ---------------------------------------------------------------------

    public function hasConsentColumns(): bool
    {
        if (self::$hasColumns === null) {
            try {
                self::$hasColumns = db_connect()->fieldExists('wa_opt_in', 'contacts');
            } catch (Throwable) {
                self::$hasColumns = false;
            }
        }

        return self::$hasColumns;
    }

    public static function resetSchemaCache(): void
    {
        self::$hasColumns = null;
    }

    /**
     * @param array<string, mixed> $contact
     *
     * @return array{ok: bool, reason: string, message: string}
     */
    public function eligibility(array $contact, string $kind, ?bool $withinWindow = null): array
    {
        $status = strtolower((string) ($contact['status'] ?? 'active'));
        if ($kind !== self::KIND_AGENT && in_array($status, ['blocked', 'inactive'], true)) {
            return $this->deny('blocked', $contact);
        }

        $businessInitiated = in_array($kind, [self::KIND_CAMPAIGN, self::KIND_TEMPLATE], true);

        if (! $this->hasConsentColumns()) {
            return $businessInitiated ? $this->deny('schema', $contact) : $this->allow();
        }

        if ($kind !== self::KIND_AGENT && $this->isOptedOut($contact)) {
            return $this->deny('opted_out', $contact);
        }

        if (! $businessInitiated) {
            return $this->allow();
        }

        if ($this->isSuppressed($contact)) {
            return $this->deny('suppressed', $contact);
        }

        if ($this->hasOptIn($contact)) {
            return $this->allow();
        }

        if ($kind === self::KIND_TEMPLATE) {
            $withinWindow ??= function_exists('contact_within_24h_window')
                && contact_within_24h_window($contact, false);
            if ($withinWindow) {
                return $this->allow();
            }
        }

        return $this->deny('no_opt_in', $contact);
    }

    /**
     * @param array<string, mixed> $contact
     *
     * @throws RuntimeException When the send would violate policy.
     */
    public function assertEligible(array $contact, string $kind, ?bool $withinWindow = null): void
    {
        $check = $this->eligibility($contact, $kind, $withinWindow);
        if (! $check['ok']) {
            throw new RuntimeException(self::POLICY_PREFIX . ' ' . $check['message'], 422);
        }
    }

    /**
     * Template test sends go to operator phones; still never to someone who sent STOP.
     *
     * @throws RuntimeException
     */
    public function assertTestRecipientAllowed(string $phone): void
    {
        $contact = $phone !== '' ? model(ContactModel::class)->findByMobile($phone) : null;
        if (! is_array($contact)) {
            return;
        }
        if ($this->isOptedOut($contact) || strtolower((string) ($contact['status'] ?? '')) === 'blocked') {
            throw new RuntimeException(
                self::POLICY_PREFIX . ' ' . $this->deny($this->isOptedOut($contact) ? 'opted_out' : 'blocked', $contact)['message'],
                422
            );
        }
    }

    /**
     * Filter a campaign audience down to policy-eligible, de-duplicated recipients.
     *
     * @param list<array<string, mixed>> $contacts
     *
     * @return array{eligible: list<array<string, mixed>>, excluded: array<string, int>, excluded_total: int}
     */
    public function splitCampaignAudience(array $contacts, ?int $campaignId = null): array
    {
        $excluded         = [];
        $eligible         = [];
        $pending          = [];
        $seen             = [];
        $excludedContacts = [];

        foreach ($contacts as $contact) {
            $phone = function_exists('normalize_phone')
                ? normalize_phone((string) ($contact['mobile'] ?? ''))
                : preg_replace('/\D+/', '', (string) ($contact['mobile'] ?? ''));
            if ($phone === '' || $phone === null) {
                $excluded['no_phone'] = ($excluded['no_phone'] ?? 0) + 1;
                $excludedContacts[] = [
                    'id'          => (int) ($contact['id'] ?? 0),
                    'name'        => (string) ($contact['name'] ?? ''),
                    'mobile'      => (string) ($contact['mobile'] ?? ''),
                    'email'       => (string) ($contact['email'] ?? ''),
                    'reason_code' => 'no_phone',
                    'reason'      => self::EXCLUSION_LABELS['no_phone'] ?? 'No valid mobile',
                ];
                continue;
            }
            if (isset($seen[$phone])) {
                $excluded['duplicate'] = ($excluded['duplicate'] ?? 0) + 1;
                $excludedContacts[] = [
                    'id'          => (int) ($contact['id'] ?? 0),
                    'name'        => (string) ($contact['name'] ?? ''),
                    'mobile'      => (string) ($contact['mobile'] ?? ''),
                    'email'       => (string) ($contact['email'] ?? ''),
                    'reason_code' => 'duplicate',
                    'reason'      => self::EXCLUSION_LABELS['duplicate'] ?? 'Duplicate mobile',
                ];
                continue;
            }

            $check = $this->eligibility($contact, self::KIND_CAMPAIGN);
            if (! $check['ok']) {
                $reasonCode = (string) ($check['reason'] ?? 'unknown');
                $excluded[$reasonCode] = ($excluded[$reasonCode] ?? 0) + 1;
                $excludedContacts[] = [
                    'id'          => (int) ($contact['id'] ?? 0),
                    'name'        => (string) ($contact['name'] ?? ''),
                    'mobile'      => (string) ($contact['mobile'] ?? ''),
                    'email'       => (string) ($contact['email'] ?? ''),
                    'reason_code' => $reasonCode,
                    'reason'      => self::EXCLUSION_LABELS[$reasonCode] ?? ($check['message'] ?? $reasonCode),
                ];
                if ($reasonCode === 'no_opt_in') {
                    $pending[] = $contact;
                }
                continue;
            }

            $seen[$phone] = true;
            $eligible[]   = $contact;
        }

        $cap = $this->campaignDailyCap();
        if ($cap > 0 && $eligible !== []) {
            $recent = $this->recentCampaignCounts(
                array_map(static fn (array $c): int => (int) ($c['id'] ?? 0), $eligible),
                $campaignId
            );
            $kept = [];
            foreach ($eligible as $contact) {
                if (($recent[(int) ($contact['id'] ?? 0)] ?? 0) >= $cap) {
                    $excluded['frequency_cap'] = ($excluded['frequency_cap'] ?? 0) + 1;
                    $excludedContacts[] = [
                        'id'          => (int) ($contact['id'] ?? 0),
                        'name'        => (string) ($contact['name'] ?? ''),
                        'mobile'      => (string) ($contact['mobile'] ?? ''),
                        'email'       => (string) ($contact['email'] ?? ''),
                        'reason_code' => 'frequency_cap',
                        'reason'      => self::EXCLUSION_LABELS['frequency_cap'] ?? 'Already got campaign in last 24h',
                    ];
                    continue;
                }
                $kept[] = $contact;
            }
            $eligible = $kept;
        }

        $capDays = $this->marketingFrequencyCapDays();
        if ($capDays > 0 && $eligible !== [] && $this->isMarketingTemplate($this->campaignTemplate($campaignId))) {
            $recent = $this->recentMarketingContactIds(
                array_map(static fn (array $c): int => (int) ($c['id'] ?? 0), $eligible),
                $capDays,
                $campaignId
            );
            $kept = [];
            foreach ($eligible as $contact) {
                if (isset($recent[(int) ($contact['id'] ?? 0)])) {
                    $excluded['marketing_cap'] = ($excluded['marketing_cap'] ?? 0) + 1;
                    $excludedContacts[] = [
                        'id'          => (int) ($contact['id'] ?? 0),
                        'name'        => (string) ($contact['name'] ?? ''),
                        'mobile'      => (string) ($contact['mobile'] ?? ''),
                        'email'       => (string) ($contact['email'] ?? ''),
                        'reason_code' => 'marketing_cap',
                        'reason'      => self::EXCLUSION_LABELS['marketing_cap'],
                    ];
                    continue;
                }
                $kept[] = $contact;
            }
            $eligible = $kept;
        }

        return [
            'eligible'          => $eligible,
            'excluded'          => $excluded,
            'excluded_total'    => array_sum($excluded),
            'excluded_contacts' => $excludedContacts,
            'pending_consent'   => $pending,
        ];
    }

    /**
     * Human-readable one-liner: "12 skipped (8 no WhatsApp opt-in, 4 opted out)".
     *
     * @param array<string, int> $excluded
     */
    public static function describeExclusions(array $excluded): string
    {
        $parts = [];
        foreach ($excluded as $reason => $count) {
            if ($count > 0) {
                $parts[] = $count . ' ' . strtolower(self::EXCLUSION_LABELS[$reason] ?? $reason);
            }
        }

        return $parts === [] ? '' : (array_sum($excluded) . ' skipped by WhatsApp policy (' . implode(', ', $parts) . ')');
    }

    public function campaignDailyCap(): int
    {
        $override = null;
        try {
            $override = service('settingsService')->get('wa_campaign_daily_cap');
        } catch (Throwable) {
            // settings unavailable (CLI tests)
        }

        if (is_numeric($override)) {
            return max(0, (int) $override);
        }

        return max(0, $this->config->campaignDailyCapPerContact);
    }

    /**
     * Campaign messages already sent per contact in the rolling 24h window.
     *
     * @param list<int> $contactIds
     *
     * @return array<int, int>
     */
    public function recentCampaignCounts(array $contactIds, ?int $excludeCampaignId = null): array
    {
        $contactIds = array_values(array_filter(array_unique($contactIds)));
        if ($contactIds === []) {
            return [];
        }

        $out = [];
        try {
            foreach (array_chunk($contactIds, 500) as $chunk) {
                $builder = db_connect()->table('campaign_contacts')
                    ->select('contact_id, COUNT(*) AS total')
                    ->whereIn('contact_id', $chunk)
                    ->whereIn('status', ['sent', 'delivered', 'read'])
                    ->where('sent_at >=', date('Y-m-d H:i:s', time() - 86400))
                    ->groupBy('contact_id');
                if ($excludeCampaignId !== null && $excludeCampaignId > 0) {
                    $builder->where('campaign_id !=', $excludeCampaignId);
                }
                foreach ($builder->get()->getResultArray() as $row) {
                    $out[(int) $row['contact_id']] = (int) $row['total'];
                }
            }
        } catch (Throwable $e) {
            log_message('warning', 'recentCampaignCounts failed: {msg}', ['msg' => $e->getMessage()]);
        }

        return $out;
    }

    /**
     * Days between MARKETING template messages to the same contact (settings
     * `wa_marketing_frequency_cap_days`). 0 disables the cap.
     */
    public function marketingFrequencyCapDays(): int
    {
        $value = null;
        try {
            $value = service('settingsService')->get('wa_marketing_frequency_cap_days');
        } catch (Throwable) {
            // settings unavailable (CLI tests)
        }

        return is_numeric($value) ? max(0, (int) $value) : max(0, $this->config->marketingFrequencyCapDays);
    }

    /**
     * @param array<string, mixed>|null $template
     */
    public function isMarketingTemplate(?array $template): bool
    {
        return is_array($template) && strtoupper(trim((string) ($template['category'] ?? ''))) === 'MARKETING';
    }

    /**
     * Template row of a WhatsApp template campaign (null for text campaigns / unknown).
     *
     * @return array<string, mixed>|null
     */
    public function campaignTemplate(?int $campaignId): ?array
    {
        if ($campaignId === null || $campaignId <= 0) {
            return null;
        }
        try {
            $campaign = model(CampaignModel::class)->find($campaignId);
            if (! is_array($campaign) || (string) ($campaign['message_type'] ?? 'template') !== 'template' || empty($campaign['template_id'])) {
                return null;
            }
            $template = model(TemplateModel::class)->find((int) $campaign['template_id']);
        } catch (Throwable) {
            return null;
        }

        return is_array($template) ? $template : null;
    }

    /**
     * Contacts that got a MARKETING template in the last $days days (campaign recipients and
     * stored outbound template messages).
     *
     * @param list<int> $contactIds
     *
     * @return array<int, true>
     */
    public function recentMarketingContactIds(array $contactIds, int $days, ?int $excludeCampaignId = null): array
    {
        $contactIds = array_values(array_filter(array_unique(array_map('intval', $contactIds))));
        if ($contactIds === [] || $days <= 0) {
            return [];
        }

        $since = date('Y-m-d H:i:s', time() - $days * 86400);
        $out   = [];
        try {
            $db    = db_connect();
            $names = array_column(
                $db->table('templates')->distinct()->select('name')->where('category', 'MARKETING')->get()->getResultArray(),
                'name'
            );

            foreach (array_chunk($contactIds, 500) as $chunk) {
                $cc = $db->table('campaign_contacts cc')->distinct()->select('cc.contact_id')
                    ->join('campaigns c', 'c.id = cc.campaign_id')
                    ->join('templates t', 't.id = c.template_id')
                    ->whereIn('cc.contact_id', $chunk)
                    ->whereIn('cc.status', ['sent', 'delivered', 'read'])
                    ->where('cc.sent_at >=', $since)
                    ->where('t.category', 'MARKETING');
                if ($excludeCampaignId !== null && $excludeCampaignId > 0) {
                    $cc->where('cc.campaign_id !=', $excludeCampaignId);
                }
                foreach ($cc->get()->getResultArray() as $row) {
                    $out[(int) $row['contact_id']] = true;
                }

                if ($names === []) {
                    continue;
                }
                $m = $db->table('messages')->distinct()->select('contact_id')
                    ->whereIn('contact_id', $chunk)
                    ->where('direction', 'outbound')
                    ->where('message_type', 'template')
                    ->whereIn('status', ['sent', 'delivered', 'read'])
                    ->where('created_at >=', $since)
                    ->whereIn('content', array_merge($names, array_map(static fn ($n): string => 'Template: ' . $n, $names)));
                if ($excludeCampaignId !== null && $excludeCampaignId > 0) {
                    $m->groupStart()->where('campaign_id IS NULL')->orWhere('campaign_id !=', $excludeCampaignId)->groupEnd();
                }
                foreach ($m->get()->getResultArray() as $row) {
                    $out[(int) $row['contact_id']] = true;
                }
            }
        } catch (Throwable $e) {
            log_message('warning', 'recentMarketingContactIds failed: {msg}', ['msg' => $e->getMessage()]);
        }

        return $out;
    }

    /**
     * One policy gate for a business-initiated template to one contact (queue, API):
     * blocked / opt-out / delivery suppression, opt-in (always for MARKETING), template
     * approval, marketing frequency cap and optionally Meta's daily messaging limit.
     *
     * @param array<string, mixed> $contact
     * @param array<string, mixed> $template
     *
     * @return array{ok: bool, reason: string, message: string}
     */
    public function templateSendCheck(
        array $contact,
        array $template,
        ?bool $withinWindow = null,
        ?int $campaignId = null,
        bool $checkMessagingLimit = false
    ): array {
        $marketing = $this->isMarketingTemplate($template);
        $kind      = ($marketing || ($campaignId ?? 0) > 0) ? self::KIND_CAMPAIGN : self::KIND_TEMPLATE;
        $check     = $this->eligibility($contact, $kind, $withinWindow);
        if (! $check['ok']) {
            if ($check['reason'] === 'no_opt_in' && $marketing) {
                $check['message'] = 'Marketing templates need a recorded WhatsApp opt-in for this contact. Record consent (or send opt_in: true with the source) before sending.';
            }

            return $check;
        }

        try {
            (new WhatsAppTemplateSendGuard())->assertApproved($template);
        } catch (RuntimeException $e) {
            return ['ok' => false, 'reason' => 'template_not_approved', 'message' => $e->getMessage()];
        }

        $days = $marketing ? $this->marketingFrequencyCapDays() : 0;
        if ($days > 0 && $this->recentMarketingContactIds([(int) ($contact['id'] ?? 0)], $days, $campaignId) !== []) {
            return ['ok' => false, 'reason' => 'marketing_cap', 'message' => $this->marketingCapMessage($days)];
        }

        if ($checkMessagingLimit) {
            try {
                $this->assertWithinMessagingLimit(1, null);
            } catch (RuntimeException $e) {
                return ['ok' => false, 'reason' => 'messaging_limit', 'message' => trim(str_replace(self::POLICY_PREFIX, '', $e->getMessage()))];
            }
        }

        return $this->allow();
    }

    /**
     * @param array<string, mixed> $contact
     * @param array<string, mixed> $template
     *
     * @throws RuntimeException (422, POLICY_PREFIX — never retried) when templateSendCheck() denies.
     */
    public function assertTemplateSendAllowed(
        array $contact,
        array $template,
        ?bool $withinWindow = null,
        ?int $campaignId = null,
        bool $checkMessagingLimit = false
    ): void {
        $check = $this->templateSendCheck($contact, $template, $withinWindow, $campaignId, $checkMessagingLimit);
        if (! $check['ok']) {
            throw new RuntimeException(self::POLICY_PREFIX . ' ' . $check['message'], 422);
        }
    }

    public function marketingCapMessage(int $days): string
    {
        return 'This contact already received a marketing message in the last '
            . ($days === 1 ? '24 hours' : $days . ' days')
            . ' (marketing frequency cap). Try again later or change the cap in Settings.';
    }

    /**
     * @param array<string, mixed> $contact
     */
    public function hasOptIn(array $contact): bool
    {
        return (int) ($contact['wa_opt_in'] ?? 0) === 1 && ! $this->isOptedOut($contact);
    }

    /**
     * @param array<string, mixed> $contact
     */
    public function isOptedOut(array $contact): bool
    {
        return ! empty($contact['wa_opted_out_at']);
    }

    /**
     * @param array<string, mixed> $contact
     */
    public function isSuppressed(array $contact): bool
    {
        $until = (string) ($contact['wa_suppressed_until'] ?? '');
        if ($until === '') {
            return false;
        }
        $ts = strtotime($until);

        return $ts !== false && $ts > time();
    }

    /**
     * @return array{ok: bool, reason: string, message: string}
     */
    protected function allow(): array
    {
        return ['ok' => true, 'reason' => '', 'message' => ''];
    }

    /**
     * @param array<string, mixed> $contact
     *
     * @return array{ok: bool, reason: string, message: string}
     */
    protected function deny(string $reason, array $contact): array
    {
        $message = match ($reason) {
            'blocked'    => 'Contact is blocked or inactive.',
            'opted_out'  => 'Contact opted out of WhatsApp messages (STOP). Only they can opt back in by sending START.',
            'no_opt_in'  => 'No WhatsApp opt-in is recorded for this contact. Record consent before sending business-initiated messages.',
            'suppressed' => 'Sending to this number is paused until ' . (string) ($contact['wa_suppressed_until'] ?? '')
                . (! empty($contact['wa_suppress_reason']) ? ' (' . $contact['wa_suppress_reason'] . ')' : '') . '.',
            'schema'     => 'WhatsApp consent columns are missing. Run "php spark migrate" for this account.',
            default      => 'Not allowed by WhatsApp policy.',
        };

        return ['ok' => false, 'reason' => $reason, 'message' => $message];
    }

    // ---------------------------------------------------------------------
    // Consent changes
    // ---------------------------------------------------------------------

    public function optIn(int $contactId, string $source, ?string $at = null, bool $log = true): bool
    {
        if ($contactId <= 0 || ! $this->hasConsentColumns()) {
            return false;
        }

        $source = $this->normalizeSource($source);
        $ok     = (bool) model(ContactModel::class)->update($contactId, [
            'wa_opt_in'           => 1,
            'wa_opt_in_at'        => $at ?? date('Y-m-d H:i:s'),
            'wa_opt_in_source'    => $source,
            'wa_opted_out_at'     => null,
            'wa_suppressed_until' => null,
            'wa_suppress_reason'  => null,
        ]);

        if ($ok && $log) {
            $this->log('wa_opt_in', 'WhatsApp opt-in recorded', ['contact_id' => $contactId, 'source' => $source]);
        }

        return $ok;
    }

    public function optOut(int $contactId, string $source): bool
    {
        if ($contactId <= 0 || ! $this->hasConsentColumns()) {
            return false;
        }

        $ok = (bool) model(ContactModel::class)->update($contactId, [
            'wa_opt_in'          => 0,
            'wa_opted_out_at'    => date('Y-m-d H:i:s'),
            'wa_suppress_reason' => 'Opted out (' . mb_substr($source, 0, 60) . ')',
        ]);

        if ($ok) {
            $this->cancelPendingSends($contactId);
            $this->log('wa_opt_out', 'WhatsApp opt-out recorded', ['contact_id' => $contactId, 'source' => $source]);
        }

        return $ok;
    }

    public function suppress(int $contactId, string $reason, int $seconds): bool
    {
        if ($contactId <= 0 || $seconds <= 0 || ! $this->hasConsentColumns()) {
            return false;
        }

        $contact = model(ContactModel::class)->find($contactId);
        if (! is_array($contact)) {
            return false;
        }

        $until    = time() + $seconds;
        $existing = strtotime((string) ($contact['wa_suppressed_until'] ?? '')) ?: 0;
        if ($existing >= $until) {
            return true;
        }

        return (bool) model(ContactModel::class)->update($contactId, [
            'wa_suppressed_until' => date('Y-m-d H:i:s', $until),
            'wa_suppress_reason'  => mb_substr($reason, 0, 191),
        ]);
    }

    public function clearSuppression(int $contactId): bool
    {
        if ($contactId <= 0 || ! $this->hasConsentColumns()) {
            return false;
        }

        return (bool) model(ContactModel::class)->update($contactId, [
            'wa_suppressed_until' => null,
            'wa_suppress_reason'  => null,
        ]);
    }

    /**
     * Contact form checkbox. Operators may record or withdraw consent, but cannot
     * override a customer's own STOP.
     */
    public function applyOperatorConsent(int $contactId, bool $optIn, string $source): void
    {
        if ($contactId <= 0 || ! $this->hasConsentColumns()) {
            return;
        }
        $contact = model(ContactModel::class)->find($contactId);
        if (! is_array($contact) || $this->isOptedOut($contact)) {
            return;
        }

        if ($optIn && ! $this->hasOptIn($contact)) {
            $this->optIn($contactId, $source);
        } elseif (! $optIn && (int) ($contact['wa_opt_in'] ?? 0) === 1) {
            model(ContactModel::class)->update($contactId, ['wa_opt_in' => 0]);
            $this->log('wa_opt_in_withdrawn', 'WhatsApp opt-in removed by operator', ['contact_id' => $contactId]);
        }
    }

    /**
     * Bulk consent update for the contacts screen.
     *
     * @param list<int> $contactIds
     */
    public function bulkSetConsent(array $contactIds, bool $optIn, string $source): int
    {
        $n = 0;
        foreach (array_unique(array_map('intval', $contactIds)) as $id) {
            if ($id <= 0) {
                continue;
            }
            if ($optIn) {
                $contact = model(ContactModel::class)->find($id);
                // Only the customer can reverse their own STOP.
                if (is_array($contact) && $this->isOptedOut($contact)) {
                    continue;
                }
            }
            if ($optIn ? $this->optIn($id, $source) : $this->optOut($id, $source)) {
                $n++;
            }
        }

        return $n;
    }

    public function normalizeSource(string $source): string
    {
        $source = strtolower(trim($source));

        return array_key_exists($source, self::OPT_IN_SOURCES) ? $source : 'other';
    }

    protected function cancelPendingSends(int $contactId): void
    {
        try {
            $db = db_connect();
            $db->table('message_queue')
                ->where('contact_id', $contactId)
                ->where('status', 'pending')
                ->update([
                    'status'        => 'cancelled',
                    'error_message' => self::POLICY_PREFIX . ' contact opted out.',
                    'updated_at'    => date('Y-m-d H:i:s'),
                ]);
            if ($db->tableExists('automation_delayed_jobs')) {
                $db->table('automation_delayed_jobs')
                    ->where('contact_id', $contactId)
                    ->whereIn('status', ['pending', 'awaiting_reply'])
                    ->update(['status' => 'cancelled', 'updated_at' => date('Y-m-d H:i:s')]);
            }
            if ($db->tableExists('sequence_enrollments')) {
                $db->table('sequence_enrollments')
                    ->where('contact_id', $contactId)
                    ->where('status', 'active')
                    ->update(['status' => 'exited', 'updated_at' => date('Y-m-d H:i:s')]);
            }
        } catch (Throwable $e) {
            log_message('warning', 'cancelPendingSends failed: {msg}', ['msg' => $e->getMessage()]);
        }
    }

    // ---------------------------------------------------------------------
    // Inbound keywords (STOP / START / AGENT)
    // ---------------------------------------------------------------------

    /**
     * Consent intent from a quick-reply / interactive button tap (payload id first, then label).
     */
    public function detectButtonIntent(string $label, string $payload = ''): ?string
    {
        $lists = [
            self::INTENT_OPT_OUT => $this->config->optOutButtonKeywords,
            self::INTENT_OPT_IN  => $this->config->optInButtonKeywords,
        ];
        foreach ([$payload, $label] as $candidate) {
            $normalized = self::normalizeKeyword(str_replace('_', ' ', $candidate));
            if ($normalized === '') {
                continue;
            }
            foreach ($lists as $intent => $keywords) {
                foreach ($keywords as $keyword) {
                    if ($normalized === self::normalizeKeyword(str_replace('_', ' ', (string) $keyword))) {
                        return $intent;
                    }
                }
            }
        }

        $fallback = $this->detectIntent($label);

        return in_array($fallback, [self::INTENT_OPT_OUT, self::INTENT_OPT_IN], true) ? $fallback : null;
    }

    public function detectIntent(string $text): ?string
    {
        $normalized = self::normalizeKeyword($text);
        if ($normalized === '' || mb_strlen($normalized) > 40) {
            return null;
        }

        $lists = [
            self::INTENT_OPT_OUT => $this->config->optOutKeywords,
            self::INTENT_OPT_IN  => $this->config->optInKeywords,
            self::INTENT_HUMAN   => $this->config->humanAgentKeywords,
        ];
        foreach ($lists as $intent => $keywords) {
            foreach ($keywords as $keyword) {
                if ($normalized === self::normalizeKeyword((string) $keyword)) {
                    return $intent;
                }
            }
        }

        return null;
    }

    public static function normalizeKeyword(string $text): string
    {
        $text = mb_strtolower(trim($text));
        $text = preg_replace('/[^\p{L}\p{M}\p{N}\s]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }

    /**
     * Apply STOP / START from an inbound WhatsApp message and confirm to the customer.
     *
     * @param array<string, mixed> $contact
     *
     * @return string|null Intent handled (caller must skip bots/automations), or null.
     */
    public function handleConsentKeyword(
        array $contact,
        string $text,
        ?string $provider = null,
        bool $reply = true,
        bool $isButton = false,
        string $buttonPayload = ''
    ): ?string {
        $intent    = $isButton ? $this->detectButtonIntent($text, $buttonPayload) : $this->detectIntent($text);
        $contactId = (int) ($contact['id'] ?? 0);
        if ($contactId <= 0 || ! in_array($intent, [self::INTENT_OPT_OUT, self::INTENT_OPT_IN], true)) {
            return null;
        }

        $source = $isButton ? 'whatsapp_button' : 'whatsapp_keyword';
        $label  = mb_substr(trim($text), 0, 40);

        if ($intent === self::INTENT_OPT_OUT) {
            $this->optOut($contactId, $source . ($label !== '' ? ': ' . $label : ''));
            $replyText = $this->config->optOutReply;
        } else {
            if ($this->isOptedOut($contact) || ! $this->hasOptIn($contact)) {
                $this->optIn($contactId, $source);
            }
            $replyText = $this->config->optInReply;
        }

        if ($reply) {
            $this->sendComplianceReply($contact, $replyText, $provider);
        }

        return $intent;
    }

    /**
     * Customer asked for a person: alert the team and acknowledge in chat.
     *
     * @param array<string, mixed> $contact
     */
    public function escalateToHuman(array $contact, ?string $provider = null): void
    {
        $contactId = (int) ($contact['id'] ?? 0);
        if ($contactId <= 0) {
            return;
        }

        try {
            $name = trim((string) ($contact['name'] ?? '')) ?: (string) ($contact['mobile'] ?? 'Customer');
            model(NotificationModel::class)->notifyChatUsers(
                'Human agent requested',
                $name . ' asked to talk to a person on WhatsApp.',
                site_url('chat?contact_id=' . $contactId),
                ! empty($contact['assigned_to']) ? (int) $contact['assigned_to'] : null
            );
        } catch (Throwable $e) {
            log_message('warning', 'Human escalation notify failed: {msg}', ['msg' => $e->getMessage()]);
        }

        $this->sendComplianceReply($contact, $this->config->humanAgentReply, $provider);
        $this->log('wa_human_escalation', 'Customer requested a human agent', ['contact_id' => $contactId]);
    }

    /**
     * Direct free-form reply inside the window the customer just opened.
     *
     * @param array<string, mixed> $contact
     */
    public function sendComplianceReply(array $contact, string $text, ?string $provider = null): void
    {
        if (trim($text) === '') {
            return;
        }

        try {
            $this->sendAndStore(
                $contact,
                $provider,
                static fn ($api, string $to): array => $api->sendText($to, $text),
                $text,
                'text',
                ['compliance_reply' => true]
            );
        } catch (Throwable $e) {
            log_message('error', 'Compliance reply failed for contact {id}: {msg}', [
                'id'  => (int) ($contact['id'] ?? 0),
                'msg' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Ask for WhatsApp consent with Agree / Stop buttons (payload WA_CONSENT_YES / WA_CONSENT_NO).
     * Free-form, so only inside the 24h window the customer opened; the tap is applied by the webhook.
     *
     * @param array<string, mixed> $contact
     *
     * @throws RuntimeException Outside the 24h window, opted out, or provider error.
     */
    public function sendConsentRequest(array $contact, ?string $provider = null): void
    {
        if ($this->isOptedOut($contact)) {
            throw new RuntimeException(self::POLICY_PREFIX . ' Contact opted out — only they can opt back in by sending START.', 422);
        }
        if (! function_exists('contact_within_24h_window') || ! contact_within_24h_window($contact, true)) {
            throw new RuntimeException(
                self::POLICY_PREFIX . ' Consent can be asked on WhatsApp only after the customer messages you (24-hour window).',
                422
            );
        }

        $text    = $this->config->consentRequestText;
        $buttons = [
            ['id' => 'WA_CONSENT_YES', 'title' => $this->config->consentAgreeLabel],
            ['id' => 'WA_CONSENT_NO', 'title' => $this->config->consentStopLabel],
        ];
        $this->sendAndStore(
            $contact,
            $provider,
            static fn ($api, string $to): array => $api->sendInteractiveButtons($to, $text, $buttons),
            $text,
            'interactive',
            ['consent_request' => true, 'buttons' => $buttons]
        );
        $this->log('wa_consent_request', 'WhatsApp consent request sent', ['contact_id' => (int) ($contact['id'] ?? 0)]);
    }

    /**
     * Tenant switch for automatic consent asks (settings `wa_auto_consent_request`, default off).
     */
    public function autoConsentRequestEnabled(): bool
    {
        $value = null;
        try {
            $value = service('settingsService')->get('wa_auto_consent_request');
        } catch (Throwable) {
            // settings unavailable (CLI tests)
        }
        if ($value === null || $value === '') {
            return $this->config->autoConsentRequest;
        }

        return in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * Why this contact cannot be asked for consent ('' when it can).
     * Automatic asks need the tenant setting and go to each contact at most once;
     * a manual ask may repeat, but not within the resend cooldown.
     *
     * @param array<string, mixed> $contact
     */
    public function consentRequestBlocker(array $contact, bool $manual = false): string
    {
        if (! $manual && ! $this->autoConsentRequestEnabled()) {
            return 'automatic opt-in requests are turned off';
        }
        if (! $this->hasConsentColumns() || ! array_key_exists('wa_consent_requested_at', $contact)) {
            return 'consent columns missing (run migrations)';
        }
        if ((int) ($contact['id'] ?? 0) <= 0) {
            return 'contact not found';
        }
        if (in_array(strtolower((string) ($contact['status'] ?? 'active')), ['blocked', 'inactive'], true)) {
            return 'contact is blocked or inactive';
        }
        if ($this->isOptedOut($contact)) {
            return 'contact opted out (STOP) — only they can opt back in by sending START';
        }
        if ($this->hasOptIn($contact)) {
            return 'contact already opted in';
        }
        if ($this->isSuppressed($contact)) {
            return 'sending to this number is paused until ' . (string) ($contact['wa_suppressed_until'] ?? '');
        }

        $last = strtotime((string) ($contact['wa_consent_requested_at'] ?? '')) ?: 0;
        if ($last === 0) {
            return '';
        }
        if (! $manual) {
            return 'already asked on ' . (string) $contact['wa_consent_requested_at'];
        }
        $next = $last + max(1, $this->config->consentRequestResendDays) * 86400;

        return $next > time()
            ? 'already asked on ' . (string) $contact['wa_consent_requested_at'] . '; you can ask again after ' . date('Y-m-d H:i', $next)
            : '';
    }

    /**
     * Contact may be asked for consent now (see consentRequestBlocker()).
     *
     * @param array<string, mixed> $contact
     */
    public function needsConsentRequest(array $contact, bool $manual = false): bool
    {
        return $this->consentRequestBlocker($contact, $manual) === '';
    }

    /**
     * Ask a pending contact for consent: Agree / Stop buttons inside the 24h window, otherwise the
     * approved consent template. Never throws; returns how it was asked, or null when skipped/failed.
     * Automatic callers pass $manual = false (tenant setting + once per contact).
     *
     * @param array<string, mixed> $contact
     */
    public function requestConsentIfPending(array $contact, ?string $provider = null, bool $manual = false): ?string
    {
        $this->lastConsentError = '';
        $blocker = $this->consentRequestBlocker($contact, $manual);
        if ($blocker !== '') {
            if ($manual) {
                $this->lastConsentError = ucfirst($blocker) . '.';
            }

            return null;
        }

        $contactId = (int) $contact['id'];
        try {
            if (function_exists('contact_within_24h_window') && contact_within_24h_window($contact, true)) {
                $this->sendConsentRequest($contact, $provider);
                $how = 'buttons';
            } else {
                $this->sendConsentTemplate($contact, $provider);
                $how = 'template';
            }
        } catch (Throwable $e) {
            log_message('warning', 'Consent request skipped for contact {id}: {msg}', ['id' => $contactId, 'msg' => $e->getMessage()]);
            $this->lastConsentError = trim(str_replace(self::POLICY_PREFIX, '', $e->getMessage()));

            return null;
        }

        model(ContactModel::class)->update($contactId, ['wa_consent_requested_at' => date('Y-m-d H:i:s')]);

        return $how;
    }

    public function lastConsentError(): string
    {
        return $this->lastConsentError;
    }

    /**
     * Operator clicked "Request opt-in" for one contact.
     *
     * @return array{ok: bool, message: string}
     */
    public function requestConsentManually(int $contactId, ?string $provider = null): array
    {
        $contact = $contactId > 0 ? model(ContactModel::class)->find($contactId) : null;
        if (! is_array($contact)) {
            return ['ok' => false, 'message' => 'Contact not found.'];
        }

        $how = $this->requestConsentIfPending($contact, $provider, true);
        if ($how === null) {
            return ['ok' => false, 'message' => 'Opt-in request not sent: ' . ($this->lastConsentError !== '' ? $this->lastConsentError : 'not allowed for this contact.')];
        }

        return ['ok' => true, 'message' => $this->describeConsentRequest($how)];
    }

    /**
     * Result line for the operator after a consent request attempt ('' when nothing to say).
     */
    public function describeConsentRequest(?string $how): string
    {
        if ($how === 'template') {
            return 'WhatsApp consent request (Agree / Stop) sent.';
        }
        if ($how === 'buttons') {
            return 'WhatsApp consent buttons (Agree / Stop) sent.';
        }

        return $this->lastConsentError !== '' ? 'WhatsApp consent request not sent: ' . $this->lastConsentError : '';
    }

    /**
     * @param list<array<string, mixed>> $contacts
     *
     * @return int Contacts asked. The first failure reason stays in lastConsentError().
     */
    public function requestConsentForContacts(array $contacts, ?string $provider = null): int
    {
        $sent   = 0;
        $error  = '';
        $limit  = max(0, $this->config->consentRequestBatchLimit);
        foreach ($contacts as $contact) {
            if ($limit > 0 && $sent >= $limit) {
                break;
            }
            if ($this->requestConsentIfPending($contact, $provider) !== null) {
                $sent++;
            } elseif ($error === '' && $this->lastConsentError !== '') {
                $error = $this->lastConsentError;
            }
        }
        $this->lastConsentError = $sent === 0 ? $error : '';

        return $sent;
    }

    /**
     * Policy denial text; for "no opt-in" also asks the customer for consent (once per cooldown).
     *
     * @param array<string, mixed> $contact
     * @param array{ok: bool, reason: string, message: string} $check
     */
    public function denialWithConsentRequest(array $contact, array $check, ?string $provider = null): string
    {
        if (($check['reason'] ?? '') !== 'no_opt_in') {
            return (string) $check['message'];
        }
        if ($this->requestConsentIfPending($contact, $provider) !== null) {
            return $check['message'] . ' A WhatsApp consent request (Agree / Stop) was sent instead.';
        }
        if ($this->lastConsentError !== '') {
            return $check['message'] . ' Consent request not sent: ' . $this->lastConsentError;
        }
        $asked = (string) ($contact['wa_consent_requested_at'] ?? '');

        return $asked !== ''
            ? $check['message'] . ' Consent was requested on ' . $asked . '; waiting for the customer to tap Agree.'
            : (string) $check['message'];
    }

    /**
     * Business-initiated consent ask via the approved consent template (Agree / Stop quick replies).
     *
     * @param array<string, mixed> $contact
     *
     * @throws RuntimeException Template missing / not approved, messaging limit, or provider error.
     */
    public function sendConsentTemplate(array $contact, ?string $provider = null): void
    {
        $template = $this->consentTemplate();
        if ($template === null || strtoupper((string) ($template['status'] ?? '')) !== 'APPROVED') {
            $status = $template !== null ? strtoupper((string) ($template['status'] ?? '')) : ($this->ensureConsentTemplate()['status'] ?? '');
            throw new RuntimeException(
                'consent template "' . $this->config->consentTemplateName . '" is '
                . ($status !== '' && $status !== null ? $status : 'not created') . ' on Meta — opt-in requests outside the 24-hour chat window work once Meta approves it.'
            );
        }

        $this->assertWithinMessagingLimit(1, null);

        $name     = (string) $template['name'];
        $language = (string) ($template['language'] ?? $this->config->consentTemplateLanguage);
        $this->sendAndStore(
            $contact,
            $provider,
            static fn ($api, string $to): array => $api->sendTemplate($to, $name, $language, []),
            $name,
            'template',
            ['consent_request' => true, 'template' => $name, 'language' => $language]
        );
        $this->log('wa_consent_request', 'WhatsApp consent template sent', ['contact_id' => (int) ($contact['id'] ?? 0)]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function consentTemplate(): ?array
    {
        try {
            $row = model(TemplateModel::class)
                ->where('name', $this->config->consentTemplateName)
                ->where('language', $this->config->consentTemplateLanguage)
                ->orderBy('id', 'DESC')
                ->first();
        } catch (Throwable) {
            return null;
        }

        return is_array($row) ? $row : null;
    }

    /**
     * Create the consent template on Meta for this tenant if it does not exist locally.
     * Approval arrives through the message_template_status_update webhook.
     *
     * @return array{action: string, status: ?string, message: string}
     */
    public function ensureConsentTemplate(): array
    {
        $existing = $this->consentTemplate();
        if ($existing !== null) {
            return ['action' => 'exists', 'status' => strtoupper((string) ($existing['status'] ?? '')), 'message' => 'Consent template already present.'];
        }

        $settings = service('settingsService');
        if ($settings->getWhatsAppProvider() !== SettingsService::PROVIDER_META) {
            return ['action' => 'skipped', 'status' => null, 'message' => 'Consent template auto-create is available for the Meta provider only.'];
        }
        $wabaId = trim((string) ($settings->getMetaConfig()['waba_id'] ?? ''));
        if ($wabaId === '') {
            return ['action' => 'skipped', 'status' => null, 'message' => 'WABA ID missing; cannot create the consent template.'];
        }

        $name       = $this->config->consentTemplateName;
        $language   = $this->config->consentTemplateLanguage;
        $body       = $this->config->consentTemplateBody;
        $components = [
            ['type' => 'BODY', 'text' => $body],
            ['type' => 'BUTTONS', 'buttons' => [
                ['type' => 'QUICK_REPLY', 'text' => $this->config->consentAgreeLabel],
                ['type' => 'QUICK_REPLY', 'text' => $this->config->consentStopLabel],
            ]],
        ];

        try {
            $response = service('whatsApp')->createTemplate([
                'name'                  => $name,
                'language'              => $language,
                'category'              => 'MARKETING',
                'components'            => $components,
                'allow_category_change' => true,
            ]);
        } catch (Throwable $e) {
            log_message('error', 'Consent template create failed: {msg}', ['msg' => $e->getMessage()]);
            $message = MetaApiErrorMapper::humanize($e->getMessage(), (int) $e->getCode());
            $this->notifyTemplate('WhatsApp consent template not created', 'Meta refused "' . $name . '": ' . $message);

            return ['action' => 'failed', 'status' => null, 'message' => $message];
        }

        $data   = is_array($response['data'] ?? null) ? $response['data'] : $response;
        $status = strtoupper((string) ($data['status'] ?? 'PENDING')) ?: 'PENDING';
        $model  = model(TemplateModel::class);
        $row    = [
            'waba_id'       => $wabaId,
            'meta_id'       => ! empty($data['id']) ? (string) $data['id'] : null,
            'name'          => $name,
            'language'      => $language,
            'category'      => 'MARKETING',
            'template_type' => 'default',
            'status'        => $status,
            'body'          => $body,
            'buttons'       => $components[1]['buttons'],
            'raw_payload'   => ['components' => $components, 'response' => $data],
            'synced_at'     => date('Y-m-d H:i:s'),
        ];
        $model->insert(array_intersect_key($row, array_flip($model->allowedFields)));
        $this->log('wa_consent_template', 'WhatsApp consent template submitted to Meta', ['status' => $status]);

        return ['action' => 'created', 'status' => $status, 'message' => 'Consent template submitted to Meta (' . $status . ').'];
    }

    /**
     * Send via the active (or forced) provider and store the outbound message in the chat.
     *
     * @param array<string, mixed> $contact
     * @param callable(mixed, string): array<string, mixed> $send
     * @param array<string, mixed> $payload
     */
    protected function sendAndStore(array $contact, ?string $provider, callable $send, string $content, string $type, array $payload): void
    {
        $contactId = (int) ($contact['id'] ?? 0);
        if ($contactId <= 0) {
            throw new RuntimeException('Contact not found.');
        }

        $api = null;
        try {
            $api = service('whatsApp');
            if ($provider !== null && $provider !== '' && method_exists($api, 'forceProvider')) {
                $api->forceProvider($provider);
            }
            $to = $api->normalizePhone((string) ($contact['mobile'] ?? ''));
            if ($to === '') {
                throw new RuntimeException('Contact has no valid mobile number.');
            }
            $result = $send($api, $to);
            $waId   = $result['messages'][0]['id'] ?? $result['data']['messages'][0]['id'] ?? null;

            $conversation = model(ConversationModel::class)->findOrCreateForContact($contactId, 'whatsapp');
            model(MessageModel::class)->insert([
                'contact_id'      => $contactId,
                'conversation_id' => (int) ($conversation['id'] ?? 0) ?: null,
                'channel'         => 'whatsapp',
                'direction'       => 'outbound',
                'message_type'    => $type,
                'wa_message_id'   => is_string($waId) ? $waId : null,
                'wamid'           => is_string($waId) ? $waId : null,
                'content'         => $content,
                'payload'         => $payload + ['response' => $result],
                'status'          => 'sent',
                'is_read'         => 1,
            ]);
        } finally {
            if ($api !== null && method_exists($api, 'clearForcedProvider')) {
                try {
                    $api->clearForcedProvider();
                } catch (Throwable) {
                }
            }
        }
    }

    // ---------------------------------------------------------------------
    // Delivery failures
    // ---------------------------------------------------------------------

    public static function extractErrorCode(string $message): ?string
    {
        if (preg_match('/\[Meta #(\d{1,7})\]/', $message, $m) === 1) {
            return $m[1];
        }
        if (preg_match('/\(#(\d{1,7})\)/', $message, $m) === 1) {
            return $m[1];
        }
        if (preg_match('/\b(13[0-3]\d{3})\b/', $message, $m) === 1) {
            return $m[1];
        }

        return null;
    }

    public static function isRetryableError(string $message): bool
    {
        if (str_starts_with($message, self::POLICY_PREFIX)
            || str_contains($message, 'Outside the 24-hour')
            || str_contains($message, 'Contact has no valid mobile')
            || str_contains($message, 'Contact not found')
            || str_contains($message, 'Unsupported queue message type')) {
            return false;
        }

        $code = self::extractErrorCode($message);

        return $code === null || ! in_array($code, self::NON_RETRYABLE_CODES, true);
    }

    /** Throughput limits: 130429 / 80007 / 4 throttle the whole number, 131056 one recipient pair. */
    private const ACCOUNT_RATE_LIMIT_CODES = ['4', '80007', '130429'];

    private const PAIR_RATE_LIMIT_CODES = ['131056'];

    public static function isRateLimitError(string $message, int $httpStatus = 0): bool
    {
        $code = self::extractErrorCode($message);

        return $httpStatus === 429
            || in_array($code, self::ACCOUNT_RATE_LIMIT_CODES, true)
            || in_array($code, self::PAIR_RATE_LIMIT_CODES, true)
            || preg_match('/\bHTTP 429\b|Too Many Requests|rate limit/i', $message) === 1;
    }

    /** True when the whole sending number is throttled, so the rest of a batch should wait too. */
    public static function isAccountRateLimit(string $message, int $httpStatus = 0): bool
    {
        return self::isRateLimitError($message, $httpStatus)
            && ! in_array(self::extractErrorCode($message), self::PAIR_RATE_LIMIT_CODES, true);
    }

    /**
     * Seconds to wait before the next attempt (exponential, capped).
     */
    public static function retryDelaySeconds(string $message, int $attempts, int $httpStatus = 0): int
    {
        $cfg     = config('WhatsApp');
        $attempt = max(1, $attempts);

        if (self::isRateLimitError($message, $httpStatus)) {
            $base = max(1, (int) ($cfg->rateLimitBackoffSeconds ?? 60));
            $max  = max($base, (int) ($cfg->rateLimitBackoffMaxSeconds ?? 1800));

            return (int) min($max, $base * (2 ** min(10, $attempt - 1)));
        }

        $base = max(1, (int) ($cfg->retryBackoffSeconds ?? 30));

        return (int) min(1800, $base * (2 ** min(10, $attempt - 1)));
    }

    /**
     * React to a Meta delivery error for a contact (webhook status or sync API error).
     */
    public function applyDeliveryFailure(int $contactId, string $code, string $title = ''): void
    {
        $code = trim($code);
        if ($code === '') {
            return;
        }

        switch ($code) {
            case '131050':
                $this->optOut($contactId, 'meta_131050');
                break;
            case '131026':
                $this->suppress(
                    $contactId,
                    'Undeliverable (Meta 131026)',
                    max(1, $this->config->undeliverableSuppressDays) * 86400
                );
                break;
            case '131049':
                $this->suppress($contactId, 'Meta per-user marketing limit (131049)', 86400);
                break;
            case '131048':
                $this->recordHealthEvent(
                    self::HEALTH_FLAGGED,
                    'SPAM_RATE_LIMIT',
                    'Meta limited sends from this number (131048). ' . $title
                );
                break;
            case '131031':
            case '368':
                $this->recordHealthEvent(
                    self::HEALTH_RESTRICTED,
                    'ACCOUNT_LOCKED',
                    'Meta blocked this account for policy violations (' . $code . '). ' . $title
                );
                break;
        }
    }

    // ---------------------------------------------------------------------
    // Account quality / restriction health
    // ---------------------------------------------------------------------

    /**
     * @return array{status: string, event: string, detail: string, updated_at: string, messaging_limit: string, quality_rating: string}
     */
    public function getHealth(): array
    {
        $settings = service('settingsService');
        $status   = (string) $settings->get('wa_health_status', self::HEALTH_OK);
        if (! in_array($status, [self::HEALTH_OK, self::HEALTH_FLAGGED, self::HEALTH_RESTRICTED], true)) {
            $status = self::HEALTH_OK;
        }

        return [
            'status'          => $status,
            'event'           => (string) $settings->get('wa_health_event', ''),
            'detail'          => (string) $settings->get('wa_health_detail', ''),
            'updated_at'      => (string) $settings->get('wa_health_updated_at', ''),
            'messaging_limit' => (string) $settings->get('wa_messaging_limit', ''),
            'quality_rating'  => (string) $settings->get('wa_quality_rating', ''),
        ];
    }

    public function recordHealthEvent(string $status, string $event, string $detail = ''): void
    {
        $settings = service('settingsService');
        $previous = $this->getHealth()['status'];

        $settings->set('wa_health_status', $status, 'whatsapp');
        $settings->set('wa_health_event', mb_substr($event, 0, 100), 'whatsapp');
        $settings->set('wa_health_detail', mb_substr(trim($detail), 0, 1000), 'whatsapp');
        $settings->set('wa_health_updated_at', date('Y-m-d H:i:s'), 'whatsapp');

        $this->log('wa_health', 'WhatsApp account health: ' . $status . ' (' . $event . ')', ['detail' => $detail]);

        if ($status === self::HEALTH_OK) {
            return;
        }

        $paused = $this->pauseRunningCampaigns();

        if ($previous !== $status || $paused > 0) {
            try {
                model(NotificationModel::class)->notifyChatUsers(
                    $status === self::HEALTH_RESTRICTED ? 'WhatsApp account restricted by Meta' : 'WhatsApp quality warning from Meta',
                    trim($event . ': ' . $detail) . ($paused > 0 ? " {$paused} running campaign(s) paused." : ''),
                    site_url('campaigns'),
                    null,
                    'warning'
                );
            } catch (Throwable $e) {
                log_message('warning', 'Health notify failed: {msg}', ['msg' => $e->getMessage()]);
            }
        }
    }

    /**
     * Operator confirms they reviewed the Meta warning (Business Support Home).
     */
    public function acknowledgeHealth(): void
    {
        $health = $this->getHealth();
        if ($health['status'] === self::HEALTH_OK) {
            return;
        }
        $this->recordHealthEvent(
            self::HEALTH_OK,
            'ACKNOWLEDGED',
            'Reviewed by operator. Previous: ' . $health['event'] . ' ' . $health['detail']
        );
    }

    /**
     * Pull the number's quality rating from Meta (cached 30 min). RED flags the account.
     */
    public function refreshQualityRating(bool $force = false): string
    {
        $settings = service('settingsService');
        $cached   = (string) $settings->get('wa_quality_rating', '');
        $checked  = strtotime((string) $settings->get('wa_quality_checked_at', '')) ?: 0;
        if (! $force && $checked > time() - 1800) {
            return $cached;
        }

        $settings->set('wa_quality_checked_at', date('Y-m-d H:i:s'), 'whatsapp');
        if (! $settings->isMetaProvider()) {
            return $cached;
        }

        try {
            $info   = service('whatsApp')->getPhoneNumberInfo();
            $rating = strtoupper(trim((string) ($info['quality_rating'] ?? '')));
            $tier   = trim((string) ($info['messaging_limit'] ?? ''));
            if ($tier !== '') {
                $settings->set('wa_messaging_limit', $tier, 'whatsapp');
            }
        } catch (Throwable $e) {
            log_message('notice', 'Quality rating refresh skipped: {msg}', ['msg' => $e->getMessage()]);

            return $cached;
        }

        if ($rating === '') {
            return $cached;
        }

        $settings->set('wa_quality_rating', $rating, 'whatsapp');
        if ($rating === 'RED' && $this->getHealth()['status'] === self::HEALTH_OK) {
            $this->recordHealthEvent(
                self::HEALTH_FLAGGED,
                'QUALITY_RED',
                'Meta quality rating is RED: customers are blocking or reporting your messages.'
            );
        }

        return $rating;
    }

    /**
     * @throws RuntimeException While Meta has the account flagged or restricted.
     */
    public function assertCanStartCampaign(): void
    {
        $this->refreshQualityRating();
        $health = $this->getHealth();
        if ($health['status'] === self::HEALTH_OK) {
            return;
        }

        $what = $health['status'] === self::HEALTH_RESTRICTED
            ? 'Meta has restricted this WhatsApp account'
            : 'Meta flagged this WhatsApp number for low quality';

        throw new RuntimeException(
            self::POLICY_PREFIX . ' ' . $what . ' (' . $health['event'] . '). Review WhatsApp Manager → Business Support Home, '
            . 'fix the cause, then click "I have reviewed" on the Campaigns page before sending again.',
            423
        );
    }

    /**
     * "TIER_1K" → 1000, "TIER_250" → 250, "TIER_UNLIMITED" / unknown → null (no local cap).
     */
    public static function parseMessagingLimit(string $tier): ?int
    {
        $tier = strtoupper(trim($tier));
        if ($tier === '' || str_contains($tier, 'UNLIMITED')) {
            return null;
        }
        if (preg_match('/(\d+)\s*(K)?\b/', $tier, $m) !== 1) {
            return null;
        }

        return (int) $m[1] * (! empty($m[2]) ? 1000 : 1);
    }

    /**
     * Unique contacts that received a business-initiated template in the rolling 24h.
     */
    public function recentBusinessInitiatedContacts(?int $excludeCampaignId = null): int
    {
        $since = date('Y-m-d H:i:s', time() - 86400);
        $ids   = [];
        try {
            $db = db_connect();
            $q  = $db->table('campaign_contacts')->distinct()->select('contact_id')
                ->whereIn('status', ['sent', 'delivered', 'read'])
                ->where('sent_at >=', $since);
            if ($excludeCampaignId !== null && $excludeCampaignId > 0) {
                $q->where('campaign_id !=', $excludeCampaignId);
            }
            foreach ($q->get()->getResultArray() as $row) {
                $ids[(int) $row['contact_id']] = true;
            }
            $m = $db->table('messages')->distinct()->select('contact_id')
                ->where('direction', 'outbound')
                ->where('message_type', 'template')
                ->where('created_at >=', $since);
            if ($excludeCampaignId !== null && $excludeCampaignId > 0) {
                $m->groupStart()->where('campaign_id IS NULL')->orWhere('campaign_id !=', $excludeCampaignId)->groupEnd();
            }
            foreach ($m->get()->getResultArray() as $row) {
                $ids[(int) $row['contact_id']] = true;
            }
        } catch (Throwable $e) {
            log_message('notice', 'recentBusinessInitiatedContacts failed: {msg}', ['msg' => $e->getMessage()]);
        }

        return count($ids);
    }

    /**
     * Meta caps business-initiated conversations per 24h by messaging tier. Sending past
     * it fails en masse and drags quality down, so refuse the start with a clear number.
     *
     * @throws RuntimeException When the audience would exceed today's remaining limit.
     */
    public function assertWithinMessagingLimit(int $recipients, ?int $campaignId = null): void
    {
        $limit = self::parseMessagingLimit((string) service('settingsService')->get('wa_messaging_limit', ''));
        if ($limit === null || $recipients <= 0) {
            return;
        }

        $used      = $this->recentBusinessInitiatedContacts($campaignId);
        $remaining = max(0, $limit - $used);
        if ($recipients <= $remaining) {
            return;
        }

        throw new RuntimeException(
            self::POLICY_PREFIX . ' Meta messaging limit is ' . number_format($limit) . ' customers per 24h; '
            . number_format($used) . ' already messaged, ' . number_format($remaining) . ' left. '
            . 'This campaign has ' . number_format($recipients) . ' recipients — split the audience or send the rest tomorrow.',
            422
        );
    }

    /**
     * Mirror Meta template status / quality / category webhooks locally. A PAUSED or
     * DISABLED template pauses running campaigns that use it.
     *
     * @param array<string, mixed> $value
     */
    public function applyTemplateWebhook(string $field, array $value): void
    {
        $event    = strtoupper(trim((string) ($value['event'] ?? '')));
        $name     = (string) ($value['message_template_name'] ?? '');
        $template = $this->findTemplateFromWebhook($value);
        $model    = model(TemplateModel::class);
        $update   = [];

        if ($field === 'message_template_status_update' && $event !== '') {
            $update['status'] = $event === 'REINSTATED' ? 'APPROVED' : $event;
            $reason = trim((string) ($value['reason'] ?? $value['other_info']['description'] ?? ''));
            if ($reason !== '' && strtoupper($reason) !== 'NONE') {
                $update['rejected_reason'] = $reason;
            }
        }

        if ($field === 'template_category_update') {
            $category = strtoupper(trim((string) ($value['new_category'] ?? $value['correct_category'] ?? '')));
            if (in_array($category, ['MARKETING', 'UTILITY', 'AUTHENTICATION'], true)) {
                $update['category'] = $category;
                $this->notifyTemplate(
                    'WhatsApp template category changed',
                    'Meta changed "' . $name . '" from ' . strtoupper((string) ($value['previous_category'] ?? '?'))
                    . ' to ' . $category . '. Marketing templates go only to opted-in customers and cost more.'
                );
            }
        }

        if ($template !== null && $update !== []) {
            $model->update((int) $template['id'], $update);
        }

        if ($name === $this->config->consentTemplateName && in_array($event, ['APPROVED', 'REJECTED'], true)) {
            $this->notifyTemplate(
                $event === 'APPROVED' ? 'WhatsApp consent template approved' : 'WhatsApp consent template rejected',
                $event === 'APPROVED'
                    ? 'You can now send the Agree / Stop opt-in request from a contact\'s page ("Request opt-in").'
                    : 'Meta rejected "' . $name . '"' . (! empty($update['rejected_reason']) ? ' (' . $update['rejected_reason'] . ')' : '')
                        . '. Change consentTemplateBody and consentTemplateName in Config/WhatsApp.php; the new template is submitted automatically.'
            );
        }

        $quality = strtoupper((string) ($value['new_quality_score'] ?? ''));
        if ($quality === 'RED' || in_array($event, ['PAUSED', 'DISABLED', 'FLAGGED'], true)) {
            $paused = 0;
            if ($template !== null && in_array($event, ['PAUSED', 'DISABLED'], true)) {
                $paused = $this->pauseRunningCampaigns((int) $template['id']);
            }
            $this->notifyTemplate(
                'WhatsApp template needs attention',
                'Template "' . $name . '" is ' . ($quality !== '' ? $quality . ' quality' : strtolower($event))
                . '. Customers are blocking or reporting it — review the content before reuse.'
                . ($paused > 0 ? ' ' . $paused . ' running campaign(s) using it were paused.' : '')
            );
            $this->log('wa_template_quality', 'Template quality alert: ' . $name, $value);
        }
    }

    /**
     * @param array<string, mixed> $value
     *
     * @return array<string, mixed>|null
     */
    protected function findTemplateFromWebhook(array $value): ?array
    {
        $model  = model(TemplateModel::class);
        $metaId = trim((string) ($value['message_template_id'] ?? ''));
        if ($metaId !== '') {
            $row = $model->where('meta_id', $metaId)->first();
            if (is_array($row)) {
                return $row;
            }
        }
        $name = trim((string) ($value['message_template_name'] ?? ''));
        if ($name === '') {
            return null;
        }
        $model->where('name', $name);
        $lang = trim((string) ($value['message_template_language'] ?? ''));
        if ($lang !== '') {
            $model->where('language', $lang);
        }
        $row = $model->first();

        return is_array($row) ? $row : null;
    }

    protected function notifyTemplate(string $title, string $message): void
    {
        try {
            model(NotificationModel::class)->notifyChatUsers($title, $message, site_url('templates'), null, 'warning');
        } catch (Throwable) {
        }
    }

    /**
     * Handle Meta WABA webhook fields other than messages/statuses.
     *
     * @param array<string, mixed> $value
     */
    public function handleAccountWebhook(string $field, array $value): bool
    {
        $field = strtolower(trim($field));
        $event = strtoupper(trim((string) ($value['event'] ?? '')));

        if (in_array($field, ['phone_number_quality_update', 'business_capability_update'], true)) {
            $limit = (string) ($value['max_daily_conversations_per_business']
                ?? $value['current_limit']
                ?? $value['max_daily_conversation_per_phone']
                ?? '');
            if ($limit !== '') {
                service('settingsService')->set('wa_messaging_limit', $limit, 'whatsapp');
            }
            if ($field === 'business_capability_update') {
                return true;
            }
            $detail = trim('Number ' . (string) ($value['display_phone_number'] ?? '') . ' limit ' . $limit);
            if (in_array($event, ['FLAGGED', 'DOWNGRADE'], true)) {
                $this->recordHealthEvent(self::HEALTH_FLAGGED, 'QUALITY_' . $event, $detail);
            } elseif (in_array($event, ['UNFLAGGED', 'UPGRADE'], true) && $this->getHealth()['status'] === self::HEALTH_FLAGGED) {
                $this->recordHealthEvent(self::HEALTH_OK, 'QUALITY_' . $event, $detail);
            }

            return true;
        }

        if ($field === 'account_update') {
            $violation   = (string) ($value['violation_info']['violation_type'] ?? '');
            $banState    = strtoupper((string) ($value['ban_info']['waba_ban_state'] ?? ''));
            $restriction = [];
            foreach ((array) ($value['restriction_info'] ?? []) as $r) {
                if (is_array($r)) {
                    $restriction[] = trim((string) ($r['restriction_type'] ?? '') . ' until ' . (string) ($r['expiration'] ?? '?'));
                }
            }
            $detail = trim(implode('; ', array_filter([$violation, $banState, implode(', ', $restriction)])));

            if ($banState === 'REINSTATE') {
                $this->recordHealthEvent(self::HEALTH_OK, 'ACCOUNT_REINSTATED', $detail);
            } elseif (in_array($event, ['ACCOUNT_RESTRICTION', 'DISABLED_UPDATE', 'ACCOUNT_DELETED'], true)
                || in_array($banState, ['DISABLE', 'SCHEDULE_FOR_DISABLE'], true)) {
                $this->recordHealthEvent(self::HEALTH_RESTRICTED, $event !== '' ? $event : 'ACCOUNT_BANNED', $detail);
            } elseif ($event === 'ACCOUNT_VIOLATION') {
                $this->recordHealthEvent(self::HEALTH_FLAGGED, $event, $detail);
            }

            return true;
        }

        if (in_array($field, ['message_template_quality_update', 'message_template_status_update', 'template_category_update'], true)) {
            $this->applyTemplateWebhook($field, $value);

            return true;
        }

        return false;
    }

    /**
     * Pause running campaigns — all of them, or only those using one template.
     */
    protected function pauseRunningCampaigns(?int $templateId = null): int
    {
        $n = 0;
        try {
            $query = model(CampaignModel::class)->where('status', 'running');
            if ($templateId !== null) {
                $query->where('template_id', $templateId);
            }
            $running = $query->findAll();
            foreach ($running as $campaign) {
                try {
                    if (service('campaignService')->pause((int) $campaign['id'])) {
                        $n++;
                    }
                } catch (Throwable) {
                }
            }
        } catch (Throwable $e) {
            log_message('warning', 'pauseRunningCampaigns failed: {msg}', ['msg' => $e->getMessage()]);
        }

        return $n;
    }

    /**
     * @param array<string, mixed> $context
     */
    protected function log(string $action, string $message, array $context = []): void
    {
        try {
            (new ActivityLogger())->log($action, 'whatsapp_policy', $message, $context);
        } catch (Throwable) {
        }
    }
}
