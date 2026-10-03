<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Libraries\WhatsAppTemplateSendGuard;
use App\Models\ContactModel;
use App\Models\ConversationModel;
use App\Models\MessageModel;
use CodeIgniter\HTTP\ResponseInterface;
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

        if ($rawTo === '') {
            return $this->respondValidationError(['to' => 'Recipient phone number is required (e.g. +917744010738).']);
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

            // 24-hour customer service window check
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

        if ($rawTo === '') {
            return $this->respondValidationError(['to' => 'Recipient phone number is required.']);
        }
        if ($templateName === '') {
            return $this->respondValidationError(['template_name' => 'Approved template name is required.']);
        }

        $phone = preg_replace('/[^\d+]/', '', trim($rawTo));
        $contactModel = model(ContactModel::class);

        try {
            $contact = $contactModel->findOrCreateForChannel('whatsapp', $phone, ['mobile' => $phone]);
            $contactId = (int) $contact['id'];

            $guard = new WhatsAppTemplateSendGuard();
            $tpl   = $guard->resolveApprovedTemplate(null, $templateName, $language);

            $components = is_array($input['components'] ?? null) ? $input['components'] : [];
            if ($components === [] && is_array($input['variables'] ?? null)) {
                $components = $guard->buildBodyComponents($tpl, $input['variables']);
            } elseif ($components === []) {
                $components = $guard->buildBodyComponents($tpl, []);
            }

            // Handle media header if provided
            $headerMediaUrl = trim((string) ($input['header_media_url'] ?? ''));
            if ($headerMediaUrl !== '') {
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
        $contactModel = model(ContactModel::class);

        try {
            $contact = $contactModel->findOrCreateForChannel('whatsapp', $phone, ['mobile' => $phone]);
            $contactId = (int) $contact['id'];

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
            $isMediaId = (! str_starts_with($url, 'http://') && ! str_starts_with($url, 'https://'));

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

