<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Libraries\ActivityLogger;
use App\Libraries\ContactAttributes;
use App\Libraries\WorkflowGraph;
use App\Models\AutomationModel;
use App\Models\AutomationRuleModel;
use App\Models\TagModel;
use App\Models\TemplateModel;
use App\Models\UserModel;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Automation workflows and visual builder rule persistence.
 */
class Automations extends BaseController
{
    public function index(): string|ResponseInterface
    {
        if ($denied = $this->requirePermission('automations.view')) {
            return $denied;
        }

        $automations = model(AutomationModel::class)
            ->orderBy('priority', 'ASC')
            ->orderBy('id', 'DESC')
            ->findAll();

        return $this->render('automations/index', [
            'pageTitle'   => 'Automations',
            'automations' => $automations,
        ]);
    }

    public function create(): string|ResponseInterface
    {
        if ($denied = $this->requirePermission('automations.create')) {
            return $denied;
        }

        return $this->render('automations/form', [
            'pageTitle'  => 'Create Automation',
            'automation' => null,
            'rules'      => [],
        ]);
    }

    public function store(): ResponseInterface
    {
        if ($denied = $this->requirePermission('automations.create')) {
            return $denied;
        }

        $rules = [
            'name'         => 'required|max_length[191]',
            'trigger_type' => 'required|max_length[100]',
            'priority'     => 'permit_empty|integer',
        ];

        if (! $this->validate($rules)) {
            return redirect()->back()->withInput()->with('errors', $this->validator->getErrors());
        }

        $triggerConfig = $this->request->getPost('trigger_config');
        if (is_string($triggerConfig) && $triggerConfig !== '') {
            $decoded = json_decode($triggerConfig, true);
            $triggerConfig = is_array($decoded) ? $decoded : null;
        }

        $id = model(AutomationModel::class)->insert([
            'name'           => $this->request->getPost('name'),
            'trigger_type'   => $this->request->getPost('trigger_type'),
            'trigger_config' => is_array($triggerConfig) ? $triggerConfig : null,
            'is_active'      => (int) ($this->request->getPost('is_active') ?? 1),
            'priority'       => (int) ($this->request->getPost('priority') ?? 10),
            'created_by'     => $this->userId(),
        ]);

        if (! $id) {
            return redirect()->back()->withInput()->with('errors', model(AutomationModel::class)->errors());
        }

        $this->saveRulesPayload((int) $id, $this->request->getPost('rules'));

        (new ActivityLogger())->log('create', 'automations', 'Automation created', ['automation_id' => $id]);

        return redirect()->to('/automations/' . $id . '/builder')->with('success', 'Automation created — design your workflow.');
    }

    public function edit(int $id): string|ResponseInterface
    {
        if ($denied = $this->requirePermission('automations.edit')) {
            return $denied;
        }

        $automation = model(AutomationModel::class)->getWithRules($id);
        if ($automation === null) {
            return redirect()->to('/automations')->with('error', 'Automation not found.');
        }

        $name = trim((string) ($automation['name'] ?? ''));

        return $this->render('automations/form', [
            'pageTitle'  => $name !== '' ? ('Edit: ' . $name) : 'Edit Automation',
            'automation' => $automation,
            'rules'      => $automation['rules'] ?? [],
            'rulesJson'  => json_encode($automation['rules'] ?? []),
        ]);
    }

