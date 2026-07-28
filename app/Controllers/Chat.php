<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Libraries\ActivityLogger;
use App\Models\ContactModel;
use App\Models\ConversationModel;
use App\Models\InternalNoteModel;
use App\Models\MessageModel;
use App\Models\TemplateModel;
use App\Models\UserModel;
use CodeIgniter\HTTP\ResponseInterface;
use Throwable;

/**
 * WhatsApp-style inbox: conversations, messages, send, notes.
 */
class Chat extends BaseController
{
    public function index(): string|ResponseInterface
    {
        if ($denied = $this->requirePermission('chat.view')) {
            return $denied;
        }

        $contactId = (int) ($this->request->getGet('contact_id') ?? 0);
        $channel   = strtolower(trim((string) ($this->request->getGet('channel') ?? 'whatsapp')));
        if (! in_array($channel, ['all', 'whatsapp', 'instagram', 'messenger'], true)) {
            $channel = 'whatsapp';
        }
        $settings  = service('settingsService');
        $activeWh  = $settings->getActiveWebhookConfig();
        $publicBase = trim((string) $settings->get('webhook_public_base', ''));
        $pageApi   = service('pageMessaging');

        $channelLabels = [
            'all'       => ['title' => 'Team Inbox', 'subtitle' => 'All channels'],
            'whatsapp'  => ['title' => (string) (setting('business_phone', 'WhatsApp Inbox') ?? 'WhatsApp Inbox'), 'subtitle' => 'WABA Number'],
            'instagram' => ['title' => 'Instagram Inbox', 'subtitle' => 'Instagram Messaging'],
            'messenger' => ['title' => 'Messenger Inbox', 'subtitle' => 'Facebook Messenger'],
        ];

        return $this->render('chat/index', [
            'pageTitle'       => $channelLabels[$channel]['title'] ?? 'Inbox',
            'chatPage'        => true,
            'inboxChannel'    => $channel,
            'inboxTitle'      => $channelLabels[$channel]['title'] ?? 'Inbox',
            'inboxSubtitle'   => $channelLabels[$channel]['subtitle'] ?? '',
            'instagramEnabled'=> $settings->isInstagramInboxEnabled() && $pageApi->isConfigured(),
            'messengerEnabled'=> $settings->isMessengerInboxEnabled() && $pageApi->isConfigured(),
            'selectedContact' => $contactId > 0 ? model(ContactModel::class)->getWithTags($contactId) : null,
            'templates'       => model(TemplateModel::class)
                ->where('status', 'APPROVED')
                ->orderBy('name', 'ASC')
                ->findAll(100),
            'agents'          => model(UserModel::class)
                ->where('status', 'active')
                ->orderBy('name', 'ASC')
                ->findAll(200),
            'webhookReady'    => $publicBase !== ''
                && str_starts_with($publicBase, 'https://')
                && trim((string) ($activeWh['verify_token'] ?? '')) !== '',
            'whatsappProvider'=> $settings->getWhatsAppProvider(),
        ]);
    }

