<?php

namespace App\Libraries;

use App\Models\CampaignContactModel;
use App\Models\CampaignModel;
use App\Models\ContactModel;
use App\Models\TemplateModel;
use RuntimeException;

/**
 * Campaign lifecycle and recipient queueing for WhatsApp broadcasts.
 */
class CampaignService
{
    protected CampaignModel $campaigns;
    protected CampaignContactModel $campaignContacts;
    protected ContactModel $contacts;
    protected TemplateModel $templates;
    protected QueueService $queue;
    protected ActivityLogger $logger;
    protected WhatsAppConsentService $consent;

    /**
     * Policy exclusions from the most recent WhatsApp recipient resolution.
     *
     * @var array<string, int>
     */
    protected array $lastExclusions = [];

    /** Consent requests sent to "no opt-in" recipients during the last dispatch. */
    protected int $lastConsentRequested = 0;

    /** Why no consent request went out during the last dispatch ('' when sent or none needed). */
    protected string $lastConsentNote = '';

    public function __construct(
        ?CampaignModel $campaigns = null,
        ?CampaignContactModel $campaignContacts = null,
        ?ContactModel $contacts = null,
        ?TemplateModel $templates = null,
        ?QueueService $queue = null,
        ?ActivityLogger $logger = null,
        ?WhatsAppConsentService $consent = null
    ) {
        $this->campaigns        = $campaigns ?? model(CampaignModel::class);
        $this->campaignContacts = $campaignContacts ?? model(CampaignContactModel::class);
        $this->contacts         = $contacts ?? model(ContactModel::class);
        $this->templates        = $templates ?? model(TemplateModel::class);
        $this->queue            = $queue ?? new QueueService();
        $this->logger           = $logger ?? new ActivityLogger();
        $this->consent          = $consent ?? service('whatsAppConsent');
    }

    /**
     * Create a campaign draft.
     *
     * @param array<string, mixed> $data
     */
    public function create(array $data): int
    {
        $payload = [
            'name'            => (string) ($data['name'] ?? 'Untitled Campaign'),
            'template_id'     => $data['template_id'] ?? null,
            'status'          => 'draft',
            'message_type'    => (string) ($data['message_type'] ?? 'template'),
            'payload'         => isset($data['payload']) ? (is_string($data['payload']) ? $data['payload'] : json_encode($data['payload'])) : null,
            'variables'       => isset($data['variables']) ? (is_string($data['variables']) ? $data['variables'] : json_encode($data['variables'])) : null,
            'scheduled_at'    => $data['scheduled_at'] ?? null,
            'total_contacts'  => 0,
            'sent_count'      => 0,
            'delivered_count' => 0,
            'read_count'      => 0,
            'failed_count'    => 0,
            'reply_count'     => 0,
            'created_by'      => $data['created_by'] ?? session('user_id'),
        ];

        $id = $this->campaigns->insert($payload);
        if (! $id) {
            throw new RuntimeException('Failed to create campaign: ' . implode(', ', $this->campaigns->errors()));
        }

        $this->logger->log('create', 'campaigns', 'Campaign created: ' . $payload['name'], ['campaign_id' => $id]);

        return (int) $id;
    }

    /**
     * Schedule a campaign for a future start time.
     */
    public function schedule(int $campaignId, string $scheduledAt): bool
    {
        $campaign = $this->requireCampaign($campaignId);

        if (! in_array($campaign['status'], ['draft', 'paused', 'scheduled'], true)) {
            throw new RuntimeException('Campaign cannot be scheduled from status: ' . $campaign['status']);
        }

        $normalized = AppDateTime::localToStorage($scheduledAt);
        if ($normalized === null) {
            throw new RuntimeException('A valid scheduled_at datetime is required.');
        }

        $ok = (bool) $this->campaigns->update($campaignId, [
            'status'       => 'scheduled',
            'scheduled_at' => $normalized,
        ]);

        if ($ok) {
            $this->logger->log('schedule', 'campaigns', 'Campaign scheduled', [
                'campaign_id'  => $campaignId,
                'scheduled_at' => $normalized,
                'timezone'     => AppDateTime::timezone(),
            ]);
        }

        return $ok;
    }

