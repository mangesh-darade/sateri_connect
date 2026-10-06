<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Libraries\WebhookValidator;
use App\Libraries\WhatsAppConsentService;
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

                $field = (string) ($change['field'] ?? '');
                if (in_array($field, [
                    'phone_number_quality_update',
                    'business_capability_update',
                    'account_update',
                    'message_template_quality_update',
                    'message_template_status_update',
                    'template_category_update',
                ], true)) {
                    try {
                        service('whatsAppConsent')->handleAccountWebhook($field, $value);
                    } catch (Throwable $e) {
                        log_message('error', 'WABA {field} webhook failed: {msg}', ['field' => $field, 'msg' => $e->getMessage()]);
                    }
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
                $name !== '' ? $name : $senderId,
                trim(($senderId !== '' && $senderId !== $name ? $senderId . ' · ' : '') . ($preview !== '' ? $preview : '(' . $type . ')')),
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
                $name !== '' ? $name : $from,
                trim(($from !== '' && $from !== $name ? $from . ' · ' : '') . ($preview !== '' ? $preview : '(' . ($parsed['type'] ?? 'message') . ')')),
                site_url('chat?contact_id=' . $contactId),
                $assign
            );
        } catch (Throwable $e) {
            log_message('warning', 'Inbound notification failed: {msg}', ['msg' => $e->getMessage()]);
        }

        // WhatsApp policy: STOP / START win over every bot and automation.
        $consent       = service('whatsAppConsent');
        $isButtonTap   = in_array((string) ($parsed['type'] ?? ''), ['button', 'interactive'], true);
        $buttonPayload = (string) ($parsed['reply_id'] ?? '');
        $consentText   = trim((string) ($parsed['content'] ?? ''));
        $consentIntent = $isButtonTap
            ? $consent->detectButtonIntent($consentText, $buttonPayload)
            : $consent->detectIntent($consentText);
        if (in_array($consentIntent, [WhatsAppConsentService::INTENT_OPT_OUT, WhatsAppConsentService::INTENT_OPT_IN], true)) {
            $consent->handleConsentKeyword(
                $contact,
                $consentText,
                $activeProvider,
                $autoReplyAllowed,
                $isButtonTap,
                $buttonPayload
            );

            return;
        }

        // Reply to an open workflow "Ask question": continue that flow instead of starting new ones.
        if ($autoReplyAllowed) {
            try {
                $wa = service('whatsApp');
                $wa->forceProvider($activeProvider);
                if (service('automationEngine')->handleAwaitedReply($contactId, [
                    'content'      => (string) ($parsed['content'] ?? ''),
                    'reply_id'     => (string) ($parsed['reply_id'] ?? ''),
                    'message_type' => (string) ($parsed['type'] ?? ''),
                    'message_id'   => (int) $messageId,
                ])) {
                    service('queueService')->processBatch(30);

                    return;
                }
            } catch (Throwable $e) {
                log_message('error', 'Workflow question reply error: {msg}', ['msg' => $e->getMessage()]);
            }
        }

        // First message from a customer who has not answered consent yet: ask with Agree / Stop.
        if ($autoReplyAllowed) {
            $consent->requestConsentIfPending($contactModel->find($contactId) ?? $contact, $activeProvider);
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

        $botMatched = false;
        if ($autoReplyAllowed && ($replyId !== '' || $runKeywordMatch)) {
            try {
                $bot = service('keywordBot');
                // Always send with Settings → active provider (not the other WABA).
                $bot->setSendProvider($activeProvider);
                if ($replyId !== '' && preg_match('/^kw_(\d+)$/', $replyId, $m)) {
                    $botMatched = ! empty($bot->replyByKeywordId($contactId, (int) $m[1])['matched']);
                } elseif ($runKeywordMatch) {
                    $botMatched = ! empty($bot->matchAndReply($contactId, $keywordText)['matched']);
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

        // Policy: automated chats must offer a direct path to a person.
        if (! $botMatched && $consentIntent === WhatsAppConsentService::INTENT_HUMAN) {
            $consent->escalateToHuman($contact, $activeProvider);

            return;
        }

        try {
            $wa = service('whatsApp');
            $wa->forceProvider($activeProvider);

            $autoRes1 = service('automationEngine')->processTrigger('message_received', [
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

            $autoRes2 = ['matched' => 0, 'executed' => 0];
            // Keyword bot already answered — a second keyword-flow reply is a duplicate.
            if ($runKeywordMatch && ! $botMatched) {
                $autoRes2 = service('automationEngine')->processTrigger('keyword', [
                    'contact_id'   => $contactId,
                    'content'      => $keywordText,
                    'text'         => $keywordText,
                    'caption'      => $keywordText,
                    'message_type' => $msgType,
                    'provider'     => $activeProvider,
                ]);
            }

            // Flush automation replies and process due delays immediately so WhatsApp users get answers without waiting for cron
            try {
                service('automationEngine')->processDelayedJobs();
                service('queueService')->processBatch(30);
            } catch (Throwable $qe) {
                log_message('error', 'Post-automation queue flush failed: {msg}', ['msg' => $qe->getMessage()]);
            }

            // AI Smart Assistant / Fallback:
            // When neither Keyword Bot nor Automation workflows triggered, invoke AI
            $hasExecutedAction = ($autoRes1['executed'] ?? 0) > 0 || ($autoRes2['executed'] ?? 0) > 0;
            if (! $botMatched && ! $hasExecutedAction && $runKeywordMatch) {
                $this->handleAiAutoReply($contactId, (int) $conversation['id'], $keywordText, $from, $channel, $activeProvider);
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

        // Keep message_queue status accurate on delivery failure
        if ($newStatus === 'failed') {
            $errText = (string) ($errors[0]['title'] ?? $errors[0]['message'] ?? 'Delivery failed');
            db_connect()->table('message_queue')->where('wa_message_id', $waId)->update([
                'status'        => 'failed',
                'error_message' => $errText,
                'updated_at'    => date('Y-m-d H:i:s'),
            ]);
        }

        if ($newStatus === 'failed' && is_array($errors) && isset($errors[0]['code'])) {
            $contactId = (int) ($message['contact_id'] ?? $cc['contact_id'] ?? 0);
            if ($contactId > 0) {
                try {
                    service('whatsAppConsent')->applyDeliveryFailure(
                        $contactId,
                        (string) $errors[0]['code'],
                        (string) ($errors[0]['title'] ?? $errors[0]['message'] ?? '')
                    );
                } catch (Throwable $e) {
                    log_message('error', 'Delivery failure policy update failed: {msg}', ['msg' => $e->getMessage()]);
                }
            }
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

    /**
     * AI-powered auto-reply with strict anti-ban, anti-loop, and rate limit guardrails.
     */
    protected function handleAiAutoReply(int $contactId, int $conversationId, string $userMessage, string $from, string $channel, string $activeProvider): void
    {
        try {
            $aiService = service('aiService');
            if (! $aiService->isConfigured()) {
                return;
            }

            $contactModel = model(ContactModel::class);
            $contact = $contactModel->find($contactId);
            if (! is_array($contact)) {
                return;
            }

            $convModel = model(ConversationModel::class);
            $conversation = $convModel->find($conversationId);
            if (! is_array($conversation)) {
                return;
            }

            // Strict anti-ban safety gate (opt-out, 24h window, consecutive limit, agent active)
            $gate = $aiService->canReply($contact, $conversation, $userMessage);
            if (! $gate['allowed']) {
                log_message('notice', 'AI reply skipped for contact #{cid} ({phone}): reason={reason}', [
                    'cid'    => $contactId,
                    'phone'  => $from,
                    'reason' => $gate['reason'],
                ]);

                // If user specifically requested human assistance, escalate cleanly
                if ($gate['reason'] === 'human_requested') {
                    service('whatsAppConsent')->escalateToHuman($contact, $activeProvider);
                }

                return;
            }

            // Load last 4 messages for conversational context
            $messagesModel = model(MessageModel::class);
            $recent = $messagesModel->where('contact_id', $contactId)
                ->orderBy('id', 'DESC')
                ->findAll(4);
            $recent = array_reverse($recent);

            $reply = $aiService->generateReply($userMessage, $contact, $conversation, $recent);
            if ($reply === null || trim($reply) === '') {
                return;
            }

            $wa = service('whatsApp');
            $wa->forceProvider($activeProvider);

            $sendRes = $wa->sendText($from, $reply);
            $wamid = (string) ($sendRes['messages'][0]['id'] ?? $sendRes['id'] ?? '');

            $replyMsgId = $messagesModel->insert([
                'contact_id'          => $contactId,
                'conversation_id'     => $conversationId,
                'channel'             => $channel,
                'direction'           => 'outbound',
                'message_type'        => 'text',
                'external_message_id' => $wamid !== '' ? $wamid : null,
                'wa_message_id'       => $wamid !== '' ? $wamid : null,
                'wamid'               => $wamid !== '' ? $wamid : null,
                'content'             => $reply,
                'status'              => 'sent',
                'is_read'             => 1,
            ]);

            $convModel->update($conversationId, [
                'last_message_id' => $replyMsgId,
                'last_message_at' => date('Y-m-d H:i:s'),
                'status'          => 'open',
            ]);
        } catch (Throwable $e) {
            log_message('error', 'AI auto-reply handler failed: {msg}', ['msg' => $e->getMessage()]);
        }
    }
}
