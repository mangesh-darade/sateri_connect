<?php

declare(strict_types=1);

namespace App\Libraries;

/**
 * Converts Cheerio-style canvas graphs ↔ sequential automation_rules.
 *
 * Graph shape:
 * {
 *   "nodes": [{ "id":"n1", "type":"trigger|condition|action|end", "x":0, "y":0, "data":{...} }],
 *   "edges": [{ "from":"n1", "to":"n2", "port":"out|true|false" }]
 * }
 */
class WorkflowGraph
{
    /**
     * @param array{nodes?: list<array<string,mixed>>, edges?: list<array<string,mixed>>} $graph
     *
     * @return list<array<string, mixed>>
     */
    public function toRules(array $graph): array
    {
        $nodes = [];
        foreach ($graph['nodes'] ?? [] as $node) {
            if (! is_array($node) || empty($node['id'])) {
                continue;
            }
            $nodes[(string) $node['id']] = $node;
        }

        $edges = [];
        foreach ($graph['edges'] ?? [] as $edge) {
            if (! is_array($edge) || empty($edge['from']) || empty($edge['to'])) {
                continue;
            }
            $from = (string) $edge['from'];
            $port = (string) ($edge['port'] ?? 'out');
            $edges[$from][$port] = (string) $edge['to'];
        }

        $triggerId = null;
        foreach ($nodes as $id => $node) {
            if (($node['type'] ?? '') === 'trigger') {
                $triggerId = $id;
                break;
            }
        }

        // Walk reachable nodes from trigger (or first non-trigger)
        $startTargets = [];
        if ($triggerId !== null) {
            $startTargets[] = $edges[$triggerId]['out'] ?? null;
            $startTargets[] = $edges[$triggerId]['true'] ?? null;
        }
        $startTargets = array_values(array_filter($startTargets));

        if ($startTargets === []) {
            foreach ($nodes as $id => $node) {
                if (($node['type'] ?? '') !== 'trigger') {
                    $startTargets[] = $id;
                    break;
                }
            }
        }

        $orderedIds = [];
        $queue      = $startTargets;
        $seen       = [];

        while ($queue !== []) {
            $id = array_shift($queue);
            if ($id === null || isset($seen[$id]) || ! isset($nodes[$id])) {
                continue;
            }
            $seen[$id] = true;
            $type      = (string) ($nodes[$id]['type'] ?? '');
            if ($type === 'trigger' || $type === 'end') {
                // Still follow outs from end? skip storing end as rule
                if ($type === 'end') {
                    continue;
                }
            } else {
                $orderedIds[] = $id;
            }

            foreach (['out', 'true', 'false'] as $port) {
                if (! empty($edges[$id][$port])) {
                    $queue[] = $edges[$id][$port];
                }
            }
            // Cheerio multi-option handles (opt_2, opt_3, …)
            foreach ($edges[$id] ?? [] as $port => $target) {
                if (is_string($port) && str_starts_with($port, 'opt_') && $target) {
                    $queue[] = $target;
                }
            }
        }

        // Map node id → step_order (1-based)
        $stepOf = [];
        $step   = 1;
        foreach ($orderedIds as $id) {
            $stepOf[$id] = $step++;
        }

        $rules = [];
        foreach ($orderedIds as $id) {
            $node = $nodes[$id];
            $data = is_array($node['data'] ?? null) ? $node['data'] : [];
            $type = (string) ($node['type'] ?? 'action');
            $order = $stepOf[$id];

            if ($type === 'condition') {
                $trueTo  = $edges[$id]['true'] ?? $edges[$id]['out'] ?? null;
                $falseTo = $edges[$id]['false'] ?? null;

                $rules[] = [
                    'step_order'    => $order,
                    'rule_type'     => 'condition',
                    'action_type'   => (string) ($data['condition_type'] ?? $data['action_type'] ?? 'message_contains'),
                    'config'        => $this->conditionConfig($data),
                    'next_on_true'  => $trueTo !== null && isset($stepOf[$trueTo]) ? $stepOf[$trueTo] : null,
                    'next_on_false' => $falseTo !== null && isset($stepOf[$falseTo]) ? $stepOf[$falseTo] : null,
                    'node_id'       => $id,
                ];
                continue;
            }

            // action (may branch like Cheerio webhookTrigger / responseMessage)
            $trueTo  = $edges[$id]['true'] ?? $edges[$id]['out'] ?? null;
            $falseTo = $edges[$id]['false'] ?? null;
            $rules[] = [
                'step_order'    => $order,
                'rule_type'     => 'action',
                'action_type'   => (string) ($data['action_type'] ?? 'send_text'),
                'config'        => $this->actionConfig($data),
                'next_on_true'  => $trueTo !== null && isset($stepOf[$trueTo]) ? $stepOf[$trueTo] : null,
                'next_on_false' => $falseTo !== null && isset($stepOf[$falseTo]) ? $stepOf[$falseTo] : null,
                'node_id'       => $id,
            ];
        }

        return $rules;
    }