    /**
     * Start a campaign: queue recipients and mark running.
     *
     * Cheerio + template campaigns use POST /v1/whatsapp/multiple (bulk).
     * Meta (and non-template) keep the local queue + per-message send path.
     *
     * @param list<int>|null $contactIds Optional explicit contact IDs
     * @param list<int>|null $tagIds     Optional tag filters
     */
    public function start(int $campaignId, ?array $contactIds = null, ?array $tagIds = null, ?bool $allActive = null): array
    {
        $campaign = $this->requireCampaign($campaignId);

        if (! in_array($campaign['status'], ['draft', 'scheduled', 'paused'], true)) {
            throw new RuntimeException('Campaign cannot be started from status: ' . $campaign['status']);
        }

        $audience = $this->audienceFromCampaign($campaign);
        if ($allActive === null && $contactIds === null && $tagIds === null) {
            $allActive  = $audience['all'];
            $contactIds = $audience['contact_ids'] !== [] ? $audience['contact_ids'] : null;
            $tagIds     = $audience['tag_ids'] !== [] ? $audience['tag_ids'] : null;
        }
        $allActive = (bool) ($allActive ?? false);

        if (! $allActive && ($contactIds === null || $contactIds === []) && ($tagIds === null || $tagIds === [])) {
            throw new RuntimeException('Select an audience (all contacts, specific contacts, or tags) before starting.');
        }

        $this->consent->assertCanStartCampaign();
        $this->assertTemplateSendable($campaign);

        $useCheerioBulk = $this->shouldDispatchViaCheerioBulk($campaign);

        // Bulk API sends cannot be postponed per message, so refuse to start inside quiet hours;
        // a scheduled campaign stays scheduled and starts on the next run after the window.
        $quietUntil = $useCheerioBulk ? (new WhatsAppSendWindow())->deferUntil() : null;
        if ($quietUntil !== null) {
            throw new RuntimeException(
                'Quiet hours are on (no marketing messages until ' . $quietUntil . '). Schedule this campaign for that time or later.',
                422
            );
        }

        if ($useCheerioBulk) {
            $queued = $this->dispatchCheerioBulkCampaign($campaignId, $contactIds, $tagIds, $allActive);
        } else {
            $queued = $this->queueRecipients($campaignId, $contactIds, $tagIds, $allActive);
            $this->assertHasEligibleRecipients($queued);

            $this->campaigns->update($campaignId, [
                'status'         => 'running',
                'started_at'     => date('Y-m-d H:i:s'),
                'total_contacts' => $queued['contacts'],
            ]);

            $this->logger->log('start', 'campaigns', 'Campaign started', [
                'campaign_id' => $campaignId,
                'queued'      => $queued,
            ]);

            // Flush immediately so "Send now" does not wait for cron / inbound webhook.
            $queuedCount = (int) ($queued['queued'] ?? 0);
            if ($queuedCount > 0) {
                try {
                    $stats = service('queueService')->processBatch(max(50, min(500, $queuedCount)));
                    $queued['sent']     = (int) ($stats['sent'] ?? 0);
                    $queued['failed']   = (int) ($stats['failed'] ?? 0);
                    $queued['deferred'] = (int) ($stats['deferred'] ?? 0);
                } catch (\Throwable $e) {
                    log_message('error', 'Campaign #{id} immediate queue flush failed: {msg}', [
                        'id'  => $campaignId,
                        'msg' => $e->getMessage(),
                    ]);
                    $queued['flush_error'] = $e->getMessage();
                }

                // Do not leave the campaign "Running" with retrying pending rows after Send now.
                $this->failRemainingCampaignQueue(
                    $campaignId,
                    'Send failed during campaign start (not retried automatically).'
                );
            }
        }

        if (method_exists($this->campaigns, 'updateStats')) {
            $this->campaigns->updateStats($campaignId);
        }

        $queued['completed'] = $this->completeIfFinished($campaignId);
        $fresh = $this->campaigns->find($campaignId);
        $queued['status'] = (string) ($fresh['status'] ?? 'running');
        if ($queued['status'] === 'completed') {
            $queued['completed'] = true;
        }
        $queued['sent']   = (int) ($fresh['sent_count'] ?? ($queued['sent'] ?? 0));
        $queued['failed'] = (int) ($fresh['failed_count'] ?? ($queued['failed'] ?? 0));
        $queued['excluded_summary'] = WhatsAppConsentService::describeExclusions($queued['excluded'] ?? []);
        if (! empty($queued['deferred'])) {
            $window = (new WhatsAppSendWindow())->deferUntil();
            $queued['excluded_summary'] .= ($queued['excluded_summary'] !== '' ? '; ' : '')
                . $queued['deferred'] . ' postponed'
                . ($window !== null ? ' (quiet hours, sends at ' . $window . ')' : ' (WhatsApp rate limit, retrying automatically)');
        }
        $queued['consent_requested'] = $this->lastConsentRequested;
        if ($this->lastConsentRequested > 0) {
            $queued['excluded_summary'] .= ($queued['excluded_summary'] !== '' ? '; ' : '')
                . 'consent request sent to ' . $this->lastConsentRequested;
        } elseif ($this->lastConsentNote !== '') {
            $queued['excluded_summary'] .= ($queued['excluded_summary'] !== '' ? '. ' : '')
                . 'Consent request not sent: ' . rtrim($this->lastConsentNote, '.');
        }

        // Fire "Campaign Sent" automations for recipients that actually got the campaign.
        $this->fireCampaignSentTriggers($campaignId);

        return $queued;
    }

    /**
     * Meta pauses / disables low-quality templates; sending them again only fails and
     * hurts the number further.
     *
     * @param array<string, mixed> $campaign
     */
    protected function assertTemplateSendable(array $campaign): void
    {
        if ((string) ($campaign['message_type'] ?? 'template') !== 'template' || empty($campaign['template_id'])) {
            return;
        }

        $template = $this->templates->find((int) $campaign['template_id']);
        if (! is_array($template)) {
            throw new RuntimeException(
                WhatsAppConsentService::POLICY_PREFIX . ' The campaign template no longer exists. Sync templates and select an APPROVED template.',
                422
            );
        }

        try {
            (new WhatsAppTemplateSendGuard())->assertApproved($template);
        } catch (RuntimeException $e) {
            throw new RuntimeException(
                WhatsAppConsentService::POLICY_PREFIX . ' ' . $e->getMessage() . ' Use an APPROVED template (sync templates if you just fixed it).',
                422
            );
        }
    }

    /**
     * Refuse to start when policy filters removed every recipient, so the operator
     * sees why instead of a silent empty "completed" campaign.
     *
     * @param array<string, mixed> $queued
     */
    protected function assertHasEligibleRecipients(array $queued): void
    {
        if ((int) ($queued['contacts'] ?? 0) > 0) {
            return;
        }

        $summary = WhatsAppConsentService::describeExclusions($queued['excluded'] ?? []);
        throw new RuntimeException(
            'No eligible WhatsApp recipients. ' . ($summary !== '' ? $summary . '. ' : '')
            . ($this->lastConsentRequested > 0
                ? 'WhatsApp consent request (Agree / Stop) sent to ' . $this->lastConsentRequested . ' contact(s); they will receive campaigns after tapping Agree. '
                : ($this->lastConsentNote !== '' ? 'Consent request not sent: ' . $this->lastConsentNote . ' ' : ''))
            . 'Only contacts with a recorded WhatsApp opt-in who have not opted out can receive campaigns.'
        );
    }

    /**
     * Audience for a WhatsApp campaign after policy filtering (opt-in, opt-out,
     * suppression, 24h frequency cap, duplicate mobiles).
     *
     * @param list<int>|null $contactIds
     * @param list<int>|null $tagIds
     *
     * @return list<array<string, mixed>>
     */
    protected function resolveWhatsAppRecipients(?int $campaignId, ?array $contactIds, ?array $tagIds, bool $allActive, bool $requestConsent = false): array
    {
        $split = $this->consent->splitCampaignAudience(
            $this->resolveContacts($contactIds, $tagIds, $allActive),
            $campaignId
        );
        $this->lastExclusions       = $split['excluded'];
        $this->lastConsentRequested = $requestConsent
            ? $this->consent->requestConsentForContacts($split['pending_consent'] ?? [])
            : 0;
        $this->lastConsentNote = $requestConsent && $this->lastConsentRequested === 0
            ? $this->consent->lastConsentError()
            : '';

        return $split['eligible'];
    }

