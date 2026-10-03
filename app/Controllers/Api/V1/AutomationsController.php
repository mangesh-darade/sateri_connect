<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Models\AutomationModel;
use App\Models\ContactModel;
use CodeIgniter\HTTP\ResponseInterface;
use Throwable;

/**
 * REST API v1 Automations & Webhooks Controller.
 * Allows external systems (CRMs, E-commerce, Zapier) to trigger automation workflows.
 */
class AutomationsController extends BaseV1Controller
{
    /**
     * Trigger an automation workflow by custom event name or webhook.
     * POST /api/v1/automations/trigger
     *
     * Body payload (JSON):
     * {
     *   "event": "order_placed",
     *   "phone": "+919876543210",
     *   "name": "Mangesh",
     *   "data": {
     *     "order_id": "ORD-12345",
     *     "total": 1499,
     *     "items": "Wireless Earbuds"
     *   }
     * }
     */
    public function trigger(): ResponseInterface
    {
        $input = $this->getJsonPayload();

        $event = trim((string) ($input['event'] ?? $input['trigger'] ?? ''));
        if ($event === '') {
            return $this->respondValidationError(['event' => 'Event or trigger name is required (e.g. order_placed, webhook).']);
        }

        $rawPhone = (string) ($input['phone'] ?? $input['mobile'] ?? '');
        $phone    = preg_replace('/[^\d+]/', '', trim($rawPhone));

        $contactId = (int) ($input['contact_id'] ?? 0);
        $name      = trim((string) ($input['name'] ?? ''));

        try {
            $contactModel = model(ContactModel::class);
            if ($phone !== '') {
                $contact   = $contactModel->findOrCreateForChannel('whatsapp', $phone, ['name' => $name !== '' ? $name : null, 'mobile' => $phone]);
                $contactId = (int) $contact['id'];
            } elseif ($contactId > 0) {
                $contact = $contactModel->find($contactId);
                if (! $contact) {
                    return $this->respondError('Contact ID not found.', 404);
                }
            }

            $customData = is_array($input['data'] ?? null) ? $input['data'] : (is_array($input['payload'] ?? null) ? $input['payload'] : []);

            $context = array_merge($customData, [
                'event'      => $event,
                'contact_id' => $contactId,
                'phone'      => $phone,
                'name'       => $name,
                'timestamp'  => time(),
                'payload'    => $customData,
            ]);

            $autoEngine = service('automationEngine');
            $result     = $autoEngine->processTrigger($event, $context);

            return $this->respondSuccess([
                'event'        => $event,
                'contact_id'   => $contactId > 0 ? $contactId : null,
                'matched'      => $result['matched'] ?? 0,
                'executed'     => $result['executed'] ?? 0,
                'errors'       => $result['errors'] ?? [],
                'triggered_at' => date('Y-m-d H:i:s'),
            ], 'Automation event triggered successfully.');
        } catch (Throwable $e) {
            log_message('error', 'API Automations::trigger error: ' . $e->getMessage());

            return $this->respondError('Failed to trigger automation: ' . $e->getMessage(), 500);
        }
    }

    /**
     * List all active automations that can be triggered.
     * GET /api/v1/automations
     */
    public function index(): ResponseInterface
    {
        $autoModel = model(AutomationModel::class);
        $items     = $autoModel->where('is_active', 1)
            ->orderBy('priority', 'ASC')
            ->findAll();

        $list = array_map(static function (array $a): array {
            return [
                'id'           => (int) $a['id'],
                'name'         => $a['name'] ?? '',
                'trigger_type' => $a['trigger_type'] ?? '',
                'priority'     => (int) ($a['priority'] ?? 0),
                'created_at'   => $a['created_at'] ?? '',
            ];
        }, $items);

        return $this->respondSuccess([
            'automations' => $list,
            'total'       => count($list),
        ], 'Automations loaded.');
    }
}