    /**
     * Build a minimal canvas graph from legacy flat rules (for first open).
     *
     * @param list<array<string, mixed>> $rules
     * @return array{nodes: list<array<string,mixed>>, edges: list<array<string,mixed>>}
     */
    public function fromRules(array $rules, string $triggerType = 'incoming_message'): array
    {
        $nodes = [
            [
                'id'   => 'trigger',
                'type' => 'trigger',
                'x'    => 40,
                'y'    => 160,
                'data' => [
                    'trigger_type' => $triggerType,
                    'label'        => $this->triggerLabel($triggerType),
                ],
            ],
        ];
        $edges = [];

        if ($rules === []) {
            $nodes[] = [
                'id'   => 'action_1',
                'type' => 'action',
                'x'    => 360,
                'y'    => 160,
                'data' => [
                    'action_type' => 'send_text',
                    'text'        => 'Hello {{contact.name}}!',
                    'label'       => 'Send text',
                ],
            ];
            $edges[] = ['from' => 'trigger', 'to' => 'action_1', 'port' => 'out'];

            return ['nodes' => $nodes, 'edges' => $edges];
        }

        // Sort by step_order
        usort($rules, static fn ($a, $b) => ((int) ($a['step_order'] ?? 0)) <=> ((int) ($b['step_order'] ?? 0)));

        $idByStep = [];
        $xBase    = 320;
        $y        = 40;

        foreach ($rules as $i => $rule) {
            $step = (int) ($rule['step_order'] ?? ($i + 1));
            $nid  = 'n' . $step;
            $idByStep[$step] = $nid;
            $rtype = (string) ($rule['rule_type'] ?? 'action');
            $config = $rule['config'] ?? [];
            if (is_string($config)) {
                $decoded = json_decode($config, true);
                $config  = is_array($decoded) ? $decoded : [];
            }

            if ($rtype === 'condition') {
                $nodes[] = [
                    'id'   => $nid,
                    'type' => 'condition',
                    'x'    => $xBase + ($i * 40),
                    'y'    => $y + ($i * 100),
                    'data' => array_merge($config, [
                        'condition_type' => $rule['action_type'] ?? 'message_contains',
                        'label'          => 'Condition',
                    ]),
                ];
            } else {
                $nodes[] = [
                    'id'   => $nid,
                    'type' => 'action',
                    'x'    => $xBase + ($i * 40),
                    'y'    => $y + ($i * 100),
                    'data' => array_merge($config, [
                        'action_type' => $rule['action_type'] ?? 'send_text',
                        'label'       => (string) ($rule['action_type'] ?? 'action'),
                    ]),
                ];
            }
        }

        // Connect trigger → first
        $firstStep = (int) ($rules[0]['step_order'] ?? 1);
        if (isset($idByStep[$firstStep])) {
            $edges[] = ['from' => 'trigger', 'to' => $idByStep[$firstStep], 'port' => 'out'];
        }

        foreach ($rules as $rule) {
            $step = (int) ($rule['step_order'] ?? 0);
            $from = $idByStep[$step] ?? null;
            if ($from === null) {
                continue;
            }
            $true  = $rule['next_on_true'] ?? null;
            $false = $rule['next_on_false'] ?? null;
            if ($true !== null && isset($idByStep[(int) $true])) {
                $port = (($rule['rule_type'] ?? '') === 'condition') ? 'true' : 'out';
                $edges[] = ['from' => $from, 'to' => $idByStep[(int) $true], 'port' => $port];
            }
            if ($false !== null && isset($idByStep[(int) $false])) {
                $edges[] = ['from' => $from, 'to' => $idByStep[(int) $false], 'port' => 'false'];
            }
        }

        return ['nodes' => $nodes, 'edges' => $edges];
    }