    /**
     * Cheerio template campaigns should use the native bulk/campaign API.
     *
     * @param array<string, mixed> $campaign
     */
    protected function shouldDispatchViaCheerioBulk(array $campaign): bool
    {
        $messageType = (string) ($campaign['message_type'] ?? 'template');
        if ($messageType !== 'template') {
            return false;
        }

        return (new SettingsService())->isCheerioProvider();
    }

    /**
     * Dispatch a template campaign via Cheerio POST /v1/whatsapp/multiple.
     *
     * @param list<int>|null $contactIds
     * @param list<int>|null $tagIds
     *
     * @return array{contacts: int, queued: int, sent?: int, failed?: int, batches?: int, flush_error?: string}
     */
    protected function dispatchCheerioBulkCampaign(
        int $campaignId,
        ?array $contactIds,
        ?array $tagIds,
        bool $allActive
    ): array {
        $campaign = $this->requireCampaign($campaignId);
        $contacts = $this->resolveWhatsAppRecipients($campaignId, $contactIds, $tagIds, $allActive, true);
        $excluded = $this->lastExclusions;
        $this->assertHasEligibleRecipients(['contacts' => count($contacts), 'excluded' => $excluded]);
        $this->consent->assertWithinMessagingLimit(count($contacts), $campaignId);

        $basePayload = $this->buildSendPayload($campaign);
        $variableMap = $this->decodeVariables($campaign['variables'] ?? null);
        $templateName = trim((string) ($basePayload['template_name'] ?? $basePayload['name'] ?? ''));
        $language     = trim((string) ($basePayload['language'] ?? 'en'));
        if ($language === '') {
            $language = 'en';
        }

        if ($templateName === '') {
            throw new RuntimeException('Cheerio bulk campaign requires an approved template.');
        }

        $templateRow = ! empty($campaign['template_id'])
            ? $this->templates->find((int) $campaign['template_id'])
            : null;
        $headerType  = WhatsAppTemplatePayload::headerTypeFromTemplate(
            is_array($templateRow) ? $templateRow : null
        );

        // Dedicated Cheerio client — never mutate the shared Meta/Cheerio facade.
        $api            = new CheerioDirectAPI();
        $recipients     = [];
        $contactByPhone = [];
        $skippedNoPhone = 0;

        foreach ($contacts as $contact) {
            $contactId = (int) ($contact['id'] ?? 0);
            $phone     = $api->normalizePhone((string) ($contact['mobile'] ?? ''));

            $existing = $this->campaignContacts
                ->where('campaign_id', $campaignId)
                ->where('contact_id', $contactId)
                ->first();

            if ($existing === null) {
                $this->campaignContacts->insert([
                    'campaign_id' => $campaignId,
                    'contact_id'  => $contactId,
                    'status'      => 'queued',
                ]);
            } else {
                $this->campaignContacts->update((int) $existing['id'], ['status' => 'queued']);
            }

            if ($phone === '') {
                $skippedNoPhone++;
                $this->markCampaignContactStatus($campaignId, $contactId, 'failed', 'Contact has no valid mobile number.');
                continue;
            }

            try {
                $components = $this->buildTemplateComponents(
                    $variableMap,
                    $contact,
                    $basePayload['components'] ?? null,
                    is_array($templateRow) ? $templateRow : null
                );
            } catch (RuntimeException $e) {
                $skippedNoPhone++;
                $this->markCampaignContactStatus($campaignId, $contactId, 'failed', $e->getMessage());
                continue;
            }
            $components = WhatsAppTemplatePayload::mergeHeaderFromPayload(
                $components,
                $basePayload,
                $headerType
            );

            $recipients[] = [
                'to'         => $phone,
                'components' => $components,
            ];
            $contactByPhone[$phone] = $contactId;
        }

        $this->campaigns->update($campaignId, [
            'status'         => 'running',
            'started_at'     => date('Y-m-d H:i:s'),
            'total_contacts' => count($contacts),
        ]);

        $result = [
            'contacts' => count($contacts),
            'queued'   => count($recipients),
            'sent'     => 0,
            'failed'   => $skippedNoPhone,
            'batches'  => 0,
            'dispatch' => 'cheerio_bulk',
            'excluded' => $excluded,
        ];

        $this->logger->log('start', 'campaigns', 'Campaign started via Cheerio bulk API', [
            'campaign_id' => $campaignId,
            'recipients'  => count($recipients),
            'skipped'     => $skippedNoPhone,
        ]);

        if ($recipients === []) {
            return $result;
        }

        $campaignName = trim((string) ($campaign['name'] ?? '')) !== ''
            ? (string) $campaign['name']
            : ('campaign-' . $campaignId);

        try {
            $bulk = $api->sendBulkCampaign($campaignName, $templateName, $language, $recipients);
            $result['batches'] = (int) ($bulk['batches'] ?? 0);
            $result['sent']    = (int) ($bulk['recipient_count'] ?? count($recipients));

            foreach ($contactByPhone as $contactId) {
                $this->markCampaignContactStatus($campaignId, $contactId, 'sent');
            }
        } catch (\Throwable $e) {
            log_message('error', 'Campaign #{id} Cheerio bulk send failed: {msg}', [
                'id'  => $campaignId,
                'msg' => $e->getMessage(),
            ]);
            $result['flush_error'] = $e->getMessage();
            $result['failed']      = count($recipients) + $skippedNoPhone;
            $result['sent']        = 0;

            foreach ($contactByPhone as $contactId) {
                $this->markCampaignContactStatus($campaignId, $contactId, 'failed', $e->getMessage());
            }
        }

        return $result;
    }

    protected function markCampaignContactStatus(
        int $campaignId,
        int $contactId,
        string $status,
        ?string $error = null
    ): void {
        $row = $this->campaignContacts
            ->where('campaign_id', $campaignId)
            ->where('contact_id', $contactId)
            ->first();

        if ($row === null) {
            return;
        }

        $data = ['status' => $status];
        if ($status === 'sent') {
            $data['sent_at'] = date('Y-m-d H:i:s');
        }
        if ($error !== null && $error !== '') {
            $data['error_message'] = $error;
        }

        $this->campaignContacts->update((int) $row['id'], $data);
    }

