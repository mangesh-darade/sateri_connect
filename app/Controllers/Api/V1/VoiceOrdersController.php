<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Libraries\VoiceOrderService;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Tools for the AI voice ordering agent (AVO): menu and caller lookup, backed by ElintOm POS.
 * Auth: API key (apiAuth filter), permission ai.use.
 */
class VoiceOrdersController extends BaseV1Controller
{
    /** GET /api/v1/voice/menu */
    public function menu(): ResponseInterface
    {
        $r = (new VoiceOrderService())->menu();

        return $r['ok']
            ? $this->respondSuccess($r['data'], $r['message'])
            : $this->respondError($r['message'], $r['status']);
    }

    /** POST /api/v1/voice/order { customer_id, items: [{product_id, quantity}], note?, call_id?, phone?, delivery_type? } */
    public function order(): ResponseInterface
    {
        $in    = $this->getJsonPayload();
        $items = is_array($in['items'] ?? null) ? $in['items'] : [];
        $errors = [];
        if ((int) ($in['customer_id'] ?? 0) < 1) {
            $errors['customer_id'] = 'customer_id is required';
        }
        if ($items === []) {
            $errors['items'] = 'items is required';
        }
        if ($errors !== []) {
            return $this->respondValidationError($errors);
        }

        $r = (new VoiceOrderService())->order(
            (int) $in['customer_id'],
            $items,
            mb_substr(trim((string) ($in['note'] ?? '')), 0, 250),
            mb_substr(trim((string) ($in['call_id'] ?? '')), 0, 60),
            mb_substr(trim((string) ($in['phone'] ?? '')), 0, 30),
            mb_substr(trim((string) ($in['delivery_type'] ?? '')), 0, 60),
        );
        if (! $r['ok']) {
            return $r['status'] === 422
                ? $this->respondValidationError(['items' => $r['message']])
                : $this->respondError($r['message'], $r['status']);
        }

        log_activity('voice_order', 'voice', 'Voice agent placed an order', ['order_id' => $r['data']['order_id'], 'reference_no' => $r['data']['reference_no']]);

        return $this->respondSuccess($r['data'], $r['message'], 201);
    }

    /** POST /api/v1/voice/customer { phone, name?, country? } */
    public function customer(): ResponseInterface
    {
        $in    = $this->getJsonPayload();
        $phone = trim((string) ($in['phone'] ?? ''));
        if ($phone === '') {
            return $this->respondValidationError(['phone' => 'phone is required']);
        }

        $r = (new VoiceOrderService())->customer(
            $phone,
            mb_substr(trim((string) ($in['name'] ?? '')), 0, 120),
            mb_substr(trim((string) ($in['country'] ?? '')), 0, 60),
        );
        if (! $r['ok']) {
            return $r['status'] === 422
                ? $this->respondValidationError(['phone' => $r['message']])
                : $this->respondError($r['message'], $r['status']);
        }

        log_activity('voice_customer', 'voice', ($r['data']['created'] ?? false) ? 'Voice agent created a customer' : 'Voice agent looked up a customer', ['phone_tail' => substr((string) $r['data']['phone'], -4)]);

        return $this->respondSuccess($r['data'], $r['message']);
    }
}
