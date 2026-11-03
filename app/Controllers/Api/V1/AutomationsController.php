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
        if (! preg_match('/^[a-zA-Z0-9_\-\.]{1,100}$/', $event)) {
            return $this->respondValidationError(['event' => 'Invalid event name format. Use alphanumeric, dashes, dots, or underscores (max 100 chars).']);
        }

        $rawPhone = (string) ($input['phone'] ?? $input['mobile'] ?? '');
        $phone    = preg_replace('/[^\d+]/', '', trim($rawPhone));

        $contactId = (int) ($input['contact_id'] ?? 0);
        $name      = trim(strip_tags((string) ($input['name'] ?? '')));
        if (mb_strlen($name) > 255) {
            $name = mb_substr($name, 0, 255);
        }

        $customData = is_array($input['data'] ?? null) ? $input['data'] : (is_array($input['payload'] ?? null) ? $input['payload'] : []);
        if (strlen(json_encode($customData)) > 65536) {
            return $this->respondValidationError(['data' => 'Payload data exceeds maximum allowed size of 64KB.']);
        }

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
        $items     = ($this->request->getGet('all') ? $autoModel : $autoModel->where('is_active', 1))
            ->orderBy('priority', 'ASC')
            ->findAll();

        $list = array_map(static function (array $a): array {
            return [
                'id'           => (int) $a['id'],
                'name'         => $a['name'] ?? '',
                'trigger_type' => $a['trigger_type'] ?? '',
                'priority'     => (int) ($a['priority'] ?? 0),
                'is_active'    => (int) ($a['is_active'] ?? 0),
                'created_at'   => $a['created_at'] ?? '',
            ];
        }, $items);

        return $this->respondSuccess([
            'automations' => $list,
            'total'       => count($list),
        ], 'Automations loaded.');
    }

    /** GET /api/v1/automations/{id}: workflow with its trigger, canvas graph and rules. */
    public function show(string $id): ResponseInterface
    {
        $row = model(AutomationModel::class)->getWithRules((int) $id);

        return $row === null ? $this->respondError('Workflow not found.', 404) : $this->respondSuccess($row, 'Workflow loaded.');
    }

    /**
     * Create a workflow. POST /api/v1/automations
     * {name, trigger_type, trigger_config?, priority?, is_active? (default 0), flow_graph?}
     * flow_graph is the canvas JSON {nodes, edges}; it is validated like the builder does. A new workflow stays off unless is_active is 1.
     */
    public function create(): ResponseInterface
    {
        $in   = $this->getJsonPayload();
        $name = trim((string) ($in['name'] ?? ''));
        $type = trim((string) ($in['trigger_type'] ?? ''));
        if ($name === '' || ($type === '' && empty($in['flow_graph']))) {
            return $this->respondError('name and trigger_type (or a flow_graph with a trigger node) are required.', 422);
        }

        $cfg = $in['trigger_config'] ?? null;
        $id  = model(AutomationModel::class)->insert([
            'name'           => mb_substr($name, 0, 191),
            'trigger_type'   => $type !== '' ? $type : 'incoming_message',
            'trigger_config' => is_array($cfg) ? $cfg : null,
            'is_active'      => empty($in['is_active']) ? 0 : 1,
            'priority'       => (int) ($in['priority'] ?? 10),
            'created_by'     => $this->currentUserId(),
        ]);
        if (! $id) {
            return $this->respondError('The workflow could not be created.', 422);
        }
        log_activity('create', 'automations', 'Workflow created via API: ' . $name, ['automation_id' => $id]);

        if (! empty($in['flow_graph'])) {
            $failed = $this->saveGraph((int) $id);
            if ($failed !== null) {
                model(AutomationModel::class)->delete((int) $id);

                return $failed;
            }
        }

        return $this->respondSuccess(['id' => (int) $id], 'Workflow created (off until activated).', 201);
    }

    /** POST /api/v1/automations/{id}: change name, trigger_type, trigger_config, priority, is_active and/or flow_graph. */
    public function update(string $id): ResponseInterface
    {
        $id    = (int) $id;
        $model = model(AutomationModel::class);
        if ($model->find($id) === null) {
            return $this->respondError('Workflow not found.', 404);
        }

        $in     = $this->getJsonPayload();
        $fields = array_intersect_key($in, array_flip(['name', 'trigger_type', 'trigger_config', 'priority', 'is_active']));
        if (isset($fields['is_active'])) {
            $fields['is_active'] = empty($fields['is_active']) ? 0 : 1;
        }
        if (isset($fields['trigger_config']) && ! is_array($fields['trigger_config'])) {
            unset($fields['trigger_config']);
        }
        if ($fields !== []) {
            $model->update($id, $fields);
        }
        if (! empty($in['flow_graph']) && ($failed = $this->saveGraph($id)) !== null) {
            return $failed;
        }
        log_activity('update', 'automations', 'Workflow updated via API', ['automation_id' => $id]);

        return $this->respondSuccess(['id' => $id], 'Workflow updated.');
    }

    /** POST /api/v1/automations/{id}/duplicate: copy with its rules; the copy stays off. */
    public function duplicate(string $id): ResponseInterface
    {
        $newId = model(AutomationModel::class)->duplicateRow((int) $id, $this->currentUserId());
        if ($newId === null) {
            return $this->respondError('Workflow not found.', 404);
        }
        log_activity('create', 'automations', 'Workflow duplicated via API', ['automation_id' => $newId, 'source_id' => (int) $id]);

        return $this->respondSuccess(['id' => $newId], 'Workflow duplicated (off until activated).', 201);
    }

    /** POST /api/v1/automations/{id}/toggle: switch on/off. */
    public function toggle(string $id): ResponseInterface
    {
        $model = model(AutomationModel::class);
        $row   = $model->find((int) $id);
        if ($row === null) {
            return $this->respondError('Workflow not found.', 404);
        }
        $on = (int) ($row['is_active'] ?? 0) === 1 ? 0 : 1;
        $model->update((int) $id, ['is_active' => $on]);
        log_activity('toggle', 'automations', ($on ? 'Enabled' : 'Disabled') . ' workflow via API', ['automation_id' => (int) $id]);

        return $this->respondSuccess(['id' => (int) $id, 'is_active' => $on], $on ? 'Workflow is on.' : 'Workflow is off.');
    }

    /** Saves the posted flow_graph through the same code as the canvas; null on success, an error response otherwise. */
    private function saveGraph(int $id): ?ResponseInterface
    {
        $screen = new \App\Controllers\Automations();
        $screen->initController($this->request, $this->response, service('logger'));
        $out = json_decode((string) $screen->builder($id)->getBody(), true);

        return is_array($out) && ! empty($out['success'])
            ? null
            : $this->respondError((string) ($out['message'] ?? 'The workflow graph could not be saved.'), 422);
    }
}