    /**
     * After an interactive Send now flush, convert leftover pending rows to failed
     * so the campaign can move to completed instead of sitting in Running.
     */
    protected function failRemainingCampaignQueue(int $campaignId, string $error): void
    {
        $db = db_connect();
        $pending = $db->table('message_queue')
            ->where('campaign_id', $campaignId)
            ->whereIn('status', ['pending', 'processing'])
            ->get()
            ->getResultArray();

        foreach ($pending as $row) {
            $previous = trim((string) ($row['error_message'] ?? ''));

            // Leave rows that were never attempted or are deliberately postponed (quiet hours,
            // WhatsApp rate limit back-off) for the queue worker instead of failing them.
            if ((string) ($row['status'] ?? '') === 'pending'
                && ((int) ($row['attempts'] ?? 0) === 0
                    || str_starts_with($previous, WhatsAppSendWindow::DEFER_PREFIX)
                    || WhatsAppConsentService::isRateLimitError($previous))) {
                continue;
            }
            // Prefer the real provider/validation error over the generic flush message.
            $fullError = $previous !== '' ? $previous : $error;
            if ($previous !== '' && ! str_contains($previous, 'campaign start')) {
                $fullError = $previous;
            } elseif ($previous !== '') {
                $fullError = $error . ' Previous: ' . $previous;
            }

            $db->table('message_queue')->where('id', (int) $row['id'])->update([
                'status'        => 'failed',
                'error_message' => $fullError,
                'processed_at'  => date('Y-m-d H:i:s'),
                'updated_at'    => date('Y-m-d H:i:s'),
            ]);

            $contactId = (int) ($row['contact_id'] ?? 0);
            if ($contactId > 0) {
                $cc = $this->campaignContacts
                    ->where('campaign_id', $campaignId)
                    ->where('contact_id', $contactId)
                    ->first();
                if (is_array($cc) && in_array((string) ($cc['status'] ?? ''), ['queued', 'pending', 'processing'], true)) {
                    $this->campaignContacts->update((int) $cc['id'], [
                        'status'        => 'failed',
                        'error_message' => $fullError,
                    ]);
                }
            }
        }
    }

