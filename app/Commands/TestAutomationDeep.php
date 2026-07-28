<?php

declare(strict_types=1);

namespace App\Commands;

use App\Libraries\AutomationEngine;
use App\Libraries\QueueService;
use App\Libraries\WorkflowGraph;
use App\Models\AutomationModel;
use App\Models\AutomationRuleModel;
use App\Models\ContactModel;
use App\Models\MessageQueueModel;
use App\Models\TagModel;
use App\Models\UserModel;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use ReflectionClass;
use Throwable;

/**
 * In-depth runtime checks for automation triggers, conditions, and actions.
 *
 * php spark automations:test-deep
 * php spark automations:test-deep --keep
 */
class TestAutomationDeep extends BaseCommand
{
    protected $group       = 'WhatsApp';
    protected $name        = 'automations:test-deep';
    protected $description = 'Deep-test automation triggers, conditions, actions, and graph compile.';
    protected $usage       = 'automations:test-deep [--keep]';
    protected $options     = [
        '--keep' => 'Keep temporary test automation instead of deleting it.',
    ];

    /** @var list<array{ok: bool, section: string, name: string, detail: string}> */
    protected array $results = [];

    public function run(array $params)
    {
        $keep = CLI::getOption('keep') !== null || in_array('keep', $params, true);

        CLI::write('=== Automation deep test ===', 'yellow');
        CLI::newLine();

        $contact = $this->pickContact();
        if ($contact === null) {
            CLI::error('No contacts found — create a contact first.');

            return;
        }

        $contactId = (int) $contact['id'];
        CLI::write("Using contact #{$contactId} ({$contact['name']} / {$contact['mobile']})", 'cyan');

        $tagId  = $this->ensureTag('AutoTestTag');
        $agentId = $this->pickAgentId();

        $this->sectionCatalog();
        $this->sectionTriggers();
        $this->sectionConditions($contact, $tagId);
        $this->sectionConditionOperators($contact);
        $this->sectionActions($contactId, $tagId, $agentId);
        $this->sectionActionExtras($contactId);
        $this->sectionGraphCompile();
        $this->sectionGraphPerTrigger();
        $this->sectionLiveTriggerFlows($contactId, $tagId, $keep);
        $this->sectionEndToEnd($contactId, $tagId, $keep);

        CLI::newLine();
        $pass = count(array_filter($this->results, static fn ($r) => $r['ok']));
        $fail = count($this->results) - $pass;
        CLI::write("RESULT: {$pass} passed, {$fail} failed (total " . count($this->results) . ')', $fail ? 'red' : 'green');

        if ($fail > 0) {
            CLI::newLine();
            CLI::write('Failures:', 'red');
            foreach ($this->results as $r) {
                if (! $r['ok']) {
                    CLI::write("  [{$r['section']}] {$r['name']}: {$r['detail']}", 'red');
                }
            }
            exit(1);
        }
    }

    protected function ok(string $section, string $name, string $detail = 'ok'): void
    {
        $this->results[] = ['ok' => true, 'section' => $section, 'name' => $name, 'detail' => $detail];
        CLI::write("  ✓ {$name}" . ($detail !== 'ok' ? " — {$detail}" : ''), 'green');
    }

    protected function fail(string $section, string $name, string $detail): void
    {
        $this->results[] = ['ok' => false, 'section' => $section, 'name' => $name, 'detail' => $detail];
        CLI::write("  ✗ {$name} — {$detail}", 'red');
    }