    public function conversations(): ResponseInterface
    {
        if ($denied = $this->requirePermission('chat.view')) {
            return $denied;
        }

        try {
            $search = (string) ($this->request->getGet('q') ?? '');
            $status = (string) ($this->request->getGet('status') ?? 'open');
            $channel = strtolower(trim((string) ($this->request->getGet('channel') ?? 'all')));
            if (! in_array($channel, ['all', 'whatsapp', 'instagram', 'messenger'], true)) {
                $channel = 'all';
            }
            $unreadOnly = (int) ($this->request->getGet('unread_only') ?? 0) === 1;
            $assignedToRaw = $this->request->getGet('assigned_to');
            $assignedTo = null;
            if ($assignedToRaw !== null && $assignedToRaw !== '') {
                if ((string) $assignedToRaw === 'unassigned') {
                    $assignedTo = 'unassigned';
                } else {
                    $assignedTo = (int) $assignedToRaw;
                }
            }
            $limit  = max(1, min(100, (int) ($this->request->getGet('limit') ?? 50)));
            $offset = max(0, (int) ($this->request->getGet('offset') ?? 0));

            $db = db_connect();
            $hasConvChannel    = $db->fieldExists('channel', 'conversations');
            $hasContactChannel = $db->fieldExists('channel', 'contacts');
            $hasExternalId     = $db->fieldExists('external_id', 'contacts');
            $hasDeletedAt      = $db->fieldExists('deleted_at', 'contacts');

            $select = 'cv.*, c.name AS contact_name, c.mobile, c.last_reply_at,
                       c.last_message_at AS contact_last_message_at,
                       m.content AS last_message_content, m.direction AS last_message_direction,
                       m.message_type AS last_message_type';
            if ($hasContactChannel) {
                $select .= ', c.channel AS contact_channel';
            }
            if ($hasExternalId) {
                $select .= ', c.external_id';
            }

            $builder = $db->table('conversations cv')
                ->select($select)
                ->join('contacts c', 'c.id = cv.contact_id')
                ->join('messages m', 'm.id = cv.last_message_id', 'left');

            if ($hasDeletedAt) {
                $builder->where('c.deleted_at', null);
            }

            if ($status !== '' && $status !== 'all') {
                $builder->where('cv.status', $status);
            }
            if ($channel !== 'all') {
                if ($hasConvChannel) {
                    $builder->where('cv.channel', $channel);
                } elseif ($hasContactChannel) {
                    $builder->where('c.channel', $channel);
                }
            }
            if ($unreadOnly) {
                $builder->where('cv.unread_count >', 0);
            }
            if ($assignedTo === 'unassigned') {
                $builder->where('cv.assigned_to', null);
            } elseif (is_int($assignedTo) && $assignedTo > 0) {
                $builder->where('cv.assigned_to', $assignedTo);
            }

            if ($search !== '') {
                $builder->groupStart()
                    ->like('c.name', $search)
                    ->orLike('c.mobile', $search);
                if ($hasExternalId) {
                    $builder->orLike('c.external_id', $search);
                }
                $builder->groupEnd();
            }

            $rows = $builder
                ->orderBy('cv.last_message_at', 'DESC')
                ->limit($limit, $offset)
                ->get()
                ->getResultArray();

            $normalized = [];
            foreach ($rows as $row) {
                $rowChannel = (string) ($row['channel'] ?? $row['contact_channel'] ?? 'whatsapp');
                $normalized[] = [
                    'id'              => (int) ($row['id'] ?? 0),
                    'contact_id'      => (int) ($row['contact_id'] ?? 0),
                    'channel'         => $rowChannel,
                    'name'            => (string) ($row['contact_name'] ?? ''),
                    'contact_name'    => (string) ($row['contact_name'] ?? ''),
                    'mobile'          => (string) ($row['mobile'] ?? $row['external_id'] ?? ''),
                    'external_id'     => (string) ($row['external_id'] ?? ''),
                    'status'          => (string) ($row['status'] ?? ''),
                    'assigned_to'     => isset($row['assigned_to']) && $row['assigned_to'] !== null && $row['assigned_to'] !== ''
                        ? (int) $row['assigned_to']
                        : null,
                    'unread_count'    => (int) ($row['unread_count'] ?? 0),
                    'last_message_at' => $row['last_message_at'] ?? null,
                    'last_message'    => (string) ($row['last_message_content'] ?? ''),
                    'last_message_content' => (string) ($row['last_message_content'] ?? ''),
                    'last_message_direction' => (string) ($row['last_message_direction'] ?? ''),
                    'within_24h'      => is_within_24h_window($row['last_reply_at'] ?? null),
                ];
            }

            return $this->jsonResponse(true, $normalized);
        } catch (Throwable $e) {
            log_message('error', 'Chat conversations failed: {msg}', ['msg' => $e->getMessage()]);

            return $this->jsonResponse(false, null, $e->getMessage(), [], 500);
        }
    }