    public function update(int $id): ResponseInterface
    {
        if ($denied = $this->requirePermission('automations.edit')) {
            return $denied;
        }

        $model = model(AutomationModel::class);
        if ($model->find($id) === null) {
            return redirect()->to('/automations')->with('error', 'Automation not found.');
        }

        $rules = [
            'name'         => 'required|max_length[191]',
            'trigger_type' => 'required|max_length[100]',
        ];

        if (! $this->validate($rules)) {
            return redirect()->back()->withInput()->with('errors', $this->validator->getErrors());
        }

        $triggerConfig = $this->request->getPost('trigger_config');
        if (is_string($triggerConfig) && $triggerConfig !== '') {
            $decoded = json_decode($triggerConfig, true);
            $triggerConfig = is_array($decoded) ? $decoded : null;
        }

        $model->update($id, [
            'name'           => $this->request->getPost('name'),
            'trigger_type'   => $this->request->getPost('trigger_type'),
            'trigger_config' => is_array($triggerConfig) ? $triggerConfig : null,
            'is_active'      => (int) ($this->request->getPost('is_active') ?? 0),
            'priority'       => (int) ($this->request->getPost('priority') ?? 10),
        ]);

        if ($this->request->getPost('rules') !== null) {
            $this->saveRulesPayload($id, $this->request->getPost('rules'));
        }

        (new ActivityLogger())->log('update', 'automations', 'Automation updated', ['automation_id' => $id]);

        return redirect()->to('/automations/' . $id . '/edit')->with('success', 'Automation updated.');
    }

    public function delete(int $id): ResponseInterface
    {
        if ($denied = $this->requirePermission('automations.delete')) {
            return $denied;
        }

        $model = model(AutomationModel::class);
        if ($model->find($id) === null) {
            return $this->request->isAJAX()
                ? $this->jsonResponse(false, null, 'Not found.', [], 404)
                : redirect()->to('/automations')->with('error', 'Not found.');
        }

        model(AutomationRuleModel::class)->where('automation_id', $id)->delete();
        $model->delete($id);

        (new ActivityLogger())->log('delete', 'automations', 'Automation deleted', ['automation_id' => $id]);

        if ($this->request->isAJAX()) {
            return $this->jsonResponse(true, null, 'Automation deleted.');
        }

        return redirect()->to('/automations')->with('success', 'Automation deleted.');
    }

    public function toggle(int $id): ResponseInterface
    {
        if ($denied = $this->requirePermission('automations.edit')) {
            return $denied;
        }

        $model = model(AutomationModel::class);
        $row   = $model->find($id);

        if ($row === null) {
            return $this->jsonResponse(false, null, 'Not found.', [], 404);
        }

        $new = ((int) ($row['is_active'] ?? 0)) === 1 ? 0 : 1;
        $model->update($id, ['is_active' => $new]);

        if ($this->request->isAJAX()) {
            return $this->jsonResponse(true, ['is_active' => $new], $new ? 'Automation enabled.' : 'Automation disabled.');
        }

        return redirect()->to('/automations')->with('success', $new ? 'Automation enabled.' : 'Automation disabled.');
    }

    /**
     * Render visual automation builder page (Cheerio-style canvas).
     */
    public function builderPage(int $id): string|ResponseInterface
    {
        if ($denied = $this->requirePermission('automations.edit')) {
            return $denied;
        }

        $automation = model(AutomationModel::class)->find($id);
        if ($automation === null) {
            return redirect()->to('/automations')->with('error', 'Automation not found.');
        }

        $rules = model(AutomationRuleModel::class)
            ->where('automation_id', $id)
            ->orderBy('step_order', 'ASC')
            ->findAll();

        $graph = $automation['flow_graph'] ?? null;
        if (is_string($graph)) {
            $decoded = json_decode($graph, true);
            $graph   = is_array($decoded) ? $decoded : null;
        }

        $wf = new WorkflowGraph();
        $needsNormalize = is_array($graph) && (
            $wf->looksLikeCheerioGraph($graph)
            || $this->graphHasUnmappedCheerioNodes($graph)
            || (int) ($graph['normalize_version'] ?? 0) < WorkflowGraph::NORMALIZE_VERSION
        );

        if ($needsNormalize && is_array($graph)) {
            $graph           = $wf->normalizeImportedGraph($graph);
            $graph['source'] = 'cheerio_local';
            $triggerType     = $wf->triggerFromGraph($graph);
            $update          = ['flow_graph' => $graph];
            if ($triggerType) {
                $prev = (string) ($automation['trigger_type'] ?? '');
                if ($prev === 'cheerio_workflow' || $prev === '') {
                    $mappedTrigger = $triggerType === 'cheerio_workflow' ? 'incoming_message' : $triggerType;
                    $update['trigger_type']     = $mappedTrigger;
                    $automation['trigger_type'] = $mappedTrigger;
                }
            }
            // Persist normalized graph so edit/builder stay consistent next open.
            model(AutomationModel::class)->update($id, $update);

            // Keep runtime rules in sync when normalize remaps action types (e.g. cheerio_action → response_message)
            $compiled = $wf->toRules($graph);
            if ($compiled !== []) {
                $this->saveRulesPayload($id, $compiled);
                $rules = model(AutomationRuleModel::class)
                    ->where('automation_id', $id)
                    ->orderBy('step_order', 'ASC')
                    ->findAll();
            }
        } elseif (! is_array($graph) || empty($graph['nodes'])) {
            $graph = $wf->fromRules(
                $rules,
                (string) ($automation['trigger_type'] ?? 'incoming_message')
            );
        }

        $automation['rules'] = $rules;

        return $this->render('automations/builder', [
            'pageTitle'  => 'Workflow Builder',
            'automation' => $automation,
            'rules'      => $rules,
            'flowGraph'  => $graph,
            'tags'       => model(TagModel::class)->orderBy('name', 'ASC')->findAll(),
            'templates'  => model(TemplateModel::class)->getApproved(),
            'agents'     => model(UserModel::class)->where('status', 'active')->orderBy('name', 'ASC')->findAll(200),
            'attributes' => ContactAttributes::knownKeys(),
            'fullBleed'  => true,
        ]);
    }

