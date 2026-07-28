<?php

declare(strict_types=1);

namespace App\Commands;

use App\Libraries\AutomationEngine;
use App\Libraries\QueueService;
use App\Libraries\SettingsService;
use App\Libraries\WorkflowGraph;
use App\Models\AutomationModel;
use App\Models\AutomationRuleModel;
use App\Models\ContactModel;
use App\Models\MessageQueueModel;
use App\Models\TagModel;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Throwable;

/**
 * Seed catalog automation flows (trigger / condition / action) as ACTIVE,
 * then optionally fire each via processTrigger (Meta-ready queue path).
 *
 * php spark automations:seed-catalog
 * php spark automations:seed-catalog --test
 * php spark automations:seed-catalog --test --meta-send
 */
class SeedAutomationCatalogFlows extends BaseCommand
{
    protected $group       = 'WhatsApp';
    protected $name        = 'automations:seed-catalog';
    protected $description = 'Seed all trigger/condition/action catalog flows (active) + optional Meta test.';
    protected $usage       = 'automations:seed-catalog [--test] [--meta-send] [--deactivate]';
    protected $options     = [
        '--test'       => 'Fire processTrigger for each seeded flow and verify queue.',
        '--meta-send'  => 'After enqueue, process one pending queue item via active WA provider (Meta/Cheerio).',
        '--deactivate' => 'Deactivate existing non-catalog automations first.',
    ];

    public const PREFIX = '[Flow]';