    public function messages(int $contactId): ResponseInterface
    {
        if ($denied = $this->requirePermission('chat.view')) {
            return $denied;
        }

        try {
            $contact = model(ContactModel::class)->find($contactId);
            if ($contact === null) {
                return $this->jsonResponse(false, null, 'Contact not found.', [], 404);
            }

            $channel = strtolower(trim((string) ($contact['channel'] ?? 'whatsapp'))) ?: 'whatsapp';
            $conversation = model(ConversationModel::class)->findOrCreateForContact($contactId, $channel);
            $limit        = max(1, min(200, (int) ($this->request->getGet('limit') ?? 50)));
            $beforeId     = (int) ($this->request->getGet('before_id') ?? 0);
            $afterId      = (int) ($this->request->getGet('after_id') ?? 0);

            $model = model(MessageModel::class)->where('contact_id', $contactId);
            if ($afterId > 0) {
                $model->where('id >', $afterId);
                $messages = $model->orderBy('id', 'ASC')->findAll($limit);
            } else {
                if ($beforeId > 0) {
                    $model->where('id <', $beforeId);
                }
                $messages = $model->orderBy('id', 'DESC')->findAll($limit);
                $messages = array_reverse($messages);
            }

            $notes = $afterId > 0
                ? []
                : model(InternalNoteModel::class)->getForContact($contactId);

            $statusUpdates = [];
            if ($afterId > 0) {
                // Silent poll: refresh delivery ticks (Cheerio often skips status webhooks)
                $this->refreshCheerioOutboundStatuses($contactId, 10);
                $statusUpdates = model(MessageModel::class)
                    ->select('id, status')
                    ->where('contact_id', $contactId)
                    ->where('direction', 'outbound')
                    ->orderBy('id', 'DESC')
                    ->findAll(40);
            } else {
                // Opening thread — sync a few recent ticks once
                $this->refreshCheerioOutboundStatuses($contactId, 6);
                // Mark conversation read when opening (not on silent poll)
                model(ConversationModel::class)->resetUnread((int) $conversation['id']);
                model(MessageModel::class)
                    ->where('contact_id', $contactId)
                    ->where('direction', 'inbound')
                    ->where('is_read', 0)
                    ->set(['is_read' => 1])
                    ->update();
            }

            return $this->jsonResponse(true, [
                'contact'         => $contact,
                'conversation'    => $conversation,
                'messages'        => $messages,
                'notes'           => $notes,
                'within_24h'      => is_within_24h_window($contact['last_reply_at'] ?? null),
                'status_updates'  => $statusUpdates,
            ]);
        } catch (Throwable $e) {
            log_message('error', 'Chat messages failed: {msg}', ['msg' => $e->getMessage()]);

            return $this->jsonResponse(false, null, $e->getMessage(), [], 500);
        }
    }

