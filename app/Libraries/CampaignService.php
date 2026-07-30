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

    public function __construct(
        ?CampaignModel $campaigns = null,
        ?CampaignContactModel $campaignContacts = null,
        ?ContactModel $contacts = null,
        ?TemplateModel $templates = null,
        ?QueueService $queue = null,
        ?ActivityLogger $logger = null
    ) {
        $this->campaigns        = $campaigns ?? model(CampaignModel::class);
        $this->campaignContacts = $campaignContacts ?? model(CampaignContactModel::class);
        $this->contacts         = $contacts ?? model(ContactModel::class);
        $this->templates        = $templates ?? model(TemplateModel::class);
        $this->queue            = $queue ?? new QueueService();
        $this->logger           = $logger ?? new ActivityLogger();
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

        $ok = (bool) $this->campaigns->update($campaignId, [
            'status'       => 'scheduled',
            'scheduled_at' => $scheduledAt,
        ]);

        if ($ok) {
            $this->logger->log('schedule', 'campaigns', 'Campaign scheduled', [
                'campaign_id'  => $campaignId,
                'scheduled_at' => $scheduledAt,
            ]);
        }

        return $ok;
    }

    /**
     * Start a campaign: queue recipients and mark running.
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

        $queued = $this->queueRecipients($campaignId, $contactIds, $tagIds, $allActive);

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
                $queued['sent']   = (int) ($stats['sent'] ?? 0);
                $queued['failed'] = (int) ($stats['failed'] ?? 0);
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

        // Fire "Campaign Sent" automations for each audience contact (capped for safety).
        $this->fireCampaignSentTriggers($campaignId, $contactIds, $tagIds, $allActive);

        return $queued;
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
            $db->table('message_queue')->where('id', (int) $row['id'])->update([
                'status'        => 'failed',
                'error_message' => $error . (trim((string) ($row['error_message'] ?? '')) !== ''
                    ? (' Previous: ' . $row['error_message'])
                    : ''),
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
                        'error_message' => $error,
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
     * Notify automation engine that a campaign was launched for contacts.
     *
     * @param list<int>|null $contactIds
     * @param list<int>|null $tagIds
     */
    protected function fireCampaignSentTriggers(int $campaignId, ?array $contactIds, ?array $tagIds, bool $allActive): void
    {
        try {
            $contacts = $this->resolveContacts($contactIds, $tagIds, $allActive);
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

        $payload['_audience'] = [
            'all'         => ! empty($audience['all']),
            'contact_ids' => array_values(array_map('intval', $audience['contact_ids'] ?? [])),
            'tag_ids'     => array_values(array_map('intval', $audience['tag_ids'] ?? [])),
        ];

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
     * @return array{contacts: int, queued: int}
     */
    public function queueRecipients(int $campaignId, ?array $contactIds = null, ?array $tagIds = null, bool $allActive = false): array
    {
        $campaign = $this->requireCampaign($campaignId);
        $contacts = $this->resolveContacts($contactIds, $tagIds, $allActive);

        $messageType = (string) ($campaign['message_type'] ?? 'template');
        $basePayload = $this->buildSendPayload($campaign);
        $variableMap = $this->decodeVariables($campaign['variables'] ?? null);
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
                $payload['components'] = $this->buildTemplateComponents($variableMap, $contact, $payload['components'] ?? null);
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

        return ['contacts' => count($contacts), 'queued' => $queued];
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

        return [
            'contacts'     => $contacts,
            'contact_ids'  => $ids,
            'phone_count'  => $phoneCount,
            'email_count'  => $emailCount,
            'total'        => count($contacts),
            'sample'       => $sample,
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
            $name = strtolower(trim((string) ($row['name'] ?? $row['attribute'] ?? '')));
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

        foreach ($rules as $rule) {
            $field = $rule['name'];
            $haystack = match ($field) {
                'name'   => (string) ($contact['name'] ?? ''),
                'mobile', 'phone' => (string) ($contact['mobile'] ?? ''),
                'email'  => (string) ($contact['email'] ?? ''),
                'status' => (string) ($contact['status'] ?? ''),
                default  => (string) ($custom[$field] ?? $contact[$field] ?? ''),
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
        $contacts = $this->resolveContacts($contactIds, $tagIds, $allActive);

        $sample = array_slice(array_map(static fn (array $c): array => [
            'id'     => $c['id'],
            'name'   => $c['name'] ?? '',
            'mobile' => $c['mobile'] ?? '',
        ], $contacts), 0, 10);

        return [
            'recipient_count' => count($contacts),
            'sample'          => $sample,
            'payload'         => $this->buildSendPayload($campaign),
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
     * Build WhatsApp template components from campaign variable mappings.
     *
     * Accepts maps like:
     * - {"1":"name","2":"custom text"}
     * - {"1":"{{name}}","2":"Hello"}
     * - already-valid template components list
     *
     * Cheerio template/send uses the same component object shape as WhatsApp Cloud API.
     *
     * @param array<string, mixed>      $variableMap
     * @param array<string, mixed>      $contact
     * @param list<array<string,mixed>>|array<string,mixed>|null $existing
     *
     * @return list<array<string, mixed>>
     */
    protected function buildTemplateComponents(array $variableMap, array $contact, mixed $existing = null): array
    {
        if (is_array($existing) && $existing !== [] && $this->looksLikeMetaComponents($existing)) {
            return array_values($existing);
        }

        if ($variableMap === []) {
            return [];
        }

        if ($this->looksLikeMetaComponents($variableMap)) {
            return array_values($variableMap);
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
            $source = $variableMap[$key];
            $text   = $this->resolveVariableValue($source, $contact);
            $parameters[] = [
                'type' => 'text',
                'text' => $text !== '' ? $text : '-',
            ];
        }

        if ($parameters === []) {
            return [];
        }

        return [[
            'type'       => 'body',
            'parameters' => $parameters,
        ]];
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

    protected function resolveVariableValue(mixed $source, array $contact): string
    {
        if (is_array($source)) {
            return (string) ($source['text'] ?? $source['value'] ?? '');
        }

        $raw = trim((string) $source);

        // "{{name}}" or "name" / "mobile" / "email"
        if (preg_match('/^\{\{\s*([a-zA-Z0-9_]+)\s*\}\}$/', $raw, $m)) {
            $raw = $m[1];
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

        // Custom static value
        return $raw;
    }
}
