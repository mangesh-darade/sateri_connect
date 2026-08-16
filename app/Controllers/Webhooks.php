<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Libraries\WebhookValidator;
use App\Models\CampaignContactModel;
use App\Models\CampaignModel;
use App\Models\ContactModel;
use App\Models\ConversationModel;
use App\Models\MessageModel;
use App\Models\NotificationModel;
use App\Models\WebhookLogModel;
use CodeIgniter\Controller;
use CodeIgniter\HTTP\ResponseInterface;
use Throwable;

/**
 * Public Cheerio / WhatsApp webhook endpoint (verification + inbound events).
 * Payload shape matches the WhatsApp Cloud API webhook format that Cheerio delivers.
 * No session auth — signature validated on POST when a webhook secret is configured.
 */
class Webhooks extends Controller
{
    /**
     * @var list<string>
     */
    protected $helpers = ['whatsapp', 'url'];

    public function index(): ResponseInterface|string
    {
        if (strtolower($this->request->getMethod()) === 'get') {
            return $this->verify();
        }

        return $this->receive();
    }

    /**
     * Webhook subscription challenge (hub.verify_token).
     */
    protected function verify(): ResponseInterface|string
    {
        $mode      = $this->request->getGet('hub_mode') ?? $this->request->getGet('hub.mode');
        $token     = $this->request->getGet('hub_verify_token') ?? $this->request->getGet('hub.verify_token');
        $challenge = $this->request->getGet('hub_challenge') ?? $this->request->getGet('hub.challenge');

        $validator = new WebhookValidator();
        $result    = $validator->verifyChallenge(
            $mode !== null ? (string) $mode : null,
            $token !== null ? (string) $token : null,
            $challenge !== null ? (string) $challenge : null
        );

        if ($result === false) {
            return $this->response
                ->setStatusCode(403)
                ->setHeader('Content-Type', 'text/plain; charset=UTF-8')
                ->setBody('Verification failed');
        }

        return $this->response
            ->setStatusCode(200)
            ->setHeader('Content-Type', 'text/plain; charset=UTF-8')
            ->setBody($result);
    }