    public function send(): ResponseInterface
    {
        if ($denied = $this->requirePermission('chat.send')) {
            return $denied;
        }

        $input = $this->requestInput();

        $contactId   = (int) ($input['contact_id'] ?? 0);
        // Accept both UI (`type`/`message`) and API (`message_type`/`content`) contracts.
        $messageType = (string) ($input['message_type'] ?? $input['type'] ?? 'text');
        if ($messageType === 'media') {
            $messageType = 'image'; // refined after file mime detection below
        }
        $content = (string) ($input['content'] ?? $input['text'] ?? $input['message'] ?? '');

        $contact = model(ContactModel::class)->find($contactId);
        if ($contact === null) {
            return $this->jsonResponse(false, null, 'Contact not found.', [], 404);
        }

        $channel = strtolower(trim((string) ($contact['channel'] ?? 'whatsapp'))) ?: 'whatsapp';
        $within24h = is_within_24h_window($contact['last_reply_at'] ?? null);

        if ($channel !== 'whatsapp' && $messageType === 'template') {
            return $this->jsonResponse(false, null, 'Templates are only available on WhatsApp.', [], 422);
        }

        if (! $within24h) {
            if ($channel === 'whatsapp' && $messageType !== 'template') {
                return $this->jsonResponse(
                    false,
                    null,
                    'Outside the 24-hour window. Only template messages can be sent.',
                    [],
                    422
                );
            }
            if ($channel !== 'whatsapp') {
                return $this->jsonResponse(
                    false,
                    null,
                    'Outside the 24-hour messaging window for this channel.',
                    [],
                    422
                );
            }
        }

        try {
            $templateName = (string) ($input['template_name'] ?? '');
            $mediaId      = (string) ($input['media_id'] ?? '');
            $mediaUrl     = (string) ($input['media_url'] ?? '');
            $externalId   = null;

            if (in_array($channel, ['instagram', 'messenger'], true)) {
                $pageApi = service('pageMessaging');
                if (! $pageApi->isConfigured()) {
                    return $this->jsonResponse(false, null, 'Page messaging is not configured. Add Page ID + Page Access Token in Settings.', [], 422);
                }
                $settings = service('settingsService');
                if ($channel === 'instagram' && ! $settings->isInstagramInboxEnabled()) {
                    return $this->jsonResponse(false, null, 'Instagram inbox is disabled in Settings.', [], 422);
                }
                if ($channel === 'messenger' && ! $settings->isMessengerInboxEnabled()) {
                    return $this->jsonResponse(false, null, 'Messenger inbox is disabled in Settings.', [], 422);
                }

                $recipientId = trim((string) ($contact['external_id'] ?? ''));
                if ($recipientId === '') {
                    return $this->jsonResponse(false, null, 'Contact has no channel recipient id.', [], 422);
                }

                $file = $this->request->getFile('file') ?? $this->request->getFile('media');
                if ($file !== null && $file->isValid()) {
                    $allowedMimes = [
                        'image/jpeg', 'image/png', 'image/webp', 'image/gif',
                        'application/pdf',
                        'audio/mpeg', 'audio/ogg', 'audio/aac', 'audio/mp4',
                        'video/mp4', 'video/3gpp',
                        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                        'application/msword',
                        'text/plain', 'text/csv',
                    ];
                    $mime = $file->getMimeType() ?: 'application/octet-stream';
                    if (! in_array($mime, $allowedMimes, true)) {
                        return $this->jsonResponse(false, null, 'File type not allowed: ' . $mime, [], 422);
                    }
                    if ($file->getSize() > 16 * 1024 * 1024) {
                        return $this->jsonResponse(false, null, 'File exceeds 16MB limit.', [], 422);
                    }
                    $dir = WRITEPATH . 'uploads/media/';
                    if (! is_dir($dir)) {
                        mkdir($dir, 0755, true);
                    }
                    $name = $file->getRandomName();
                    $file->move($dir, $name);
                    $mediaUrl = site_url('media/serve/' . $name);
                    if (str_starts_with($mime, 'image/')) {
                        $messageType = 'image';
                    } elseif (str_starts_with($mime, 'video/')) {
                        $messageType = 'video';
                    } elseif (str_starts_with($mime, 'audio/')) {
                        $messageType = 'audio';
                    } else {
                        $messageType = 'file';
                    }
                }

                $attachType = match ($messageType) {
                    'image' => 'image',
                    'video' => 'video',
                    'audio' => 'audio',
                    'file', 'document' => 'file',
                    default => 'text',
                };

                if ($attachType === 'text') {
                    $result = $pageApi->sendText($recipientId, $content, $channel);
                } else {
                    if ($mediaUrl === '') {
                        return $this->jsonResponse(false, null, 'Media URL is required for attachments.', [], 422);
                    }
                    $result = $pageApi->sendAttachment(
                        $recipientId,
                        $attachType,
                        $mediaUrl,
                        $content !== '' ? $content : null
                    );
                    $messageType = $attachType === 'file' ? 'document' : $attachType;
                }

                $externalId = (string) ($result['message_id'] ?? $result['id'] ?? '');
            } else {
                $api = service('whatsApp');
                $to  = $api->normalizePhone((string) ($contact['mobile'] ?? $contact['external_id'] ?? ''));

                $language   = (string) ($input['language'] ?? 'en_US');
                $components = is_array($input['components'] ?? null) ? $input['components'] : [];

                // Meta free-form only works on Meta's number session. Cheerio-inbound chats
                // look "open" locally but Meta returns 131047 Re-engagement.
                if ($messageType !== 'template') {
                    $activeProvider = service('settingsService')->getWhatsAppProvider();
                    $lastInboundProvider = model(MessageModel::class)->lastInboundProvider($contactId);
                    if ($activeProvider === 'meta' && $lastInboundProvider === 'cheerio') {
                        $metaPhone = preg_replace(
                            '/\D+/',
                            '',
                            (string) (service('settingsService')->get('meta_display_phone', '') ?: '')
                        ) ?: '';
                        if ($metaPhone === '') {
                            try {
                                $info = $api->getPhoneNumberInfo();
                                $metaPhone = preg_replace('/\D+/', '', (string) ($info['display_phone'] ?? '')) ?: '';
                            } catch (Throwable $ignored) {
                                $metaPhone = '';
                            }
                        }
                        $metaHint = $metaPhone !== '' ? ('+' . $metaPhone) : 'your Meta WhatsApp number';

                        return $this->jsonResponse(
                            false,
                            null,
                            'This chat arrived on the Cheerio number, so Meta free-text fails (131047). '
                            . 'Click Template to send a Meta template, or ask the customer to WhatsApp '
                            . $metaHint . ' first.',
                            [],
                            422
                        );
                    }
                }

                if ($messageType === 'template') {
                    $templateId = (int) ($input['template_id'] ?? 0);
                    if ($templateId > 0) {
                        $tpl = model(TemplateModel::class)->find($templateId);
                        if ($tpl === null) {
                            return $this->jsonResponse(false, null, 'Template not found.', [], 404);
                        }
                        $status = strtoupper((string) ($tpl['status'] ?? ''));
                        if ($status !== '' && $status !== 'APPROVED') {
                            return $this->jsonResponse(
                                false,
                                null,
                                'Only APPROVED templates can be sent. Current status: ' . $status,
                                [],
                                422
                            );
                        }
                        $templateName = (string) $tpl['name'];
                        $language     = (string) ($tpl['language'] ?? $language);
                    }
                    if ($templateName === '') {
                        return $this->jsonResponse(false, null, 'Template name is required.', [], 422);
                    }
                    if ($components === [] && is_array($input['variables'] ?? null)) {
                        $components = $this->variablesToComponents((array) $input['variables'], $contact);
                    }
                }

                $file = $this->request->getFile('file') ?? $this->request->getFile('media');
                if ($file !== null && $file->isValid()) {
                    $allowedMimes = [
                        'image/jpeg', 'image/png', 'image/webp', 'image/gif',
                        'application/pdf',
                        'audio/mpeg', 'audio/ogg', 'audio/aac', 'audio/mp4',
                        'video/mp4', 'video/3gpp',
                        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                        'application/msword',
                        'text/plain', 'text/csv',
                    ];
                    $mime = $file->getMimeType() ?: 'application/octet-stream';
                    if (! in_array($mime, $allowedMimes, true)) {
                        return $this->jsonResponse(false, null, 'File type not allowed: ' . $mime, [], 422);
                    }
                    if ($file->getSize() > 16 * 1024 * 1024) {
                        return $this->jsonResponse(false, null, 'File exceeds 16MB limit.', [], 422);
                    }
                    $dir  = WRITEPATH . 'uploads/media/';
                    if (! is_dir($dir)) {
                        mkdir($dir, 0755, true);
                    }
                    $name = $file->getRandomName();
                    $file->move($dir, $name);
                    $path = $dir . $name;
                    $uploaded = $api->uploadMedia($path, $mime);
                    $mediaId  = (string) ($uploaded['id'] ?? '');
                    $mediaUrl = site_url('media/serve/' . $name);
                    if (str_starts_with($mime, 'image/')) {
                        $messageType = 'image';
                    } elseif (str_starts_with($mime, 'video/')) {
                        $messageType = 'video';
                    } elseif (str_starts_with($mime, 'audio/')) {
                        $messageType = 'audio';
                    } else {
                        $messageType = 'document';
                    }
                    if (! $within24h) {
                        return $this->jsonResponse(false, null, 'Outside the 24-hour window. Only template messages can be sent.', [], 422);
                    }
                }

                $result = match ($messageType) {
                    'template' => $api->sendTemplate($to, $templateName, $language, $components),
                    'image' => $api->sendImage(
                        $to,
                        $mediaId !== '' ? $mediaId : $mediaUrl,
                        $content !== '' ? $content : null,
                        $mediaId !== ''
                    ),
                    'document' => $api->sendDocument(
                        $to,
                        $mediaId !== '' ? $mediaId : $mediaUrl,
                        $content !== '' ? $content : null,
                        (string) ($input['filename'] ?? 'document'),
                        $mediaId !== ''
                    ),
                    'audio' => $api->sendAudio(
                        $to,
                        $mediaId !== '' ? $mediaId : $mediaUrl,
                        $mediaId !== ''
                    ),
                    'video' => $api->sendVideo(
                        $to,
                        $mediaId !== '' ? $mediaId : $mediaUrl,
                        $content !== '' ? $content : null,
                        $mediaId !== ''
                    ),
                    default => $api->sendText($to, $content),
                };

                $externalId = (string) ($result['messages'][0]['id'] ?? '');
            }

            $conversation = model(ConversationModel::class)->findOrCreateForContact($contactId, $channel);

            $messageId = model(MessageModel::class)->insert([
                'contact_id'           => $contactId,
                'conversation_id'      => (int) $conversation['id'],
                'channel'              => $channel,
                'direction'            => 'outbound',
                'message_type'         => $messageType,
                'wa_message_id'        => $channel === 'whatsapp' ? ($externalId !== '' ? $externalId : null) : null,
                'wamid'                => $channel === 'whatsapp' ? ($externalId !== '' ? $externalId : null) : null,
                'external_message_id'  => $externalId !== '' ? $externalId : null,
                'content'              => $content !== '' ? $content : ($templateName !== '' ? $templateName : null),
                'media_url'            => $mediaUrl !== '' ? $mediaUrl : null,
                'media_id'             => $mediaId !== '' ? $mediaId : null,
                'payload'              => $result,
                'status'               => 'sent',
                'is_read'              => 1,
            ]);

            model(ConversationModel::class)->update((int) $conversation['id'], [
                'last_message_id' => $messageId,
                'last_message_at' => date('Y-m-d H:i:s'),
                'status'          => 'open',
            ]);

            model(ContactModel::class)->update($contactId, [
                'last_message_at' => date('Y-m-d H:i:s'),
            ]);

            (new ActivityLogger())->log('send', 'chat', 'Outbound message sent', [
                'contact_id' => $contactId,
                'message_id' => $messageId,
                'channel'    => $channel,
            ]);

            $message = model(MessageModel::class)->find((int) $messageId);

            return $this->jsonResponse(true, $message, 'Message sent.');
        } catch (Throwable $e) {
            log_message('error', 'Chat send failed: {msg}', ['msg' => $e->getMessage()]);

            return $this->jsonResponse(false, null, $e->getMessage(), [], 500);
        }
    }

