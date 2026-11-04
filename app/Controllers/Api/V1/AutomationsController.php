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

    /**
     * GET /api/v1/automations/agents: the team members a workflow can assign a chat to (id, name, role).
     * No email or phone is returned.
     */
    public function agents(): ResponseInterface
    {
        $rows = db_connect()->table('users u')
            ->select('u.id, u.name, r.name AS role')
            ->join('roles r', 'r.id = u.role_id', 'left')
            ->where('u.status', 'active')
            ->where('u.deleted_at', null)
            ->orderBy('u.name', 'ASC')
            ->limit(200)
            ->get()->getResultArray();

        $list = array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'name' => (string) $r['name'], 'role' => (string) ($r['role'] ?? '')], $rows);

        return $this->respondSuccess(['agents' => $list, 'total' => count($list)], 'Agents loaded. Use an id as user_id in an assign_agent node.');
    }

    /**
     * GET /api/v1/automations/schema: how to write a flow_graph (node and edge format, every action with its data keys,
     * condition presets, WhatsApp limits and a complete example). Lets an AI client build workflows without guessing.
     */
    public function schema(): ResponseInterface
    {
        $example = [
            'nodes' => [
                ['id' => 'trigger', 'type' => 'trigger', 'x' => 40, 'y' => 40, 'data' => ['trigger_type' => 'incoming_message', 'keyword' => 'mango, hapus', 'match_type' => 'contains']],
                ['id' => 'tag', 'type' => 'action', 'x' => 360, 'y' => 40, 'data' => ['action_type' => 'add_tag', 'tag_name' => 'Mango']],
                ['id' => 'ask', 'type' => 'action', 'x' => 680, 'y' => 40, 'data' => ['action_type' => 'ask_question', 'text' => 'What would you like to do?', 'options' => ['Order a sample', 'Talk to sales'], 'reply_type' => 'buttons']],
                ['id' => 'sample', 'type' => 'action', 'x' => 1000, 'y' => 40, 'data' => ['action_type' => 'send_text', 'text' => 'Please send your city and quantity.']],
                ['id' => 'agent', 'type' => 'action', 'x' => 1000, 'y' => 170, 'data' => ['action_type' => 'assign_agent', 'user_id' => 0]],
            ],
            'edges' => [
                ['from' => 'trigger', 'to' => 'tag', 'port' => 'out'],
                ['from' => 'tag', 'to' => 'ask', 'port' => 'out'],
                ['from' => 'ask', 'to' => 'sample', 'port' => 'opt_1'],
                ['from' => 'ask', 'to' => 'agent', 'port' => 'opt_2'],
            ],
        ];

        return $this->respondSuccess([
            'flow_graph' => [
                'shape'        => '{ "nodes": [ ... ], "edges": [ ... ] }',
                'node'         => '{ "id": "unique string", "type": "trigger|action|condition", "x": number, "y": number, "data": { ... } }. Exactly one trigger node. Lay nodes out left to right (x grows by about 320 per column, y by about 130 per row).',
                'edge'         => '{ "from": node id, "to": node id, "port": "out|true|false|opt_N" }. "out" for a normal next step, "true" / "false" from a condition, "opt_1", "opt_2" ... from an ask_question node, one per option in the order listed. A port with no edge ends that branch.',
                'merge'        => 'Several nodes may point to the same next node. There are no loops.',
                'trigger_data' => [
                    'trigger_type' => 'incoming_message | keyword_matched | contact_created | tag_added | campaign_replied | shopify_event | form_response | schedule | birthday',
                    'keyword'      => 'comma separated keywords',
                    'match_type'   => 'exact | contains | starts_with (keywords match whole words, case and punctuation ignored)',
                ],
            ],
            'actions' => [
                'send_text'          => ['data' => ['text' => 'message text; {{name}} style placeholders are filled from the contact'], 'note' => 'Free text only works inside the customer 24 hour window.'],
                'send_template'      => ['data' => ['template_name' => 'approved template name', 'language' => 'e.g. en_US', 'variables' => '{ "1": "{{name}}", "2": "text" } mapping template variables to values'], 'note' => 'Use for messages outside the 24 hour window. The template must be approved.'],
                'send_media'         => ['data' => ['media_type' => 'image | video | document', 'media_url' => 'public https link', 'filename' => 'for documents', 'caption' => 'optional']],
                'ask_question'       => [
                    'data' => [
                        'text'            => 'the question',
                        'options'         => ['option label', 'option label'],
                        'reply_type'      => 'buttons (max 3 options, label up to 20 characters) | list (up to 10 options, label up to 24 characters) | text (free answer, no branching)',
                        'list_button'     => 'list button label, up to 20 characters',
                        'save_as'         => 'optional contact attribute that stores the answer',
                        'max_retries'     => 'default 1',
                        'timeout_minutes' => 'default 1440',
                    ],
                    'note' => 'Pauses the flow until the customer answers. Connect one "opt_N" edge per option; for reply_type text use the "out" edge.',
                ],
                'set_attribute'      => ['data' => ['attribute' => 'attribute key, letters digits underscore', 'value' => 'text']],
                'add_tag'            => ['data' => ['tag_name' => 'created if missing']],
                'remove_tag'         => ['data' => ['tag_name' => 'tag to remove']],
                'assign_agent'       => ['data' => ['user_id' => 'agent user id (0 leaves the chat unassigned)']],
                'assign_bot'         => ['data' => [], 'note' => 'Hands the chat to the chatbot.'],
                'update_chat_status' => ['data' => ['status' => 'open | pending | resolved | intervened']],
                'add_note'           => ['data' => ['text' => 'internal note on the contact']],
                'delay'              => ['data' => ['minutes' => 'or seconds'], 'note' => 'Pauses the flow, then continues with the next node.'],
                'ai_reply'           => ['data' => ['text' => 'optional extra instruction for the AI, e.g. "answer only about delivery"'], 'note' => 'Needs the AI bot enabled in Settings. The AI hands over to a person when unsure.'],
                'webhook_call'       => ['data' => ['url' => 'https endpoint', 'method' => 'POST | GET', 'body' => 'optional']],
                'send_email'         => ['data' => ['subject' => 'subject', 'text' => 'body']],
            ],
            'conditions' => [
                'node'    => '{ "id": "...", "type": "condition", "data": { "condition_type": "<preset>", "value": "..." } } with edges from it on ports "true" and "false".',
                'presets' => ['message_contains' => 'value', 'message_equals' => 'value', 'message_type' => 'value: text|image|document|audio', 'has_tag' => 'tag_id', 'within_window' => 'no value', 'contact_status' => 'value: active|inactive|blocked'],
            ],
            'limits' => [
                'Interactive buttons and lists, and free text, only work while the customer has written in the last 24 hours.',
                'Use approved templates to start a conversation or after the window closes.',
                'Prices and fixed facts belong in send_text nodes; use ai_reply only for open questions.',
                'A new workflow is created OFF. Review it on the Automations screen, then switch it on.',
            ],
            'example' => $example,
        ], 'Workflow schema.');
    }

    /** GET /api/v1/automations/{id}: workflow with its trigger, canvas graph and rules. */
    public function show(string $id): ResponseInterface
    {
        $row = model(AutomationModel::class)->getWithRules((int) $id);
        if ($row === null) {
            return $this->respondError('Workflow not found.', 404);
        }

        // Workflows made the old way have rules but no canvas graph. Rebuild one from the rules so a client always has
        // a working example of the node format (it is not saved).
        $graph = $row['flow_graph'] ?? null;
        $empty = $graph === null || $graph === '' || $graph === '[]' || $graph === '{}'
            || (is_array($graph) && empty($graph['nodes'])) || (is_string($graph) && empty((json_decode($graph, true) ?: [])['nodes']));
        if ($empty && ! empty($row['rules'])) {
            $rules = array_map(static function (array $r): array {
                if (is_string($r['config'] ?? null)) {
                    $r['config'] = json_decode($r['config'], true) ?: [];
                }

                return $r;
            }, $row['rules']);
            $row['flow_graph']         = (new \App\Libraries\WorkflowGraph())->fromRules($rules, (string) ($row['trigger_type'] ?? 'incoming_message'));
            $row['flow_graph_derived'] = true;
        }

        return $this->respondSuccess($row, 'Workflow loaded.');
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
