<?php

declare(strict_types=1);

/**
 * Workflow "Ask question" + "Send media" nodes: send question (buttons / list / text), wait for
 * the reply, validate, save to attribute, branch per option, retry, timeout, opt-out cancel.
 * Messages are only queued (then cancelled) — nothing is sent to WhatsApp.
 *
 * Run: php tests/WorkflowAskQuestionTest.php
 */

define('FCPATH', __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR);
chdir(FCPATH);

require FCPATH . '../app/Config/Paths.php';
$paths = new \Config\Paths();
require $paths->systemDirectory . '/Boot.php';
\CodeIgniter\Boot::bootSpark($paths);

use App\Libraries\WorkflowGraph;
use App\Models\AutomationModel;
use App\Models\AutomationRuleModel;
use App\Models\ContactModel;

helper('whatsapp');

$pass = 0;
$fail = 0;

function check(string $label, bool $condition, string $detail = ''): void
{
    global $pass, $fail;
    if ($condition) {
        $pass++;
        echo "[PASS] {$label}\n";

        return;
    }
    $fail++;
    echo "[FAIL] {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

echo "=== Workflow Ask Question Test ===\n\n";

$db       = db_connect();
$contacts = model(ContactModel::class);
$autos    = model(AutomationModel::class);
$rulesM   = model(AutomationRuleModel::class);
$wf       = new WorkflowGraph();
$engine   = service('automationEngine');
$mobile   = '919000000871';
$autoIds  = [];

$cleanup = static function () use ($db, $mobile, &$autoIds): void {
    $ids = array_column($db->table('contacts')->select('id')->where('mobile', $mobile)->get()->getResultArray(), 'id');
    if ($ids !== []) {
        foreach (['message_queue', 'messages', 'conversations', 'automation_delayed_jobs', 'contact_tags'] as $t) {
            if ($db->tableExists($t)) {
                $db->table($t)->whereIn('contact_id', $ids)->delete();
            }
        }
        $db->table('contacts')->whereIn('id', $ids)->delete();
    }
    if ($autoIds !== []) {
        $db->table('automation_rules')->whereIn('automation_id', $autoIds)->delete();
        $db->table('automations')->whereIn('id', $autoIds)->delete();
    }
};
$cleanup();

/** Build + save a workflow from a canvas graph; returns automation id. */
$makeFlow = static function (array $graph) use ($autos, $rulesM, $wf, &$autoIds): int {
    $id = (int) $autos->insert(['name' => 'AskQ test ' . count($autoIds), 'trigger_type' => 'incoming_message', 'is_active' => 0, 'flow_graph' => json_encode($graph)]);
    $autoIds[] = $id;
    foreach ($wf->toRules($graph) as $rule) {
        $rulesM->insert([
            'automation_id' => $id,
            'step_order'    => $rule['step_order'],
            'rule_type'     => $rule['rule_type'],
            'action_type'   => $rule['action_type'],
            'config'        => $rule['config'],
            'next_on_true'  => $rule['next_on_true'],
            'next_on_false' => $rule['next_on_false'],
        ]);
    }

    return $id;
};

$setAttr = static fn (string $id, string $value): array => [
    'id' => $id, 'type' => 'action', 'data' => ['action_type' => 'set_attribute', 'attribute' => 'branch', 'text' => $value],
];

$buttonsGraph = [
    'nodes' => [
        ['id' => 'trigger', 'type' => 'trigger', 'data' => ['trigger_type' => 'incoming_message']],
        ['id' => 'ask', 'type' => 'action', 'data' => [
            'action_type' => 'ask_question', 'text' => 'Which city?', 'reply_type' => 'buttons',
            'options' => "Pune\nMumbai", 'save_as' => 'city', 'timeout_minutes' => 60, 'max_retries' => 1,
            'retry_text' => 'Please tap Pune or Mumbai.',
        ]],
        $setAttr('a_pune', 'pune:{{answer}}'),
        $setAttr('a_mum', 'mumbai:{{answer}}'),
        $setAttr('a_none', 'no_reply'),
    ],
    'edges' => [
        ['from' => 'trigger', 'to' => 'ask', 'port' => 'out'],
        ['from' => 'ask', 'to' => 'a_pune', 'port' => 'opt_1'],
        ['from' => 'ask', 'to' => 'a_mum', 'port' => 'opt_2'],
        ['from' => 'ask', 'to' => 'a_none', 'port' => 'false'],
    ],
];

$attr = static function (int $cid, string $key) use ($contacts): ?string {
    $c  = $contacts->find($cid);
    $cf = is_string($c['custom_fields'] ?? null) ? json_decode($c['custom_fields'], true) : ($c['custom_fields'] ?? []);

    return isset($cf[$key]) ? (string) $cf[$key] : null;
};
$openJob = static fn (int $cid): ?array => $db->table('automation_delayed_jobs')->where('contact_id', $cid)->where('status', 'awaiting_reply')->orderBy('id', 'DESC')->get(1)->getRowArray();
$lastQueued = static fn (int $cid): ?array => $db->table('message_queue')->where('contact_id', $cid)->orderBy('id', 'DESC')->get(1)->getRowArray();
$resetContact = static function (int $cid) use ($contacts, $db): void {
    $contacts->update($cid, ['custom_fields' => [], 'last_reply_at' => date('Y-m-d H:i:s')]);
    $db->table('automation_delayed_jobs')->where('contact_id', $cid)->delete();
};

try {
    // --- Save-time validation ------------------------------------------------
    $bad = ['nodes' => [
        ['id' => 'a', 'type' => 'action', 'data' => ['action_type' => 'ask_question', 'text' => '', 'reply_type' => 'buttons', 'options' => "A\nB\nC\nD", 'save_as' => 'my city']],
        ['id' => 'm', 'type' => 'action', 'data' => ['action_type' => 'send_media', 'media_type' => 'gif', 'media_url' => 'not a url']],
    ]];
    $errors = implode(' | ', $wf->validate($bad));
    check('V1 empty question rejected', str_contains($errors, 'enter the question'), $errors);
    check('V2 more than 3 buttons rejected', str_contains($errors, 'max 3 buttons'), $errors);
    check('V3 invalid attribute name rejected', str_contains($errors, 'letters, numbers and _'), $errors);
    check('V4 bad media type / URL rejected', str_contains($errors, 'choose image, video or document') && str_contains($errors, 'public https://'), $errors);
    check('V5 valid graph passes', $wf->validate($buttonsGraph) === [], implode(' | ', $wf->validate($buttonsGraph)));

    // --- Compile: per-option routes -----------------------------------------
    $rules  = $wf->toRules($buttonsGraph);
    $askCfg = $rules[0]['config'] ?? [];
    check('C1 option branches compiled into routes', isset($askCfg['routes']['opt_1'], $askCfg['routes']['opt_2']) && $rules[0]['next_on_false'] !== null, json_encode($askCfg['routes'] ?? null));

    $cid = (int) $contacts->insert(['name' => 'AskQ Tester', 'mobile' => $mobile, 'status' => 'active', 'last_reply_at' => date('Y-m-d H:i:s')]);
    $aid = $makeFlow($buttonsGraph);

    // --- Buttons: question sent + waiting ------------------------------------
    $engine->runAutomation($aid, ['contact_id' => $cid]);
    $q       = $lastQueued($cid);
    $payload = json_decode((string) ($q['payload'] ?? ''), true) ?: [];
    check('B1 question queued as interactive buttons', ($q['message_type'] ?? '') === 'interactive_buttons' && ($payload['body'] ?? '') === 'Which city?');
    check('B2 buttons carry option ids', array_column($payload['buttons'] ?? [], 'id') === ['opt_1', 'opt_2'], json_encode($payload['buttons'] ?? null));
    check('B3 flow paused awaiting reply', $openJob($cid) !== null);
    check('B4 next nodes not run before reply', $attr($cid, 'branch') === null);

    // --- Button tap → saved + own branch -------------------------------------
    check('B5 reply consumed', $engine->handleAwaitedReply($cid, ['content' => 'Mumbai', 'reply_id' => 'opt_2', 'message_type' => 'interactive']));
    check('B6 answer saved to attribute', $attr($cid, 'city') === 'Mumbai', (string) $attr($cid, 'city'));
    check('B7 Mumbai branch ran with {{answer}}', $attr($cid, 'branch') === 'mumbai:Mumbai', (string) $attr($cid, 'branch'));
    check('B8 wait closed', $openJob($cid) === null);
    check('B9 no open question → message not consumed', ! $engine->handleAwaitedReply($cid, ['content' => 'hello']));

    // --- Typed number / label ------------------------------------------------
    $resetContact($cid);
    $engine->runAutomation($aid, ['contact_id' => $cid]);
    $engine->handleAwaitedReply($cid, ['content' => '1']);
    check('T1 typed "1" picks option 1 (Pune)', $attr($cid, 'city') === 'Pune' && $attr($cid, 'branch') === 'pune:Pune');

    $resetContact($cid);
    $engine->runAutomation($aid, ['contact_id' => $cid]);
    $engine->handleAwaitedReply($cid, ['content' => 'mumbai']);
    check('T2 typed label (any case) picks option', $attr($cid, 'city') === 'Mumbai');

    // --- Wrong answer → retry once → No reply path ---------------------------
    $resetContact($cid);
    $engine->runAutomation($aid, ['contact_id' => $cid]);
    check('R1 wrong answer consumed', $engine->handleAwaitedReply($cid, ['content' => 'Delhi']));
    $retry = json_decode((string) ($lastQueued($cid)['payload'] ?? ''), true) ?: [];
    check('R2 question re-sent with retry text', str_contains((string) ($retry['body'] ?? ''), 'Please tap Pune or Mumbai.') && $openJob($cid) !== null);
    check('R3 nothing saved on wrong answer', $attr($cid, 'city') === null && $attr($cid, 'branch') === null);
    $engine->handleAwaitedReply($cid, ['content' => 'Nagpur']);
    check('R4 second wrong answer → No reply path', $attr($cid, 'branch') === 'no_reply' && $openJob($cid) === null);

    // --- Timeout ------------------------------------------------------------
    $resetContact($cid);
    $engine->runAutomation($aid, ['contact_id' => $cid]);
    $job = $openJob($cid);
    $db->table('automation_delayed_jobs')->where('id', $job['id'])->update(['run_at' => date('Y-m-d H:i:s', time() - 5)]);
    check('X1 expired question ignores late reply', ! $engine->handleAwaitedReply($cid, ['content' => 'Pune']));
    $engine->processAwaitingTimeouts();
    $row = $db->table('automation_delayed_jobs')->where('id', $job['id'])->get()->getRowArray();
    check('X2 timeout follows No reply path', $attr($cid, 'branch') === 'no_reply' && ($row['status'] ?? '') === 'expired', (string) ($row['status'] ?? ''));

    // --- List (4 options) ---------------------------------------------------
    $listGraph = $buttonsGraph;
    $listGraph['nodes'][1]['data']['reply_type'] = 'list';
    $listGraph['nodes'][1]['data']['options']    = "Pune\nMumbai\nNashik\nOther";
    $lid = $makeFlow($listGraph);
    $resetContact($cid);
    $engine->runAutomation($lid, ['contact_id' => $cid]);
    $q    = $lastQueued($cid);
    $pl   = json_decode((string) ($q['payload'] ?? ''), true) ?: [];
    $rows = $pl['sections'][0]['rows'] ?? [];
    check('L1 list question queued with 4 rows', ($q['message_type'] ?? '') === 'interactive_list' && count($rows) === 4 && ($pl['button_text'] ?? '') === 'Choose');
    $engine->handleAwaitedReply($cid, ['content' => 'Nashik', 'reply_id' => 'opt_3']);
    check('L2 unconnected option saves answer and ends', $attr($cid, 'city') === 'Nashik' && $attr($cid, 'branch') === null);

    // --- Free text with email validation → core column ----------------------
    $textGraph = ['nodes' => [
        ['id' => 'trigger', 'type' => 'trigger', 'data' => ['trigger_type' => 'incoming_message']],
        ['id' => 'ask', 'type' => 'action', 'data' => ['action_type' => 'ask_question', 'text' => 'Your email?', 'reply_type' => 'text', 'validation' => 'email', 'save_as' => 'email']],
        $setAttr('done', 'got {{contact.email}}'),
    ], 'edges' => [
        ['from' => 'trigger', 'to' => 'ask', 'port' => 'out'],
        ['from' => 'ask', 'to' => 'done', 'port' => 'true'],
    ]];
    $tid = $makeFlow($textGraph);
    $resetContact($cid);
    $engine->runAutomation($tid, ['contact_id' => $cid]);
    check('E1 text question queued as text', ($lastQueued($cid)['message_type'] ?? '') === 'text');
    $engine->handleAwaitedReply($cid, ['content' => 'not-an-email']);
    check('E2 invalid email asked again', $openJob($cid) !== null && empty($contacts->find($cid)['email']));
    $engine->handleAwaitedReply($cid, ['content' => 'tester@example.com']);
    check('E3 valid email saved to contact email', ($contacts->find($cid)['email'] ?? '') === 'tester@example.com');
    check('E4 Answered path ran with saved value', $attr($cid, 'branch') === 'got tester@example.com', (string) $attr($cid, 'branch'));

    // --- Opt-out cancels an open question ------------------------------------
    $resetContact($cid);
    $engine->runAutomation($aid, ['contact_id' => $cid]);
    service('whatsAppConsent')->optOut($cid, 'test');
    check('O1 STOP cancels waiting question', $openJob($cid) === null && ! $engine->handleAwaitedReply($cid, ['content' => 'Pune']));

    // --- Send media ---------------------------------------------------------
    $ctx = ['contact_id' => $cid, 'contact' => $contacts->find($cid)];
    $engine->executeAction('send_media', ['media_type' => 'document', 'media_url' => 'https://example.com/files/brochure.pdf', 'caption' => 'Hi {{contact.name}}'], $ctx);
    $m  = $lastQueued($cid);
    $mp = json_decode((string) ($m['payload'] ?? ''), true) ?: [];
    check('M1 document queued with link, caption and file name', ($m['message_type'] ?? '') === 'document' && ($mp['link'] ?? '') === 'https://example.com/files/brochure.pdf'
        && ($mp['filename'] ?? '') === 'brochure.pdf' && ($mp['caption'] ?? '') === 'Hi AskQ Tester', json_encode($mp));
    $err = '';
    try {
        $engine->executeAction('send_media', ['media_type' => 'image', 'media_url' => ''], $ctx);
    } catch (RuntimeException $e) {
        $err = $e->getMessage();
    }
    check('M2 missing URL gives clear error', str_contains($err, 'public media URL'), $err);
} finally {
    $cleanup();
}

echo "\n=== Result: {$pass} passed, {$fail} failed ===\n";
exit($fail > 0 ? 1 : 0);