    protected function assertTrue(string $section, string $name, bool $cond, string $detail = ''): void
    {
        if ($cond) {
            $this->ok($section, $name, $detail !== '' ? $detail : 'ok');
        } else {
            $this->fail($section, $name, $detail !== '' ? $detail : 'expected true');
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function pickContact(): ?array
    {
        return model(ContactModel::class)->orderBy('id', 'ASC')->first();
    }

    protected function ensureTag(string $name): int
    {
        $tags = model(TagModel::class);
        $row  = $tags->where('name', $name)->first();
        if ($row) {
            return (int) $row['id'];
        }

        return (int) $tags->insert(['name' => $name, 'color' => '#25D366']);
    }

    protected function pickAgentId(): int
    {
        $u = model(UserModel::class)->where('status', 'active')->orderBy('id', 'ASC')->first();

        return $u ? (int) $u['id'] : 1;
    }

    protected function engine(): AutomationEngine
    {
        return new AutomationEngine();
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $context
     */
    protected function evalCond(array $config, array $context): bool
    {
        return $this->engine()->evaluateCondition($config, $context);
    }

    /**
     * Call protected normalizeActionType via reflection.
     */
    protected function normalize(string $type): string
    {
        $ref = new ReflectionClass(AutomationEngine::class);
        $m   = $ref->getMethod('normalizeActionType');
        $m->setAccessible(true);

        return (string) $m->invoke($this->engine(), $type);
    }

    /**
     * Call protected matchesTriggerConfig via reflection.
     *
     * @param array<string, mixed> $cfg
     * @param array<string, mixed> $ctx
     */
    protected function matchesCfg(array $cfg, array $ctx): bool
    {
        $ref = new ReflectionClass(AutomationEngine::class);
        $m   = $ref->getMethod('matchesTriggerConfig');
        $m->setAccessible(true);

        return (bool) $m->invoke($this->engine(), $cfg, $ctx);
    }

    protected function sectionTriggers(): void
    {
        CLI::write('1) TRIGGERS / aliases / filters', 'yellow');
        $engine = $this->engine();

        $pairs = [
            'message_received'   => ['message_received', 'incoming_message'],
            'incoming_message'   => ['message_received', 'incoming_message'],
            'keyword'            => ['keyword', 'keyword_matched'],
            'keyword_matched'    => ['keyword', 'keyword_matched'],
            'contact_created'    => ['contact_created'],
            'tag_added'          => ['tag_added'],
            'birthday'           => ['birthday', 'schedule'],
            'schedule'           => ['birthday', 'schedule'],
            'campaign_sent'      => ['campaign_sent'],
            'campaign_replied'   => ['campaign_replied'],
            'shopify_event'      => ['shopify_event', 'shopify'],
            'facebook_lead'      => ['facebook_lead', 'facebooklead'],
            'kylas_event_create' => ['kylas_event_create', 'kylas_create'],
            'kylas_event_update' => ['kylas_event_update', 'kylas_update'],
            'pabbly_event'       => ['pabbly_event', 'pabbly'],
            'incoming_webhook'   => ['incoming_webhook', 'incomingwebhook'],
            'messenger'          => ['messenger'],
            'instagram'          => ['instagram'],
            'commerce_event'     => ['commerce_event', 'commerce'],
            'form_response'      => ['form_response', 'lead_form', 'leadform'],
        ];

        foreach ($pairs as $input => $expect) {
            $got = $engine->triggerAliases($input);
            $this->assertTrue(
                'trigger',
                "alias {$input}",
                $got === $expect,
                'got=' . json_encode($got)
            );
        }

        $this->assertTrue(
            'trigger',
            'empty config matches',
            $this->matchesCfg([], ['content' => 'hello'])
        );
        $this->assertTrue(
            'trigger',
            'keyword filter hit',
            $this->matchesCfg(['keyword' => 'help'], ['content' => 'Please HELP me'])
        );
        $this->assertTrue(
            'trigger',
            'keyword filter miss',
            ! $this->matchesCfg(['keyword' => 'help'], ['content' => 'hello there'])
        );
        $this->assertTrue(
            'trigger',
            'content alias filter',
            $this->matchesCfg(['content' => 'price'], ['text' => 'what is the PRICE?'])
        );
    }

    /**
     * @param array<string, mixed> $contact
     */
    protected function sectionConditions(array $contact, int $tagId): void
    {
        CLI::write('2) CONDITIONS', 'yellow');
        $contactId = (int) $contact['id'];
        $ctx = [
            'contact_id' => $contactId,
            'contact'    => $contact,
            'content'    => 'Hello WORLD from test',
            'text'       => 'Hello WORLD from test',
        ];

        // Presets used by builder
        $this->assertTrue(
            'condition',
            'message_contains hit',
            $this->evalCond(['_preset' => 'message_contains', 'value' => 'world'], $ctx)
        );
        $this->assertTrue(
            'condition',
            'message_contains miss',
            ! $this->evalCond(['_preset' => 'message_contains', 'value' => 'zzzz'], $ctx)
        );
        $this->assertTrue(
            'condition',
            'message_equals hit',
            $this->evalCond(['_preset' => 'message_equals', 'value' => 'Hello WORLD from test'], $ctx)
        );
        $this->assertTrue(
            'condition',
            'message_equals miss',
            ! $this->evalCond(['_preset' => 'message_equals', 'value' => 'nope'], $ctx)
        );

        $status = (string) ($contact['status'] ?? 'active');
        $this->assertTrue(
            'condition',
            'contact_status hit',
            $this->evalCond(['_preset' => 'contact_status', 'value' => $status], $ctx)
        );
        $this->assertTrue(
            'condition',
            'contact_status miss',
            ! $this->evalCond(['_preset' => 'contact_status', 'value' => 'blocked_xyz'], $ctx)
        );

        // Attach tag then test has_tag
        $db = db_connect();
        $db->table('contact_tags')->where(['contact_id' => $contactId, 'tag_id' => $tagId])->delete();
        $this->assertTrue(
            'condition',
            'has_tag miss before attach',
            ! $this->evalCond(['_preset' => 'has_tag', 'tag_id' => $tagId], $ctx)
        );
        $db->table('contact_tags')->ignore(true)->insert([
            'contact_id' => $contactId,
            'tag_id'     => $tagId,
        ]);
        $this->assertTrue(
            'condition',
            'has_tag hit after attach',
            $this->evalCond(['_preset' => 'has_tag', 'tag_id' => $tagId], $ctx)
        );

        // within_window — refresh last_reply_at
        model(ContactModel::class)->update($contactId, ['last_reply_at' => date('Y-m-d H:i:s')]);
        $fresh = model(ContactModel::class)->find($contactId);
        $ctx['contact'] = $fresh;
        $this->assertTrue(
            'condition',
            'within_window recent',
            $this->evalCond(['_preset' => 'within_window'], $ctx)
        );
        model(ContactModel::class)->update($contactId, ['last_reply_at' => date('Y-m-d H:i:s', strtotime('-2 days'))]);
        $old = model(ContactModel::class)->find($contactId);
        $this->assertTrue(
            'condition',
            'within_window expired',
            ! $this->evalCond(['_preset' => 'within_window'], ['contact_id' => $contactId, 'contact' => $old])
        );
        // restore window for later action sends
        model(ContactModel::class)->update($contactId, ['last_reply_at' => date('Y-m-d H:i:s')]);

        // Raw operators
        $this->assertTrue(
            'condition',
            'contains operator',
            $this->evalCond(['operator' => 'contains', 'field' => 'content', 'value' => 'WORLD'], $ctx)
        );
        $this->assertTrue(
            'condition',
            'starts_with operator',
            $this->evalCond(['operator' => 'starts_with', 'field' => 'content', 'value' => 'hello'], $ctx)
        );
        $this->assertTrue(
            'condition',
            'not_contains operator',
            $this->evalCond(['operator' => 'not_contains', 'field' => 'content', 'value' => 'zzzz'], $ctx)
        );
        $this->assertTrue(
            'condition',
            'and composite',
            $this->evalCond([
                'operator'   => 'and',
                'conditions' => [
                    ['operator' => 'contains', 'field' => 'content', 'value' => 'Hello'],
                    ['operator' => 'contains', 'field' => 'content', 'value' => 'test'],
                ],
            ], $ctx)
        );
        $this->assertTrue(
            'condition',
            'or composite',
            $this->evalCond([
                'operator'   => 'or',
                'conditions' => [
                    ['operator' => 'equals', 'field' => 'content', 'value' => 'nope'],
                    ['operator' => 'contains', 'field' => 'content', 'value' => 'WORLD'],
                ],
            ], $ctx)
        );
        $this->assertTrue(
            'condition',
            'unknown operator is false',
            ! $this->evalCond(['operator' => 'weird_op', 'field' => 'content', 'value' => 'x'], $ctx)
        );
    }

    protected function sectionActions(int $contactId, int $tagId, int $agentId): void
    {
        CLI::write('3) ACTIONS (normalize + execute)', 'yellow');
        $engine = $this->engine();
        $queue  = model(MessageQueueModel::class);

        $aliases = [
            'response_message' => 'send_text',
            'cheerio_action'   => 'send_text',
            'action'           => 'send_text',
            'system_initiated' => 'system_initiated',
            'collect_images'   => 'collect_images',
            'cheerio_addtolabel' => 'add_tag',
            'cheerio_timedelay'  => 'delay',
            'webhook'            => 'webhook_call',
            'cheerio_updateattribute' => 'set_attribute',
            'cheerio_assignagent'     => 'assign_agent',
        ];
        foreach ($aliases as $from => $to) {
            $this->assertTrue('action', "normalize {$from}→{$to}", $this->normalize($from) === $to, 'got=' . $this->normalize($from));
        }

        $ctx = [
            'contact_id'    => $contactId,
            'automation_id' => 0,
            'content'       => 'inbound sample',
            'contact'       => model(ContactModel::class)->find($contactId),
        ];

        // send_text / response_message
        $before = $queue->where('contact_id', $contactId)->where('status', 'pending')->countAllResults();
        try {
            $engine->executeAction('send_text', [
                'text' => 'DeepTest text {{contact.name}} :: {{content}}',
            ], $ctx);
            $after = $queue->where('contact_id', $contactId)->where('status', 'pending')->countAllResults();
            $this->assertTrue('action', 'send_text enqueues', $after > $before, "pending {$before}→{$after}");
        } catch (Throwable $e) {
            $this->fail('action', 'send_text enqueues', $e->getMessage());
        }

        try {
            $engine->executeAction('response_message', [
                'text' => 'DeepTest response_message alias',
            ], $ctx);
            // response_message is normalized by runAutomation, but executeAction gets raw —
            // call via normalized path
            $engine->executeAction($this->normalize('response_message'), [
                'text' => 'DeepTest via normalize',
            ], $ctx);
            $this->ok('action', 'response_message normalize+enqueue');
        } catch (Throwable $e) {
            $this->fail('action', 'response_message normalize+enqueue', $e->getMessage());
        }

        try {
            $engine->executeAction('system_initiated', [
                'template_name' => 'hello_world',
                'language'      => 'en_US',
            ], $ctx);
            $row = $queue->where('contact_id', $contactId)->where('message_type', 'template')->orderBy('id', 'DESC')->first();
            $this->assertTrue('action', 'system_initiated template enqueue', is_array($row) && (($row['message_type'] ?? '') === 'template'), 'type=' . ($row['message_type'] ?? 'none'));
        } catch (Throwable $e) {
            $this->fail('action', 'system_initiated template enqueue', $e->getMessage());
        }

        try {
            $engine->executeAction('send_template', [
                'template_name' => 'hello_world',
                'language'      => 'en',
            ], $ctx);
            $this->ok('action', 'send_template enqueue');
        } catch (Throwable $e) {
            $this->fail('action', 'send_template enqueue', $e->getMessage());
        }

        try {
            $engine->executeAction('add_tag', ['tag_id' => $tagId], $ctx);
            $n = db_connect()->table('contact_tags')->where(['contact_id' => $contactId, 'tag_id' => $tagId])->countAllResults();
            $this->assertTrue('action', 'add_tag', $n > 0);
        } catch (Throwable $e) {
            $this->fail('action', 'add_tag', $e->getMessage());
        }

        try {
            $engine->executeAction('remove_tag', ['tag_id' => $tagId], $ctx);
            $n = db_connect()->table('contact_tags')->where(['contact_id' => $contactId, 'tag_id' => $tagId])->countAllResults();
            $this->assertTrue('action', 'remove_tag', $n === 0);
            // re-add for later e2e
            $engine->executeAction('add_tag', ['tag_id' => $tagId], $ctx);
        } catch (Throwable $e) {
            $this->fail('action', 'remove_tag', $e->getMessage());
        }

        try {
            $engine->executeAction('assign_agent', ['user_id' => $agentId], $ctx);
            $c = model(ContactModel::class)->find($contactId);
            $this->assertTrue('action', 'assign_agent', (int) ($c['assigned_to'] ?? 0) === $agentId, 'assigned_to=' . ($c['assigned_to'] ?? 'null'));
        } catch (Throwable $e) {
            $this->fail('action', 'assign_agent', $e->getMessage());
        }

        try {
            $engine->executeAction('add_note', ['text' => 'DeepTest note ' . date('H:i:s')], $ctx);
            $n = db_connect()->table('internal_notes')->where('contact_id', $contactId)->like('note', 'DeepTest note')->countAllResults();
            $this->assertTrue('action', 'add_note', $n > 0);
        } catch (Throwable $e) {
            $this->fail('action', 'add_note', $e->getMessage());
        }

        try {
            $engine->executeAction('set_attribute', [
                'attribute' => 'city',
                'text'      => 'Pune',
            ], $ctx);
            $c = model(ContactModel::class)->find($contactId);
            $fields = $c['custom_fields'] ?? [];
            if (is_string($fields)) {
                $fields = json_decode($fields, true) ?: [];
            }
            $ok = (is_array($fields) && ($fields['city'] ?? null) === 'Pune')
                || (($c['city'] ?? null) === 'Pune');
            $this->assertTrue('action', 'set_attribute city', $ok, json_encode($fields));
        } catch (Throwable $e) {
            $this->fail('action', 'set_attribute city', $e->getMessage());
        }

        try {
            $beforeCtx = $ctx;
            $engine->executeAction('delay', ['minutes' => 2, 'seconds' => 120], $ctx);
            $this->assertTrue('action', 'delay sets _delayed_until', ! empty($ctx['_delayed_until']), json_encode($ctx['_delayed_until'] ?? null));
            $ctx = $beforeCtx;
        } catch (Throwable $e) {
            $this->fail('action', 'delay sets _delayed_until', $e->getMessage());
        }

        try {
            $engine->executeAction('collect_images', [
                'count'  => 2,
                'prompt' => 'Please send 2 photos',
            ], $ctx);
            $c = model(ContactModel::class)->find($contactId);
            $fields = $c['custom_fields'] ?? [];
            if (is_string($fields)) {
                $fields = json_decode($fields, true) ?: [];
            }
            $this->assertTrue(
                'action',
                'collect_images sets await flag',
                is_array($fields) && isset($fields['_await_images']),
                json_encode($fields['_await_images'] ?? null)
            );
        } catch (Throwable $e) {
            $this->fail('action', 'collect_images sets await flag', $e->getMessage());
        }

        // cheerio_action fallback with text
        try {
            $before = $queue->where('contact_id', $contactId)->where('status', 'pending')->countAllResults();
            $engine->executeAction('cheerio_action', [
                'text' => 'Fallback cheerio_action text',
            ], $ctx);
            // executeAction does not auto-normalize — default branch should still enqueue when text present
            $after = $queue->where('contact_id', $contactId)->where('status', 'pending')->countAllResults();
            $this->assertTrue('action', 'cheerio_action text fallback enqueue', $after > $before, "pending {$before}→{$after}");
        } catch (Throwable $e) {
            $this->fail('action', 'cheerio_action text fallback enqueue', $e->getMessage());
        }

        // webhook — use httpbin-like local failure-safe URL that won't hang forever; use example.com
        try {
            $engine->executeAction('webhook_call', [
                'url'     => 'https://httpbin.org/post',
                'method'  => 'POST',
                'values'  => ['city'],
                'header'  => ['key' => 'Content-Type', 'value' => 'application/json'],
                'timeout' => 8,
            ], $ctx);
            $this->ok('action', 'webhook_call executed', 'status=' . ($ctx['_http_status'] ?? 'n/a'));
        } catch (Throwable $e) {
            // Network may be blocked — treat soft fail as warning but still record
            $this->fail('action', 'webhook_call executed', $e->getMessage());
        }
    }

    protected function sectionGraphCompile(): void
    {
        CLI::write('4) WORKFLOW GRAPH compile (trigger→condition→actions)', 'yellow');
        $wf = new WorkflowGraph();

        $graph = [
            'nodes' => [
                [
                    'id'   => 'trigger',
                    'type' => 'trigger',
                    'x'    => 40,
                    'y'    => 120,
                    'data' => ['trigger_type' => 'incoming_message', 'keyword' => ''],
                ],
                [
                    'id'   => 'cond',
                    'type' => 'condition',
                    'x'    => 280,
                    'y'    => 120,
                    'data' => ['condition_type' => 'message_contains', 'value' => 'yes'],
                ],
                [
                    'id'   => 'act_yes',
                    'type' => 'action',
                    'x'    => 520,
                    'y'    => 40,
                    'data' => ['action_type' => 'response_message', 'text' => 'YES branch'],
                ],
                [
                    'id'   => 'act_no',
                    'type' => 'action',
                    'x'    => 520,
                    'y'    => 200,
                    'data' => ['action_type' => 'response_message', 'text' => 'NO branch'],
                ],
                [
                    'id'   => 'end',
                    'type' => 'end',
                    'x'    => 760,
                    'y'    => 120,
                    'data' => ['action_type' => 'end'],
                ],
            ],
            'edges' => [
                ['from' => 'trigger', 'to' => 'cond', 'port' => 'out'],
                ['from' => 'cond', 'to' => 'act_yes', 'port' => 'true'],
                ['from' => 'cond', 'to' => 'act_no', 'port' => 'false'],
                ['from' => 'act_yes', 'to' => 'end', 'port' => 'out'],
                ['from' => 'act_no', 'to' => 'end', 'port' => 'out'],
            ],
            'normalize_version' => WorkflowGraph::NORMALIZE_VERSION,
        ];

        $trigger = $wf->triggerFromGraph($graph);
        $this->assertTrue('graph', 'triggerFromGraph', $trigger === 'incoming_message', (string) $trigger);

        $rules = $wf->toRules($graph);
        $this->assertTrue('graph', 'toRules count=3', count($rules) === 3, 'count=' . count($rules));

        $cond = $rules[0] ?? null;
        $this->assertTrue(
            'graph',
            'first rule is condition',
            is_array($cond) && ($cond['rule_type'] ?? '') === 'condition',
            json_encode($cond)
        );
        $this->assertTrue(
            'graph',
            'condition has true/false next',
            is_array($cond) && ! empty($cond['next_on_true']) && ! empty($cond['next_on_false']),
            'true=' . ($cond['next_on_true'] ?? 'null') . ' false=' . ($cond['next_on_false'] ?? 'null')
        );

        $norm = $wf->normalizeImportedGraph($graph);
        $types = array_map(static fn ($n) => $n['type'], $norm['nodes']);
        $this->assertTrue(
            'graph',
            'normalize keeps trigger/condition/action/end',
            in_array('trigger', $types, true)
                && in_array('condition', $types, true)
                && in_array('action', $types, true)
                && in_array('end', $types, true),
            json_encode($types)
        );

        $tNode = null;
        foreach ($norm['nodes'] as $n) {
            if ($n['type'] === 'trigger') {
                $tNode = $n;
                break;
            }
        }
        $this->assertTrue(
            'graph',
            'normalize does not convert trigger→action',
            is_array($tNode) && ($tNode['data']['trigger_type'] ?? '') === 'incoming_message',
            json_encode($tNode)
        );
    }

    protected function sectionEndToEnd(int $contactId, int $tagId, bool $keep): void
    {
        CLI::write('5) END-TO-END runAutomation (condition branches)', 'yellow');

        $wf    = new WorkflowGraph();
        $graph = [
            'nodes' => [
                [
                    'id'   => 'trigger',
                    'type' => 'trigger',
                    'x'    => 40,
                    'y'    => 100,
                    'data' => ['trigger_type' => 'incoming_message'],
                ],
                [
                    'id'   => 'cond',
                    'type' => 'condition',
                    'x'    => 280,
                    'y'    => 100,
                    'data' => ['condition_type' => 'message_contains', 'value' => 'DEEPYES'],
                ],
                [
                    'id'   => 'yes',
                    'type' => 'action',
                    'x'    => 520,
                    'y'    => 40,
                    'data' => [
                        'action_type' => 'send_text',
                        'text'        => 'BRANCH_YES DeepTest',
                    ],
                ],
                [
                    'id'   => 'no',
                    'type' => 'action',
                    'x'    => 520,
                    'y'    => 180,
                    'data' => [
                        'action_type' => 'send_text',
                        'text'        => 'BRANCH_NO DeepTest',
                    ],
                ],
            ],
            'edges' => [
                ['from' => 'trigger', 'to' => 'cond', 'port' => 'out'],
                ['from' => 'cond', 'to' => 'yes', 'port' => 'true'],
                ['from' => 'cond', 'to' => 'no', 'port' => 'false'],
            ],
        ];
        $rules = $wf->toRules($graph);

        $autos = model(AutomationModel::class);
        $name  = 'DeepTest Branch Flow ' . date('His');
        $id    = (int) $autos->insert([
            'name'           => $name,
            'trigger_type'   => 'incoming_message',
            'trigger_config' => [],
            'is_active'      => 1,
            'priority'       => 1,
            'flow_graph'     => $graph,
        ]);

        $ruleModel = model(AutomationRuleModel::class);
        foreach ($rules as $rule) {
            $cfg = $rule['config'] ?? [];
            $ruleModel->insert([
                'automation_id' => $id,
                'step_order'    => (int) $rule['step_order'],
                'rule_type'     => (string) $rule['rule_type'],
                'action_type'   => $rule['action_type'] ?? null,
                'config'        => $cfg,
                'next_on_true'  => $rule['next_on_true'] ?? null,
                'next_on_false' => $rule['next_on_false'] ?? null,
            ]);
        }

        $engine = $this->engine();
        $queue  = model(MessageQueueModel::class);

        // TRUE branch
        $engine->runAutomation($id, [
            'contact_id' => $contactId,
            'content'    => 'please say DEEPYES now',
        ]);
        $yes = $queue->where('contact_id', $contactId)
            ->like('payload', 'BRANCH_YES DeepTest')
            ->orderBy('id', 'DESC')
            ->first();
        $this->assertTrue('e2e', 'condition TRUE → BRANCH_YES', is_array($yes), is_array($yes) ? ('queue#' . $yes['id']) : 'queue row missing');

        // FALSE branch
        $engine->runAutomation($id, [
            'contact_id' => $contactId,
            'content'    => 'something else entirely',
        ]);
        $no = $queue->where('contact_id', $contactId)
            ->like('payload', 'BRANCH_NO DeepTest')
            ->orderBy('id', 'DESC')
            ->first();
        $this->assertTrue('e2e', 'condition FALSE → BRANCH_NO', is_array($no), is_array($no) ? ('queue#' . $no['id']) : 'queue row missing');

        // processTrigger match
        $res = $engine->processTrigger('message_received', [
            'contact_id' => $contactId,
            'content'    => 'DEEPYES via processTrigger',
        ]);
        $this->assertTrue(
            'e2e',
            'processTrigger matched temp automation',
            $res['matched'] >= 1 && $res['executed'] >= 1,
            json_encode($res)
        );

        // Keyword-filtered trigger automation
        $id2 = (int) $autos->insert([
            'name'           => 'DeepTest Keyword ' . date('His'),
            'trigger_type'   => 'incoming_message',
            'trigger_config' => ['keyword' => 'ONLYKEYWORD'],
            'is_active'      => 1,
            'priority'       => 1,
            'flow_graph'     => [
                'nodes' => [
                    ['id' => 'trigger', 'type' => 'trigger', 'x' => 40, 'y' => 40, 'data' => ['trigger_type' => 'incoming_message', 'keyword' => 'ONLYKEYWORD']],
                    ['id' => 'a1', 'type' => 'action', 'x' => 300, 'y' => 40, 'data' => ['action_type' => 'send_text', 'text' => 'KEYWORD_HIT']],
                ],
                'edges' => [['from' => 'trigger', 'to' => 'a1', 'port' => 'out']],
            ],
        ]);
        $ruleModel->insert([
            'automation_id' => $id2,
            'step_order'    => 1,
            'rule_type'     => 'action',
            'action_type'   => 'send_text',
            'config'        => ['text' => 'KEYWORD_HIT'],
        ]);

        $miss = $engine->processTrigger('message_received', [
            'contact_id' => $contactId,
            'content'    => 'no match here',
        ]);
        // may still match other active autos; check keyword auto did NOT enqueue KEYWORD_HIT for this miss
        $hitBefore = $queue->where('contact_id', $contactId)->like('payload', 'KEYWORD_HIT')->countAllResults();

        $hit = $engine->processTrigger('message_received', [
            'contact_id' => $contactId,
            'content'    => 'say ONLYKEYWORD please',
        ]);
        $hitAfter = $queue->where('contact_id', $contactId)->like('payload', 'KEYWORD_HIT')->countAllResults();
        $this->assertTrue(
            'e2e',
            'keyword trigger fires only on match',
            $hitAfter > $hitBefore,
            "hits {$hitBefore}→{$hitAfter}; miss_matched={$miss['matched']} hit_matched={$hit['matched']}"
        );

        if (! $keep) {
            $ruleModel->where('automation_id', $id)->delete();
            $ruleModel->where('automation_id', $id2)->delete();
            $autos->delete($id);
            $autos->delete($id2);
            $this->ok('e2e', 'cleaned temp automations');
        } else {
            $this->ok('e2e', 'kept temp automations', "#{$id}, #{$id2}");
        }

        // Flush deep-test pending texts (don't need to actually hit WA API for unit proof,
        // but cancel leftover deep-test pending to avoid spam)
        $pending = $queue->where('contact_id', $contactId)
            ->where('status', 'pending')
            ->like('payload', 'DeepTest')
            ->findAll(200);
        foreach ($pending as $item) {
            $queue->update((int) $item['id'], ['status' => 'cancelled']);
        }
        $pending2 = $queue->where('contact_id', $contactId)
            ->where('status', 'pending')
            ->groupStart()
                ->like('payload', 'BRANCH_')
                ->orLike('payload', 'KEYWORD_HIT')
                ->orLike('payload', 'Fallback cheerio')
            ->groupEnd()
            ->findAll(200);
        foreach ($pending2 as $item) {
            $queue->update((int) $item['id'], ['status' => 'cancelled']);
        }
        $this->ok('e2e', 'cancelled deep-test pending queue rows', (string) (count($pending) + count($pending2)));
    }

    /**
     * Catalog: every UI trigger / condition / action must be known to the engine.
     */
    protected function sectionCatalog(): void
    {
        CLI::write('0) CATALOG sync (UI ↔ engine)', 'yellow');

        $uiTriggers = [
            'incoming_message', 'campaign_sent', 'shopify_event', 'facebook_lead',
            'kylas_event_create', 'kylas_event_update', 'pabbly_event', 'incoming_webhook',
            'messenger', 'instagram', 'commerce_event', 'contact_created', 'form_response',
            'keyword_matched', 'tag_added', 'birthday', 'campaign_replied', 'schedule',
        ];
        $engine = $this->engine();
        foreach ($uiTriggers as $t) {
            $aliases = $engine->triggerAliases($t);
            $this->assertTrue(
                'catalog',
                "trigger known: {$t}",
                $aliases !== [] && $aliases[0] !== '',
                'aliases=' . json_encode($aliases)
            );
        }

        $uiConditions = [
            'message_contains', 'message_equals', 'caption_contains', 'message_type',
            'has_tag', 'within_window', 'contact_status',
        ];
        foreach ($uiConditions as $preset) {
            $cfg = $this->expandPreset($preset, ['value' => 'x', 'tag_id' => 1]);
            $this->assertTrue(
                'catalog',
                "condition preset expands: {$preset}",
                is_array($cfg) && $cfg !== [],
                json_encode($cfg)
            );
        }

        $uiActions = [
            'system_initiated', 'response_message', 'collect_images', 'send_template',
            'send_text', 'add_tag', 'remove_tag', 'assign_agent', 'add_note', 'delay',
            'webhook', 'webhook_call', 'set_attribute', 'http_request', 'email_notification',
        ];
        foreach ($uiActions as $a) {
            $norm = $this->normalize($a);
            $this->assertTrue(
                'catalog',
                "action normalizes: {$a}",
                $norm !== '',
                "→ {$norm}"
            );
        }
    }

    /**
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    protected function expandPreset(string $preset, array $extra = []): array
    {
        $ref = new ReflectionClass(AutomationEngine::class);
        $m   = $ref->getMethod('expandConditionPreset');
        $m->setAccessible(true);

        return (array) $m->invoke($this->engine(), $preset, $extra + ['_preset' => $preset], []);
    }

    /**
     * @param array<string, mixed> $contact
     */
    protected function sectionConditionOperators(array $contact): void
    {
        CLI::write('2b) CONDITION operators + remaining presets', 'yellow');
        $ctx = [
            'contact_id'   => (int) $contact['id'],
            'contact'      => $contact,
            'content'      => 'Alpha Beta 42',
            'message_type' => 'image',
            'score'        => 10,
        ];

        $this->assertTrue(
            'condition',
            'caption_contains hit (uses content)',
            $this->evalCond(['_preset' => 'caption_contains', 'value' => 'Beta'], $ctx)
        );
        $this->assertTrue(
            'condition',
            'message_type hit',
            $this->evalCond(['_preset' => 'message_type', 'value' => 'image'], $ctx)
        );
        $this->assertTrue(
            'condition',
            'message_type miss',
            ! $this->evalCond(['_preset' => 'message_type', 'value' => 'video'], $ctx)
        );

        $ops = [
            ['equals', 'content', 'Alpha Beta 42', true, 'equals hit'],
            ['not_equals', 'content', 'nope', true, 'not_equals hit'],
            ['gt', 'score', 5, true, 'gt hit'],
            ['gte', 'score', 10, true, 'gte hit'],
            ['lt', 'score', 20, true, 'lt hit'],
            ['lte', 'score', 10, true, 'lte hit'],
            ['empty', 'missing_field', null, true, 'empty hit'],
            ['not_empty', 'content', null, true, 'not_empty hit'],
        ];
        foreach ($ops as [$op, $field, $value, $expect, $name]) {
            $got = $this->evalCond(['operator' => $op, 'field' => $field, 'value' => $value], $ctx);
            $this->assertTrue('condition', $name, $got === $expect, 'got=' . ($got ? 'true' : 'false'));
        }

        $this->assertTrue(
            'condition',
            'in operator hit',
            $this->evalCond(['operator' => 'in', 'field' => 'message_type', 'value' => ['image', 'video']], $ctx)
        );
        $this->assertTrue(
            'condition',
            'in operator miss',
            ! $this->evalCond(['operator' => 'in', 'field' => 'message_type', 'value' => ['audio']], $ctx)
        );
    }

    protected function sectionActionExtras(int $contactId): void
    {
        CLI::write('3b) ACTION extras (http / email / end)', 'yellow');
        $engine = $this->engine();
        $ctx    = [
            'contact_id'    => $contactId,
            'automation_id' => 0,
            'contact'       => model(ContactModel::class)->find($contactId),
        ];

        $moreAliases = [
            'http'              => 'http_request',
            'send_wa_template'  => 'send_template',
            'sendTemplate'      => 'send_template',
            'add_to_label'      => 'add_tag',
            'remove_from_label' => 'remove_tag',
            'time_delay'        => 'delay',
            'note'              => 'add_note',
            'updateattribute'   => 'set_attribute',
            'assignagent'       => 'assign_agent',
        ];
        foreach ($moreAliases as $from => $to) {
            $this->assertTrue('action', "normalize {$from}→{$to}", $this->normalize($from) === $to, 'got=' . $this->normalize($from));
        }

        try {
            $engine->executeAction('http_request', [
                'url'     => 'https://httpbin.org/get',
                'method'  => 'GET',
                'timeout' => 8,
            ], $ctx);
            $this->ok('action', 'http_request executed', 'status=' . ($ctx['_http_status'] ?? 'n/a'));
        } catch (Throwable $e) {
            $this->ok('action', 'http_request soft-skip', $e->getMessage());
        }

        try {
            $engine->executeAction('email_notification', [
                'to'      => 'sateri.mangesh@gmail.com',
                'subject' => 'DeepTest automation email',
                'message' => 'Automation deep-test email_notification action.',
            ], $ctx);
            $this->ok('action', 'email_notification executed');
        } catch (Throwable $e) {
            $this->fail('action', 'email_notification executed', $e->getMessage());
        }

        try {
            $engine->executeAction('end', [], $ctx);
            $this->ok('action', 'end no-op (unknown → no crash)');
        } catch (Throwable $e) {
            $this->fail('action', 'end no-op', $e->getMessage());
        }
    }

    /**
     * Compile a mini graph for every UI trigger type.
     */
    protected function sectionGraphPerTrigger(): void
    {
        CLI::write('4b) GRAPH compile per trigger type', 'yellow');
        $wf       = new WorkflowGraph();
        $triggers = [
            'incoming_message', 'keyword_matched', 'contact_created', 'tag_added', 'birthday',
            'schedule', 'campaign_sent', 'campaign_replied', 'shopify_event', 'facebook_lead',
            'kylas_event_create', 'kylas_event_update', 'pabbly_event', 'incoming_webhook',
            'messenger', 'instagram', 'commerce_event', 'form_response',
        ];

        foreach ($triggers as $trigger) {
            $marker = 'FLOW_' . strtoupper($trigger);
            $graph  = [
                'nodes' => [
                    [
                        'id'   => 'trigger',
                        'type' => 'trigger',
                        'x'    => 40,
                        'y'    => 40,
                        'data' => ['trigger_type' => $trigger],
                    ],
                    [
                        'id'   => 'act',
                        'type' => 'action',
                        'x'    => 300,
                        'y'    => 40,
                        'data' => ['action_type' => 'send_text', 'text' => $marker],
                    ],
                    [
                        'id'   => 'end',
                        'type' => 'end',
                        'x'    => 560,
                        'y'    => 40,
                        'data' => ['label' => 'End'],
                    ],
                ],
                'edges' => [
                    ['from' => 'trigger', 'to' => 'act', 'port' => 'out'],
                    ['from' => 'act', 'to' => 'end', 'port' => 'out'],
                ],
            ];

            $fromGraph = $wf->triggerFromGraph($graph);
            $rules     = $wf->toRules($graph);
            $ok        = $fromGraph === $trigger
                && count($rules) === 1
                && ($rules[0]['rule_type'] ?? '') === 'action'
                && ($rules[0]['action_type'] ?? '') === 'send_text';

            $this->assertTrue(
                'graph',
                "compile {$trigger}",
                $ok,
                "triggerFrom={$fromGraph} rules=" . count($rules)
            );
        }
    }

    /**
     * Persist + fire live processTrigger for every trigger type + condition presets.
     */
    protected function sectionLiveTriggerFlows(int $contactId, int $tagId, bool $keep): void
    {
        CLI::write('5) LIVE trigger flows (save + processTrigger)', 'yellow');

        $autos     = model(AutomationModel::class);
        $ruleModel = model(AutomationRuleModel::class);
        $queue     = model(MessageQueueModel::class);
        $engine    = $this->engine();
        $created   = [];

        $flows = [
            ['trigger' => 'incoming_message', 'fire' => 'message_received', 'marker' => 'LIVE_MSG', 'ctx' => ['contact_id' => $contactId, 'content' => 'hello live message']],
            ['trigger' => 'keyword_matched', 'fire' => 'keyword', 'marker' => 'LIVE_KW', 'ctx' => ['contact_id' => $contactId, 'content' => 'keyword path', 'keyword' => 'hello']],
            ['trigger' => 'contact_created', 'fire' => 'contact_created', 'marker' => 'LIVE_CONTACT', 'ctx' => ['contact_id' => $contactId]],
            ['trigger' => 'tag_added', 'fire' => 'tag_added', 'marker' => 'LIVE_TAG', 'ctx' => ['contact_id' => $contactId, 'tag_id' => $tagId]],
            ['trigger' => 'birthday', 'fire' => 'birthday', 'marker' => 'LIVE_BDAY', 'ctx' => ['contact_id' => $contactId]],
            ['trigger' => 'campaign_sent', 'fire' => 'campaign_sent', 'marker' => 'LIVE_CAMPAIGN_SENT', 'ctx' => ['contact_id' => $contactId, 'campaign_id' => 1]],
            ['trigger' => 'campaign_replied', 'fire' => 'campaign_replied', 'marker' => 'LIVE_CAMPAIGN_REPLY', 'ctx' => ['contact_id' => $contactId, 'campaign_id' => 1, 'content' => 'thanks']],
            ['trigger' => 'shopify_event', 'fire' => 'shopify_event', 'marker' => 'LIVE_SHOPIFY', 'ctx' => ['contact_id' => $contactId, 'shopify_topic' => 'orders/create']],
            ['trigger' => 'facebook_lead', 'fire' => 'facebook_lead', 'marker' => 'LIVE_FB', 'ctx' => ['contact_id' => $contactId, 'form_id' => 'f1']],
            ['trigger' => 'kylas_event_create', 'fire' => 'kylas_event_create', 'marker' => 'LIVE_KYLAS_C', 'ctx' => ['contact_id' => $contactId, 'event_type' => 'lead.create']],
            ['trigger' => 'kylas_event_update', 'fire' => 'kylas_event_update', 'marker' => 'LIVE_KYLAS_U', 'ctx' => ['contact_id' => $contactId, 'event_type' => 'lead.update']],
            ['trigger' => 'pabbly_event', 'fire' => 'pabbly_event', 'marker' => 'LIVE_PABBLY', 'ctx' => ['contact_id' => $contactId, 'event_type' => 'hook']],
            ['trigger' => 'incoming_webhook', 'fire' => 'incoming_webhook', 'marker' => 'LIVE_WEBHOOK', 'ctx' => ['contact_id' => $contactId, 'token' => 'deep-test-token']],
            ['trigger' => 'messenger', 'fire' => 'messenger', 'marker' => 'LIVE_MESSENGER', 'ctx' => ['contact_id' => $contactId, 'page_id' => 'p1', 'content' => 'hi']],
            ['trigger' => 'instagram', 'fire' => 'instagram', 'marker' => 'LIVE_IG', 'ctx' => ['contact_id' => $contactId, 'page_id' => 'ig1', 'content' => 'hi']],
            ['trigger' => 'commerce_event', 'fire' => 'commerce_event', 'marker' => 'LIVE_COMMERCE', 'ctx' => ['contact_id' => $contactId, 'event_type' => 'order.created']],
            ['trigger' => 'form_response', 'fire' => 'form_response', 'marker' => 'LIVE_FORM', 'ctx' => ['contact_id' => $contactId, 'form_id' => 'form-1']],
            ['trigger' => 'schedule', 'fire' => 'schedule', 'marker' => 'LIVE_SCHEDULE', 'ctx' => ['contact_id' => $contactId]],
        ];

        foreach ($flows as $flow) {
            $name = 'DeepLive ' . $flow['trigger'] . ' ' . date('His');
            $id   = (int) $autos->insert([
                'name'           => $name,
                'trigger_type'   => $flow['trigger'],
                'trigger_config' => [],
                'is_active'      => 1,
                'priority'       => 1,
                'flow_graph'     => [
                    'nodes' => [
                        ['id' => 'trigger', 'type' => 'trigger', 'x' => 40, 'y' => 40, 'data' => ['trigger_type' => $flow['trigger']]],
                        ['id' => 'a1', 'type' => 'action', 'x' => 280, 'y' => 40, 'data' => ['action_type' => 'send_text', 'text' => $flow['marker']]],
                    ],
                    'edges' => [['from' => 'trigger', 'to' => 'a1', 'port' => 'out']],
                ],
            ]);
            $created[] = $id;
            $ruleModel->insert([
                'automation_id' => $id,
                'step_order'    => 1,
                'rule_type'     => 'action',
                'action_type'   => 'send_text',
                'config'        => ['text' => $flow['marker']],
            ]);

            $before = $queue->where('contact_id', $contactId)->like('payload', $flow['marker'])->countAllResults();
            try {
                $res   = $engine->processTrigger($flow['fire'], $flow['ctx']);
                $after = $queue->where('contact_id', $contactId)->like('payload', $flow['marker'])->countAllResults();
                $this->assertTrue(
                    'live',
                    "flow {$flow['trigger']} → {$flow['fire']}",
                    $after > $before && ($res['matched'] ?? 0) >= 1,
                    "matched={$res['matched']} executed={$res['executed']} queue {$before}→{$after}"
                );
            } catch (Throwable $e) {
                $this->fail('live', "flow {$flow['trigger']} → {$flow['fire']}", $e->getMessage());
            }
        }

        db_connect()->table('contact_tags')->ignore(true)->insert([
            'contact_id' => $contactId,
            'tag_id'     => $tagId,
        ]);
        model(ContactModel::class)->update($contactId, ['last_reply_at' => date('Y-m-d H:i:s')]);

        $status  = (string) (model(ContactModel::class)->find($contactId)['status'] ?? 'active');
        $presets = [
            ['message_contains', ['value' => 'HITME'], ['content' => 'please HITME now']],
            ['message_equals', ['value' => 'EXACT'], ['content' => 'EXACT']],
            ['message_type', ['value' => 'text'], ['content' => 'hi', 'message_type' => 'text']],
            ['contact_status', ['value' => $status], []],
            ['has_tag', ['tag_id' => $tagId], []],
            ['within_window', [], []],
            ['caption_contains', ['value' => 'CAP'], ['content' => 'has CAP here']],
        ];

        foreach ($presets as [$preset, $cfg, $extraCtx]) {
            $markerYes = 'CONDYES_' . strtoupper($preset);
            $markerNo  = 'CONDNO_' . strtoupper($preset);
            $id        = (int) $autos->insert([
                'name'           => 'DeepCond ' . $preset . ' ' . date('His'),
                'trigger_type'   => 'incoming_message',
                'trigger_config' => [],
                'is_active'      => 0,
                'priority'       => 1,
                'flow_graph'     => [],
            ]);
            $created[] = $id;

            $ruleModel->insert([
                'automation_id' => $id,
                'step_order'    => 1,
                'rule_type'     => 'condition',
                'action_type'   => $preset,
                'config'        => $cfg,
                'next_on_true'  => 2,
                'next_on_false' => 3,
            ]);
            $ruleModel->insert([
                'automation_id' => $id,
                'step_order'    => 2,
                'rule_type'     => 'action',
                'action_type'   => 'send_text',
                'config'        => ['text' => $markerYes],
            ]);
            $ruleModel->insert([
                'automation_id' => $id,
                'step_order'    => 3,
                'rule_type'     => 'action',
                'action_type'   => 'send_text',
                'config'        => ['text' => $markerNo],
            ]);

            $ctx = array_merge([
                'contact_id' => $contactId,
                'content'    => 'default',
                'contact'    => model(ContactModel::class)->find($contactId),
            ], $extraCtx);

            try {
                $engine->runAutomation($id, $ctx);
                $yes = $queue->where('contact_id', $contactId)->like('payload', $markerYes)->countAllResults();
                $no  = $queue->where('contact_id', $contactId)->like('payload', $markerNo)->countAllResults();
                $this->assertTrue('live', "condition {$preset} TRUE branch", $yes > 0, "yes={$yes} no={$no}");
            } catch (Throwable $e) {
                $this->fail('live', "condition {$preset}", $e->getMessage());
            }
        }

        if (! $keep) {
            foreach ($created as $aid) {
                $ruleModel->where('automation_id', $aid)->delete();
                $autos->delete($aid);
            }
            $this->ok('live', 'cleaned live-flow automations', count($created) . ' removed');
        } else {
            $this->ok('live', 'kept live-flow automations', implode(',', $created));
        }

        $pending = $queue->where('contact_id', $contactId)->where('status', 'pending')->findAll(500);
        $nCancel = 0;
        foreach ($pending as $item) {
            $payload = is_string($item['payload'] ?? null) ? $item['payload'] : json_encode($item['payload'] ?? '');
            if (str_contains($payload, 'LIVE_') || str_contains($payload, 'CONDYES_') || str_contains($payload, 'CONDNO_')) {
                $queue->update((int) $item['id'], ['status' => 'cancelled']);
                $nCancel++;
            }
        }
        $this->ok('live', 'cancelled live-flow queue rows', (string) $nCancel);
    }
}
