<?php

declare(strict_types=1);

namespace App\Libraries;

/**
 * Dry run for a workflow flow_graph: checks the structure and every node without saving anything, and previews the
 * steps the app would store. Used by POST /api/v1/automations/validate.
 *
 * Errors would make the app refuse or break the workflow; warnings are things that usually are not intended.
 */
class WorkflowValidator
{
    private const ACTIONS_NEEDING_TEXT = ['send_text', 'add_note'];

    /**
     * @param array<string, mixed> $graph
     *
     * @return array{ok: bool, errors: list<string>, warnings: list<string>, steps?: list<array<string, mixed>>}
     */
    public function check(array $graph): array
    {
        $errors   = [];
        $warnings = [];

        $nodes = [];
        foreach ((array) ($graph['nodes'] ?? []) as $i => $n) {
            if (! is_array($n) || (string) ($n['id'] ?? '') === '') {
                $errors[] = 'Node #' . ($i + 1) . ' has no id.';
                continue;
            }
            $id = (string) $n['id'];
            if (isset($nodes[$id])) {
                $errors[] = 'Node id "' . $id . '" is used twice.';
                continue;
            }
            $nodes[$id] = $n;
        }
        if ($nodes === []) {
            return ['ok' => false, 'errors' => ['The graph has no nodes.'], 'warnings' => []];
        }

        $triggers = array_filter($nodes, static fn ($n) => ($n['type'] ?? '') === 'trigger');
        if (count($triggers) !== 1) {
            $errors[] = 'The graph needs exactly one trigger node (found ' . count($triggers) . ').';
        }

        // Edges
        $out = [];
        foreach ((array) ($graph['edges'] ?? []) as $i => $e) {
            $from = (string) ($e['from'] ?? '');
            $to   = (string) ($e['to'] ?? '');
            $port = (string) ($e['port'] ?? 'out');
            if (! isset($nodes[$from]) || ! isset($nodes[$to])) {
                $errors[] = 'Edge #' . ($i + 1) . ' (' . $from . ' to ' . $to . ') points to a node that does not exist.';
                continue;
            }
            if (! preg_match('/^(out|true|false|opt_\d+)$/', $port)) {
                $errors[] = 'Edge from "' . $from . '" has an unknown port "' . $port . '" (use out, true, false or opt_N).';
                continue;
            }
            $out[$from][$port] = $to;
        }

        foreach ($nodes as $id => $n) {
            $type = (string) ($n['type'] ?? 'action');
            $data = is_array($n['data'] ?? null) ? $n['data'] : [];

            if ($type === 'trigger') {
                if (trim((string) ($data['trigger_type'] ?? '')) === '') {
                    $errors[] = 'The trigger node has no trigger_type.';
                }
                continue;
            }
            if ($type === 'condition') {
                if (empty($out[$id]['true']) && empty($out[$id]['false']) && empty($out[$id]['out'])) {
                    $warnings[] = 'Condition "' . $id . '" has no outgoing edges.';
                }
                continue;
            }
            if ($type !== 'action') {
                $errors[] = 'Node "' . $id . '" has an unknown type "' . $type . '" (use trigger, action or condition).';
                continue;
            }

            $action = (string) ($data['action_type'] ?? '');
            if (! in_array($action, WorkflowGraph::LOCAL_ACTIONS, true)) {
                $errors[] = 'Node "' . $id . '": unknown action_type "' . $action . '".';
                continue;
            }
            $this->checkAction($id, $action, $data, $out[$id] ?? [], $errors, $warnings);
        }

        // Every node should be reachable from the trigger, and the graph must not loop.
        if (count($triggers) === 1) {
            $start = (string) array_key_first($triggers);
            $seen  = [];
            $stack = [$start];
            while ($stack) {
                $cur = array_pop($stack);
                if (isset($seen[$cur])) {
                    continue;
                }
                $seen[$cur] = true;
                foreach ($out[$cur] ?? [] as $to) {
                    $stack[] = $to;
                }
            }
            foreach (array_keys($nodes) as $id) {
                if (! isset($seen[$id])) {
                    $warnings[] = 'Node "' . $id . '" cannot be reached from the trigger.';
                }
            }
            if ($this->hasCycle($start, $out)) {
                $errors[] = 'The graph has a loop. Flows must move forward only.';
            }
        }

        $result = ['ok' => $errors === [], 'errors' => $errors, 'warnings' => $warnings];
        if ($errors === []) {
            try {
                $steps = [];
                foreach ((new WorkflowGraph())->toRules($graph) as $r) {
                    $steps[] = [
                        'step'        => (int) $r['step_order'],
                        'type'        => (string) ($r['action_type'] ?? ''),
                        'next'        => $r['next_on_true'],
                        'next_false'  => $r['next_on_false'],
                        'routes'      => $r['config']['routes'] ?? null,
                    ];
                }
                $result['steps'] = $steps;
            } catch (\Throwable $e) {
                $result['ok']       = false;
                $result['errors'][] = 'The app could not turn this graph into steps: ' . $e->getMessage();
            }
        }

        return $result;
    }

