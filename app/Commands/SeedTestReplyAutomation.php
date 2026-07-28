<?php

declare(strict_types=1);

namespace App\Commands;

use App\Libraries\WorkflowGraph;
use App\Models\AutomationModel;
use App\Models\AutomationRuleModel;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Seed a local builder workflow that auto-replies to WhatsApp inbound messages.
 *
 * php spark automations:seed-test-reply
 */
class SeedTestReplyAutomation extends BaseCommand
{
    protected $group       = 'WhatsApp';
    protected $name        = 'automations:seed-test-reply';
    protected $description = 'Create/activate a test workflow: inbound WhatsApp → send text reply.';
    protected $usage       = 'automations:seed-test-reply';

    public function run(array $params)
    {
        $name = 'Test WA Auto Reply';

        $graph = [
            'nodes' => [
                [
                    'id'   => 'trigger',
                    'type' => 'trigger',
                    'x'    => 60,
                    'y'    => 180,
                    'data' => [
                        'trigger_type' => 'incoming_message',
                        'label'        => 'WhatsApp reply received',
                    ],
                ],
                [
                    'id'   => 'action_reply',
                    'type' => 'action',
                    'x'    => 380,
                    'y'    => 180,
                    'data' => [
                        'action_type' => 'send_text',
                        'text'        => "Hi {{contact.name}}! Auto-reply from whstapp test workflow.\n\nWe received your message: \"{{content}}\"\n\nReply HELP for assistance.",
                        'label'       => 'Send text reply',
                    ],
                ],
                [
                    'id'   => 'end_1',
                    'type' => 'end',
                    'x'    => 700,
                    'y'    => 180,
                    'data' => [
                        'label' => 'End',
                    ],
                ],
            ],
            'edges' => [
                ['from' => 'trigger', 'to' => 'action_reply', 'port' => 'out'],
                ['from' => 'action_reply', 'to' => 'end_1', 'port' => 'out'],
            ],
            'normalize_version' => 5,
            'source'            => 'local_test',
        ];

        $wf    = new WorkflowGraph();
        $rules = $wf->toRules($graph);

        if ($rules === []) {
            CLI::error('WorkflowGraph produced 0 rules — aborting.');

            return;
        }

        $model = model(AutomationModel::class);
        $existing = $model->where('name', $name)->first();

        $payload = [
            'name'           => $name,
            'trigger_type'   => 'incoming_message',
            'trigger_config' => null,
            'flow_graph'     => $graph,
            'is_active'      => 1,
            'priority'       => 5,
            'updated_at'     => date('Y-m-d H:i:s'),
        ];

        if ($existing !== null) {
            $id = (int) $existing['id'];
            $model->update($id, $payload);
            CLI::write("Updated automation #{$id} ({$name})", 'yellow');
        } else {
            $payload['created_by'] = 1;
            $payload['created_at'] = date('Y-m-d H:i:s');
            $id = (int) $model->insert($payload);
            CLI::write("Created automation #{$id} ({$name})", 'green');
        }

        $ruleModel = model(AutomationRuleModel::class);
        $ruleModel->where('automation_id', $id)->delete();

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
                'automation_id' => $id,
                'step_order'    => (int) ($rule['step_order'] ?? $order),
                'rule_type'     => (string) ($rule['rule_type'] ?? 'action'),
                'action_type'   => $rule['action_type'] ?? null,
                'config'        => $config,
                'next_on_true'  => $rule['next_on_true'] ?? null,
                'next_on_false' => $rule['next_on_false'] ?? null,
            ]);
            $order++;
        }

        CLI::write('Compiled rules: ' . $order, 'green');
        CLI::write('Active: yes | Trigger: incoming_message (any WhatsApp inbound)', 'white');
        CLI::write('Open list: ' . site_url('automations'), 'cyan');
        CLI::write('Open builder: ' . site_url('automations/' . $id . '/builder'), 'cyan');
        CLI::write('Test: send any WhatsApp text — replies flush automatically on webhook (backup cron: queue:process).', 'white');

        $doTest = in_array('test', $params, true) || CLI::getOption('test');
        if ($doTest) {
            $contactId = (int) (CLI::getOption('contact') ?: ($params[0] ?? 1));
            if ($contactId <= 0) {
                $contactId = 1;
            }
            CLI::newLine();
            CLI::write("Dry-run trigger for contact_id={$contactId} …", 'yellow');
            $result = service('automationEngine')->processTrigger('message_received', [
                'contact_id'   => $contactId,
                'message_id'   => 0,
                'message_type' => 'text',
                'content'      => 'lab test hello from spark',
                'from'         => 'test',
            ]);
            CLI::write('matched=' . $result['matched'] . ' executed=' . $result['executed'], 'green');
            if ($result['errors'] !== []) {
                foreach ($result['errors'] as $err) {
                    CLI::error($err);
                }
            }
            CLI::write('Processing queue…', 'yellow');
            passthru('"' . PHP_BINARY . '" "' . ROOTPATH . 'spark" queue:process 5');
        }
    }
}
