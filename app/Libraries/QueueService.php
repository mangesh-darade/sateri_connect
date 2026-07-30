<?php

namespace App\Libraries;

use App\Models\CampaignContactModel;
use App\Models\CampaignModel;
use App\Models\ContactModel;
use App\Models\MessageModel;
use App\Models\MessageQueueModel;
use RuntimeException;
use Throwable;

/**
 * Outbound message queue processor for Cheerio Direct API sends.
 */
class QueueService
{
    protected MessageQueueModel $queue;
    protected MessageModel $messages;
    protected ContactModel $contacts;
    protected CampaignModel $campaigns;
    protected CampaignContactModel $campaignContacts;
    protected WhatsAppCloudAPI $api;
    protected ActivityLogger $logger;

    public function __construct(
        ?MessageQueueModel $queue = null,
        ?WhatsAppCloudAPI $api = null,
        ?MessageModel $messages = null,
        ?ContactModel $contacts = null,
        ?CampaignModel $campaigns = null,
        ?CampaignContactModel $campaignContacts = null,
        ?ActivityLogger $logger = null
    ) {
        $this->queue            = $queue ?? model(MessageQueueModel::class);
        $this->api              = $api ?? (function_exists('service') ? service('whatsApp') : new WhatsAppCloudAPI());
        $this->messages         = $messages ?? model(MessageModel::class);
        $this->contacts         = $contacts ?? model(ContactModel::class);
        $this->campaigns        = $campaigns ?? model(CampaignModel::class);
        $this->campaignContacts = $campaignContacts ?? model(CampaignContactModel::class);
        $this->logger           = $logger ?? new ActivityLogger();
    }

    /**
     * Enqueue an outbound message.
     *
     * @param array<string, mixed> $payload
     */
    public function enqueue(
        int $contactId,
        string $messageType,
        array $payload,
        ?int $campaignId = null,
        int $priority = 5,
        ?string $scheduledAt = null
    ): int {
        $data = [
            'campaign_id'  => $campaignId,
            'contact_id'   => $contactId,
            'message_type' => $messageType,
            'payload'      => json_encode($payload),
            'priority'     => $priority,
            'status'       => 'pending',
            'attempts'     => 0,
            'max_attempts' => 3,
            'scheduled_at' => $scheduledAt ?? date('Y-m-d H:i:s'),
        ];

        $id = $this->queue->insert($data);
        if (! $id) {
            throw new RuntimeException('Failed to enqueue message: ' . implode(', ', $this->queue->errors()));
        }

        return (int) $id;
    }

    /**
     * Process the next batch of pending queue items (priority ordered).
     *
     * @return array{processed: int, sent: int, failed: int}
     */
    public function processBatch(int $limit = 50): array
    {
        $limit = max(1, min(500, $limit));
        $stats = ['processed' => 0, 'sent' => 0, 'failed' => 0];

        $items = $this->claimBatch($limit);

        // Always use the currently active provider credentials
        try {
            $this->api->loadCredentials();
        } catch (Throwable $e) {
            log_message('warning', 'QueueService credential refresh: {msg}', ['msg' => $e->getMessage()]);
        }

        foreach ($items as $item) {
            $stats['processed']++;
            $id = (int) $item['id'];

            try {
                $result = $this->dispatch($item);
                $waId   = $this->extractMessageId($result);

                if (method_exists($this->queue, 'markSent')) {
                    $this->queue->markSent($id, $waId);
                } else {
                    $this->queue->update($id, [
                        'status'        => 'sent',
                        'wa_message_id' => $waId,
                        'processed_at'  => date('Y-m-d H:i:s'),
                        'error_message' => null,
                    ]);
                }

                $this->storeOutboundMessage($item, $result, $waId);
                $this->updateCampaignOnSuccess($item, $waId);
                $stats['sent']++;
            } catch (Throwable $e) {
                // Count this attempt before deciding retry vs final failure.
                $attempts = ((int) ($item['attempts'] ?? 0)) + 1;
                $max      = (int) ($item['max_attempts'] ?? 3);
                $status   = $attempts >= $max ? 'failed' : 'pending';

                if (method_exists($this->queue, 'markFailed')) {
                    $this->queue->markFailed($id, $e->getMessage(), $status, $attempts);
                } else {
                    $this->queue->update($id, [
                        'status'        => $status,
                        'attempts'      => $attempts,
                        'error_message' => $e->getMessage(),
                        'processed_at'  => date('Y-m-d H:i:s'),
                    ]);
                }

                // Always surface the real send error on the campaign recipient row.
                if (! empty($item['campaign_id'])) {
                    $this->updateCampaignContactStatus(
                        (int) $item['campaign_id'],
                        (int) $item['contact_id'],
                        $status === 'failed' ? 'failed' : 'queued',
                        null,
                        $e->getMessage()
                    );
                }

                if ($status === 'failed' && ! empty($item['campaign_id'])) {
                    if (method_exists($this->campaigns, 'updateStats')) {
                        $this->campaigns->updateStats((int) $item['campaign_id']);
                    }
                    service('campaignService')->completeIfFinished((int) $item['campaign_id']);
                }

                log_message('error', 'Queue item {id} failed: {msg}', [
                    'id'  => $id,
                    'msg' => $e->getMessage(),
                ]);
                $stats['failed']++;
            }
        }

        return $stats;
    }