    /**
     * @param array<string, mixed>  $data
     * @param array<string, string> $ports
     * @param list<string>          $errors
     * @param list<string>          $warnings
     */
    private function checkAction(string $id, string $action, array $data, array $ports, array &$errors, array &$warnings): void
    {
        $where = 'Node "' . $id . '" (' . $action . '): ';
        $text  = trim((string) ($data['text'] ?? ''));

        if (in_array($action, self::ACTIONS_NEEDING_TEXT, true) && $text === '' && trim((string) ($data['body'] ?? '')) === '') {
            $errors[] = $where . 'text is empty.';
        }

        switch ($action) {
            case 'send_media':
                if (! in_array(strtolower((string) ($data['media_type'] ?? '')), ['image', 'video', 'document'], true)) {
                    $errors[] = $where . 'media_type must be image, video or document.';
                }
                $url = trim((string) ($data['media_url'] ?? $data['url'] ?? ''));
                if (! preg_match('#^https://#i', $url)) {
                    $errors[] = $where . 'media_url must be a public https link.';
                } elseif (stripos($url, 'REPLACE') !== false) {
                    $warnings[] = $where . 'media_url still contains a placeholder.';
                }
                break;

            case 'send_template':
                if (trim((string) ($data['template_name'] ?? $data['name'] ?? '')) === '') {
                    $errors[] = $where . 'template_name is missing.';
                }
                break;

            case 'set_attribute':
                $attr = (string) ($data['attribute'] ?? '');
                if (! preg_match(ContactAttributeService::KEY_PATTERN, $attr)) {
                    $errors[] = $where . 'attribute must start with a letter and use letters, digits and underscore.';
                }
                break;

            case 'add_tag':
            case 'remove_tag':
                if (trim((string) ($data['tag_name'] ?? '')) === '' && (int) ($data['tag_id'] ?? 0) <= 0) {
                    $errors[] = $where . 'tag_name is missing.';
                }
                break;

            case 'assign_agent':
                if ((int) ($data['user_id'] ?? $data['agent_id'] ?? 0) <= 0) {
                    $warnings[] = $where . 'no agent chosen, the chat stays unassigned.';
                }
                break;

            case 'delay':
                if ((int) ($data['seconds'] ?? 0) <= 0 && (int) ($data['minutes'] ?? 0) <= 0 && (int) ($data['delay'] ?? 0) <= 0) {
                    $warnings[] = $where . 'no delay set, it defaults to 60 seconds.';
                }
                break;

            case 'ask_question':
                $this->checkQuestion($where, $data, $ports, $errors, $warnings);
                break;

            case 'ai_call':
                if (! (new VoiceAgentLeadService())->isActive()) {
                    $warnings[] = $where . 'the AI voice agent is not switched on with consent confirmed (Settings, AI Voice Agent), so this step will be skipped.';
                }
                break;
        }
    }

    /**
     * @param array<string, mixed>  $data
     * @param array<string, string> $ports
     * @param list<string>          $errors
     * @param list<string>          $warnings
     */
    private function checkQuestion(string $where, array $data, array $ports, array &$errors, array &$warnings): void
    {
        if (trim((string) ($data['text'] ?? $data['question'] ?? '')) === '') {
            $errors[] = $where . 'the question text is empty.';
        }
        $type    = (string) ($data['reply_type'] ?? '');
        $options = AutomationEngine::questionOptions($data);
        if ($type === 'text') {
            return;
        }
        if ($options === []) {
            $errors[] = $where . 'add at least one option, or set reply_type to text.';

            return;
        }
        $list = $type === 'list' || count($options) > 3;
        if (! $list && count($options) > 3) {
            $errors[] = $where . 'buttons allow at most 3 options.';
        }
        foreach ($options as $port => $label) {
            $max = $list ? 24 : 20;
            if (mb_strlen($label) > $max) {
                $warnings[] = $where . 'option "' . $label . '" is longer than ' . $max . ' characters and WhatsApp will cut it.';
            }
            if (empty($ports[$port])) {
                $warnings[] = $where . 'option "' . $label . '" (' . $port . ') has no next step, so that answer ends the flow.';
            }
        }
        foreach (array_keys($ports) as $port) {
            if (str_starts_with($port, 'opt_') && ! isset($options[$port])) {
                $errors[] = $where . 'port ' . $port . ' has no matching option.';
            }
        }
    }

    /**
     * @param array<string, array<string, string>> $out
     */
    private function hasCycle(string $start, array $out): bool
    {
        $state = [];
        $visit = function (string $n) use (&$visit, &$state, $out): bool {
            $state[$n] = 1;
            foreach ($out[$n] ?? [] as $to) {
                if (($state[$to] ?? 0) === 1) {
                    return true;
                }
                if (($state[$to] ?? 0) === 0 && $visit($to)) {
                    return true;
                }
            }
            $state[$n] = 2;

            return false;
        };

        return $visit($start);
    }
}