    public function markRead(): ResponseInterface
    {
        if ($denied = $this->requirePermission('chat.view')) {
            return $denied;
        }

        $input     = $this->requestInput();
        $contactId = (int) ($input['contact_id'] ?? 0);

        if ($contactId <= 0) {
            return $this->jsonResponse(false, null, 'contact_id is required.', [], 422);
        }

        $conversation = model(ConversationModel::class)->findByContact($contactId);
        if ($conversation !== null) {
            model(ConversationModel::class)->resetUnread((int) $conversation['id']);
        }

        model(MessageModel::class)
            ->where('contact_id', $contactId)
            ->where('direction', 'inbound')
            ->where('is_read', 0)
            ->set(['is_read' => 1])
            ->update();

        // Optionally mark last inbound as read via Cheerio
        $lastInbound = model(MessageModel::class)
            ->where('contact_id', $contactId)
            ->where('direction', 'inbound')
            ->where('wa_message_id IS NOT NULL', null, false)
            ->orderBy('id', 'DESC')
            ->first();

        if ($lastInbound && ! empty($lastInbound['wa_message_id'])) {
            try {
                service('whatsApp')->markAsRead((string) $lastInbound['wa_message_id']);
            } catch (Throwable $e) {
                log_message('debug', 'markAsRead API skipped: {msg}', ['msg' => $e->getMessage()]);
            }
        }

        return $this->jsonResponse(true, null, 'Marked as read.');
    }