    /**
     * AJAX / form: save builder flow graph + compiled rules.
     */
    public function builder(int $id): ResponseInterface
    {
        if ($denied = $this->requirePermission('automations.edit')) {
            return $denied;
        }

        if (model(AutomationModel::class)->find($id) === null) {
            return $this->jsonResponse(false, null, 'Automation not found.', [], 404);
        }

        $input = $this->request->getJSON(true) ?: $this->request->getPost();
        $graph = $input['flow_graph'] ?? $input['graph'] ?? null;
        $rules = $input['rules'] ?? null;

        if (is_string($graph)) {
            $decoded = json_decode($graph, true);
            $graph   = is_array($decoded) ? $decoded : null;
        }

        $wf = new WorkflowGraph();

        if (is_array($graph) && ! empty($graph['nodes'])) {
            $rules = $wf->toRules($graph);
            $triggerType = $wf->triggerFromGraph($graph);
            $existing    = model(AutomationModel::class)->find($id);
            $update      = [
                'flow_graph' => $graph,
            ];
            // Always trust the canvas trigger node (cheerio_workflow never fires on inbound WA).
            if ($triggerType) {
                $update['trigger_type'] = $triggerType === 'cheerio_workflow'
                    ? 'incoming_message'
                    : $triggerType;
            }
            if (isset($input['name']) && is_string($input['name']) && trim($input['name']) !== '') {
                $update['name'] = trim($input['name']);
            } elseif (! empty($existing['name'])) {
                // Never wipe the saved workflow name on canvas save.
                $update['name'] = (string) $existing['name'];
            }
            if (isset($input['is_active'])) {
                $update['is_active'] = (int) $input['is_active'];
            }
            if (isset($input['priority'])) {
                $update['priority'] = (int) $input['priority'];
            }
            if (isset($input['trigger_config'])) {
                $cfg = $input['trigger_config'];
                if (is_string($cfg)) {
                    $cfg = json_decode($cfg, true);
                }
                if (is_array($cfg)) {
                    $update['trigger_config'] = $cfg;
                }
            }
            model(AutomationModel::class)->update($id, $update);
        } elseif (is_string($rules)) {
            $decoded = json_decode($rules, true);
            $rules   = is_array($decoded) ? $decoded : null;
        }

        if (! is_array($rules)) {
            return $this->jsonResponse(false, null, 'flow_graph or rules required.', [], 422);
        }

        $this->saveRulesPayload($id, $rules);

        (new ActivityLogger())->log('builder_save', 'automations', 'Workflow canvas saved', [
            'automation_id' => $id,
            'rule_count'    => count($rules),
        ]);

        if ($this->request->isAJAX() || $this->request->getHeaderLine('Accept') === 'application/json'
            || str_contains((string) $this->request->getHeaderLine('Content-Type'), 'application/json')) {
            return $this->jsonResponse(true, [
                'rules'      => model(AutomationRuleModel::class)->getByAutomation($id),
                'flow_graph' => model(AutomationModel::class)->find($id)['flow_graph'] ?? $graph,
            ], 'Workflow saved.');
        }

        return redirect()->to('/automations/' . $id . '/builder')->with('success', 'Workflow saved.');
    }

