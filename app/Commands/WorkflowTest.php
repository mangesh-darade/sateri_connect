<?php

declare(strict_types=1);

namespace App\Commands;

use App\Libraries\AutomationEngine;
use App\Libraries\InboxStatus;
use App\Libraries\SequenceService;
use App\Libraries\WorkflowGraph;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * php spark workflow:test
 */
class WorkflowTest extends BaseCommand
{
    protected $group       = 'WhatsApp';
    protected $name        = 'workflow:test';
    protected $description = 'Test Phase 3 workflow nodes + sequences with dummy data';

    public function run(array $params)
    {
        $pass = 0;
        $fail = 0;
        $check = static function (string $label, bool $ok, string $detail = '') use (&$pass, &$fail): void {
            if ($ok) {
                $pass++;
                CLI::write("[PASS] {$label}", 'green');

                return;
            }
            $fail++;
            CLI::write('[FAIL] ' . $label . ($detail !== '' ? " — {$detail}" : ''), 'red');
        };

        CLI::write('=== Workflow + Sequences Feature Test ===', 'yellow');

        $root = ROOTPATH;
        $js = file_get_contents($root . 'public/assets/js/automations-flow.js') ?: '';
        $builder = file_get_contents($root . 'app/Views/automations/builder.php') ?: '';
        $engineSrc = file_get_contents($root . 'app/Libraries/AutomationEngine.php') ?: '';

        $check('palette send_email', str_contains($builder, 'data-action="send_email"'));
        $check('palette assign_bot', str_contains($builder, 'data-action="assign_bot"'));
        $check('palette update_chat_status', str_contains($builder, 'data-action="update_chat_status"'));
        $check('palette attribute_condition', str_contains($builder, 'data-condition="attribute_condition"'));
        $check('JS ACTION send_email', str_contains($js, 'send_email:'));
        $check('engine delay stop', str_contains($engineSrc, '_stop_automation'));
        $check('engine delayed jobs', str_contains($engineSrc, 'processDelayedJobs'));
        $check('campaign_sent fire', str_contains(file_get_contents($root . 'app/Libraries/CampaignService.php') ?: '', 'fireCampaignSentTriggers'));

        $db = db_connect();
        $check('automation_delayed_jobs table', $db->tableExists('automation_delayed_jobs'));
        $check('message_sequences table', $db->tableExists('message_sequences'));

        // Ensure demo contact
        $mobile = '919999002001';
        $contact = $db->table('contacts')->where('mobile', $mobile)->get()->getRowArray();
        $now = date('Y-m-d H:i:s');
        if (! $contact) {
            $db->table('contacts')->insert([
                'name' => 'Workflow Demo',
                'mobile' => $mobile,
                'email' => 'workflow-demo@example.com',
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $contactId = (int) $db->insertID();
        } else {
            $contactId = (int) $contact['id'];
        }

        $engine = new AutomationEngine();

        // attribute condition
        $okAttr = $engine->evaluateCondition([
            '_preset'   => 'attribute_condition',
            'attribute' => 'name',
            'operator'  => 'contains',
            'value'     => 'Workflow',
        ], ['contact_id' => $contactId, 'contact' => ['name' => 'Workflow Demo']]);
        $check('attribute_condition true', $okAttr);

        // update_chat_status + assign_bot
        $ctx = ['contact_id' => $contactId, 'channel' => 'whatsapp'];
        $engine->executeAction('update_chat_status', ['status' => 'pending'], $ctx);
        $conv = model(\App\Models\ConversationModel::class)->findByContact($contactId, 'whatsapp');
        $check('update_chat_status pending', is_array($conv) && InboxStatus::normalize((string) ($conv['status'] ?? '')) === 'pending');

        $engine->executeAction('assign_bot', [], $ctx);
        $conv2 = model(\App\Models\ConversationModel::class)->findByContact($contactId, 'whatsapp');
        $check('assign_bot chatbot', is_array($conv2) && InboxStatus::normalize((string) ($conv2['status'] ?? '')) === 'chatbot');

        // Delay schedule
        $db->table('automations')->insert([
            'name'         => 'TEST Delay Resume ' . time(),
            'trigger_type' => 'incoming_message',
            'is_active'    => 0,
            'priority'     => 99,
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);
        $autoId = (int) $db->insertID();
        $check('test automation inserted', $autoId > 0);
        // Minimal: insert two action rules via WorkflowGraph style
        $db->table('automation_rules')->insert([
            'automation_id' => $autoId,
            'step_order'    => 1,
            'rule_type'     => 'action',
            'action_type'   => 'delay',
            'config'        => json_encode(['seconds' => 1]),
            // Mimic WorkflowGraph: next_on_true is step_order, NOT rule id
            'next_on_true'  => 2,
            'created_at'    => $now,
            'updated_at'    => $now,
        ]);
        $delayRuleId = (int) $db->insertID();
        $db->table('automation_rules')->insert([
            'automation_id' => $autoId,
            'step_order'    => 2,
            'rule_type'     => 'action',
            'action_type'   => 'update_chat_status',
            'config'        => json_encode(['status' => 'open']),
            'created_at'    => $now,
            'updated_at'    => $now,
        ]);
        $resumeId = (int) $db->insertID();

        $engine->runAutomation($autoId, ['contact_id' => $contactId, 'channel' => 'whatsapp']);
        $pendingJob = $db->table('automation_delayed_jobs')
            ->where('automation_id', $autoId)
            ->where('status', 'pending')
            ->get()
            ->getRowArray();
        $check('delay creates pending job', is_array($pendingJob));
        $check(
            'delay job stores DB rule id (not step_order)',
            is_array($pendingJob) && (int) ($pendingJob['resume_rule_id'] ?? 0) === $resumeId,
            'got ' . (string) ($pendingJob['resume_rule_id'] ?? 'null') . ' expected ' . $resumeId
        );

        // Force due
        $db->table('automation_delayed_jobs')
            ->where('automation_id', $autoId)
            ->update(['run_at' => date('Y-m-d H:i:s', time() - 5)]);
        $processed = $engine->processDelayedJobs();
        $check('processDelayedJobs ran', $processed >= 1);
        $conv3 = model(\App\Models\ConversationModel::class)->findByContact($contactId, 'whatsapp');
        $check('delay resume updated status open', is_array($conv3) && InboxStatus::normalize((string) ($conv3['status'] ?? '')) === 'open');

        // Sequences
        $svc = new SequenceService();
        $seqId = $svc->create('Demo Welcome Sequence', [
            ['delay_minutes' => 0, 'message_type' => 'text', 'body_text' => 'Step1 hi {{contact.name}}'],
            ['delay_minutes' => 0, 'message_type' => 'text', 'body_text' => 'Step2 follow-up'],
        ], true, 'whatsapp', null);
        $check('sequence created', $seqId > 0);
        $enrollId = $svc->enroll($seqId, $contactId);
        $check('sequence enrolled', $enrollId > 0);
        $sent = $svc->processDue(10);
        $check('sequence step sent', $sent >= 1);

        $exited = $svc->onContactReply($contactId);
        $check('exit on reply', $exited >= 1);

        // Terminal delay must NOT restart from step 1
        $db->table('automations')->insert([
            'name'         => 'TEST Terminal Delay ' . time(),
            'trigger_type' => 'incoming_message',
            'is_active'    => 0,
            'priority'     => 99,
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);
        $termAutoId = (int) $db->insertID();
        $db->table('automation_rules')->insert([
            'automation_id' => $termAutoId,
            'step_order'    => 1,
            'rule_type'     => 'action',
            'action_type'   => 'update_chat_status',
            'config'        => json_encode(['status' => 'open']),
            'next_on_true'  => 2,
            'created_at'    => $now,
            'updated_at'    => $now,
        ]);
        $db->table('automation_rules')->insert([
            'automation_id' => $termAutoId,
            'step_order'    => 2,
            'rule_type'     => 'action',
            'action_type'   => 'delay',
            'config'        => json_encode(['seconds' => 1]),
            'next_on_true'  => null,
            'created_at'    => $now,
            'updated_at'    => $now,
        ]);
        $engine->runAutomation($termAutoId, ['contact_id' => $contactId, 'channel' => 'whatsapp']);
        // After delay paused, mutate status. Restart bug would set it back to open.
        $ctxTerm = ['contact_id' => $contactId, 'channel' => 'whatsapp'];
        $engine->executeAction('update_chat_status', ['status' => 'intervened'], $ctxTerm);
        $db->table('automation_delayed_jobs')
            ->where('automation_id', $termAutoId)
            ->update(['run_at' => date('Y-m-d H:i:s', time() - 5)]);
        $termJob = $db->table('automation_delayed_jobs')
            ->where('automation_id', $termAutoId)
            ->get()
            ->getRowArray();
        $check('terminal delay job has null resume', is_array($termJob) && ($termJob['resume_rule_id'] === null || $termJob['resume_rule_id'] === ''));
        $engine->processDelayedJobs();
        $convTerm = model(\App\Models\ConversationModel::class)->findByContact($contactId, 'whatsapp');
        $check(
            'terminal delay does not restart graph',
            is_array($convTerm) && InboxStatus::normalize((string) ($convTerm['status'] ?? '')) === 'intervened',
            'status=' . (string) ($convTerm['status'] ?? '')
        );

        // Cleanup test automation
        $db->table('automation_delayed_jobs')->whereIn('automation_id', [$autoId, $termAutoId])->delete();
        $db->table('automation_rules')->whereIn('automation_id', [$autoId, $termAutoId])->delete();
        $db->table('automations')->whereIn('id', [$autoId, $termAutoId])->delete();

        CLI::newLine();
        CLI::write("Result: {$pass} passed, {$fail} failed", $fail > 0 ? 'red' : 'green');

        return $fail > 0 ? EXIT_ERROR : EXIT_SUCCESS;
    }
}
