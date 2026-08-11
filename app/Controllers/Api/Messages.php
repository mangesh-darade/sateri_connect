<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Models\ContactModel;
use App\Models\ConversationModel;
use App\Models\MessageModel;
use CodeIgniter\HTTP\ResponseInterface;
use Throwable;

/**
 * API message send (text/template) and list.
 */
class Messages extends BaseApiController
{
    public function index(): ResponseInterface
    {
        $contactId = (int) ($this->request->getGet('contact_id') ?? 0);
        $page      = max(1, (int) ($this->request->getGet('page') ?? 1));
        $limit     = max(1, min(100, (int) ($this->request->getGet('limit') ?? 50)));

        $model = model(MessageModel::class);
        if ($contactId > 0) {
            $model->where('contact_id', $contactId);
        }

        $total = $model->countAllResults(false);
        $items = $model->orderBy('id', 'DESC')->findAll($limit, ($page - 1) * $limit);

        return $this->respondSuccess([
            'items' => $items,
            'meta'  => ['page' => $page, 'limit' => $limit, 'total' => $total],
        ]);
    }

    public function send(): ResponseInterface
    {
        $input = $this->getJsonInput();
        $type  = (string) ($input['type'] ?? $input['message_type'] ?? 'text');

        $contactId = (int) ($input['contact_id'] ?? 0);
        $to        = normalize_phone((string) ($input['to'] ?? $input['mobile'] ?? ''));

        $contact = null;
        if ($contactId > 0) {
            $contact = model(ContactModel::class)->find($contactId);
        } elseif ($to !== '') {
            $contact = model(ContactModel::class)->findByMobile($to);
            if ($contact === null) {
                $contactId = (int) model(ContactModel::class)->insert([
                    'mobile' => $to,
                    'name'   => $input['name'] ?? null,
                    'status' => 'active',
                ]);
                $contact = model(ContactModel::class)->find($contactId);
            }
        }

        if ($contact === null) {
            return $this->respondValidationError([
                'contact_id' => 'contact_id or to/mobile is required.',
            ]);
        }

        $contactId = (int) $contact['id'];
        $within24h = is_within_24h_window($contact['last_reply_at'] ?? null);

        if (! $within24h && $type !== 'template') {
            return $this->respondError(
                'Outside 24-hour window. Use a template message.',
                [],
                422
            );
        }

        try {
            $api    = service('whatsApp');
            $phone  = $api->normalizePhone((string) $contact['mobile']);
            if ($type === 'template') {
                $guard = new \App\Libraries\WhatsAppTemplateSendGuard();
                $guard->assertPhoneNumberId(isset($input['phone_number_id']) ? (string) $input['phone_number_id'] : null);
                $guard->assertWabaId(isset($input['waba_id']) ? (string) $input['waba_id'] : null);
                $tpl = $guard->resolveApprovedTemplate(
                    isset($input['template_id']) ? (int) $input['template_id'] : null,
                    (string) ($input['template_name'] ?? $input['name'] ?? ''),
                    isset($input['language']) ? (string) $input['language'] : null
                );
                $components = is_array($input['components'] ?? null) ? $input['components'] : [];
                if ($components === [] && is_array($input['variables'] ?? null)) {
                    $components = $guard->buildBodyComponents($tpl, $input['variables']);
                } elseif ($components === []) {
                    // Validate variables even when components omitted (no vars required)
                    $components = $guard->buildBodyComponents($tpl, []);
                }
                $result = $api->sendTemplate(
                    $phone,
                    (string) $tpl['name'],
                    (string) ($tpl['language'] ?? 'en_US'),
                    $components
                );
            } else {
                $result = $api->sendText($phone, (string) ($input['text'] ?? $input['content'] ?? ''));
            }

            $waId = $result['messages'][0]['id'] ?? null;
            $conversation = model(ConversationModel::class)->findOrCreateForContact($contactId);

            $messageId = model(MessageModel::class)->insert([
                'contact_id'      => $contactId,
                'conversation_id' => (int) $conversation['id'],
                'direction'       => 'outbound',
                'message_type'    => $type,
                'wa_message_id'   => $waId,
                'wamid'           => $waId,
                'content'         => $input['text'] ?? $input['content'] ?? ($input['template_name'] ?? null),
                'payload'         => $result,
                'status'          => 'sent',
                'is_read'         => 1,
            ]);

            model(ConversationModel::class)->update((int) $conversation['id'], [
                'last_message_id' => $messageId,
                'last_message_at' => date('Y-m-d H:i:s'),
            ]);

            model(ContactModel::class)->update($contactId, [
                'last_message_at' => date('Y-m-d H:i:s'),
            ]);

            return $this->respondSuccess([
                'message'  => model(MessageModel::class)->find((int) $messageId),
                'api'      => $result,
            ], 'Message sent.', 201);
        } catch (Throwable $e) {
            return $this->respondError($e->getMessage(), [], 500);
        }
    }