    public function addNote(): ResponseInterface
    {
        if ($denied = $this->requirePermission('chat.view')) {
            return $denied;
        }

        $input     = $this->requestInput();
        $contactId = (int) ($input['contact_id'] ?? 0);
        $note      = trim((string) ($input['note'] ?? ''));

        if ($contactId <= 0 || $note === '') {
            return $this->jsonResponse(false, null, 'contact_id and note are required.', [], 422);
        }

        if (model(ContactModel::class)->find($contactId) === null) {
            return $this->jsonResponse(false, null, 'Contact not found.', [], 404);
        }

        $id = model(InternalNoteModel::class)->insert([
            'contact_id'  => $contactId,
            'user_id'     => $this->userId(),
            'note'        => $note,
            'is_internal' => 1,
        ]);

        if (! $id) {
            return $this->jsonResponse(false, null, 'Failed to save note.', model(InternalNoteModel::class)->errors(), 422);
        }

        $saved = model(InternalNoteModel::class)->find((int) $id);

        return $this->jsonResponse(true, $saved, 'Note added.');
    }

    public function assign(): ResponseInterface
    {
        if ($denied = $this->requirePermission('chat.assign')) {
            return $denied;
        }

        $input       = $this->requestInput();
        $contactId   = (int) ($input['contact_id'] ?? 0);
        $assignedTo  = $input['assigned_to'] ?? null;
        $assignedTo  = $assignedTo === '' || $assignedTo === null ? null : (int) $assignedTo;

        if ($contactId <= 0) {
            return $this->jsonResponse(false, null, 'contact_id is required.', [], 422);
        }

        if (model(ContactModel::class)->find($contactId) === null) {
            return $this->jsonResponse(false, null, 'Contact not found.', [], 404);
        }

        if ($assignedTo !== null) {
            $agent = model(UserModel::class)->find($assignedTo);
            if ($agent === null || ($agent['status'] ?? '') !== 'active') {
                return $this->jsonResponse(false, null, 'Invalid agent.', [], 422);
            }
        }

        model(ContactModel::class)->update($contactId, ['assigned_to' => $assignedTo]);
        $conversation = model(ConversationModel::class)->findOrCreateForContact($contactId);
        model(ConversationModel::class)->update((int) $conversation['id'], ['assigned_to' => $assignedTo]);

        (new ActivityLogger())->log('assign', 'chat', 'Conversation assigned', [
            'contact_id'  => $contactId,
            'assigned_to' => $assignedTo,
        ]);

        return $this->jsonResponse(true, [
            'contact_id'  => $contactId,
            'assigned_to' => $assignedTo,
        ], 'Assigned.');
    }