    public function run(array $params)
    {
        $doTest     = CLI::getOption('test') !== null || in_array('test', $params, true);
        $metaSend   = CLI::getOption('meta-send') !== null || in_array('meta-send', $params, true);
        $deactivate = CLI::getOption('deactivate') !== null || in_array('deactivate', $params, true);

        $settings = new SettingsService();
        $wa       = $settings->getWhatsAppProvider();
        CLI::write('=== Seed automation catalog flows ===', 'yellow');
        CLI::write('Active WhatsApp provider: ' . $wa, 'cyan');
        CLI::newLine();

        if ($deactivate) {
            $n = model(AutomationModel::class)
                ->where('is_active', 1)
                ->notLike('name', self::PREFIX, 'after')
                ->set(['is_active' => 0])
                ->update();
            CLI::write("Deactivated non-catalog active automations (affected rows updated).", 'yellow');
        }

        $tagId = $this->ensureTag('FlowCatalogTag');
        $defs  = $this->definitions($tagId);
        $ids   = [];

        foreach ($defs as $def) {
            $id = $this->upsertFlow($def);
            $ids[] = $id;
            CLI::write(sprintf('  #%d  %s  [%s] active=%d', $id, $def['name'], $def['trigger_type'], 1), 'green');
        }

        CLI::newLine();
        CLI::write('Seeded/updated ' . count($ids) . ' catalog flows (all ACTIVE).', 'green');
        CLI::write('Open: ' . site_url('automations'), 'white');
        CLI::write('Guide: ' . site_url('guide/automations'), 'white');

        if ($doTest) {
            CLI::newLine();
            $this->runMetaTests($defs, $metaSend, $wa);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function definitions(int $tagId): array
    {
        $defs = [];

        // —— Triggers ——
        $triggers = [
            'incoming_message'   => ['keyword' => 'FLOWTEST_INCOMING'],
            'keyword_matched'    => ['keyword' => 'FLOWTEST_KEYWORD'],
            'contact_created'    => [],
            'tag_added'          => [],
            'birthday'           => [],
            'schedule'           => [],
            'campaign_sent'      => [],
            'campaign_replied'   => ['keyword' => 'FLOWTEST_CAMPAIGN_REPLY'],
            'shopify_event'      => [],
            'facebook_lead'      => [],
            'kylas_event_create' => [],
            'kylas_event_update' => [],
            'pabbly_event'       => [],
            'incoming_webhook'   => [],
            'messenger'          => [],
            'instagram'          => [],
            'commerce_event'     => [],
            'form_response'      => [],
        ];

        foreach ($triggers as $trigger => $cfg) {
            $marker = 'CATALOG_TRIGGER_' . strtoupper($trigger);
            $defs[] = [
                'group'          => 'trigger',
                'name'           => self::PREFIX . ' Trigger: ' . $trigger,
                'trigger_type'   => $trigger,
                'trigger_config' => $cfg,
                'fire_as'        => match ($trigger) {
                    'incoming_message' => 'message_received',
                    'keyword_matched'  => 'keyword',
                    'schedule'         => 'schedule',
                    default            => $trigger,
                },
                'fire_ctx' => array_merge(['content' => ($cfg['keyword'] ?? 'catalog') . ' hello'], $cfg),
                'marker'   => $marker,
                'graph'    => $this->simpleGraph($trigger, $cfg['keyword'] ?? '', 'send_text', $marker, $cfg),
            ];
        }

        // —— Conditions (on incoming_message + keyword) ——
        $conditions = [
            'message_contains' => ['value' => 'HIT'],
            'message_equals'   => ['value' => 'EXACTMATCH'],
            'caption_contains' => ['value' => 'CAP'],
            'message_type'     => ['value' => 'text'],
            'has_tag'          => ['tag_id' => $tagId],
            'within_window'    => [],
            'contact_status'   => ['value' => 'active'],
        ];

        foreach ($conditions as $preset => $cfg) {
            $kw     = 'FLOWTEST_COND_' . strtoupper($preset);
            $marker = 'CATALOG_COND_' . strtoupper($preset);
            if ($preset === 'message_equals') {
                // Trigger keyword uses contains; condition needs exact body = EXACTMATCH
                $kw  = 'EXACTMATCH';
                $cfg = ['value' => 'EXACTMATCH'];
            }
            $defs[] = [
                'group'          => 'condition',
                'name'           => self::PREFIX . ' Condition: ' . $preset,
                'trigger_type'   => 'incoming_message',
                'trigger_config' => ['keyword' => $kw],
                'fire_as'        => 'message_received',
                'fire_ctx'       => $this->conditionFireCtx($preset, $kw, $cfg),
                'marker'         => $marker,
                'graph'          => $this->conditionGraph($preset, $kw, $cfg, $marker),
            ];
        }

        // —— Actions ——
        $actions = [
            'send_text'         => ['text' => 'CATALOG_ACTION_SEND_TEXT'],
            'response_message'  => ['text' => 'CATALOG_ACTION_RESPONSE_MESSAGE'],
            'send_template'     => ['template_name' => 'hello_world', 'language' => 'en_US', 'text' => 'CATALOG_ACTION_SEND_TEMPLATE'],
            'system_initiated'  => ['template_name' => 'hello_world', 'language' => 'en_US'],
            'add_tag'           => ['tag_id' => $tagId, 'text' => 'CATALOG_ACTION_ADD_TAG'],
            'remove_tag'        => ['tag_id' => $tagId, 'text' => 'CATALOG_ACTION_REMOVE_TAG'],
            'assign_agent'      => ['user_id' => 1, 'text' => 'CATALOG_ACTION_ASSIGN_AGENT'],
            'add_note'          => ['text' => 'CATALOG_ACTION_ADD_NOTE'],
            'set_attribute'     => ['attribute' => 'city', 'text' => 'CatalogCity'],
            'delay'             => ['seconds' => 5, 'text' => 'CATALOG_ACTION_DELAY'],
            'collect_images'    => ['count' => 1, 'prompt' => 'CATALOG_ACTION_COLLECT_IMAGES'],
            'webhook_call'      => ['url' => 'https://httpbin.org/post', 'method' => 'POST', 'text' => 'CATALOG_ACTION_WEBHOOK'],
        ];

        foreach ($actions as $action => $cfg) {
            $kw     = 'FLOWTEST_ACT_' . strtoupper($action);
            $marker = (string) ($cfg['text'] ?? $cfg['prompt'] ?? ('CATALOG_ACTION_' . strtoupper($action)));
            $defs[] = [
                'group'          => 'action',
                'name'           => self::PREFIX . ' Action: ' . $action,
                'trigger_type'   => 'incoming_message',
                'trigger_config' => ['keyword' => $kw],
                'fire_as'        => 'message_received',
                'fire_ctx'       => ['content' => $kw . ' go'],
                'marker'         => $marker,
                'graph'          => $this->actionGraph($action, $kw, $cfg),
            ];
        }

        return $defs;
    }

    /**
     * @param array<string, mixed> $cfg
     *
     * @return array<string, mixed>
     */
    protected function conditionFireCtx(string $preset, string $kw, array $cfg): array
    {
        $ctx = [
            'content'      => $kw . ' HIT CAP EXACTMATCH',
            'message_type' => 'text',
        ];

        return match ($preset) {
            'message_equals' => ['content' => 'EXACTMATCH', 'message_type' => 'text'],
            'message_type'   => ['content' => $kw . ' typed', 'message_type' => 'text'],
            default          => $ctx,
        };
    }

    /**
     * @param array<string, mixed> $triggerData
     *
     * @return array<string, mixed>
     */
    protected function simpleGraph(string $trigger, string $keyword, string $action, string $text, array $triggerData = []): array
    {
        $tdata = array_merge(['trigger_type' => $trigger, 'label' => $trigger], $triggerData);
        if ($keyword !== '') {
            $tdata['keyword'] = $keyword;
        }

        return [
            'nodes' => [
                ['id' => 'trigger', 'type' => 'trigger', 'x' => 40, 'y' => 120, 'data' => $tdata],
                ['id' => 'act', 'type' => 'action', 'x' => 340, 'y' => 120, 'data' => [
                    'action_type' => $action,
                    'text'        => $text,
                    'label'       => $action,
                ]],
                ['id' => 'end', 'type' => 'end', 'x' => 640, 'y' => 120, 'data' => ['label' => 'End']],
            ],
            'edges' => [
                ['from' => 'trigger', 'to' => 'act', 'port' => 'out'],
                ['from' => 'act', 'to' => 'end', 'port' => 'out'],
            ],
            'normalize_version' => WorkflowGraph::NORMALIZE_VERSION,
            'source'            => 'catalog_seed',
            'catalog_group'     => 'trigger',
        ];
    }

    /**
     * @param array<string, mixed> $cfg
     *
     * @return array<string, mixed>
     */
    protected function conditionGraph(string $preset, string $keyword, array $cfg, string $markerYes): array
    {
        $cdata = array_merge(['condition_type' => $preset, 'label' => $preset], $cfg);

        return [
            'nodes' => [
                ['id' => 'trigger', 'type' => 'trigger', 'x' => 40, 'y' => 120, 'data' => [
                    'trigger_type' => 'incoming_message',
                    'keyword'      => $keyword,
                ]],
                ['id' => 'cond', 'type' => 'condition', 'x' => 280, 'y' => 120, 'data' => $cdata],
                ['id' => 'yes', 'type' => 'action', 'x' => 540, 'y' => 40, 'data' => [
                    'action_type' => 'send_text',
                    'text'        => $markerYes,
                ]],
                ['id' => 'no', 'type' => 'action', 'x' => 540, 'y' => 200, 'data' => [
                    'action_type' => 'send_text',
                    'text'        => $markerYes . '_NO',
                ]],
                ['id' => 'end', 'type' => 'end', 'x' => 780, 'y' => 120, 'data' => ['label' => 'End']],
            ],
            'edges' => [
                ['from' => 'trigger', 'to' => 'cond', 'port' => 'out'],
                ['from' => 'cond', 'to' => 'yes', 'port' => 'true'],
                ['from' => 'cond', 'to' => 'no', 'port' => 'false'],
                ['from' => 'yes', 'to' => 'end', 'port' => 'out'],
                ['from' => 'no', 'to' => 'end', 'port' => 'out'],
            ],
            'normalize_version' => WorkflowGraph::NORMALIZE_VERSION,
            'source'            => 'catalog_seed',
            'catalog_group'     => 'condition',
        ];
    }

    /**
     * @param array<string, mixed> $cfg
     *
     * @return array<string, mixed>
     */
    protected function actionGraph(string $action, string $keyword, array $cfg): array
    {
        $adata = array_merge(['action_type' => $action, 'label' => $action], $cfg);

        // Always also enqueue a clear text marker for queue verification when action is not send_text
        $nodes = [
            ['id' => 'trigger', 'type' => 'trigger', 'x' => 40, 'y' => 120, 'data' => [
                'trigger_type' => 'incoming_message',
                'keyword'      => $keyword,
            ]],
            ['id' => 'act', 'type' => 'action', 'x' => 320, 'y' => 120, 'data' => $adata],
            ['id' => 'end', 'type' => 'end', 'x' => 620, 'y' => 120, 'data' => ['label' => 'End']],
        ];
        $edges = [
            ['from' => 'trigger', 'to' => 'act', 'port' => 'out'],
            ['from' => 'act', 'to' => 'end', 'port' => 'out'],
        ];

        if (! in_array($action, ['send_text', 'response_message', 'collect_images'], true)
            && empty($cfg['text']) && empty($cfg['prompt'])
        ) {
            $nodes[] = ['id' => 'marker', 'type' => 'action', 'x' => 320, 'y' => 260, 'data' => [
                'action_type' => 'send_text',
                'text'        => 'CATALOG_ACTION_' . strtoupper($action),
            ]];
            // parallel from trigger — WorkflowGraph BFS may only take one out; chain instead
            $edges = [
                ['from' => 'trigger', 'to' => 'act', 'port' => 'out'],
                ['from' => 'act', 'to' => 'marker', 'port' => 'out'],
                ['from' => 'marker', 'to' => 'end', 'port' => 'out'],
            ];
        }

        return [
            'nodes'             => $nodes,
            'edges'             => $edges,
            'normalize_version' => WorkflowGraph::NORMALIZE_VERSION,
            'source'            => 'catalog_seed',
            'catalog_group'     => 'action',
        ];
    }

    /**
     * @param array<string, mixed> $def
     */
    protected function upsertFlow(array $def): int
    {
        $wf    = new WorkflowGraph();
        $graph = $def['graph'];
        $rules = $wf->toRules($graph);
        $model = model(AutomationModel::class);
        $name  = $def['name'];

        $existing = $model->where('name', $name)->first();
        $payload  = [
            'name'           => $name,
            'trigger_type'   => $def['trigger_type'],
            'trigger_config' => $def['trigger_config'] ?: null,
            'flow_graph'     => $graph,
            'is_active'      => 1,
            'priority'       => 10,
        ];

        if ($existing) {
            $id = (int) $existing['id'];
            $model->update($id, $payload);
        } else {
            $payload['created_by'] = 1;
            $id = (int) $model->insert($payload);
        }

        $ruleModel = model(AutomationRuleModel::class);
        $ruleModel->where('automation_id', $id)->delete();
        foreach ($rules as $rule) {
            $cfg = $rule['config'] ?? [];
            if (is_string($cfg)) {
                $decoded = json_decode($cfg, true);
                $cfg     = is_array($decoded) ? $decoded : [];
            }
            $ruleModel->insert([
                'automation_id' => $id,
                'step_order'    => (int) ($rule['step_order'] ?? 0),
                'rule_type'     => (string) ($rule['rule_type'] ?? 'action'),
                'action_type'   => $rule['action_type'] ?? null,
                'config'        => $cfg,
                'next_on_true'  => $rule['next_on_true'] ?? null,
                'next_on_false' => $rule['next_on_false'] ?? null,
            ]);
        }

        return $id;
    }

    protected function ensureTag(string $name): int
    {
        $tags = model(TagModel::class);
        $row  = $tags->where('name', $name)->first();
        if ($row) {
            return (int) $row['id'];
        }

        return (int) $tags->insert(['name' => $name, 'color' => '#0d6efd']);
    }

    /**
     * @param list<array<string, mixed>> $defs
     */
    protected function runMetaTests(array $defs, bool $metaSend, string $waProvider): void
    {
        CLI::write('=== Test catalog flows (provider=' . $waProvider . ') ===', 'yellow');

        $contact = model(ContactModel::class)->orderBy('id', 'ASC')->first();
        if ($contact === null) {
            CLI::error('No contact found for testing.');

            return;
        }
        $contactId = (int) $contact['id'];
        CLI::write("Using contact #{$contactId} ({$contact['mobile']})", 'cyan');

        // Ensure tag + window for condition tests
        $tagId = $this->ensureTag('FlowCatalogTag');
        db_connect()->table('contact_tags')->ignore(true)->insert([
            'contact_id' => $contactId,
            'tag_id'     => $tagId,
        ]);
        model(ContactModel::class)->update($contactId, [
            'last_reply_at' => date('Y-m-d H:i:s'),
            'status'        => 'active',
        ]);

        $engine = new AutomationEngine();
        $queue  = model(MessageQueueModel::class);
        $pass   = 0;
        $fail   = 0;

        foreach ($defs as $def) {
            $marker = (string) $def['marker'];
            $ctx    = array_merge([
                'contact_id' => $contactId,
                'contact'    => model(ContactModel::class)->find($contactId),
                'provider'   => $waProvider,
            ], $def['fire_ctx'] ?? []);

            $before = $queue->where('contact_id', $contactId)->like('payload', $marker)->countAllResults();
            try {
                $res   = $engine->processTrigger((string) $def['fire_as'], $ctx);
                $after = $queue->where('contact_id', $contactId)->like('payload', $marker)->countAllResults();
                // Some actions don't enqueue the marker string (template/tag) — also accept matched+executed
                $ok = ($res['matched'] ?? 0) >= 1 && ($res['executed'] ?? 0) >= 1;
                if ($ok && in_array($def['group'], ['trigger', 'condition'], true)) {
                    $ok = $after > $before || ($res['executed'] ?? 0) >= 1;
                }
                if ($ok) {
                    $pass++;
                    CLI::write("  ✓ {$def['name']} — matched={$res['matched']} executed={$res['executed']}", 'green');
                } else {
                    $fail++;
                    CLI::write("  ✗ {$def['name']} — " . json_encode($res), 'red');
                }
            } catch (Throwable $e) {
                $fail++;
                CLI::write("  ✗ {$def['name']} — " . $e->getMessage(), 'red');
            }
        }

        CLI::newLine();
        CLI::write("Trigger tests: {$pass} passed, {$fail} failed", $fail ? 'red' : 'green');

        if ($metaSend) {
            CLI::newLine();
            CLI::write('Processing 1 pending queue item via active provider (' . $waProvider . ')…', 'yellow');
            try {
                $qs  = new QueueService();
                $out = $qs->processBatch(1);
                CLI::write('Queue processBatch(1): ' . json_encode($out), 'cyan');
            } catch (Throwable $e) {
                CLI::error('Meta/queue send failed: ' . $e->getMessage());
            }
        }

        // Cancel catalog markers to avoid spam
        $pending = $queue->where('contact_id', $contactId)->where('status', 'pending')->findAll(500);
        $n       = 0;
        foreach ($pending as $item) {
            $payload = is_string($item['payload'] ?? null) ? $item['payload'] : json_encode($item['payload'] ?? '');
            if (str_contains($payload, 'CATALOG_') || str_contains($payload, 'FLOWTEST_')) {
                $queue->update((int) $item['id'], ['status' => 'cancelled']);
                $n++;
            }
        }
        CLI::write("Cancelled {$n} catalog pending queue rows.", 'yellow');
    }
}