    /**
     * Process inbound webhook payload from Cheerio / WABA.
     */
    protected function receive(): ResponseInterface
    {
        $rawBody = $this->request->getBody();
        if ($rawBody === null || $rawBody === '') {
            $rawBody = $this->request->getRawInput() ?: '';
        }
        if (! is_string($rawBody)) {
            $rawBody = (string) $rawBody;
        }

        $payload = json_decode($rawBody, true);
        if (! is_array($payload)) {
            $payload = [];
        }

        // Portal multi-client: switch tenant DB from phone_number_id before signature/settings.
        $phoneNumberId = $this->extractPhoneNumberId($payload);
        if ($phoneNumberId !== '') {
            (new \App\Libraries\TenantConnection())->applyFromPhoneNumberId($phoneNumberId);
        }

        $signature = $this->request->getHeaderLine('X-Hub-Signature-256');
        $validator = new WebhookValidator();
        $matchedProvider = $validator->matchSignatureProvider($rawBody, $signature !== '' ? $signature : null);
        $valid           = $matchedProvider !== null;

        // Local / non-production: still accept payload so Live Chat testing works
        // when App Secret is missing/mismatched. Production stays strict.
        $allowUnsigned = defined('ENVIRONMENT') && ENVIRONMENT !== 'production';

        $logId = model(WebhookLogModel::class)->insert([
            'event_type'      => $this->detectEventType($payload),
            'payload'         => $payload,
            'headers'         => [
                'X-Hub-Signature-256' => $signature,
                'Content-Type'        => $this->request->getHeaderLine('Content-Type'),
            ],
            'signature_valid' => $valid ? 1 : 0,
            'processed'       => 0,
        ]);

        if (! $valid && ! $allowUnsigned) {
            if ($logId) {
                model(WebhookLogModel::class)->markProcessed((int) $logId, 'Invalid signature');
            }

            return $this->response->setStatusCode(403)->setJSON([
                'success' => false,
                'message' => 'Invalid signature',
            ]);
        }

        if (! $valid && $allowUnsigned) {
            log_message('warning', 'Webhook accepted without valid signature (ENVIRONMENT={env}). Check Meta App Secret in Settings.', [
                'env' => ENVIRONMENT,
            ]);
            if ($logId) {
                model(WebhookLogModel::class)->update((int) $logId, [
                    'error_message' => 'Accepted without valid signature (non-production)',
                ]);
            }
        }

        try {
            $this->processPayload($payload);
            if ($logId) {
                model(WebhookLogModel::class)->markProcessed((int) $logId);
            }
        } catch (Throwable $e) {
            log_message('error', 'Webhook processing error: {msg}', ['msg' => $e->getMessage()]);
            if ($logId) {
                model(WebhookLogModel::class)->markProcessed((int) $logId, $e->getMessage());
            }
        }

        return $this->response->setStatusCode(200)->setJSON(['success' => true]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    protected function extractPhoneNumberId(array $payload): string
    {
        $entries = $payload['entry'] ?? [];
        if (! is_array($entries)) {
            return '';
        }

        foreach ($entries as $entry) {
            $changes = is_array($entry) ? ($entry['changes'] ?? []) : [];
            if (! is_array($changes)) {
                continue;
            }
            foreach ($changes as $change) {
                $value = is_array($change) ? ($change['value'] ?? []) : [];
                if (! is_array($value)) {
                    continue;
                }
                $meta = $value['metadata'] ?? null;
                if (is_array($meta) && ! empty($meta['phone_number_id'])) {
                    return trim((string) $meta['phone_number_id']);
                }
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $payload
     */
    protected function processPayload(array $payload): void
    {
        $object = strtolower((string) ($payload['object'] ?? ''));

        // Messenger / Instagram Messaging (Page webhooks)
        if ($object === 'page' || $object === 'instagram') {
            $this->processPageMessagingPayload($payload, $object === 'instagram' ? 'instagram' : null);

            return;
        }

        // Default: WhatsApp Cloud API / Cheerio WABA shape
        $this->processWhatsAppPayload($payload);
    }

    /**
     * @param array<string, mixed> $payload
     */
    protected function processWhatsAppPayload(array $payload): void
    {
        $entries = $payload['entry'] ?? [];
        if (! is_array($entries)) {
            return;
        }

        foreach ($entries as $entry) {
            $changes = $entry['changes'] ?? [];
            if (! is_array($changes)) {
                continue;
            }

            foreach ($changes as $change) {
                $value = $change['value'] ?? [];
                if (! is_array($value)) {
                    continue;
                }

                if (! empty($value['messages']) && is_array($value['messages'])) {
                    $contactsMeta = $value['contacts'] ?? [];
                    $metadata     = is_array($value['metadata'] ?? null) ? $value['metadata'] : [];
                    foreach ($value['messages'] as $message) {
                        if (is_array($message)) {
                            $this->handleInboundMessage(
                                $message,
                                is_array($contactsMeta) ? $contactsMeta : [],
                                $metadata
                            );
                        }
                    }
                }

                if (! empty($value['statuses']) && is_array($value['statuses'])) {
                    foreach ($value['statuses'] as $status) {
                        if (is_array($status)) {
                            $this->handleStatusUpdate($status);
                        }
                    }
                }
            }
        }
    }

    /**
     * Page / Instagram Messaging webhook shape: entry[].messaging[].
     *
     * @param array<string, mixed> $payload
     */
    protected function processPageMessagingPayload(array $payload, ?string $forcedChannel = null): void
    {
        $entries = $payload['entry'] ?? [];
        if (! is_array($entries)) {
            return;
        }

        $settings = new \App\Libraries\SettingsService();

        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $pageId = (string) ($entry['id'] ?? '');
            $events = $entry['messaging'] ?? [];
            if (! is_array($events)) {
                continue;
            }

            foreach ($events as $event) {
                if (! is_array($event)) {
                    continue;
                }

                $this->handlePageMessagingEvent($event, $pageId, $forcedChannel, $settings);
            }
        }
    }

    /**
     * @param array<string, mixed> $event
     */
    protected function handlePageMessagingEvent(
        array $event,
        string $pageId,
        ?string $forcedChannel,
        \App\Libraries\SettingsService $settings
    ): void {
        $senderId    = (string) ($event['sender']['id'] ?? '');
        $recipientId = (string) ($event['recipient']['id'] ?? '');
        if ($senderId === '') {
            return;
        }

        // Echo of our own outbound — skip creating duplicate inbound
        if (! empty($event['message']['is_echo'])) {
            $mid = (string) ($event['message']['mid'] ?? '');
            if ($mid !== '') {
                $existing = model(MessageModel::class)->findByExternalMessageId($mid);
                if ($existing !== null && ($existing['status'] ?? '') === 'sent') {
                    model(MessageModel::class)->update((int) $existing['id'], ['status' => 'delivered']);
                }
            }

            return;
        }

        // Delivery / read receipts
        if (! empty($event['delivery']['mids']) && is_array($event['delivery']['mids'])) {
            foreach ($event['delivery']['mids'] as $mid) {
                $this->handleStatusUpdate(['id' => (string) $mid, 'status' => 'delivered']);
            }

            return;
        }
        if (! empty($event['read'])) {
            // Mark latest outbound as read is approximate; skip bulk without mids
            return;
        }

        $message = $event['message'] ?? null;
        if (! is_array($message)) {
            return;
        }

        $channel = $forcedChannel;
        if ($channel === null) {
            // Heuristic: Instagram events often include message.reply_to.story or attachments of type story
            $channel = 'messenger';
            if (! empty($message['reply_to']['story']) || ($settings->getMetaConfig()['instagram_account_id'] ?? '') === $recipientId) {
                $channel = 'instagram';
            }
            // Prefer explicit enable flags: if only one channel enabled and page matches, use that
            if ($channel === 'messenger' && $settings->isInstagramInboxEnabled() && ! $settings->isMessengerInboxEnabled()) {
                $channel = 'instagram';
            }
        }

        if ($channel === 'instagram' && ! $settings->isInstagramInboxEnabled()) {
            log_message('notice', 'Instagram inbound ignored — inbox_instagram_enabled is off.');

            return;
        }
        if ($channel === 'messenger' && ! $settings->isMessengerInboxEnabled()) {
            log_message('notice', 'Messenger inbound ignored — inbox_messenger_enabled is off.');

            return;
        }

        $mid = (string) ($message['mid'] ?? '');
        $messages = model(MessageModel::class);
        if ($mid !== '' && $messages->findByExternalMessageId($mid) !== null) {
            return;
        }

        $text = (string) ($message['text'] ?? '');
        $type = 'text';
        $mediaUrl = null;
        $mediaId  = null;

        if (! empty($message['attachments'][0]) && is_array($message['attachments'][0])) {
            $att  = $message['attachments'][0];
            $type = strtolower((string) ($att['type'] ?? 'file'));
            $mediaUrl = (string) ($att['payload']['url'] ?? '');
            if ($text === '' && $mediaUrl !== '') {
                $text = '[' . $type . ']';
            }
        }

        $contactModel = model(ContactModel::class);
        $contact      = $contactModel->findOrCreateForChannel($channel, $senderId, [
            'name' => null,
        ]);
        $contactId = (int) $contact['id'];
        $now       = date('Y-m-d H:i:s');

        $conversation = model(ConversationModel::class)->findOrCreateForContact(
            $contactId,
            $channel,
            $pageId !== '' ? $pageId : null
        );

        $messageId = $messages->insert([
            'contact_id'          => $contactId,
            'conversation_id'     => (int) $conversation['id'],
            'channel'             => $channel,
            'direction'           => 'inbound',
            'message_type'        => $type,
            'external_message_id' => $mid !== '' ? $mid : null,
            'wa_message_id'       => $mid !== '' ? $mid : null,
            'wamid'               => $mid !== '' ? $mid : null,
            'content'             => $text !== '' ? $text : null,
            'media_url'           => $mediaUrl !== '' ? $mediaUrl : null,
            'media_id'            => $mediaId,
            'payload'             => $event,
            'status'              => 'received',
            'is_read'             => 0,
        ]);

        model(ConversationModel::class)->update((int) $conversation['id'], [
            'last_message_id' => $messageId,
            'last_message_at' => $now,
            'status'          => 'open',
        ]);
        model(ConversationModel::class)->incrementUnread((int) $conversation['id']);

        $contactModel->update($contactId, [
            'last_message_at' => $now,
            'last_reply_at'   => $now,
        ]);

        try {
            $preview = trim(mb_substr($text, 0, 80));
            $name    = (string) ($contact['name'] ?? $senderId);
            $assign  = ! empty($contact['assigned_to']) ? (int) $contact['assigned_to'] : null;
            model(NotificationModel::class)->notifyChatUsers(
                'New ' . $channel . ' message from ' . ($name !== '' ? $name : $senderId),
                $preview !== '' ? $preview : ('(' . $type . ')'),
                site_url('chat?contact_id=' . $contactId . '&channel=' . $channel),
                $assign
            );
        } catch (Throwable $e) {
            log_message('warning', 'Page messaging notification failed: {msg}', ['msg' => $e->getMessage()]);
        }

        try {
            service('automationEngine')->processTrigger($channel, [
                'contact_id'   => $contactId,
                'message_id'   => $messageId,
                'message_type' => $type,
                'content'      => $text,
                'from'         => $senderId,
                'channel'      => $channel,
                'source'       => $channel,
            ]);
            service('automationEngine')->processTrigger('message_received', [
                'contact_id'   => $contactId,
                'message_id'   => $messageId,
                'message_type' => $type,
                'content'      => $text,
                'from'         => $senderId,
                'channel'      => $channel,
                'source'       => $channel,
            ]);
            try {
                (new \App\Libraries\SequenceService())->onContactReply($contactId);
            } catch (\Throwable $e) {
                log_message('error', 'Sequence exit-on-reply failed: {msg}', ['msg' => $e->getMessage()]);
            }
        } catch (Throwable $e) {
            log_message('error', 'Page messaging automation error: {msg}', ['msg' => $e->getMessage()]);
        }
    }

    /**
     * @param array<string, mixed> $message
     * @param list<array<string, mixed>> $contactsMeta
     * @param array<string, mixed> $metadata Webhook value.metadata (phone_number_id, display_phone_number)
     */
    protected function handleInboundMessage(array $message, array $contactsMeta, array $metadata = []): void
    {
        $from = normalize_phone((string) ($message['from'] ?? ''));
        if ($from === '') {
            return;
        }

        $settings      = new \App\Libraries\SettingsService();
        $pnid          = (string) ($metadata['phone_number_id'] ?? '');
        $displayPhone  = (string) ($metadata['display_phone_number'] ?? '');
        $sourceProvider = $settings->resolveProviderFromPhoneNumberId(
            $pnid !== '' ? $pnid : null,
            $displayPhone !== '' ? $displayPhone : null
        );
        $activeProvider = $settings->getWhatsAppProvider();
        // Auto-replies (keywords + workflows) ONLY for Settings → active provider's number.
        $autoReplyAllowed = ($sourceProvider === $activeProvider);

        $waMessageId = (string) ($message['id'] ?? '');
        $messages    = model(MessageModel::class);

        if ($waMessageId !== '' && $messages->findByWaMessageId($waMessageId) !== null) {
            return; // idempotent
        }

        $profileName = null;
        foreach ($contactsMeta as $c) {
            if (normalize_phone((string) ($c['wa_id'] ?? '')) === $from) {
                $profileName = $c['profile']['name'] ?? null;
                break;
            }
        }

        $contactModel = model(ContactModel::class);
        $isNewContact = false;

        try {
            // Also matches soft-deleted rows and revives them, so a number that was removed
            // from Contacts still lands back in the inbox when it messages again.
            $contact = $contactModel->findOrCreateForChannel('whatsapp', $from, [
                'name'   => $profileName,
                'mobile' => $from,
            ], $isNewContact);
        } catch (Throwable $e) {
            log_message('error', 'Inbound contact resolve failed for {from}: {msg}', [
                'from' => $from,
                'msg'  => $e->getMessage(),
            ]);

            return;
        }

        $contactId = (int) ($contact['id'] ?? 0);
        if ($contactId <= 0) {
            return;
        }

        if ($isNewContact && $autoReplyAllowed) {
            try {
                service('automationEngine')->processTrigger('contact_created', [
                    'contact_id' => $contactId,
                    'contact'    => $contact,
                    'from'       => $from,
                    'source'     => 'whatsapp',
                    'channel'    => 'whatsapp',
                    'provider'   => $activeProvider,
                ]);
            } catch (Throwable $e) {
                log_message('error', 'contact_created automation error: {msg}', ['msg' => $e->getMessage()]);
            }
        }

        $parsed = $this->extractMessageContent($message);
        $now    = date('Y-m-d H:i:s');

        $mediaUrl = null;
        if (! empty($parsed['media_id'])) {
            $mediaUrl = $this->storeInboundMedia((string) $parsed['media_id']);
        }

        $conversation = model(ConversationModel::class)->findOrCreateForContact($contactId, 'whatsapp');

        $messageId = $messages->insert([
            'contact_id'          => $contactId,
            'conversation_id'     => (int) $conversation['id'],
            'channel'             => 'whatsapp',
            'direction'           => 'inbound',
            'message_type'        => $parsed['type'],
            'wa_message_id'       => $waMessageId !== '' ? $waMessageId : null,
            'wamid'               => $waMessageId !== '' ? $waMessageId : null,
            'external_message_id' => $waMessageId !== '' ? $waMessageId : null,
            'content'             => $parsed['content'],
            'media_url'           => $mediaUrl,
            'media_id'            => $parsed['media_id'],
            'payload'             => [
                'message'  => $message,
                'metadata' => $metadata,
                'provider' => $sourceProvider,
            ],
            'status'              => 'received',
            'is_read'             => 0,
        ]);

        model(ConversationModel::class)->update((int) $conversation['id'], [
            'last_message_id' => $messageId,
            'last_message_at' => $now,
            'status'          => 'open',
        ]);
        model(ConversationModel::class)->incrementUnread((int) $conversation['id']);

        $contactModel->update($contactId, [
            'last_message_at' => $now,
            'last_reply_at'   => $now,
        ]);

        // Header bell — notify assigned agent or chat staff
        try {
            $preview = trim(mb_substr((string) ($parsed['content'] ?? ''), 0, 80));
            $name    = (string) ($contact['name'] ?? $from);
            $assign  = ! empty($contact['assigned_to']) ? (int) $contact['assigned_to'] : null;
            model(NotificationModel::class)->notifyChatUsers(
                'New message from ' . ($name !== '' ? $name : $from),
                $preview !== '' ? $preview : ('(' . ($parsed['type'] ?? 'message') . ')'),
                site_url('chat?contact_id=' . $contactId),
                $assign
            );
        } catch (Throwable $e) {
            log_message('warning', 'Inbound notification failed: {msg}', ['msg' => $e->getMessage()]);
        }

        // Keyword bot — only when inbound number matches Settings → active provider
        $keywordText = trim((string) ($parsed['content'] ?? ''));
        $replyId     = (string) ($parsed['reply_id'] ?? '');
        $msgType     = (string) ($parsed['type'] ?? '');
        $captionTypes = ['text', 'image', 'video', 'document'];
        $runKeywordMatch = $keywordText !== '' && in_array($msgType, $captionTypes, true);

        if (! $autoReplyAllowed) {
            log_message('notice', 'Skip keyword/automation reply: inbound via {source} but Settings active is {active} (pnid={pnid} phone={phone}).', [
                'source' => $sourceProvider,
                'active' => $activeProvider,
                'pnid'   => $pnid,
                'phone'  => (string) ($metadata['display_phone_number'] ?? ''),
            ]);
        }

        if ($autoReplyAllowed && ($replyId !== '' || $runKeywordMatch)) {
            try {
                $bot = service('keywordBot');
                // Always send with Settings → active provider (not the other WABA).
                $bot->setSendProvider($activeProvider);
                if ($replyId !== '' && preg_match('/^kw_(\d+)$/', $replyId, $m)) {
                    $bot->replyByKeywordId($contactId, (int) $m[1]);
                } elseif ($runKeywordMatch) {
                    $bot->matchAndReply($contactId, $keywordText);
                }
            } catch (Throwable $e) {
                log_message('error', 'Keyword bot error ({provider}): {msg}', [
                    'provider' => $activeProvider,
                    'msg'      => $e->getMessage(),
                ]);
            }
        }

        // Automation triggers — same gate: active provider number only
        if (! $autoReplyAllowed) {
            return;
        }

        try {
            $wa = service('whatsApp');
            $wa->forceProvider($activeProvider);

            service('automationEngine')->processTrigger('message_received', [
                'contact_id'   => $contactId,
                'message_id'   => $messageId,
                'message_type' => $msgType,
                'content'      => $parsed['content'],
                'caption'      => $keywordText,
                'from'         => $from,
                'provider'     => $activeProvider,
            ]);
            try {
                (new \App\Libraries\SequenceService())->onContactReply($contactId);
            } catch (\Throwable $e) {
                log_message('error', 'Sequence exit-on-reply failed: {msg}', ['msg' => $e->getMessage()]);
            }

            if ($runKeywordMatch) {
                service('automationEngine')->processTrigger('keyword', [
                    'contact_id'   => $contactId,
                    'content'      => $keywordText,
                    'text'         => $keywordText,
                    'caption'      => $keywordText,
                    'message_type' => $msgType,
                    'provider'     => $activeProvider,
                ]);
            }

            // Flush automation replies immediately so WhatsApp users get answers without waiting for cron
            try {
                service('queueService')->processBatch(30);
            } catch (Throwable $qe) {
                log_message('error', 'Post-automation queue flush failed: {msg}', ['msg' => $qe->getMessage()]);
            }
        } catch (Throwable $e) {
            log_message('error', 'Automation trigger error: {msg}', ['msg' => $e->getMessage()]);
        } finally {
            try {
                service('whatsApp')->clearForcedProvider();
            } catch (Throwable $ignored) {
            }
        }
    }

    /**
     * @param array<string, mixed> $status
     */
    protected function handleStatusUpdate(array $status): void
    {
        $waId   = (string) ($status['id'] ?? '');
        $state  = strtolower((string) ($status['status'] ?? ''));
        $errors = $status['errors'] ?? null;

        if ($waId === '' || $state === '') {
            return;
        }

        $map = [
            'sent'      => 'sent',
            'delivered' => 'delivered',
            'read'      => 'read',
            'failed'    => 'failed',
        ];

        if (! isset($map[$state])) {
            return;
        }

        $newStatus = $map[$state];
        $messages  = model(MessageModel::class);
        $message   = $messages->findByWaMessageId($waId);
        if ($message === null) {
            $message = $messages->findByExternalMessageId($waId);
        }

        if ($message !== null) {
            $update = ['status' => $newStatus];
            if ($newStatus === 'failed' && is_array($errors) && isset($errors[0])) {
                $update['error_code']    = (string) ($errors[0]['code'] ?? '');
                $update['error_message'] = (string) ($errors[0]['title'] ?? $errors[0]['message'] ?? 'Failed');
            }
            $messages->update((int) $message['id'], $update);

            if (! empty($message['campaign_id'])) {
                model(CampaignModel::class)->updateStats((int) $message['campaign_id']);
            }
        }

        $ccModel = model(CampaignContactModel::class);
        $ccModel->updateStatusByWaMessageId($waId, $newStatus);

        // Also update by joining if wa_message_id was set on send
        $cc = $ccModel->where('wa_message_id', $waId)->first();
        if ($cc !== null && ! empty($cc['campaign_id'])) {
            if ($newStatus === 'failed' && is_array($errors) && isset($errors[0])) {
                $ccModel->update((int) $cc['id'], [
                    'error_message' => (string) ($errors[0]['title'] ?? 'Failed'),
                ]);
            }
            model(CampaignModel::class)->updateStats((int) $cc['campaign_id']);
        }
    }

    /**
     * @param array<string, mixed> $message
     *
     * @return array{type: string, content: string, media_id: ?string}
     */
    protected function extractMessageContent(array $message): array
    {
        $type = (string) ($message['type'] ?? 'text');

        return match ($type) {
            'text' => [
                'type'     => 'text',
                'content'  => (string) ($message['text']['body'] ?? ''),
                'media_id' => null,
            ],
            'image' => [
                'type'     => 'image',
                'content'  => (string) ($message['image']['caption'] ?? ''),
                'media_id' => $message['image']['id'] ?? null,
            ],
            'document' => [
                'type'     => 'document',
                'content'  => (string) ($message['document']['filename'] ?? $message['document']['caption'] ?? ''),
                'media_id' => $message['document']['id'] ?? null,
            ],
            'audio' => [
                'type'     => 'audio',
                'content'  => '',
                'media_id' => $message['audio']['id'] ?? null,
            ],
            'video' => [
                'type'     => 'video',
                'content'  => (string) ($message['video']['caption'] ?? ''),
                'media_id' => $message['video']['id'] ?? null,
            ],
            'button' => [
                'type'     => 'button',
                'content'  => (string) ($message['button']['text'] ?? $message['button']['payload'] ?? ''),
                'media_id' => null,
                'reply_id' => (string) ($message['button']['payload'] ?? ''),
            ],
            'interactive' => [
                'type'     => 'interactive',
                'content'  => (string) (
                    $message['interactive']['button_reply']['title']
                    ?? $message['interactive']['list_reply']['title']
                    ?? ''
                ),
                'media_id' => null,
                'reply_id' => (string) (
                    $message['interactive']['button_reply']['id']
                    ?? $message['interactive']['list_reply']['id']
                    ?? ''
                ),
            ],
            default => [
                'type'     => $type,
                'content'  => '',
                'media_id' => null,
                'reply_id' => null,
            ],
        };
    }

    /**
     * Download media via Cheerio and store locally; return serve URL or null on failure.
     */
    protected function storeInboundMedia(string $mediaId): ?string
    {
        try {
            $downloaded = service('whatsApp')->downloadMedia($mediaId);
            $mime       = (string) ($downloaded['mime_type'] ?? 'application/octet-stream');
            $ext        = match (true) {
                str_contains($mime, 'jpeg'), str_contains($mime, 'jpg') => 'jpg',
                str_contains($mime, 'png') => 'png',
                str_contains($mime, 'webp') => 'webp',
                str_contains($mime, 'gif') => 'gif',
                str_contains($mime, 'pdf') => 'pdf',
                str_contains($mime, 'mp4') => 'mp4',
                str_contains($mime, 'ogg') => 'ogg',
                str_contains($mime, 'mpeg'), str_contains($mime, 'mp3') => 'mp3',
                default => 'bin',
            };

            $dir = WRITEPATH . 'uploads/media/';
            if (! is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            $filename = 'in_' . bin2hex(random_bytes(8)) . '.' . $ext;
            if (file_put_contents($dir . $filename, $downloaded['content']) === false) {
                return null;
            }

            return site_url('media/serve/' . $filename);
        } catch (Throwable $e) {
            log_message('warning', 'Inbound media download failed: {msg}', ['msg' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    protected function detectEventType(array $payload): string
    {
        $object = (string) ($payload['object'] ?? '');
        if ($object === 'page' || $object === 'instagram') {
            $messaging = $payload['entry'][0]['messaging'][0] ?? [];
            if (is_array($messaging) && ! empty($messaging['message'])) {
                return 'page_messages';
            }
            if (is_array($messaging) && ! empty($messaging['delivery'])) {
                return 'page_delivery';
            }
            if (is_array($messaging) && ! empty($messaging['read'])) {
                return 'page_read';
            }

            return $object;
        }

        $entries = $payload['entry'][0]['changes'][0]['value'] ?? [];
        if (! empty($entries['messages'])) {
            return 'messages';
        }
        if (! empty($entries['statuses'])) {
            return 'statuses';
        }

        return $object !== '' ? $object : 'unknown';
    }
}