    /**
     * Extract trigger type from graph trigger node (if present).
     */
    public function triggerFromGraph(array $graph, ?string $fallback = null): ?string
    {
        foreach ($graph['nodes'] ?? [] as $node) {
            if (($node['type'] ?? '') === 'trigger') {
                $t = (string) (($node['data']['trigger_type'] ?? '') ?: '');

                return $t !== '' ? $t : $fallback;
            }
        }

        return $fallback;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    protected function conditionConfig(array $data): array
    {
        $preset = (string) ($data['condition_type'] ?? $data['action_type'] ?? 'message_contains');

        return [
            'preset'   => $preset,
            'operator' => $data['operator'] ?? null,
            'field'    => $data['field'] ?? null,
            'value'    => $data['value'] ?? $data['text'] ?? '',
            'tag_id'   => isset($data['tag_id']) ? (int) $data['tag_id'] : null,
        ];
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    protected function actionConfig(array $data): array
    {
        $out = $data;
        unset($out['label'], $out['action_type'], $out['condition_type']);

        // Normalize common aliases into engine keys
        if (isset($out['body']) && ! isset($out['text'])) {
            $out['text'] = $out['body'];
        }
        if (isset($out['minutes']) && ! isset($out['seconds'])) {
            $out['seconds'] = ((int) $out['minutes']) * 60;
        }
        if (isset($out['delay_minutes']) && ! isset($out['seconds'])) {
            $out['seconds'] = ((int) $out['delay_minutes']) * 60;
        }

        return $out;
    }

    protected function triggerLabel(string $type): string
    {
        return match ($type) {
            'incoming_message', 'message_received' => 'Incoming WhatsApp',
            'campaign_sent' => 'Campaign Sent',
            'shopify_event' => 'Shopify Events',
            'facebook_lead' => 'Facebook Lead',
            'kylas_event_create' => 'Kylas Event Create',
            'kylas_event_update' => 'Kylas Event Update',
            'pabbly_event' => 'Pabbly Event',
            'incoming_webhook' => 'Incoming Webhook',
            'messenger' => 'Messenger',
            'instagram' => 'Instagram',
            'commerce_event' => 'Commerce Event',
            'contact_created' => 'New contact',
            'form_response', 'lead_form' => 'New form response',
            'keyword_matched', 'keyword' => 'Keyword matched',
            'campaign_replied' => 'Campaign reply',
            'tag_added' => 'Tag added',
            'birthday', 'schedule' => 'Birthday / schedule',
            'cheerio_workflow' => 'Cheerio workflow',
            default => $type,
        };
    }

    /**
     * Current normalize schema version — bump when mapper/layout fixes need re-apply.
     */
    public const NORMALIZE_VERSION = 6;

    /**
     * Convert Cheerio React Flow graph → local builder shape (x/y, from/to, labels).
     *
     * @param array<string, mixed> $graph
     *
     * @return array{nodes: list<array<string,mixed>>, edges: list<array<string,mixed>>, source?: string, normalize_version?: int}
     */
    public function normalizeImportedGraph(array $graph): array
    {
        $nodes = [];
        foreach ($graph['nodes'] ?? [] as $node) {
            if (! is_array($node) || empty($node['id'])) {
                continue;
            }

            $pos = [];
            if (isset($node['position']) && is_array($node['position'])) {
                $pos = $node['position'];
            } elseif (isset($node['positionAbsolute']) && is_array($node['positionAbsolute'])) {
                $pos = $node['positionAbsolute'];
            }

            $x = (float) ($node['x'] ?? $pos['x'] ?? 40);
            $y = (float) ($node['y'] ?? $pos['y'] ?? 40);

            $data = is_array($node['data'] ?? null) ? $node['data'] : [];
            $cheerioType = $this->resolveCheerioType($node, $data);
            $mapped      = $this->mapCheerioNode($cheerioType, $data);
            $merged      = array_merge($data, $mapped['data'], [
                'cheerio_type' => $cheerioType,
                'label'        => $mapped['label'],
            ]);
            // Prevent stale Cheerio keys from fighting mapped local types
            if ($mapped['canvas_type'] === 'action') {
                unset($merged['trigger_type']);
            }
            if ($mapped['canvas_type'] === 'trigger') {
                unset($merged['action_type']);
            }
            if ($mapped['canvas_type'] === 'condition') {
                unset($merged['action_type'], $merged['trigger_type']);
            }
            if (isset($mapped['data']['action_type'])) {
                $merged['action_type'] = $mapped['data']['action_type'];
            }
            if (isset($mapped['data']['trigger_type'])) {
                $merged['trigger_type'] = $mapped['data']['trigger_type'];
            }
            if (! empty($mapped['data']['branches'])) {
                $merged['branches'] = true;
            }

            $nodes[] = [
                'id'   => (string) $node['id'],
                'type' => $mapped['canvas_type'],
                'x'    => $x,
                'y'    => $y,
                'data' => $merged,
            ];
        }

        // Shift whole graph so min x/y = 60 — keep relative Cheerio layout (don't stack negatives)
        if ($nodes !== []) {
            $minX = min(array_map(static fn ($n) => (float) $n['x'], $nodes));
            $minY = min(array_map(static fn ($n) => (float) $n['y'], $nodes));
            $shiftX = 60 - $minX;
            $shiftY = 60 - $minY;
            foreach ($nodes as &$n) {
                $n['x'] = round((float) $n['x'] + $shiftX, 2);
                $n['y'] = round((float) $n['y'] + $shiftY, 2);
            }
            unset($n);
        }

        $edges = [];
        foreach ($graph['edges'] ?? [] as $edge) {
            if (! is_array($edge)) {
                continue;
            }
            $from = (string) ($edge['from'] ?? $edge['source'] ?? '');
            $to   = (string) ($edge['to'] ?? $edge['target'] ?? '');
            if ($from === '' || $to === '') {
                continue;
            }

            $handle = (string) ($edge['sourceHandle'] ?? $edge['port'] ?? 'out');
            $port   = $this->mapEdgePort($handle);

            $edges[] = [
                'from' => $from,
                'to'   => $to,
                'port' => $port,
            ];
        }

        // Mark nodes that need multi-option ports
        $portsByFrom = [];
        foreach ($edges as $e) {
            $portsByFrom[$e['from']][$e['port']] = true;
        }
        foreach ($nodes as &$n) {
            $ports = array_keys($portsByFrom[$n['id']] ?? []);
            if (count($ports) > 1 || in_array('true', $ports, true) || in_array('false', $ports, true)
                || (bool) preg_grep('/^opt_/', $ports)) {
                $n['data']['branches'] = true;
                $n['data']['ports']    = $ports;
            }
        }
        unset($n);

        $out = [
            'nodes'             => $nodes,
            'edges'             => $edges,
            'normalize_version' => self::NORMALIZE_VERSION,
        ];
        if (! empty($graph['source'])) {
            $out['source'] = (string) $graph['source'];
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed> $data
     */
    protected function resolveCheerioType(array $node, array $data): string
    {
        $canvasType = strtolower((string) ($node['type'] ?? ''));

        // Already-local canvas nodes: keep mapped types stable across re-normalize
        if ($canvasType === 'trigger') {
            $trigger = strtolower((string) ($data['trigger_type'] ?? 'incoming_message'));

            return $trigger !== '' ? $trigger : 'incoming_message';
        }
        if ($canvasType === 'condition') {
            return (string) ($data['condition_type'] ?? 'message_contains');
        }
        if ($canvasType === 'end') {
            return 'end';
        }

        // Prefer already-mapped local action types so re-open does not corrupt graphs.
        $action = strtolower((string) ($data['action_type'] ?? ''));
        $knownActions = [
            'send_text', 'response_message', 'send_template', 'system_initiated',
            'collect_images', 'add_tag', 'remove_tag', 'assign_agent', 'add_note',
            'delay', 'webhook', 'webhook_call', 'set_attribute', 'end',
        ];
        if ($action !== '' && in_array($action, $knownActions, true)) {
            return $action;
        }

        $trigger = strtolower((string) ($data['trigger_type'] ?? ''));
        if ($trigger !== '' && $trigger !== 'cheerio_workflow') {
            return $trigger;
        }

        $fromData = (string) ($data['cheerio_type'] ?? '');
        if ($fromData !== '' && ! in_array(strtolower($fromData), ['trigger', 'condition', 'action', 'end'], true)) {
            return $fromData;
        }

        if (str_starts_with($action, 'cheerio_')) {
            $stripped = substr($action, strlen('cheerio_'));
            if ($stripped === 'action' || $stripped === '') {
                return 'response_message';
            }

            return $stripped;
        }

        $type = (string) ($node['type'] ?? 'action');
        if (! in_array(strtolower($type), ['trigger', 'condition', 'action', 'end'], true)) {
            return $type;
        }

        // Plain canvas "action" node
        if (strtolower($type) === 'action' || strtolower($fromData) === 'action') {
            return 'response_message';
        }

        return $fromData !== '' ? $fromData : $type;
    }

    protected function mapEdgePort(string $handle): string
    {
        $handle = trim($handle);
        if ($handle === '' || $handle === 'out' || $handle === 'source' || $handle === 'default') {
            return 'out';
        }
        if ($handle === 'false' || str_ends_with($handle, '_no') || str_ends_with($handle, '-no')) {
            return 'false';
        }
        if ($handle === 'true' || str_ends_with($handle, '_yes') || str_ends_with($handle, '-yes')) {
            return 'true';
        }
        if (preg_match('/child_node_(\d+)/i', $handle, $m)) {
            $idx = (int) $m[1];

            return match ($idx) {
                0       => 'true',
                1       => 'false',
                default => 'opt_' . $idx,
            };
        }
        if (preg_match('/(?:^|_)(\d+)$/', $handle, $m) && (int) $m[1] >= 2) {
            return 'opt_' . $m[1];
        }

        return $handle;
    }

    /**
     * Whether graph looks like raw Cheerio React Flow export.
     *
     * @param array<string, mixed> $graph
     */
    public function looksLikeCheerioGraph(array $graph): bool
    {
        if (($graph['source'] ?? '') === 'cheerio') {
            return true;
        }

        foreach ($graph['nodes'] ?? [] as $node) {
            if (! is_array($node)) {
                continue;
            }
            if (isset($node['position']) && is_array($node['position'])) {
                return true;
            }
            $type = (string) ($node['type'] ?? '');
            if ($type !== '' && ! in_array($type, ['trigger', 'condition', 'action', 'end'], true)) {
                return true;
            }
        }

        foreach ($graph['edges'] ?? [] as $edge) {
            if (is_array($edge) && (isset($edge['source']) || isset($edge['target']))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param mixed $value
     */
    protected function stringify(mixed $value, string $fallback = ''): string
    {
        if ($value === null || $value === false) {
            return $fallback;
        }
        if (is_string($value) || is_int($value) || is_float($value)) {
            return str_replace(['\\/', '\\'], ['/', ''], (string) $value);
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_array($value)) {
            if (isset($value['text']) && (is_string($value['text']) || is_numeric($value['text']))) {
                return $this->stringify($value['text'], $fallback);
            }
            if (isset($value['body']) && (is_string($value['body']) || is_numeric($value['body']))) {
                return $this->stringify($value['body'], $fallback);
            }
            $parts = [];
            foreach ($value as $item) {
                if (is_string($item) || is_numeric($item)) {
                    $parts[] = (string) $item;
                }
            }

            return $parts !== [] ? implode(' ', $parts) : $fallback;
        }

        return $fallback;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array{canvas_type: string, label: string, data: array<string, mixed>}
     */
    protected function mapCheerioNode(string $cheerioType, array $data): array
    {
        $type = strtolower($cheerioType);

        $triggerTypes = [
            'facebooklead', 'facebook_lead', 'incomingwebhook', 'incoming_webhook',
            'contactcreated', 'contact_created', 'incomingmessage', 'incoming_message',
            'message_received', 'incomingwa', 'trigger', 'leadform',
            'form_response', 'formresponse', 'formswfnode', 'forms', 'campaignsent', 'campaign_sent',
            'campaign_replied', 'campaignreplied', 'keyword', 'keyword_matched', 'keywordmatched',
            'tag_added', 'tagadded', 'birthday', 'schedule',
            'shopifyevent', 'shopify_event', 'shopify', 'kylaseventcreate', 'kylas_event_create',
            'kylaseventupdate', 'kylas_event_update', 'pabblyevent', 'pabbly_event', 'pabbly',
            'messenger', 'instagram', 'commerceevent', 'commerce_event',
        ];
        $conditionTypes = [
            'ifelse', 'condition', 'branch', 'decision',
            'message_contains', 'messagecontains', 'message_equals', 'messageequals',
            'caption_contains', 'captioncontains', 'message_type', 'messagetype',
            'has_tag', 'hastag', 'within_window', 'withinwindow', 'contact_status', 'contactstatus',
        ];
        $endTypes       = ['endnode', 'end', 'stop'];

        // Outbound webhook / HTTP call nodes (Cheerio names often include "Trigger")
        if (
            $type === 'webhooktrigger'
            || $type === 'webhook'
            || $type === 'httprequest'
            || (str_contains($type, 'webhook') && ! str_contains($type, 'incoming'))
        ) {
            $url   = $this->stringify($data['url'] ?? '');
            $label = $url !== '' ? ('Webhook: ' . mb_strimwidth($url, 0, 48, '…')) : 'Call webhook';

            $values = $data['values'] ?? [];
            if (! is_array($values)) {
                $values = [];
            }
            $values = array_values(array_filter(array_map(
                static fn ($v) => trim((string) $v),
                $values
            ), static fn ($v) => $v !== ''));

            $header = $data['header'] ?? null;
            if (! is_array($header)) {
                $header = ['key' => 'Content-Type', 'value' => 'application/json'];
            }

            $headers = $data['headers'] ?? null;
            if (! is_array($headers)) {
                $headers = null;
            }

            return [
                'canvas_type' => 'action',
                'label'       => $label,
                'data'        => [
                    'action_type'     => 'webhook',
                    'url'             => $url,
                    'method'          => $this->stringify($data['method'] ?? 'POST', 'POST'),
                    // Cheerio webhookTrigger uses success/fail child handles
                    'branches'        => true,
                    'values'          => $values,
                    'header'          => $header,
                    'headers'         => $headers,
                    'savedAttributes' => is_array($data['savedAttributes'] ?? null) ? $data['savedAttributes'] : [],
                    'saveVariables'   => (bool) ($data['saveVariables'] ?? false),
                ],
            ];
        }

        if (
            in_array($type, $triggerTypes, true)
            || str_contains($type, 'lead')
            || ($type !== 'webhooktrigger' && str_ends_with($type, 'trigger') && ! str_contains($type, 'webhook'))
        ) {
            $localTrigger = $this->mapCheerioTriggerType($type);
            $label        = $this->stringify(
                $data['adName'] ?? $data['labelName'] ?? $data['label'] ?? ''
            );
            if ($label === '' || str_ends_with(strtolower($label), ' node')) {
                $label = $this->triggerLabel($localTrigger);
            }

            return [
                'canvas_type' => 'trigger',
                'label'       => $label,
                'data'        => [
                    'trigger_type' => $localTrigger,
                    'action_type'  => null,
                ],
            ];
        }

        if (in_array($type, $conditionTypes, true) || str_starts_with($type, 'message_') || $type === 'has_tag' || $type === 'within_window') {
            $preset = match (true) {
                in_array($type, ['messageequals', 'message_equals'], true) => 'message_equals',
                in_array($type, ['captioncontains', 'caption_contains'], true) => 'caption_contains',
                in_array($type, ['messagetype', 'message_type'], true) => 'message_type',
                in_array($type, ['hastag', 'has_tag'], true) => 'has_tag',
                in_array($type, ['withinwindow', 'within_window'], true) => 'within_window',
                in_array($type, ['contactstatus', 'contact_status'], true) => 'contact_status',
                in_array($type, ['messagecontains', 'message_contains'], true) => 'message_contains',
                default => (string) ($data['condition_type'] ?? 'message_contains'),
            };

            return [
                'canvas_type' => 'condition',
                'label'       => $this->stringify($data['label'] ?? 'If / Else', 'If / Else'),
                'data'        => [
                    'condition_type' => $preset,
                    'value'          => $this->stringify($data['value'] ?? ''),
                    'tag_id'         => $data['tag_id'] ?? null,
                ],
            ];
        }

        if (in_array($type, $endTypes, true)) {
            return [
                'canvas_type' => 'end',
                'label'       => 'End',
                'data'        => ['action_type' => 'end'],
            ];
        }

        // Actions
        $label = $this->stringify($data['label'] ?? $cheerioType, $cheerioType);
        $extra = [];

        if ($type === 'systeminitiated' || $type === 'system_initiated' || str_contains($type, 'systeminit')) {
            $label = 'System initiated';
            $extra = [
                'action_type'   => 'system_initiated',
                'template_name' => $this->stringify($data['templateName'] ?? $data['template_name'] ?? ''),
                'language'      => $this->stringify($data['language'] ?? $data['language_code'] ?? 'en_US', 'en_US'),
                'text'          => $this->stringify($data['text'] ?? $data['message'] ?? ''),
            ];
        } elseif ($type === 'collectimages' || $type === 'collect_images' || str_contains($type, 'collectimage')) {
            $label = 'Collect Images';
            $extra = [
                'action_type' => 'collect_images',
                'count'       => (int) ($data['count'] ?? $data['max_images'] ?? $data['imageCount'] ?? 1),
                'prompt'      => $this->stringify($data['prompt'] ?? $data['text'] ?? $data['message'] ?? ''),
                'text'        => $this->stringify($data['prompt'] ?? $data['text'] ?? $data['message'] ?? ''),
            ];
        } elseif ($type === 'responsemessage' || $type === 'response_message') {
            $label = 'Response message';
            $text  = $this->stringify(
                $data['text']
                ?? $data['message']
                ?? (is_array($data['body'] ?? null) ? ($data['body']['message'] ?? $data['body']['text'] ?? $data['body']) : ($data['body'] ?? ''))
            );
            $extra = [
                'action_type' => 'response_message',
                'text'        => $text,
                // Interactive replies / buttons use true|false|/opt_* handles in Cheerio
                'branches'    => true,
            ];
        } elseif ($type === 'addtolabel' || $type === 'add_to_label') {
            $tagName = $this->stringify($data['labelName'] ?? $data['tag_name'] ?? $data['tag'] ?? '');
            $label   = $tagName !== '' ? ('Add tag: ' . $tagName) : 'Add tag';
            $extra   = [
                'action_type' => 'add_tag',
                'tag_name'    => $tagName,
                'labelName'   => $tagName,
            ];
        } elseif ($type === 'removefromlabel' || $type === 'remove_from_label') {
            $tagName = $this->stringify($data['labelName'] ?? $data['tag_name'] ?? $data['tag'] ?? '');
            $label   = $tagName !== '' ? ('Remove tag: ' . $tagName) : 'Remove tag';
            $extra   = [
                'action_type' => 'remove_tag',
                'tag_name'    => $tagName,
                'labelName'   => $tagName,
            ];
        } elseif ($type === 'timedelay' || $type === 'time_delay' || $type === 'delay') {
            $seconds = (int) ($data['seconds'] ?? $data['delay'] ?? $data['timeDelay'] ?? $data['delayInSeconds'] ?? 0);
            if ($seconds <= 0) {
                $minutes = (int) ($data['minutes'] ?? $data['delayMinutes'] ?? $data['delay_minutes'] ?? 1);
                $seconds = max(1, $minutes) * 60;
            }
            $minutes = max(1, (int) round($seconds / 60));
            $label   = 'Delay ' . $minutes . ' min';
            $extra   = [
                'action_type' => 'delay',
                'seconds'     => $seconds,
                'minutes'     => $minutes,
            ];
        } elseif ($type === 'assignagent' || $type === 'assign_agent') {
            $label = 'Assign agent';
            $extra = [
                'action_type' => 'assign_agent',
                'user_id'     => $data['userId'] ?? $data['user_id'] ?? $data['agent_id'] ?? '',
            ];
        } elseif ($type === 'addnote' || $type === 'add_note' || $type === 'internalnote') {
            $label = 'Add note';
            $extra = [
                'action_type' => 'add_note',
                'text'        => $this->stringify($data['text'] ?? $data['note'] ?? $data['message'] ?? ''),
            ];
        } elseif ($type === 'updateattribute' || str_contains($type, 'attribute')) {
            $attr  = $this->stringify($data['attribute'] ?? '');
            $value = $this->stringify($data['attributeNewValue'] ?? $data['value'] ?? '');
            $label = $attr !== '' ? ('Update ' . $attr . ($value !== '' ? ' = ' . $value : '')) : 'Update attribute';
            $extra = ['action_type' => 'set_attribute', 'attribute' => $attr, 'text' => $value];
        } elseif (str_contains($type, 'template')) {
            $label = 'Send WA template';
            $extra = [
                'action_type'   => 'send_template',
                'template_name' => $this->stringify($data['templateName'] ?? $data['template_name'] ?? ''),
                'language'      => $this->stringify($data['language'] ?? $data['language_code'] ?? 'en', 'en'),
            ];
        } elseif (
            $type === 'action'
            || $type === 'send_text'
            || $type === 'sendtext'
            || $type === 'response_message'
            || $type === 'responsemessage'
            || ((str_contains($type, 'message') || str_contains($type, 'text') || str_contains($type, 'whatsapp'))
                && ! str_contains($type, 'incoming')
                && ! str_starts_with($type, 'message_received')
                && ! str_contains($type, 'message_contains')
                && ! str_contains($type, 'message_equals')
                && ! str_contains($type, 'messagecontains')
                && ! str_contains($type, 'messageequals'))
        ) {
            $label = $this->stringify($data['label'] ?? '', 'Response message');
            if ($label === '' || str_ends_with(strtolower($label), ' node')) {
                $label = 'Response message';
            }
            $extra = [
                'action_type' => 'response_message',
                'text'        => $this->stringify($data['text'] ?? $data['message'] ?? $data['body'] ?? ''),
                'branches'    => ! empty($data['branches']),
            ];
        } else {
            // Unknown Cheerio type with a message body still becomes a reply action
            $text = $this->stringify($data['text'] ?? $data['message'] ?? $data['body'] ?? '');
            if ($text !== '') {
                $label = $this->stringify($data['label'] ?? '', 'Response message');
                $extra = [
                    'action_type' => 'response_message',
                    'text'        => $text,
                ];
            } else {
                if ($label === '' || str_ends_with(strtolower($label), ' node')) {
                    $label = $cheerioType !== '' ? $cheerioType : 'Action';
                }
                $extra = ['action_type' => 'cheerio_' . preg_replace('/[^a-z0-9_]+/i', '_', $type)];
            }
        }

        return [
            'canvas_type' => 'action',
            'label'       => $label,
            'data'        => $extra,
        ];
    }

    /**
     * Map Cheerio / external trigger node names → local trigger_type keys.
     */
    protected function mapCheerioTriggerType(string $type): string
    {
        $type = strtolower($type);

        return match (true) {
            in_array($type, ['incomingmessage', 'incomingwa', 'incoming_message', 'message_received'], true) => 'incoming_message',
            in_array($type, ['contactcreated', 'contact_created', 'newcontact'], true) => 'contact_created',
            in_array($type, ['facebooklead', 'facebook_lead'], true) => 'facebook_lead',
            in_array($type, ['incomingwebhook', 'incoming_webhook'], true) => 'incoming_webhook',
            in_array($type, ['leadform', 'form_response', 'formresponse', 'newformresponse', 'formswfnode', 'forms'], true)
                || str_contains($type, 'formswf') => 'form_response',
            in_array($type, ['campaignsent', 'campaign_sent'], true) => 'campaign_sent',
            str_contains($type, 'shopify') => 'shopify_event',
            str_contains($type, 'kylas') && str_contains($type, 'update') => 'kylas_event_update',
            str_contains($type, 'kylas') => 'kylas_event_create',
            str_contains($type, 'pabbly') => 'pabbly_event',
            str_contains($type, 'messenger') => 'messenger',
            str_contains($type, 'instagram') => 'instagram',
            str_contains($type, 'commerce') => 'commerce_event',
            default => 'cheerio_workflow',
        };
    }
}