    /**
     * Replace all rules for an automation from builder payload.
     *
     * @param list<array<string, mixed>>|string|null $rules
     */
    protected function saveRulesPayload(int $automationId, mixed $rules): void
    {
        if (is_string($rules) && $rules !== '') {
            $decoded = json_decode($rules, true);
            $rules   = is_array($decoded) ? $decoded : [];
        }

        if (! is_array($rules)) {
            return;
        }

        $ruleModel = model(AutomationRuleModel::class);
        $ruleModel->where('automation_id', $automationId)->delete();

        $order = 0;
        foreach ($rules as $rule) {
            if (! is_array($rule)) {
                continue;
            }

            $config = $rule['config'] ?? [];
            if (is_string($config)) {
                $decoded = json_decode($config, true);
                $config  = is_array($decoded) ? $decoded : [];
            }

            $ruleModel->insert([
                'automation_id' => $automationId,
                'step_order'    => (int) ($rule['step_order'] ?? $order),
                'rule_type'     => (string) ($rule['rule_type'] ?? 'action'),
                'action_type'   => $rule['action_type'] ?? null,
                'config'        => $config,
                'next_on_true'  => $rule['next_on_true'] ?? null,
                'next_on_false' => $rule['next_on_false'] ?? null,
            ]);
            $order++;
        }
    }

    /**
     * @param array<string, mixed> $graph
     */
    protected function graphHasUnmappedCheerioNodes(array $graph): bool
    {
        foreach ($graph['nodes'] ?? [] as $node) {
            if (! is_array($node)) {
                continue;
            }
            $type = (string) ($node['type'] ?? '');
            if ($type !== '' && ! in_array($type, ['trigger', 'condition', 'action', 'end'], true)) {
                return true;
            }
            $action = (string) (($node['data']['action_type'] ?? '') ?: '');
            if ($action !== '' && str_starts_with($action, 'cheerio_')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Import workflows from the active provider into local automations.
     * Cheerio: Direct API workflows. Meta: uses Cheerio API key if saved (Graph has no workflow directory).
     */
    public function syncFromCheerio(): ResponseInterface
    {
        if ($denied = $this->requirePermission('automations.create')) {
            return $denied;
        }

        $settings = service('settingsService');
        $provider = $settings->getWhatsAppProvider();

        try {
            $stats  = (new \App\Libraries\CheerioSyncService())->syncWorkflows($this->userId());
            $source = (string) ($stats['source'] ?? $provider);
            $msg    = sprintf(
                '%s workflows: %d created, %d updated%s. Imported as Off — review before enabling.',
                $source === 'cheerio' || $source === 'cheerio_directory' ? 'Cheerio' : ucfirst($source),
                $stats['created'],
                $stats['updated'],
                $stats['skipped'] ? ', ' . $stats['skipped'] . ' skipped' : ''
            );
            if ($provider === 'meta' && ($source === 'cheerio' || $source === 'cheerio_directory')) {
                $msg .= ' (messaging stays on Meta)';
            }

            if ($this->request->isAJAX()) {
                return $this->jsonResponse(true, $stats, $msg);
            }

            return redirect()->to('/automations')->with('success', $msg);
        } catch (\Throwable $e) {
            log_message('error', 'Workflow sync failed: {msg}', ['msg' => $e->getMessage()]);

            if ($this->request->isAJAX()) {
                return $this->jsonResponse(false, null, $e->getMessage(), [], 500);
            }

            return redirect()->to('/automations')->with('error', $e->getMessage());
        }
    }
}