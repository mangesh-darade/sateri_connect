<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Libraries\WhatsAppTemplateSendGuard;
use App\Models\ContactModel;
use App\Models\ConversationModel;
use App\Models\MessageModel;
use CodeIgniter\HTTP\ResponseInterface;
use RuntimeException;
use Throwable;

/**
 * REST API v1 Messages Controller.
 * Direct sending of WhatsApp text and approved template messages, and delivery status lookup.
 */
class MessagesController extends BaseV1Controller
{
    /**
     * Send direct WhatsApp text message (within 24-hour service window).
     * POST /api/v1/messages/send-text
     *
     * Body payload (JSON):
     * {
     *   "to": "+917744010738",
     *   "text": "Hello! Your appointment is confirmed for tomorrow."
     * }
     */
    public function sendText(): ResponseInterface
    {
        $input = $this->getJsonPayload();

        $rawTo = (string) ($input['to'] ?? $input['phone'] ?? $input['mobile'] ?? '');
        $text  = trim((string) ($input['text'] ?? $input['message'] ?? ''));

        $digits = preg_replace('/\D/', '', $rawTo);
        if ($rawTo === '' || strlen($digits) < 7 || strlen($digits) > 15) {
            return $this->respondValidationError(['to' => 'A valid recipient phone number is required (7 to 15 digits with country code, e.g. +917744010738).']);
        }
        if ($text === '') {
            return $this->respondValidationError(['text' => 'Message text cannot be empty.']);
        }
        if (mb_strlen($text) > 4096) {
            return $this->respondValidationError(['text' => 'Message text exceeds WhatsApp maximum length of 4096 characters.']);
        }

        $phone = preg_replace('/[^\d+]/', '', trim($rawTo));
        $contactModel = model(ContactModel::class);

        try {
            $contact = $contactModel->findOrCreateForChannel('whatsapp', $phone, ['mobile' => $phone]);
            $contactId = (int) $contact['id'];

            // Meta Policy: Check recipient opt-out status (STOP)
            $consentService = new \App\Libraries\WhatsAppConsentService();
            if ($consentService->isOptedOut($contact)) {
                return $this->respondError(
                    'Recipient has opted out of WhatsApp messages from this business (STOP). Under Meta policy, messages cannot be delivered.',
                    422,
                    ['policy' => 'meta_consent', 'code' => 'RECIPIENT_OPTED_OUT']
                );
            }
            if (($contact['status'] ?? '') === 'blocked') {
                return $this->respondError('Recipient contact is marked as blocked in your CRM.', 403);
            }

            // Meta Policy: 24-hour customer service window check
            $within24h = is_within_24h_window($contact['last_reply_at'] ?? null);
            if (! $within24h) {
                return $this->respondError(
                    'Customer is outside the WhatsApp 24-hour service window. According to Meta policy, you must send an approved template message to initiate a conversation.',
                    422,
                    ['suggestion' => 'Use endpoint POST /api/v1/messages/send-template']
                );
            }

            $wa = service('whatsApp');
            $normPhone = $wa->normalizePhone((string) ($contact['mobile'] ?? $phone));

            $sendRes = $wa->sendText($normPhone, $text);
            $wamid   = (string) ($sendRes['messages'][0]['id'] ?? $sendRes['id'] ?? uniqid('msg_'));

            $convModel    = model(ConversationModel::class);
            $conversation = $convModel->findOrCreateForContact($contactId);

            $msgModel = model(MessageModel::class);
            $msgId    = $msgModel->insert([
                'contact_id'          => $contactId,
                'conversation_id'     => (int) $conversation['id'],
                'channel'             => 'whatsapp',
                'direction'           => 'outbound',
                'message_type'        => 'text',
                'external_message_id' => $wamid,
                'wa_message_id'       => $wamid,
                'wamid'               => $wamid,
                'content'             => $text,
                'status'              => 'sent',
                'is_read'             => 1,
            ]);

            $convModel->update((int) $conversation['id'], [
                'last_message_id' => $msgId,
                'last_message_at' => date('Y-m-d H:i:s'),
                'status'          => 'open',
            ]);

            return $this->respondSuccess([
                'message_id'   => (int) $msgId,
                'wamid'        => $wamid,
                'to'           => $normPhone,
                'contact_id'   => $contactId,
                'status'       => 'sent',
                'delivered_at' => null,
                'created_at'   => date('Y-m-d H:i:s'),
            ], 'Message sent successfully.', 201);
        } catch (Throwable $e) {
            log_message('error', 'API Messages::sendText error: ' . $e->getMessage());

            return $this->respondError('Failed to send WhatsApp message: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Send approved WhatsApp Template message (outside or inside 24h window).
     * POST /api/v1/messages/send-template
     *
     * Body payload (JSON):
     * {
     *   "to": "+917744010738",
     *   "template_name": "order_confirmation",
     *   "language": "en_US",
     *   "variables": ["Mangesh", "ORD-9988", "1500"]
     * }
     */
    public function sendTemplate(): ResponseInterface
    {
        $input = $this->getJsonPayload();

        $rawTo        = (string) ($input['to'] ?? $input['phone'] ?? $input['mobile'] ?? '');
        $templateName = trim((string) ($input['template_name'] ?? $input['name'] ?? ''));
        $language     = trim((string) ($input['language'] ?? 'en_US'));

        $digits = preg_replace('/\D/', '', $rawTo);
        if ($rawTo === '' || strlen($digits) < 7 || strlen($digits) > 15) {
            return $this->respondValidationError(['to' => 'A valid recipient phone number is required (7 to 15 digits with country code, e.g. +917744010738).']);
        }
        if ($templateName === '') {
            return $this->respondValidationError(['template_name' => 'Approved template name is required.']);
        }
        if (! preg_match('/^[a-z0-9_]{1,150}$/i', $templateName)) {
            return $this->respondValidationError(['template_name' => 'Invalid template name format. Must be alphanumeric and underscore characters only.']);
        }

        $phone = preg_replace('/[^\d+]/', '', trim($rawTo));
        $contactModel = model(ContactModel::class);

        try {
            $contact = $contactModel->findOrCreateForChannel('whatsapp', $phone, ['mobile' => $phone]);
            $contactId = (int) $contact['id'];

            $guard = new WhatsAppTemplateSendGuard();
            try {
                $tpl = $guard->resolveApprovedTemplate(null, $templateName, $language);
            } catch (RuntimeException $e) {
                return $this->respondError($e->getMessage(), 422, ['policy' => 'template', 'code' => 'TEMPLATE_NOT_SENDABLE']);
            }

            // Same WhatsApp policy gate as campaigns / queue (opt-in, suppression, approval, caps, daily limit).
            $consentService = service('whatsAppConsent');
            if (filter_var($input['opt_in'] ?? false, FILTER_VALIDATE_BOOLEAN) && ! $consentService->isOptedOut($contact)) {
                $consentService->optIn($contactId, (string) ($input['opt_in_source'] ?? 'api'));
                $contact = $contactModel->find($contactId) ?? $contact;
            }
            $check = $consentService->templateSendCheck($contact, $tpl, null, null, true);
            if (! $check['ok']) {
                return $this->respondError(
                    $check['message'],
                    422,
                    [
                        'policy'        => 'meta_consent',
                        'code'          => $check['reason'] === 'opted_out' ? 'RECIPIENT_OPTED_OUT' : strtoupper('POLICY_' . $check['reason']),
                        'policy_reason' => $check['reason'],
                    ]
                );
            }

            $components = is_array($input['components'] ?? null) ? $input['components'] : [];
            if ($components === [] && is_array($input['variables'] ?? null)) {
                $components = $guard->buildBodyComponents($tpl, $input['variables']);
            } elseif ($components === []) {
                $components = $guard->buildBodyComponents($tpl, []);
            }

            // Handle media header if provided (with SSRF protection)
            $headerMediaUrl = trim((string) ($input['header_media_url'] ?? ''));
            if ($headerMediaUrl !== '') {
                if (! $this->validateSafeUrl($headerMediaUrl)) {
                    return $this->respondValidationError(['header_media_url' => 'Disallowed or invalid header media URL.']);
                }

                $mediaType = strtolower((string) ($tpl['header_type'] ?? 'image'));
                $components[] = [
                    'type'       => 'header',
                    'parameters' => [
                        [
                            'type'     => $mediaType,
                            $mediaType => ['link' => $headerMediaUrl],
                        ],
                    ],
                ];
            }

            $wa = service('whatsApp');
            $normPhone = $wa->normalizePhone((string) ($contact['mobile'] ?? $phone));

            $result = $wa->sendTemplate(
                $normPhone,
                (string) $tpl['name'],
                (string) ($tpl['language'] ?? $language),
                $components
            );

            $wamid = (string) ($result['messages'][0]['id'] ?? $result['id'] ?? uniqid('tpl_'));

            $convModel    = model(ConversationModel::class);
            $conversation = $convModel->findOrCreateForContact($contactId);

            $msgModel = model(MessageModel::class);
            $msgId    = $msgModel->insert([
                'contact_id'          => $contactId,
                'conversation_id'     => (int) $conversation['id'],
                'channel'             => 'whatsapp',
                'direction'           => 'outbound',
                'message_type'        => 'template',
                'external_message_id' => $wamid,
                'wa_message_id'       => $wamid,
                'wamid'               => $wamid,
                'content'             => "Template: {$templateName}",
                'status'              => 'sent',
                'is_read'             => 1,
            ]);

            $convModel->update((int) $conversation['id'], [
                'last_message_id' => $msgId,
                'last_message_at' => date('Y-m-d H:i:s'),
                'status'          => 'open',
            ]);

            log_activity('send', 'api', 'API WhatsApp template sent: ' . $tpl['name'], [
                'contact_id' => $contactId,
                'message_id' => (int) $msgId,
                'category'   => (string) ($tpl['category'] ?? ''),
            ]);

            return $this->respondSuccess([
                'message_id'    => (int) $msgId,
                'wamid'         => $wamid,
                'to'            => $normPhone,
                'contact_id'    => $contactId,
                'template_name' => $tpl['name'],
                'language'      => $tpl['language'],
                'status'        => 'sent',
                'created_at'    => date('Y-m-d H:i:s'),
            ], 'Template message dispatched successfully.', 201);
        } catch (Throwable $e) {
            log_message('error', 'API Messages::sendTemplate error: ' . $e->getMessage());

            return $this->respondError('Failed to send WhatsApp template: ' . $e->getMessage(), 400);
        }
    }

    /**
     * Conversation thread (inbound + outbound) for one WhatsApp number.
     * GET /api/v1/messages?phone=+917744010738&after_id=0&limit=50
     */
    public function index(): ResponseInterface
    {
        $rawPhone = trim((string) ($this->request->getGet('phone') ?? ''));
        $digits   = preg_replace('/\D/', '', $rawPhone);
        if (strlen($digits) < 7 || strlen($digits) > 15) {
            return $this->respondValidationError(['phone' => 'A valid phone number is required (7 to 15 digits with country code).']);
        }
        $afterId = max(0, (int) ($this->request->getGet('after_id') ?? 0));
        $limit   = min(100, max(1, (int) ($this->request->getGet('limit') ?? 50)));

        $contactModel = model(ContactModel::class);
        $contact = $contactModel->findByMobile('+' . $digits) ?? $contactModel->findByMobile($digits);
        if (! $contact) {
            return $this->respondSuccess([
                'contact'  => null,
                'messages' => [],
            ], 'No conversation for this number yet.');
        }

        $builder = model(MessageModel::class)
            ->where('contact_id', (int) $contact['id'])
            ->where('channel', 'whatsapp');
        if ($afterId > 0) {
            $rows = $builder->where('id >', $afterId)->orderBy('id', 'ASC')->findAll($limit);
        } else {
            $rows = array_reverse($builder->orderBy('id', 'DESC')->findAll($limit));
        }

        $messages = array_map(static fn (array $m): array => [
            'id'         => (int) $m['id'],
            'direction'  => (string) ($m['direction'] ?? ''),
            'type'       => (string) ($m['message_type'] ?? 'text'),
            'content'    => (string) ($m['content'] ?? ''),
            'media_url'  => $m['media_url'] ?? null,
            'status'     => (string) ($m['status'] ?? ''),
            'error'      => $m['error_message'] ?? null,
            'created_at' => (string) ($m['created_at'] ?? ''),
        ], $rows);

        return $this->respondSuccess([
            'contact' => [
                'id'            => (int) $contact['id'],
                'name'          => (string) ($contact['name'] ?? ''),
                'phone'         => (string) ($contact['mobile'] ?? ''),
                'last_reply_at' => $contact['last_reply_at'] ?? null,
                'within_24h'    => is_within_24h_window($contact['last_reply_at'] ?? null),
            ],
            'messages' => $messages,
        ], 'Conversation retrieved.');
    }

    /**
     * Check delivery status of a sent message.
     * GET /api/v1/messages/{idOrWamid}/status
     */
    public function status(string $idOrWamid): ResponseInterface
    {
        $msgModel = model(MessageModel::class);

        $msg = null;
        if (ctype_digit($idOrWamid)) {
            $msg = $msgModel->find((int) $idOrWamid);
        }

        if (! $msg) {
            $msg = $msgModel->where('wamid', $idOrWamid)
                ->orWhere('wa_message_id', $idOrWamid)
                ->first();
        }

        if (! $msg) {
            return $this->respondError('Message not found.', 404);
        }

        $contact = model(ContactModel::class)->find((int) $msg['contact_id']);

        return $this->respondSuccess([
            'message_id'   => (int) $msg['id'],
            'wamid'        => $msg['wamid'] ?? $msg['wa_message_id'] ?? '',
            'to'           => $contact['mobile'] ?? '',
            'type'         => $msg['message_type'] ?? 'text',
            'status'       => $msg['status'] ?? 'unknown',
            'sent_at'      => $msg['created_at'] ?? '',
            'delivered_at' => $msg['delivered_at'] ?? null,
            'read_at'      => $msg['read_at'] ?? null,
            'error'        => $msg['error_message'] ?? null,
        ], 'Message status retrieved.');
    }

    /**
     * Send WhatsApp Media message (Image, Document/PDF, Video, Audio).
     * POST /api/v1/messages/send-media
     *
     * Body payload (JSON):
     * {
     *   "to": "+917744010738",
     *   "type": "document", // image | document | video | audio
     *   "url": "https://example.com/invoice.pdf",
     *   "caption": "Your monthly statement",
     *   "filename": "invoice_1024.pdf"
     * }
     */
    public function sendMedia(): ResponseInterface
    {
        $input = $this->getJsonPayload();

        $rawTo    = (string) ($input['to'] ?? $input['phone'] ?? $input['mobile'] ?? '');
        $type     = strtolower(trim((string) ($input['type'] ?? $input['media_type'] ?? 'image')));
        $url      = trim((string) ($input['url'] ?? $input['media_url'] ?? $input['link'] ?? ''));
        $caption  = trim((string) ($input['caption'] ?? ''));
        $filename = trim((string) ($input['filename'] ?? 'file'));

        if ($rawTo === '') {
            return $this->respondValidationError(['to' => 'Recipient phone number is required (e.g. +917744010738).']);
        }
        if ($url === '') {
            return $this->respondValidationError(['url' => 'Media URL (https://...) or Meta media ID is required.']);
        }
        if (! in_array($type, ['image', 'document', 'video', 'audio'], true)) {
            return $this->respondValidationError(['type' => 'Media type must be one of: image, document, video, audio.']);
        }

        $phone = preg_replace('/[^\d+]/', '', trim($rawTo));
        $phoneDigits = ltrim($phone, '+');
        if (strlen($phoneDigits) < 7 || strlen($phoneDigits) > 15) {
            return $this->respondValidationError(['to' => 'Invalid phone number length. Must be in E.164 format (7-15 digits, e.g. +917744010738).']);
        }
        $contactModel = model(ContactModel::class);

        // Security: SSRF and Media ID validation
        $isMediaId = (! str_starts_with($url, 'http://') && ! str_starts_with($url, 'https://'));
        if ($isMediaId) {
            if (! preg_match('/^[a-zA-Z0-9_\-]{5,100}$/', $url)) {
                return $this->respondValidationError(['url' => 'Invalid Meta media ID format. Must be an alphanumeric ID.']);
            }
        } else {
            if (! $this->validateSafeUrl($url)) {
                return $this->respondValidationError(['url' => 'Disallowed or invalid media URL (internal network addresses, loopback, and non-HTTP protocols are blocked).']);
            }
        }

        // Caption length limit (WhatsApp Meta max 1024 characters)
        if (mb_strlen($caption) > 1024) {
            return $this->respondValidationError(['caption' => 'Media caption exceeds WhatsApp maximum length of 1024 characters.']);
        }

        // Sanitize display filename (prevent path traversal / directory injection)
        $filename = basename(preg_replace('/[^a-zA-Z0-9_\-\. ]/', '', $filename));
        if ($filename === '') {
            $filename = 'document_' . date('Ymd_His');
        }

        try {
            $contact = $contactModel->findOrCreateForChannel('whatsapp', $phone, ['mobile' => $phone]);
            $contactId = (int) $contact['id'];

            // Meta Policy: Check recipient opt-out status (STOP)
            $consentService = new \App\Libraries\WhatsAppConsentService();
            if ($consentService->isOptedOut($contact)) {
                return $this->respondError(
                    'Recipient has opted out of WhatsApp messages from this business (STOP). Under Meta policy, messages cannot be delivered.',
                    422,
                    ['policy' => 'meta_consent', 'code' => 'RECIPIENT_OPTED_OUT']
                );
            }
            if (($contact['status'] ?? '') === 'blocked') {
                return $this->respondError('Recipient contact is marked as blocked in your CRM.', 403);
            }

            // 24-hour service window check for free-form media messages
            $within24h = is_within_24h_window($contact['last_reply_at'] ?? null);
            if (! $within24h) {
                return $this->respondError(
                    'Customer is outside the WhatsApp 24-hour service window. Meta requires an approved template message to initiate conversations.',
                    422,
                    ['suggestion' => 'Use endpoint POST /api/v1/messages/send-template with media header support.']
                );
            }

            $wa = service('whatsApp');
            $normPhone = $wa->normalizePhone((string) ($contact['mobile'] ?? $phone));

            $sendRes = match ($type) {
                'image'    => $wa->sendImage($normPhone, $url, $caption !== '' ? $caption : null, $isMediaId),
                'document' => $wa->sendDocument($normPhone, $url, $caption !== '' ? $caption : null, $filename !== '' ? $filename : null, $isMediaId),
                'video'    => $wa->sendVideo($normPhone, $url, $caption !== '' ? $caption : null, $isMediaId),
                'audio'    => $wa->sendAudio($normPhone, $url, $isMediaId),
            };

            $wamid = (string) ($sendRes['messages'][0]['id'] ?? $sendRes['id'] ?? uniqid('msg_'));

            $convModel    = model(ConversationModel::class);
            $conversation = $convModel->findOrCreateForContact($contactId);

            $msgModel = model(MessageModel::class);
            $msgId    = $msgModel->insert([
                'contact_id'          => $contactId,
                'conversation_id'     => (int) $conversation['id'],
                'channel'             => 'whatsapp',
                'direction'           => 'outbound',
                'message_type'        => $type,
                'media_url'           => $url,
                'external_message_id' => $wamid,
                'wa_message_id'       => $wamid,
                'wamid'               => $wamid,
                'content'             => $caption !== '' ? $caption : '[' . ucfirst($type) . ']',
                'status'              => 'sent',
                'is_read'             => 1,
            ]);

            $convModel->update((int) $conversation['id'], [
                'last_message_id' => $msgId,
                'last_message_at' => date('Y-m-d H:i:s'),
                'status'          => 'open',
            ]);

            return $this->respondSuccess([
                'message_id'   => (int) $msgId,
                'wamid'        => $wamid,
                'to'           => $normPhone,
                'type'         => $type,
                'media_url'    => $url,
                'caption'      => $caption,
                'status'       => 'sent',
                'delivered_at' => null,
                'created_at'   => date('Y-m-d H:i:s'),
            ], 'Media message sent successfully.', 201);
        } catch (Throwable $e) {
            log_message('error', 'API Messages::sendMedia error: ' . $e->getMessage());

            return $this->respondError('Failed to send WhatsApp media message: ' . $e->getMessage(), 500);
        }
    }
}

