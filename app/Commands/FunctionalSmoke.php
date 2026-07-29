<?php

declare(strict_types=1);

namespace App\Commands;

use App\Libraries\InboxStatus;
use App\Libraries\SequenceService;
use App\Libraries\WorkflowGraph;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * php spark functional:smoke
 *
 * Deep smoke across routes, schema, permissions wiring, and core libraries.
 */
class FunctionalSmoke extends BaseCommand
{
    protected $group       = 'WhatsApp';
    protected $name        = 'functional:smoke';
    protected $description = 'Deep functional smoke across screens, schema, and core actions';

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

        CLI::write('=== Functional Smoke ===', 'yellow');
        $root = ROOTPATH;
        $db   = db_connect();

        // --- Schema ---
        foreach ([
            'conversations', 'messages', 'contacts', 'automations', 'automation_rules',
            'automation_delayed_jobs', 'message_sequences', 'sequence_steps', 'sequence_enrollments',
            'permissions', 'role_permissions', 'campaigns', 'templates',
        ] as $table) {
            $check("table {$table}", $db->tableExists($table));
        }
        foreach (['frt_due_at', 'intervened_at', 'ctwa_referral'] as $col) {
            $check("conversations.{$col}", $db->fieldExists($col, 'conversations'));
        }

        // --- Routes present in Config ---
        $routesSrc = (string) file_get_contents($root . 'app/Config/Routes.php');
        foreach ([
            'sequences',
            'automations/(:num)/builder',
            'chat/status',
            'guide/(:segment)',
            'analytics',
        ] as $needle) {
            $check("route {$needle}", str_contains($routesSrc, $needle));
        }

        // --- Controllers / views exist ---
        foreach ([
            'app/Controllers/Sequences.php',
            'app/Controllers/Automations.php',
            'app/Controllers/Chat.php',
            'app/Controllers/Guide.php',
            'app/Controllers/Analytics.php',
            'app/Controllers/Campaigns.php',
            'app/Controllers/Contacts.php',
            'app/Controllers/Keywords.php',
            'app/Controllers/Reports.php',
            'app/Controllers/Settings.php',
            'app/Controllers/Roles.php',
            'app/Controllers/Users.php',
            'app/Views/sequences/index.php',
            'app/Views/sequences/form.php',
            'app/Views/automations/builder.php',
            'app/Views/chat/index.php',
            'app/Libraries/SequenceService.php',
            'app/Libraries/InboxStatus.php',
            'app/Libraries/AutomationEngine.php',
        ] as $rel) {
            $check("file {$rel}", is_file($root . $rel));
        }

        // --- PHP syntax ---
        $php = PHP_BINARY;
        foreach ([
            'app/Libraries/AutomationEngine.php',
            'app/Libraries/SequenceService.php',
            'app/Controllers/Sequences.php',
            'app/Controllers/Chat.php',
            'app/Controllers/Automations.php',
            'app/Commands/WorkflowTest.php',
        ] as $rel) {
            $out = [];
            $code = 0;
            exec(escapeshellarg($php) . ' -l ' . escapeshellarg($root . $rel) . ' 2>&1', $out, $code);
            $check("syntax {$rel}", $code === 0, implode(' ', $out));
        }

        // --- Permission wiring ---
        $permSlugs = array_column($db->table('permissions')->select('slug')->get()->getResultArray(), 'slug');
        foreach (['sequences.view', 'sequences.edit', 'guide.view', 'chat.close', 'automations.edit'] as $slug) {
            $check("perm in DB {$slug}", in_array($slug, $permSlugs, true));
        }
        $seqCtrl = (string) file_get_contents($root . 'app/Controllers/Sequences.php');
        $guideCtrl = (string) file_get_contents($root . 'app/Controllers/Guide.php');
        $seqIndex = (string) file_get_contents($root . 'app/Views/sequences/index.php');
        $check('Sequences gated sequences.view', str_contains($seqCtrl, "requirePermission('sequences.view')"));
        $check('Guide gated guide.view', str_contains($guideCtrl, "requirePermission('guide.view')"));
        $check('Edit button gated sequences.edit', str_contains($seqIndex, "can('sequences.edit')"));

        // --- Builder fullscreen + toast z-index ---
        $flowCss = (string) file_get_contents($root . 'public/assets/css/automations-flow.css');
        $check('flow shell fixed fullscreen', str_contains($flowCss, 'position: fixed') && str_contains($flowCss, 'z-index: 2000'));
        $check('swal above flow shell', str_contains($flowCss, 'swal2-container') && str_contains($flowCss, 'z-index: 3000'));

        // --- Chat status UI ---
        $chatView = (string) file_get_contents($root . 'app/Views/chat/index.php');
        $chatJs = (string) file_get_contents($root . 'public/assets/js/chat.js');
        $check('chat status select UI', str_contains($chatView, 'chatStatusSelect'));
        $check('chat status select handler', str_contains($chatJs, "Chat.setStatus(status)"));
        $check('InboxStatus writable pending', InboxStatus::isWritable('pending'));

        // --- WorkflowGraph step_order refs ---
        $wf = new WorkflowGraph();
        $graph = [
            'nodes' => [
                ['id' => 't1', 'type' => 'trigger', 'data' => ['trigger_type' => 'incoming_message']],
                ['id' => 'd1', 'type' => 'action', 'data' => ['action_type' => 'delay', 'seconds' => 5]],
                ['id' => 'a1', 'type' => 'action', 'data' => ['action_type' => 'add_note', 'note' => 'hi']],
            ],
            'edges' => [
                ['from' => 't1', 'to' => 'd1', 'port' => 'out'],
                ['from' => 'd1', 'to' => 'a1', 'port' => 'out'],
            ],
        ];
        $compiled = $wf->toRules($graph);
        $delayRule = null;
        foreach ($compiled as $r) {
            if (($r['action_type'] ?? '') === 'delay') {
                $delayRule = $r;
                break;
            }
        }
        $check('WorkflowGraph compiles delay', is_array($delayRule));
        $check(
            'WorkflowGraph next_on_true is step_order',
            is_array($delayRule) && (int) ($delayRule['next_on_true'] ?? 0) === 2,
            'next=' . (string) ($delayRule['next_on_true'] ?? '')
        );

        // --- Sequence inactive create ---
        if ($db->tableExists('message_sequences')) {
            $svc = new SequenceService();
            $id = $svc->create('Smoke Inactive Seq', [
                ['delay_minutes' => 0, 'message_type' => 'text', 'body_text' => 'x', 'template_name' => null, 'language' => 'en'],
            ], true, 'whatsapp', null, false);
            $row = $db->table('message_sequences')->where('id', $id)->get()->getRowArray();
            $check('sequence create respects is_active=0', is_array($row) && (int) ($row['is_active'] ?? 1) === 0);
            $enrollFailed = false;
            try {
                $svc->enroll($id, 1);
            } catch (\Throwable $e) {
                $enrollFailed = true;
            }
            $check('inactive sequence blocks enroll', $enrollFailed);
            $db->table('sequence_steps')->where('sequence_id', $id)->delete();
            $db->table('message_sequences')->where('id', $id)->delete();
        }

        // --- Nested feature suites ---
        CLI::write('');
        CLI::write('=== Nested: permissions:audit (includes inbox + workflow) ===', 'yellow');
        $this->call('permissions:audit');

        CLI::write('');
        CLI::write("Functional smoke checks: {$pass} pass, {$fail} fail", $fail === 0 ? 'green' : 'red');

        return $fail > 0 ? EXIT_ERROR : EXIT_SUCCESS;
    }
}