    public function setStatus(): ResponseInterface
    {
        if ($denied = $this->requirePermission('chat.close')) {
            return $denied;
        }

        $input     = $this->requestInput();
        $contactId = (int) ($input['contact_id'] ?? 0);
        $status    = strtolower(trim((string) ($input['status'] ?? '')));

        if ($contactId <= 0 || ! in_array($status, ['open', 'closed'], true)) {
            return $this->jsonResponse(false, null, 'contact_id and status (open|closed) are required.', [], 422);
        }

        $conversation = model(ConversationModel::class)->findOrCreateForContact($contactId);
        model(ConversationModel::class)->update((int) $conversation['id'], ['status' => $status]);

        (new ActivityLogger())->log('status', 'chat', 'Conversation ' . $status, [
            'contact_id' => $contactId,
            'status'     => $status,
        ]);

        return $this->jsonResponse(true, [
            'contact_id' => $contactId,
            'status'     => $status,
        ], $status === 'closed' ? 'Conversation closed.' : 'Conversation reopened.');
    }

    public function search(): ResponseInterface
    {
        if ($denied = $this->requirePermission('chat.view')) {
            return $denied;
        }

        $q = (string) ($this->request->getGet('q') ?? '');
        if (strlen($q) < 2) {
            return $this->jsonResponse(true, []);
        }

        $messages = db_connect()->table('messages m')
            ->select('m.id, m.contact_id, m.content, m.created_at, c.name AS contact_name, c.mobile')
            ->join('contacts c', 'c.id = m.contact_id')
            ->like('m.content', $q)
            ->orderBy('m.created_at', 'DESC')
            ->limit(50)
            ->get()
            ->getResultArray();

        $contacts = model(ContactModel::class)->search($q, 20);

        return $this->jsonResponse(true, [
            'messages'  => $messages,
            'contacts'  => $contacts,
        ]);
    }