    /**
     * Re-queue failed items for another attempt.
     *
     * @return int Number of items reset to pending
     */
    public function retryFailed(int $limit = 20): int
    {
        $limit = max(1, min(500, $limit));

        $failed = $this->queue
            ->where('status', 'failed')
            ->orderBy('updated_at', 'ASC')
            ->findAll($limit);

        $count = 0;
        foreach ($failed as $item) {
            $ok = $this->queue->update((int) $item['id'], [
                'status'        => 'pending',
                'attempts'      => 0,
                'error_message' => null,
                'scheduled_at'  => date('Y-m-d H:i:s'),
            ]);
            if ($ok) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Queue status counters.
     *
     * @return array<string, int>
     */
    public function getStats(): array
    {
        $db = db_connect();
        $rows = $db->table('message_queue')
            ->select('status, COUNT(*) AS total')
            ->groupBy('status')
            ->get()
            ->getResultArray();

        $stats = [
            'pending'     => 0,
            'processing'  => 0,
            'sent'        => 0,
            'failed'      => 0,
            'cancelled'   => 0,
            'total'       => 0,
        ];

        foreach ($rows as $row) {
            $status = (string) $row['status'];
            $total  = (int) $row['total'];
            if (isset($stats[$status])) {
                $stats[$status] = $total;
            }
            $stats['total'] += $total;
        }

        return $stats;
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function claimBatch(int $limit): array
    {
        if (method_exists($this->queue, 'claimPending')) {
            return $this->queue->claimPending($limit);
        }

        $items = $this->fetchPending($limit);
        foreach ($items as &$item) {
            $id = (int) ($item['id'] ?? 0);
            if (method_exists($this->queue, 'markProcessing')) {
                $this->queue->markProcessing($id);
            } else {
                $this->queue->update($id, ['status' => 'processing']);
            }
            $item['attempts'] = ((int) ($item['attempts'] ?? 0)) + 1;
            $item['status']   = 'processing';
        }
        unset($item);

        return $items;
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function fetchPending(int $limit): array
    {
        if (method_exists($this->queue, 'getPending')) {
            return $this->queue->getPending($limit);
        }

        $now = date('Y-m-d H:i:s');

        return $this->queue
            ->where('status', 'pending')
            ->groupStart()
                ->where('scheduled_at <=', $now)
                ->orWhere('scheduled_at', null)
            ->groupEnd()
            ->orderBy('priority', 'ASC')
            ->orderBy('id', 'ASC')
            ->findAll($limit);
    }

    /**
     * @param array<string, mixed> $item
     *
     * @return array<string, mixed>
     *
     * @throws RuntimeException
     */
    protected function dispatch(array $item): array
    {
        $contact = $this->contacts->find((int) $item['contact_id']);
        if ($contact === null) {
            throw new RuntimeException('Contact not found: ' . $item['contact_id']);
        }

        $to = $this->api->normalizePhone((string) ($contact['mobile'] ?? ''));
        if ($to === '') {
            throw new RuntimeException('Contact has no valid mobile number.');
        }

        $payload = $this->decodePayload($item['payload'] ?? null);
        $type    = (string) ($item['message_type'] ?? ($payload['type'] ?? 'text'));

        // WhatsApp policy: free-form outbound only inside the 24h customer-care window.
        if ($type !== 'template' && ! contact_within_24h_window($contact, true)) {
            throw new RuntimeException(
                'Outside the 24-hour messaging window. Only template messages can be sent.'
            );
        }

        return match ($type) {
            'text' => $this->api->sendText(
                $to,
                (string) ($payload['text'] ?? $payload['body'] ?? ''),
                (bool) ($payload['preview_url'] ?? false)
            ),
            'template' => $this->api->sendTemplate(
                $to,
                (string) ($payload['template_name'] ?? $payload['name'] ?? ''),
                (string) ($payload['language'] ?? 'en'),
                (array) ($payload['components'] ?? [])
            ),
            'image' => $this->api->sendImage(
                $to,
                (string) ($payload['link'] ?? $payload['id'] ?? ''),
                $payload['caption'] ?? null,
                isset($payload['id']) && ! isset($payload['link'])
            ),
            'document' => $this->api->sendDocument(
                $to,
                (string) ($payload['link'] ?? $payload['id'] ?? ''),
                $payload['caption'] ?? null,
                (string) ($payload['filename'] ?? 'document'),
                isset($payload['id']) && ! isset($payload['link'])
            ),
            'video' => $this->api->sendVideo(
                $to,
                (string) ($payload['link'] ?? $payload['id'] ?? ''),
                $payload['caption'] ?? null,
                isset($payload['id']) && ! isset($payload['link'])
            ),
            'audio' => $this->api->sendAudio(
                $to,
                (string) ($payload['link'] ?? $payload['id'] ?? ''),
                isset($payload['id']) && ! isset($payload['link'])
            ),
            'location' => $this->api->sendLocation(
                $to,
                (float) ($payload['latitude'] ?? 0),
                (float) ($payload['longitude'] ?? 0),
                (string) ($payload['name'] ?? ''),
                (string) ($payload['address'] ?? '')
            ),
            'interactive_buttons', 'buttons', 'quick_reply' => $this->api->sendInteractiveButtons(
                $to,
                (string) ($payload['body'] ?? ''),
                (array) ($payload['buttons'] ?? []),
                $payload['header'] ?? null,
                $payload['footer'] ?? null
            ),
            'interactive_list', 'list' => $this->api->sendInteractiveList(
                $to,
                (string) ($payload['body'] ?? ''),
                (string) ($payload['button_text'] ?? 'Options'),
                (array) ($payload['sections'] ?? []),
                $payload['header'] ?? null,
                $payload['footer'] ?? null
            ),
            default => throw new RuntimeException('Unsupported queue message type: ' . $type),
        };
    }

    /**
     * @param array<string, mixed> $item
     * @param array<string, mixed> $result
     */
    protected function storeOutboundMessage(array $item, array $result, ?string $waId): void
    {
        $payload = $this->decodePayload($item['payload'] ?? null);

        $this->messages->insert([
            'contact_id'    => (int) $item['contact_id'],
            'campaign_id'   => $item['campaign_id'] ?? null,
            'direction'     => 'outbound',
            'message_type'  => $item['message_type'] ?? 'text',
            'wa_message_id' => $waId,
            'wamid'         => $waId,
            'content'       => $payload['text'] ?? $payload['body'] ?? ($payload['template_name'] ?? null),
            'payload'       => json_encode(['request' => $payload, 'response' => $result]),
            'status'        => 'sent',
            'is_read'       => 0,
        ]);
    }

    /**
     * @param array<string, mixed> $item
     */
    protected function updateCampaignOnSuccess(array $item, ?string $waId): void
    {
        if (empty($item['campaign_id'])) {
            return;
        }

        $campaignId = (int) $item['campaign_id'];
        $contactId  = (int) $item['contact_id'];

        $this->updateCampaignContactStatus($campaignId, $contactId, 'sent', $waId);

        $campaign = $this->campaigns->find($campaignId);
        if ($campaign !== null) {
            $this->campaigns->update($campaignId, [
                'sent_count' => ((int) ($campaign['sent_count'] ?? 0)) + 1,
            ]);
        }

        if (method_exists($this->campaigns, 'updateStats')) {
            $this->campaigns->updateStats($campaignId);
        }

        service('campaignService')->completeIfFinished($campaignId);
    }

    protected function updateCampaignContactStatus(
        int $campaignId,
        int $contactId,
        string $status,
        ?string $waId = null,
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
        if ($waId !== null) {
            $data['wa_message_id'] = $waId;
        }
        if ($status === 'sent') {
            $data['sent_at'] = date('Y-m-d H:i:s');
        }
        if ($error !== null) {
            $data['error_message'] = $error;
        }

        $this->campaignContacts->update((int) $row['id'], $data);
    }

    /**
     * @param array<string, mixed> $result
     */
    protected function extractMessageId(array $result): ?string
    {
        $id = $result['messages'][0]['id']
            ?? $result['data']['messages'][0]['id']
            ?? null;

        return is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * @return array<string, mixed>
     */
    protected function decodePayload(mixed $payload): array
    {
        if (is_array($payload)) {
            return $payload;
        }

        if (is_string($payload) && $payload !== '') {
            $decoded = json_decode($payload, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }
}