    public function sendText(): ResponseInterface
    {
        return $this->dispatchSend('text');
    }

    public function sendTemplate(): ResponseInterface
    {
        return $this->dispatchSend('template');
    }

    protected function dispatchSend(string $forcedType): ResponseInterface
    {
        $input         = $this->getJsonInput();
        $input['type'] = $forcedType;

        // Mirror send() with forced type
        $type      = $forcedType;
        $contactId = (int) ($input['contact_id'] ?? 0);
        $to        = normalize_phone((string) ($input['to'] ?? $input['mobile'] ?? ''));

        $contact = null;
        if ($contactId > 0) {
            $contact = model(ContactModel::class)->find($contactId);
        } elseif ($to !== '') {
            $contact = model(ContactModel::class)->findByMobile($to);
            if ($contact === null) {
                $contactId = (int) model(ContactModel::class)->insert([
                    'mobile' => $to,
                    'name'   => $input['name'] ?? null,
                    'status' => 'active',
                ]);
                $contact = model(ContactModel::class)->find($contactId);
            }
        }

        if ($contact === null) {
            return $this->respondValidationError(['contact_id' => 'contact_id or to/mobile is required.']);
        }

        $contactId = (int) $contact['id'];
        $within24h = is_within_24h_window($contact['last_reply_at'] ?? null);

        if (! $within24h && $type !== 'template') {
            return $this->respondError('Outside 24-hour window. Use a template message.', [], 422);
        }

        try {
            $api   = service('whatsApp');
            $phone = $api->normalizePhone((string) $contact['mobile']);
            if ($type === 'template') {
                $guard = new \App\Libraries\WhatsAppTemplateSendGuard();
                $guard->assertPhoneNumberId(isset($input['phone_number_id']) ? (string) $input['phone_number_id'] : null);
                $guard->assertWabaId(isset($input['waba_id']) ? (string) $input['waba_id'] : null);
                $tpl = $guard->resolveApprovedTemplate(
                    isset($input['template_id']) ? (int) $input['template_id'] : null,
                    (string) ($input['template_name'] ?? $input['name'] ?? ''),
                    isset($input['language']) ? (string) $input['language'] : null
                );
                $components = is_array($input['components'] ?? null) ? $input['components'] : [];
                if ($components === [] && is_array($input['variables'] ?? null)) {
                    $components = $guard->buildBodyComponents($tpl, $input['variables']);
                } elseif ($components === []) {
                    $components = $guard->buildBodyComponents($tpl, []);
                }
                $result = $api->sendTemplate(
                    $phone,
                    (string) $tpl['name'],
                    (string) ($tpl['language'] ?? 'en_US'),
                    $components
                );
            } else {
                $result = $api->sendText($phone, (string) ($input['text'] ?? $input['content'] ?? ''));
            }

            $waId         = $result['messages'][0]['id'] ?? null;
            $conversation = model(ConversationModel::class)->findOrCreateForContact($contactId);
            $messageId    = model(MessageModel::class)->insert([
                'contact_id'      => $contactId,
                'conversation_id' => (int) $conversation['id'],
                'direction'       => 'outbound',
                'message_type'    => $type,
                'wa_message_id'   => $waId,
                'wamid'           => $waId,
                'content'         => $input['text'] ?? $input['content'] ?? ($input['template_name'] ?? null),
                'payload'         => $result,
                'status'          => 'sent',
                'is_read'         => 1,
            ]);

            model(ConversationModel::class)->update((int) $conversation['id'], [
                'last_message_id' => $messageId,
                'last_message_at' => date('Y-m-d H:i:s'),
            ]);

            return $this->respondSuccess(model(MessageModel::class)->find((int) $messageId), 'Message sent.', 201);
        } catch (Throwable $e) {
            return $this->respondError($e->getMessage(), [], 500);
        }
    }
}