    /**
     * Convert simple variable map to WhatsApp template BODY components.
     *
     * @param array<string, mixed> $variables
     * @param array<string, mixed> $contact
     *
     * @return list<array<string, mixed>>
     */
    protected function variablesToComponents(array $variables, array $contact): array
    {
        if ($variables === []) {
            return [];
        }

        $keys = array_keys($variables);
        usort($keys, static function ($a, $b): int {
            if (is_numeric($a) && is_numeric($b)) {
                return (int) $a <=> (int) $b;
            }

            return strcmp((string) $a, (string) $b);
        });

        $parameters = [];
        foreach ($keys as $key) {
            $raw = trim((string) $variables[$key]);
            if (preg_match('/^\{\{\s*([a-zA-Z0-9_]+)\s*\}\}$/', $raw, $m)) {
                $raw = $m[1];
            }
            $field = strtolower($raw);
            $text  = match ($field) {
                'name' => (string) ($contact['name'] ?? ''),
                'mobile', 'phone' => (string) ($contact['mobile'] ?? ''),
                'email' => (string) ($contact['email'] ?? ''),
                default => $raw,
            };
            $parameters[] = ['type' => 'text', 'text' => $text !== '' ? $text : '-'];
        }

        return [[
            'type'       => 'body',
            'parameters' => $parameters,
        ]];
    }

    /**
     * Cheerio often does not push delivered/read webhooks. Poll status API and upgrade ticks.
     * Never downgrades (sent → delivered → read). Meta keeps using webhooks only.
     */
    protected function refreshCheerioOutboundStatuses(int $contactId, int $limit = 8): void
    {
        if (! function_exists('is_cheerio_provider') || ! is_cheerio_provider()) {
            return;
        }

        $rows = model(MessageModel::class)
            ->where('contact_id', $contactId)
            ->where('direction', 'outbound')
            ->whereIn('status', ['sent', 'delivered', 'queued', 'pending'])
            ->groupStart()
                ->where('wamid !=', '')
                ->orWhere('wa_message_id !=', '')
            ->groupEnd()
            ->orderBy('id', 'DESC')
            ->findAll(max(1, min(20, $limit)));

        if ($rows === []) {
            return;
        }

        $rank = [
            'queued'    => 0,
            'pending'   => 0,
            'sent'      => 1,
            'delivered' => 2,
            'read'      => 3,
            'failed'    => 4,
        ];

        $api = service('whatsApp');
        $api->forceProvider('cheerio');

        try {
            foreach ($rows as $row) {
                $wamid = (string) ($row['wamid'] ?? $row['wa_message_id'] ?? '');
                if ($wamid === '') {
                    continue;
                }

                try {
                    $driver = $api->getDriver();
                    if (! method_exists($driver, 'resolveDeliveryStatus')) {
                        break;
                    }
                    $newStatus = $driver->resolveDeliveryStatus($wamid);
                } catch (Throwable $e) {
                    log_message('debug', 'Cheerio status poll failed for {id}: {msg}', [
                        'id'  => $row['id'] ?? 0,
                        'msg' => $e->getMessage(),
                    ]);
                    continue;
                }

                if ($newStatus === null) {
                    continue;
                }

                $old = strtolower((string) ($row['status'] ?? 'sent'));
                $oldRank = $rank[$old] ?? 0;
                $newRank = $rank[$newStatus] ?? 0;

                // Allow failed anytime; otherwise only upgrade
                if ($newStatus !== 'failed' && $newRank <= $oldRank) {
                    continue;
                }

                model(MessageModel::class)->update((int) $row['id'], ['status' => $newStatus]);
            }
        } finally {
            $api->clearForcedProvider();
        }
    }
}