    /**
     * Mark a running campaign completed when no queue work remains.
     */
    public function completeIfFinished(int $campaignId): bool
    {
        $campaign = $this->campaigns->find($campaignId);
        if ($campaign === null || (string) ($campaign['status'] ?? '') !== 'running') {
            return false;
        }

        $remaining = db_connect()->table('message_queue')
            ->where('campaign_id', $campaignId)
            ->whereIn('status', ['pending', 'processing'])
            ->countAllResults();

        if ($remaining > 0) {
            return false;
        }

        // Still running with zero recipients queued → keep running only if nothing was attempted.
        $total = (int) ($campaign['total_contacts'] ?? 0);
        if ($total <= 0) {
            $total = db_connect()->table('campaign_contacts')
                ->where('campaign_id', $campaignId)
                ->countAllResults();
        }
        if ($total <= 0) {
            return false;
        }

        if (method_exists($this->campaigns, 'updateStats')) {
            $this->campaigns->updateStats($campaignId);
        }

        return (bool) $this->campaigns->update($campaignId, [
            'status'       => 'completed',
            'completed_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Complete every running campaign that has drained its queue.
     *
     * @return int Number marked completed
     */
    public function completeFinishedCampaigns(): int
    {
        $done = 0;
        $running = $this->campaigns->where('status', 'running')->findAll();
        foreach ($running as $campaign) {
            if ($this->completeIfFinished((int) $campaign['id'])) {
                $done++;
            }
        }

        return $done;
    }

    /**
     * Notify automation engine for recipients the campaign was actually sent to
     * (never for skipped / opted-out / failed contacts).
     */
    protected function fireCampaignSentTriggers(int $campaignId): void
    {
        try {
            $sentIds = array_map(
                'intval',
                array_column(
                    db_connect()->table('campaign_contacts')
                        ->select('contact_id')
                        ->where('campaign_id', $campaignId)
                        ->whereIn('status', ['sent', 'delivered', 'read'])
                        ->get()
                        ->getResultArray(),
                    'contact_id'
                )
            );
            if ($sentIds === []) {
                return;
            }
            $contacts = $this->contacts->whereIn('id', $sentIds)->findAll();
            $engine   = service('automationEngine');
            $limit    = 500;
            $n        = 0;
            foreach ($contacts as $contact) {
                if ($n >= $limit) {
                    break;
                }
                $engine->processTrigger('campaign_sent', [
                    'contact_id'  => (int) $contact['id'],
                    'contact'     => $contact,
                    'campaign_id' => $campaignId,
                ]);
                $n++;
            }
        } catch (\Throwable $e) {
            log_message('error', 'campaign_sent triggers failed: {msg}', ['msg' => $e->getMessage()]);
        }
    }

    /**
     * Persist audience selection inside campaign payload for later scheduled starts.
     *
     * @param array{all?: bool, contact_ids?: list<int>, tag_ids?: list<int>} $audience
     */
    public function saveAudience(int $campaignId, array $audience): void
    {
        $campaign = $this->requireCampaign($campaignId);
        $payload  = [];
        if (! empty($campaign['payload'])) {
            $decoded = is_string($campaign['payload'])
                ? json_decode($campaign['payload'], true)
                : $campaign['payload'];
            if (is_array($decoded)) {
                $payload = $decoded;
            }
        }

        $existingAudience = is_array($payload['_audience'] ?? null) ? $payload['_audience'] : [];

        $payload['_audience'] = array_merge($existingAudience, [
            'all'         => ! empty($audience['all']),
            'contact_ids' => array_values(array_map('intval', $audience['contact_ids'] ?? ($existingAudience['contact_ids'] ?? []))),
            'tag_ids'     => array_values(array_map('intval', $audience['tag_ids'] ?? ($existingAudience['tag_ids'] ?? []))),
        ]);

        if (isset($audience['label_id'])) {
            $payload['_audience']['label_id'] = (int) $audience['label_id'];
        }
        if (! empty($audience['label_name'])) {
            $payload['_audience']['label_name'] = (string) $audience['label_name'];
        }
        if (isset($audience['attributes'])) {
            $payload['_audience']['attributes'] = $audience['attributes'];
        }

        $this->campaigns->update($campaignId, ['payload' => $payload]);
    }

    public function pause(int $campaignId): bool
    {
        $campaign = $this->requireCampaign($campaignId);
        if ($campaign['status'] !== 'running') {
            throw new RuntimeException('Only running campaigns can be paused.');
        }

        // Cancel pending queue items for this campaign
        db_connect()->table('message_queue')
            ->where('campaign_id', $campaignId)
            ->where('status', 'pending')
            ->update(['status' => 'cancelled']);

        $ok = (bool) $this->campaigns->update($campaignId, ['status' => 'paused']);
        if ($ok) {
            $this->logger->log('pause', 'campaigns', 'Campaign paused', ['campaign_id' => $campaignId]);
        }

        return $ok;
    }

    public function resume(int $campaignId): bool
    {
        $campaign = $this->requireCampaign($campaignId);
        if ($campaign['status'] !== 'paused') {
            throw new RuntimeException('Only paused campaigns can be resumed.');
        }

        $this->consent->assertCanStartCampaign();
        $this->assertTemplateSendable($campaign);

        // Re-queue cancelled items that were never sent
        db_connect()->table('message_queue')
            ->where('campaign_id', $campaignId)
            ->where('status', 'cancelled')
            ->update([
                'status'       => 'pending',
                'scheduled_at' => date('Y-m-d H:i:s'),
            ]);

        $ok = (bool) $this->campaigns->update($campaignId, ['status' => 'running']);
        if ($ok) {
            $this->logger->log('resume', 'campaigns', 'Campaign resumed', ['campaign_id' => $campaignId]);
        }

        return $ok;
    }

    public function cancel(int $campaignId): bool
    {
        $campaign = $this->requireCampaign($campaignId);
        if (in_array($campaign['status'], ['completed', 'cancelled'], true)) {
            throw new RuntimeException('Campaign is already ' . $campaign['status']);
        }

        db_connect()->table('message_queue')
            ->where('campaign_id', $campaignId)
            ->whereIn('status', ['pending', 'processing'])
            ->update(['status' => 'cancelled']);

        $ok = (bool) $this->campaigns->update($campaignId, [
            'status'       => 'cancelled',
            'completed_at' => date('Y-m-d H:i:s'),
        ]);

        if ($ok) {
            $this->logger->log('cancel', 'campaigns', 'Campaign cancelled', ['campaign_id' => $campaignId]);
        }

        return $ok;
    }

    /**
     * Build queue items for campaign recipients from contacts / tags.
     *
     * @param list<int>|null $contactIds
     * @param list<int>|null $tagIds
     *
     * @return array{contacts: int, queued: int, excluded: array<string, int>}
     */
    public function queueRecipients(int $campaignId, ?array $contactIds = null, ?array $tagIds = null, bool $allActive = false): array
    {
        $campaign = $this->requireCampaign($campaignId);
        $contacts = $this->resolveWhatsAppRecipients($campaignId, $contactIds, $tagIds, $allActive, true);
        $excluded = $this->lastExclusions;
        $messageType = (string) ($campaign['message_type'] ?? 'template');
        if ($messageType === 'template') {
            $this->consent->assertWithinMessagingLimit(count($contacts), $campaignId);
        }

        $basePayload = $this->buildSendPayload($campaign);
        $variableMap = $this->decodeVariables($campaign['variables'] ?? null);
        $templateRow = ! empty($campaign['template_id'])
            ? $this->templates->find((int) $campaign['template_id'])
            : null;
        $headerType  = WhatsAppTemplatePayload::headerTypeFromTemplate(
            is_array($templateRow) ? $templateRow : null
        );
        $queued      = 0;
        $db          = db_connect();

        foreach ($contacts as $contact) {
            $contactId = (int) $contact['id'];

            // Skip if already queued/sent for this campaign (restart / double-start safe)
            $already = $db->table('message_queue')
                ->where('campaign_id', $campaignId)
                ->where('contact_id', $contactId)
                ->whereIn('status', ['pending', 'processing', 'sent'])
                ->countAllResults();
            if ($already > 0) {
                continue;
            }

            $existing = $this->campaignContacts
                ->where('campaign_id', $campaignId)
                ->where('contact_id', $contactId)
                ->first();

            if ($existing === null) {
                $this->campaignContacts->insert([
                    'campaign_id' => $campaignId,
                    'contact_id'  => $contactId,
                    'status'      => 'queued',
                ]);
            } else {
                $this->campaignContacts->update((int) $existing['id'], ['status' => 'queued']);
            }

            $payload = $basePayload;
            if ($messageType === 'template') {
                try {
                    $components = $this->buildTemplateComponents(
                        $variableMap,
                        $contact,
                        $payload['components'] ?? null,
                        is_array($templateRow) ? $templateRow : null
                    );
                } catch (RuntimeException $e) {
                    $this->markCampaignContactStatus($campaignId, $contactId, 'failed', $e->getMessage());
                    continue;
                }
                $payload['components'] = WhatsAppTemplatePayload::mergeHeaderFromPayload(
                    $components,
                    $basePayload,
                    $headerType
                );
            }

            $this->queue->enqueue(
                $contactId,
                $messageType,
                $payload,
                $campaignId,
                5
            );
            $queued++;
        }

        $this->campaigns->update($campaignId, ['total_contacts' => count($contacts)]);

        return ['contacts' => count($contacts), 'queued' => $queued, 'excluded' => $excluded];
    }

    /**
     * Resolve tag/contact audience and apply optional attribute filters.
     *
     * @param list<int> $tagIds
     * @param list<int> $contactIds
     * @param list<array{name?:string,condition?:string,value?:string}> $attributes
     *
     * @return array{
     *     contacts: list<array<string,mixed>>,
     *     contact_ids: list<int>,
     *     phone_count: int,
     *     email_count: int,
     *     total: int,
     *     sample: list<array<string,mixed>>
     * }
     */
    public function previewAudience(array $tagIds = [], array $contactIds = [], array $attributes = [], bool $allActive = false): array
    {
        $contacts = $this->resolveContacts(
            $contactIds !== [] ? $contactIds : null,
            $tagIds !== [] ? $tagIds : null,
            $allActive
        );
        $contacts = $this->filterContactsByAttributes($contacts, $attributes);

        $phoneCount = 0;
        $emailCount = 0;
        $ids        = [];
        foreach ($contacts as $contact) {
            $ids[] = (int) $contact['id'];
            if (trim((string) ($contact['mobile'] ?? '')) !== '') {
                $phoneCount++;
            }
            if (trim((string) ($contact['email'] ?? '')) !== '' && filter_var((string) $contact['email'], FILTER_VALIDATE_EMAIL)) {
                $emailCount++;
            }
        }

        $sample = array_slice(array_map(static fn (array $c): array => [
            'id'     => (int) ($c['id'] ?? 0),
            'name'   => (string) ($c['name'] ?? ''),
            'mobile' => (string) ($c['mobile'] ?? ''),
            'email'  => (string) ($c['email'] ?? ''),
        ], $contacts), 0, 10);

        $waSplit = $this->consent->splitCampaignAudience($contacts);

        $eligibleList = array_map(static fn (array $c): array => [
            'id'        => (int) ($c['id'] ?? 0),
            'name'      => (string) ($c['name'] ?? ''),
            'mobile'    => (string) ($c['mobile'] ?? ''),
            'email'     => (string) ($c['email'] ?? ''),
            'status'    => 'eligible',
            'reason'    => 'Ready to send (WhatsApp opt-in recorded)',
        ], $waSplit['eligible']);

        return [
            'contacts'          => $contacts,
            'contact_ids'       => $ids,
            'phone_count'       => $phoneCount,
            'email_count'       => $emailCount,
            'total'             => count($contacts),
            'sample'            => $sample,
            'wa_eligible_count' => count($waSplit['eligible']),
            'wa_excluded'       => $waSplit['excluded'],
            'wa_excluded_text'  => WhatsAppConsentService::describeExclusions($waSplit['excluded']),
            'wa_eligible_list'  => $eligibleList,
            'wa_excluded_list'  => $waSplit['excluded_contacts'] ?? [],
        ];
    }

    /**
     * @param list<array<string, mixed>> $contacts
     * @param list<array{name?:string,condition?:string,value?:string}> $attributes
     *
     * @return list<array<string, mixed>>
     */
    public function filterContactsByAttributes(array $contacts, array $attributes): array
    {
        $rules = [];
        foreach ($attributes as $row) {
            if (! is_array($row)) {
                continue;
            }
            $name = trim((string) ($row['name'] ?? $row['attribute'] ?? ''));
            $value = trim((string) ($row['value'] ?? ''));
            $condition = strtolower(trim((string) ($row['condition'] ?? 'equals')));
            if ($name === '' || $value === '') {
                continue;
            }
            if (! in_array($condition, ['equals', 'contains', 'not_equals', 'starts_with'], true)) {
                $condition = 'equals';
            }
            $rules[] = compact('name', 'value', 'condition');
        }

        if ($rules === []) {
            return array_values($contacts);
        }

        $out = [];
        foreach ($contacts as $contact) {
            if ($this->contactMatchesAttributes($contact, $rules)) {
                $out[] = $contact;
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $contact
     * @param list<array{name:string,value:string,condition:string}> $rules
     */
    protected function contactMatchesAttributes(array $contact, array $rules): bool
    {
        $custom = $contact['custom_fields'] ?? [];
        if (is_string($custom)) {
            $decoded = json_decode($custom, true);
            $custom  = is_array($decoded) ? $decoded : [];
        }
        if (! is_array($custom)) {
            $custom = [];
        }

        $customLower = array_change_key_case($custom, CASE_LOWER);
        foreach ($rules as $rule) {
            $field = $rule['name'];
            $lower = strtolower($field);
            $haystack = match ($lower) {
                'name'   => (string) ($contact['name'] ?? ''),
                'mobile', 'phone' => (string) ($contact['mobile'] ?? ''),
                'email'  => (string) ($contact['email'] ?? ''),
                'status' => (string) ($contact['status'] ?? ''),
                default  => (string) ($custom[$field] ?? $customLower[$lower] ?? (in_array($lower, ContactAttributes::coreKeys(), true) ? ($contact[$lower] ?? '') : '')),
            };

            $needle = $rule['value'];
            $ok     = match ($rule['condition']) {
                'contains'    => stripos($haystack, $needle) !== false,
                'starts_with' => stripos($haystack, $needle) === 0,
                'not_equals'  => strcasecmp($haystack, $needle) !== 0,
                default       => strcasecmp($haystack, $needle) === 0,
            };

            if (! $ok) {
                return false;
            }
        }

        return true;
    }

    /**
     * Preview campaign recipient count and sample payload.
     *
     * @param list<int>|null $contactIds
     * @param list<int>|null $tagIds
     *
     * @return array{recipient_count: int, sample: list<array<string, mixed>>, payload: array<string, mixed>}
     */
    public function preview(int $campaignId, ?array $contactIds = null, ?array $tagIds = null, bool $allActive = false): array
    {
        $campaign = $this->requireCampaign($campaignId);
        if (! $allActive && ($contactIds === null || $contactIds === []) && ($tagIds === null || $tagIds === [])) {
            $audience   = $this->audienceFromCampaign($campaign);
            $allActive  = $audience['all'];
            $contactIds = $audience['contact_ids'] !== [] ? $audience['contact_ids'] : null;
            $tagIds     = $audience['tag_ids'] !== [] ? $audience['tag_ids'] : null;
        }
        $contacts = $this->resolveWhatsAppRecipients($campaignId, $contactIds, $tagIds, $allActive);

        $sample = array_slice(array_map(static fn (array $c): array => [
            'id'     => $c['id'],
            'name'   => $c['name'] ?? '',
            'mobile' => $c['mobile'] ?? '',
        ], $contacts), 0, 10);

        return [
            'recipient_count' => count($contacts),
            'sample'          => $sample,
            'payload'         => $this->buildSendPayload($campaign),
            'excluded'        => $this->lastExclusions,
            'excluded_text'   => WhatsAppConsentService::describeExclusions($this->lastExclusions),
        ];
    }

    /**
     * Process scheduled campaigns whose start time has arrived.
     *
     * @return int Number of campaigns started
     */
    public function processScheduled(): int
    {
        $now = date('Y-m-d H:i:s');
        $due = $this->campaigns
            ->where('status', 'scheduled')
            ->where('scheduled_at <=', $now)
            ->findAll();

        $started = 0;
        foreach ($due as $campaign) {
            try {
                $this->start((int) $campaign['id']);
                $started++;
            } catch (RuntimeException $e) {
                log_message('error', 'Failed to start scheduled campaign {id}: {msg}', [
                    'id'  => $campaign['id'],
                    'msg' => $e->getMessage(),
                ]);
            }
        }

        return $started;
    }

    /**
     * @return array<string, mixed>
     */
    protected function requireCampaign(int $campaignId): array
    {
        $campaign = $this->campaigns->find($campaignId);
        if ($campaign === null) {
            throw new RuntimeException('Campaign not found: ' . $campaignId);
        }

        return $campaign;
    }

    /**
     * @param list<int>|null $contactIds
     * @param list<int>|null $tagIds
     *
     * @return list<array<string, mixed>>
     */
    protected function resolveContacts(?array $contactIds, ?array $tagIds, bool $allActive = false): array
    {
        $hasContacts = $contactIds !== null && $contactIds !== [];
        $hasTags     = $tagIds !== null && $tagIds !== [];

        // Never default to "all contacts" unless explicitly requested.
        if (! $allActive && ! $hasContacts && ! $hasTags) {
            return [];
        }

        $builder = $this->contacts->builder();
        $builder->where('contacts.status', 'active');
        $builder->where('contacts.deleted_at', null);

        if ($hasContacts) {
            $builder->whereIn('contacts.id', array_map('intval', $contactIds));
        }

        if ($hasTags) {
            $builder->join('contact_tags', 'contact_tags.contact_id = contacts.id')
                ->whereIn('contact_tags.tag_id', array_map('intval', $tagIds))
                ->groupBy('contacts.id');
        }

        return $builder->get()->getResultArray();
    }

    /**
     * @param array<string, mixed> $campaign
     *
     * @return array{all: bool, contact_ids: list<int>, tag_ids: list<int>}
     */
    protected function audienceFromCampaign(array $campaign): array
    {
        $payload = [];
        if (! empty($campaign['payload'])) {
            $decoded = is_string($campaign['payload'])
                ? json_decode($campaign['payload'], true)
                : $campaign['payload'];
            if (is_array($decoded)) {
                $payload = $decoded;
            }
        }

        $audience = is_array($payload['_audience'] ?? null) ? $payload['_audience'] : [];

        return [
            'all'         => ! empty($audience['all']),
            'contact_ids' => array_values(array_map('intval', $audience['contact_ids'] ?? [])),
            'tag_ids'     => array_values(array_map('intval', $audience['tag_ids'] ?? [])),
        ];
    }

    /**
     * @param array<string, mixed> $campaign
     *
     * @return array<string, mixed>
     */
    protected function buildSendPayload(array $campaign): array
    {
        $payload = [];
        if (! empty($campaign['payload'])) {
            $decoded = is_string($campaign['payload'])
                ? json_decode($campaign['payload'], true)
                : $campaign['payload'];
            if (is_array($decoded)) {
                $payload = $decoded;
            }
        }

        unset($payload['_audience']);

        $type = (string) ($campaign['message_type'] ?? 'template');

        if ($type === 'template' && empty($payload['template_name']) && ! empty($campaign['template_id'])) {
            $template = $this->templates->find((int) $campaign['template_id']);
            if ($template !== null) {
                $payload['template_name'] = $template['name'];
                $payload['language']      = $template['language'] ?? 'en_US';
                $payload['name']          = $template['name'];
            }
        }

        // Keep raw variable map for per-contact resolution at enqueue time.
        // Do NOT assign field maps directly as template components.
        $payload['type'] = $type;

        return $payload;
    }

    /**
     * @param mixed $raw
     *
     * @return array<string, mixed>
     */
    protected function decodeVariables(mixed $raw): array
    {
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);

            return is_array($decoded) ? $decoded : [];
        }

        return is_array($raw) ? $raw : [];
    }

    /**
     * Template components for one contact from already-resolved variable values
     * (used by workflows). Throws RuntimeException when a required value is empty.
     *
     * @param array<string, string>     $values   placeholder key => final text
     * @param array<string, mixed>      $contact
     * @param list<array<string,mixed>> $existing extra components (e.g. media header)
     *
     * @return list<array<string, mixed>>
     */
    public function templateComponentsForContact(string $templateName, string $language, array $values, array $contact, array $existing = []): array
    {
        $template = $this->templates->where('name', $templateName)->where('language', $language)->first()
            ?? $this->templates->where('name', $templateName)->first();
        $map = [];
        foreach ($values as $key => $text) {
            $map[(string) $key] = ['text' => (string) $text];
        }

        return $this->buildTemplateComponents($map, $contact, $existing !== [] ? $existing : null, is_array($template) ? $template : null);
    }

    /**
     * Build WhatsApp template components from campaign variable mappings.
     *
     * Accepts maps like:
     * - {"1":"name","2":"custom text"}
     * - {"1":"{{name}}","2":"Hello"}
     * - already-valid template components list
     *
     * Both Meta Graph and Cheerio Direct use the same component object shape
     * (header / body / button + parameters). Provider envelopes differ only at send time.
     *
     * @param array<string, mixed>      $variableMap
     * @param array<string, mixed>      $contact
     * @param list<array<string,mixed>>|array<string,mixed>|null $existing
     *
     * @return list<array<string, mixed>>
     */
    protected function buildTemplateComponents(
        array $variableMap,
        array $contact,
        mixed $existing = null,
        ?array $template = null
    ): array {
        // The wizard stores a HEADER-only component for media templates; that must
        // not suppress the BODY parameters the template still expects.
        $prebuilt = is_array($existing) && $existing !== [] && $this->looksLikeMetaComponents($existing)
            ? array_values(array_filter($existing, 'is_array'))
            : [];

        if ($this->hasBodyComponent($prebuilt)) {
            return $prebuilt;
        }

        if ($this->looksLikeMetaComponents($variableMap)) {
            return array_values($variableMap);
        }

        // Meta rejects the send (#132000) unless the BODY parameter count matches
        // the approved template exactly, so the template placeholders — not the
        // saved campaign map — decide how many parameters are sent.
        $definitions = $template !== null
            ? WhatsAppTemplateVariables::definitionsForTemplate(
                $template['variables'] ?? null,
                (string) ($template['body'] ?? ''),
                $template['raw_payload'] ?? null
            )
            : [];

        // A known template with no placeholders must send no BODY parameters,
        // even when an outdated campaign map still holds values.
        $parameters = $template !== null
            ? $this->parametersFromDefinitions($definitions, $variableMap, $contact)
            : $this->parametersFromVariableMap($variableMap, $contact);

        if ($parameters === []) {
            return $prebuilt;
        }

        $prebuilt[] = [
            'type'       => 'body',
            'parameters' => $parameters,
        ];

        return $prebuilt;
    }

    /**
     * @param list<array<string, mixed>> $components
     */
    protected function hasBodyComponent(array $components): bool
    {
        foreach ($components as $component) {
            if (is_array($component) && strtolower((string) ($component['type'] ?? '')) === 'body') {
                return true;
            }
        }

        return false;
    }

    /**
     * One parameter per approved template placeholder, in template order.
     *
     * @param list<array{key:string,index:int,style:string,example:string,suggested_source:string}> $definitions
     * @param array<string, mixed> $variableMap
     * @param array<string, mixed> $contact
     *
     * @return list<array<string, mixed>>
     */
    protected function parametersFromDefinitions(array $definitions, array $variableMap, array $contact): array
    {
        $parameters = [];

        foreach ($definitions as $definition) {
            $key   = (string) ($definition['key'] ?? '');
            $index = (int) ($definition['index'] ?? 0);
            if ($key === '') {
                continue;
            }

            // Older campaigns stored the map by position even for named templates.
            $source = $variableMap[$key] ?? $variableMap[(string) $index] ?? null;

            $text       = $source !== null ? $this->resolveVariableValue($source, $contact) : '';
            $suggestion = (string) ($definition['suggested_source'] ?? '');
            if ($text === '' && in_array($suggestion, ['name', 'mobile', 'email'], true)) {
                $text = $this->resolveVariableValue($suggestion, $contact);
            }
            $isNameField = $suggestion === 'name' || ($source !== null && $this->variableSourceField($source) === 'name');

            $parameter = [
                'type' => 'text',
                'text' => $this->requireVariableText($text, $key, $isNameField),
            ];
            if (($definition['style'] ?? '') === 'named') {
                $parameter['parameter_name'] = $key;
            }

            $parameters[] = $parameter;
        }

        return $parameters;
    }

    /**
     * Fallback when the template row is unavailable — trust the saved map.
     *
     * @param array<string, mixed> $variableMap
     * @param array<string, mixed> $contact
     *
     * @return list<array<string, mixed>>
     */
    protected function parametersFromVariableMap(array $variableMap, array $contact): array
    {
        if ($variableMap === []) {
            return [];
        }

        // Sort numeric keys so {{1}}, {{2}} stay ordered for BODY parameters.
        $keys = array_keys($variableMap);
        usort($keys, static function ($a, $b): int {
            if (is_numeric($a) && is_numeric($b)) {
                return (int) $a <=> (int) $b;
            }

            return strcmp((string) $a, (string) $b);
        });

        $parameters = [];
        foreach ($keys as $key) {
            $text = $this->resolveVariableValue($variableMap[$key], $contact);
            $parameter = [
                'type' => 'text',
                'text' => $this->requireVariableText(
                    $text,
                    (string) $key,
                    $this->variableSourceField($variableMap[$key]) === 'name'
                ),
            ];
            if (! ctype_digit((string) $key)) {
                $parameter['parameter_name'] = (string) $key;
            }
            $parameters[] = $parameter;
        }

        return $parameters;
    }

    /**
     * @param array<string, mixed>|list<mixed> $value
     */
    protected function looksLikeMetaComponents(array $value): bool
    {
        if ($value === []) {
            return false;
        }

        // List of component objects
        if (array_is_list($value)) {
            $first = $value[0] ?? null;

            return is_array($first) && isset($first['type']) && (
                isset($first['parameters']) || in_array(strtolower((string) $first['type']), ['body', 'header', 'button', 'carousel'], true)
            );
        }

        return false;
    }

    /**
     * Placeholder text like "-" or the approval example ("John") reads as spam to
     * recipients, so an empty value skips the recipient instead. Only a missing
     * name gets a neutral salutation.
     *
     * @throws RuntimeException When the variable has no value for this contact.
     */
    protected function requireVariableText(string $text, string $key, bool $isNameField): string
    {
        $text = trim($text);
        if ($text !== '') {
            return $text;
        }
        if ($isNameField) {
            return 'Customer';
        }

        throw new RuntimeException('Missing value for template variable {{' . $key . '}} for this contact.');
    }

    protected function variableSourceField(mixed $source): string
    {
        if (! is_string($source)) {
            return '';
        }
        $raw = trim($source);
        if (preg_match('/^\{\{\s*([a-zA-Z0-9_]+)\s*\}\}$/', $raw, $m)) {
            $raw = $m[1];
        }

        return strtolower($raw);
    }

    protected function resolveVariableValue(mixed $source, array $contact): string
    {
        if (is_array($source)) {
            return (string) ($source['text'] ?? $source['value'] ?? '');
        }

        $raw = trim((string) $source);

        // "attr:city" → value of that attribute (Contacts → Attributes) for this contact
        if (str_starts_with($raw, 'attr:')) {
            return service('contactAttributes')->valueFor($contact, substr($raw, 5));
        }

        // "{{name}}" or "name" / "mobile" / "email"
        $isPlaceholder = false;
        if (preg_match('/^\{\{\s*([a-zA-Z0-9_]+)\s*\}\}$/', $raw, $m)) {
            $raw           = $m[1];
            $isPlaceholder = true;
        }

        $field = strtolower($raw);
        if ($field === 'custom') {
            return '';
        }

        $map   = [
            'name'   => (string) ($contact['name'] ?? ''),
            'mobile' => (string) ($contact['mobile'] ?? ''),
            'phone'  => (string) ($contact['mobile'] ?? ''),
            'email'  => (string) ($contact['email'] ?? ''),
        ];

        if (isset($map[$field])) {
            return $map[$field];
        }

        // "{{city}}" is a contact placeholder, never literal text
        if ($isPlaceholder) {
            return service('contactAttributes')->valueFor($contact, $raw);
        }

        // Custom static value
        return $raw;
    }
}
