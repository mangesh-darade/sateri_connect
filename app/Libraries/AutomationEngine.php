<?php

namespace App\Libraries;

use App\Models\AutomationModel;
use App\Models\AutomationRuleModel;
use App\Models\ContactModel;
use App\Models\NotificationModel;
use Config\Services;
use RuntimeException;
use Throwable;

/**
 * Rule-based automation engine for WhatsApp platform triggers.
 */
class AutomationEngine
{
    protected AutomationModel $automations;
    protected AutomationRuleModel $rules;
    protected ContactModel $contacts;
    protected QueueService $queue;
    protected WhatsAppCloudAPI $api;
    protected ActivityLogger $logger;

    public function __construct(
        ?AutomationModel $automations = null,
        ?AutomationRuleModel $rules = null,
        ?ContactModel $contacts = null,
        ?QueueService $queue = null,
        ?WhatsAppCloudAPI $api = null,
        ?ActivityLogger $logger = null
    ) {
        $this->automations = $automations ?? model(AutomationModel::class);
        $this->rules       = $rules ?? model(AutomationRuleModel::class);
        $this->contacts    = $contacts ?? model(ContactModel::class);
        $this->queue       = $queue ?? new QueueService();
        $this->api         = $api ?? new WhatsAppCloudAPI();
        $this->logger      = $logger ?? new ActivityLogger();
    }

    /**
     * Process all active automations matching a trigger type (including UI aliases).
     *
     * @param array<string, mixed> $context
     *
     * @return array{matched: int, executed: int, errors: list<string>}
     */
    public function processTrigger(string $triggerType, array $context = []): array
    {
        $result = ['matched' => 0, 'executed' => 0, 'errors' => []];
        $types  = $this->triggerAliases($triggerType);

        $automations = $this->automations
            ->where('is_active', 1)
            ->whereIn('trigger_type', $types)
            ->orderBy('priority', 'ASC')
            ->findAll();

        foreach ($automations as $automation) {
            $triggerConfig = $this->decodeJson($automation['trigger_config'] ?? null);
            // flow_graph metadata keys must not filter context
            unset($triggerConfig['_flow'], $triggerConfig['flow_graph']);

            if (! $this->matchesTriggerConfig($triggerConfig, $context)) {
                continue;
            }

            $result['matched']++;

            try {
                $this->runAutomation((int) $automation['id'], $context);
                $result['executed']++;
            } catch (Throwable $e) {
                $msg = sprintf('Automation #%d failed: %s', $automation['id'], $e->getMessage());
                $result['errors'][] = $msg;
                log_message('error', $msg);
            }
        }

        return $result;
    }

    /**
     * UI trigger names ↔ runtime webhook/cron names.
     *
     * @return list<string>
     */
    public function triggerAliases(string $triggerType): array
    {
        $map = [
            'message_received'   => ['message_received', 'incoming_message'],
            'incoming_message'   => ['message_received', 'incoming_message'],
            'keyword'            => ['keyword', 'keyword_matched'],
            'keyword_matched'    => ['keyword', 'keyword_matched'],
            'birthday'           => ['birthday', 'schedule'],
            'schedule'           => ['birthday', 'schedule'],
            'contact_created'    => ['contact_created'],
            'tag_added'          => ['tag_added'],
            'campaign_replied'   => ['campaign_replied'],
            'campaign_sent'      => ['campaign_sent'],
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

        return $map[$triggerType] ?? [$triggerType];
    }

    /**
     * Execute an automation's rule graph starting from the first step.
     *
     * @param array<string, mixed> $context
     */
    public function runAutomation(int $automationId, array $context = []): void
    {
        $context['automation_id'] = $automationId;
        $context = $this->enrichContactContext($context);

        $rules = $this->rules
            ->where('automation_id', $automationId)
            ->orderBy('step_order', 'ASC')
            ->findAll();

        if ($rules === []) {
            return;
        }

        $byId   = [];
        $byStep = [];
        foreach ($rules as $rule) {
            $byId[(int) $rule['id']] = $rule;
            $byStep[(int) $rule['step_order']] = $rule;
        }

        $current = $rules[0];
        $guard   = 0;

        while ($current !== null && $guard < 100) {
            $guard++;
            $config = $this->decodeJson($current['config'] ?? null);
            $type   = (string) ($current['rule_type'] ?? 'action');

            if ($type === 'condition') {
                $config['_preset'] = (string) ($current['action_type'] ?? $config['preset'] ?? '');
                $passed = $this->evaluateCondition($config, $context);
                $nextRef = $passed
                    ? ($current['next_on_true'] ?? null)
                    : ($current['next_on_false'] ?? null);

                $current = $this->resolveNext($rules, $byId, $byStep, $current, $nextRef);
                continue;
            }

            $actionType = $this->normalizeActionType((string) ($current['action_type'] ?? ''));
            unset($context['_action_failed'], $context['_http_status'], $context['_stop_automation']);
            $this->executeAction($actionType, $config, $context);

            // Delay (and similar) pause the graph; resume via automation_delayed_jobs.
            if (! empty($context['_stop_automation'])) {
                if (empty($context['_delay_scheduled']) && ! empty($context['_delayed_until'])) {
                    $this->scheduleDelayedResume(
                        $automationId,
                        $current,
                        $context,
                        (string) $context['_delayed_until']
                    );
                }
                break;
            }

            if (! empty($current['next_on_false']) && ! empty($context['_action_failed'])) {
                $nextRef = $current['next_on_false'];
            } else {
                $nextRef = $current['next_on_true'] ?? null;
            }
            $current = $this->resolveNext($rules, $byId, $byStep, $current, $nextRef);
        }
    }

    /**
     * Resume an automation from a specific rule (used by delayed jobs).
     *
     * @param array<string, mixed> $context
     */
    public function resumeFromRule(int $automationId, ?int $resumeRuleId, array $context = []): void
    {
        $context['automation_id'] = $automationId;
        $context = $this->enrichContactContext($context);

        $rules = $this->rules
            ->where('automation_id', $automationId)
            ->orderBy('step_order', 'ASC')
            ->findAll();

        if ($rules === []) {
            return;
        }

        $byId   = [];
        $byStep = [];
        foreach ($rules as $rule) {
            $byId[(int) $rule['id']] = $rule;
            $byStep[(int) $rule['step_order']] = $rule;
        }

        $current = null;
        if ($resumeRuleId === null) {
            // Terminal delay — do not restart the graph from step 1.
            return;
        }
        if (isset($byId[$resumeRuleId])) {
            $current = $byId[$resumeRuleId];
        } elseif (isset($byStep[$resumeRuleId])) {
            // Legacy jobs may have stored step_order instead of rule id.
            $current = $byStep[$resumeRuleId];
        } else {
            log_message('error', 'Delay resume rule #{id} missing for automation #{a}', [
                'id' => $resumeRuleId,
                'a'  => $automationId,
            ]);

            return;
        }

        $guard = 0;
        while ($current !== null && $guard < 100) {
            $guard++;
            $config = $this->decodeJson($current['config'] ?? null);
            $type   = (string) ($current['rule_type'] ?? 'action');

            if ($type === 'condition') {
                $config['_preset'] = (string) ($current['action_type'] ?? $config['preset'] ?? '');
                $passed = $this->evaluateCondition($config, $context);
                $nextRef = $passed
                    ? ($current['next_on_true'] ?? null)
                    : ($current['next_on_false'] ?? null);
                $current = $this->resolveNext($rules, $byId, $byStep, $current, $nextRef);
                continue;
            }

            $actionType = $this->normalizeActionType((string) ($current['action_type'] ?? ''));
            unset($context['_action_failed'], $context['_http_status'], $context['_stop_automation'], $context['_delay_scheduled']);
            $this->executeAction($actionType, $config, $context);

            if (! empty($context['_stop_automation'])) {
                if (empty($context['_delay_scheduled']) && ! empty($context['_delayed_until'])) {
                    $this->scheduleDelayedResume($automationId, $current, $context, (string) $context['_delayed_until']);
                }
                break;
            }

            if (! empty($current['next_on_false']) && ! empty($context['_action_failed'])) {
                $nextRef = $current['next_on_false'];
            } else {
                $nextRef = $current['next_on_true'] ?? null;
            }
            $current = $this->resolveNext($rules, $byId, $byStep, $current, $nextRef);
        }
    }

    /**
     * @param list<array<string, mixed>>          $rules
     * @param array<int, array<string, mixed>>    $byId
     * @param array<int, array<string, mixed>>    $byStep
     * @param array<string, mixed>                $current
     *
     * @return array<string, mixed>|null
     */
    protected function resolveNext(array $rules, array $byId, array $byStep, array $current, mixed $nextRef): ?array
    {
        if ($nextRef === null || $nextRef === '') {
            return $this->nextSequential($rules, $current);
        }

        $ref = (int) $nextRef;
        if (isset($byId[$ref])) {
            return $byId[$ref];
        }
        if (isset($byStep[$ref])) {
            return $byStep[$ref];
        }

        return $this->nextSequential($rules, $current);
    }

    protected function normalizeActionType(string $actionType): string
    {
        return match ($actionType) {
            'webhook' => 'webhook_call',
            'http' => 'http_request',
            'note', 'add_note' => 'add_note',
            'response_message', 'responseMessage', 'responsemessage',
            'cheerio_action', 'action', 'sendtext' => 'send_text',
            'system_initiated', 'systemInitiated', 'systeminitiated' => 'system_initiated',
            'collect_images', 'collectImages', 'collectimages' => 'collect_images',
            'send_wa_template', 'sendTemplate' => 'send_template',
            'cheerio_addtolabel', 'addtolabel', 'add_to_label' => 'add_tag',
            'cheerio_removefromlabel', 'removefromlabel', 'remove_from_label' => 'remove_tag',
            'cheerio_timedelay', 'timedelay', 'time_delay' => 'delay',
            'cheerio_updateattribute', 'updateattribute' => 'set_attribute',
            'cheerio_assignagent', 'assignagent' => 'assign_agent',
            'send_email', 'sendemail', 'email' => 'send_email',
            'update_chat_status', 'updatechatstatus', 'chat_status' => 'update_chat_status',
            'assign_bot', 'assignbot', 'cheerio_assignchattobot' => 'assign_bot',
            default => $actionType,
        };
    }

    /**
     * Evaluate IF / ELSE style conditions.
     *
     * @param array<string, mixed> $config
     * @param array<string, mixed> $context
     */
    public function evaluateCondition(array $config, array $context): bool
    {
        $preset = strtolower((string) ($config['_preset'] ?? $config['preset'] ?? $config['type'] ?? ''));
        if ($preset !== '') {
            $config = $this->expandConditionPreset($preset, $config, $context);
        }

        $operator = strtolower((string) ($config['operator'] ?? $config['op'] ?? 'equals'));
        $field    = (string) ($config['field'] ?? '');
        $expected = $config['value'] ?? null;
        $actual   = $this->resolveField($field, $context);

        // has_tag special: field may be empty
        if ($operator === 'has_tag' || $preset === 'has_tag') {
            $tagId = (int) ($config['tag_id'] ?? $expected ?? 0);
            $contactId = (int) ($context['contact_id'] ?? 0);
            if ($contactId <= 0 || $tagId <= 0) {
                return false;
            }
            $count = db_connect()->table('contact_tags')
                ->where(['contact_id' => $contactId, 'tag_id' => $tagId])
                ->countAllResults();

            return $count > 0;
        }

        if ($preset === 'within_window') {
            $contact = $context['contact'] ?? null;
            if (! is_array($contact) && ! empty($context['contact_id'])) {
                $contact = $this->contacts->find((int) $context['contact_id']);
            }
            $last = is_array($contact) ? ($contact['last_reply_at'] ?? null) : ($context['last_reply_at'] ?? null);

            return function_exists('is_within_24h_window') && is_within_24h_window($last);
        }

        return match ($operator) {
            'equals', 'eq', '==' => $this->looseEquals($actual, $expected),
            'not_equals', 'neq', '!=' => ! $this->looseEquals($actual, $expected),
            'contains' => is_string($actual) && is_string($expected)
                && str_contains(mb_strtolower((string) $actual), mb_strtolower((string) $expected)),
            'not_contains' => is_string($actual) && is_string($expected)
                && ! str_contains(mb_strtolower((string) $actual), mb_strtolower((string) $expected)),
            'starts_with' => is_string($actual) && is_string($expected)
                && str_starts_with(mb_strtolower((string) $actual), mb_strtolower((string) $expected)),
            'gt', '>' => is_numeric($actual) && is_numeric($expected) && (float) $actual > (float) $expected,
            'gte', '>=' => is_numeric($actual) && is_numeric($expected) && (float) $actual >= (float) $expected,
            'lt', '<' => is_numeric($actual) && is_numeric($expected) && (float) $actual < (float) $expected,
            'lte', '<=' => is_numeric($actual) && is_numeric($expected) && (float) $actual <= (float) $expected,
            'in' => is_array($expected) && in_array($actual, $expected, false),
            'empty' => $actual === null || $actual === '' || $actual === [],
            'not_empty' => ! ($actual === null || $actual === '' || $actual === []),
            'and' => $this->evaluateComposite($config['conditions'] ?? [], $context, true),
            'or' => $this->evaluateComposite($config['conditions'] ?? [], $context, false),
            default => false,
        };
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    protected function expandConditionPreset(string $preset, array $config, array $context): array
    {
        return match ($preset) {
            'message_contains' => [
                'operator' => 'contains',
                'field'    => 'content',
                'value'    => $config['value'] ?? '',
            ] + $config,
            'message_equals' => [
                'operator' => 'equals',
                'field'    => 'content',
                'value'    => $config['value'] ?? '',
            ] + $config,
            'caption_contains' => [
                'operator' => 'contains',
                'field'    => 'content',
                'value'    => $config['value'] ?? '',
            ] + $config,
            'message_type' => [
                'operator' => 'equals',
                'field'    => 'message_type',
                'value'    => $config['value'] ?? 'text',
            ] + $config,
            'contact_status' => [
                'operator' => 'equals',
                'field'    => 'contact.status',
                'value'    => $config['value'] ?? 'active',
            ] + $config,
            'attribute_condition' => [
                'operator' => strtolower((string) ($config['operator'] ?? 'equals')),
                'field'    => (static function (array $config): string {
                    $attr = trim((string) ($config['attribute'] ?? $config['field'] ?? ''));
                    if ($attr !== '' && ! str_contains($attr, '.')) {
                        return 'contact.' . $attr;
                    }

                    return $attr;
                })($config),
                'value'    => $config['value'] ?? '',
            ] + $config,
            'has_tag', 'within_window' => $config,
            default => $config,
        };
    }

    /**
     * Execute a single automation action.
     *
     * @param array<string, mixed> $config
     * @param array<string, mixed> $context
     */
    public function executeAction(string $actionType, array $config, array &$context): void
    {
        $contactId = (int) ($context['contact_id'] ?? 0);

        switch ($actionType) {
            case 'send_template':
            case 'system_initiated':
                if ($contactId <= 0) {
                    throw new RuntimeException($actionType . ' requires contact_id in context.');
                }
                $this->queue->enqueue($contactId, 'template', [
                    'template_name' => $config['template_name'] ?? $config['name'] ?? '',
                    'language'      => $config['language'] ?? 'en',
                    'components'    => $config['components'] ?? [],
                ], null, (int) ($config['priority'] ?? 3));
                break;

            case 'send_text':
                if ($contactId <= 0) {
                    throw new RuntimeException('send_text requires contact_id in context.');
                }
                $text = $this->interpolate((string) ($config['text'] ?? $config['body'] ?? ''), $context);
                $this->queue->enqueue($contactId, 'text', [
                    'text' => $text,
                ], null, (int) ($config['priority'] ?? 3));
                break;

            case 'collect_images':
                if ($contactId <= 0) {
                    throw new RuntimeException('collect_images requires contact_id in context.');
                }
                $contact = $this->contacts->find($contactId);
                $fields  = [];
                if (is_array($contact)) {
                    $raw = $contact['custom_fields'] ?? [];
                    if (is_string($raw)) {
                        $decoded = json_decode($raw, true);
                        $fields  = is_array($decoded) ? $decoded : [];
                    } elseif (is_array($raw)) {
                        $fields = $raw;
                    }
                }
                $count  = max(1, (int) ($config['count'] ?? $config['max_images'] ?? 1));
                $prompt = $this->interpolate((string) ($config['prompt'] ?? $config['text'] ?? ''), $context);
                $fields['_await_images'] = [
                    'automation_id' => (int) ($context['automation_id'] ?? 0),
                    'count'         => $count,
                    'collected'     => [],
                    'prompt'        => $prompt,
                    'at'            => date('c'),
                ];
                $this->contacts->update($contactId, ['custom_fields' => $fields]);
                if ($prompt !== '') {
                    $this->queue->enqueue($contactId, 'text', [
                        'text' => $prompt,
                    ], null, (int) ($config['priority'] ?? 3));
                }
                $context['_paused_for_images'] = true;
                break;

            case 'delay':
                $seconds = (int) ($config['seconds'] ?? 0);
                if ($seconds <= 0 && isset($config['minutes'])) {
                    $seconds = (int) $config['minutes'] * 60;
                }
                if ($seconds <= 0) {
                    $seconds = (int) ($config['delay'] ?? 60);
                }
                $seconds = max(1, $seconds);
                if ($contactId > 0 && ! empty($config['follow_up'])) {
                    $followUp = is_array($config['follow_up']) ? $config['follow_up'] : [];
                    $type     = (string) ($followUp['message_type'] ?? 'text');
                    $this->queue->enqueue(
                        $contactId,
                        $type,
                        $followUp,
                        null,
                        (int) ($config['priority'] ?? 5),
                        date('Y-m-d H:i:s', time() + $seconds)
                    );
                }
                $context['_delayed_until']   = date('Y-m-d H:i:s', time() + $seconds);
                $context['_stop_automation'] = true;
                break;

            case 'assign_agent':
                if ($contactId > 0) {
                    $agentId = (int) ($config['user_id'] ?? $config['agent_id'] ?? 0);
                    $this->contacts->update($contactId, ['assigned_to' => $agentId ?: null]);
                    db_connect()->table('conversations')
                        ->where('contact_id', $contactId)
                        ->update(['assigned_to' => $agentId ?: null]);
                }
                break;

            case 'assign_bot':
                if ($contactId > 0) {
                    $channel = strtolower((string) ($context['channel'] ?? 'whatsapp')) ?: 'whatsapp';
                    $payload = [
                        'status'      => \App\Libraries\InboxStatus::CHATBOT,
                        'assigned_to' => null,
                    ];
                    if (db_connect()->fieldExists('intervened_at', 'conversations')) {
                        $payload['intervened_at'] = null;
                    }
                    $conv = model(\App\Models\ConversationModel::class)->findOrCreateForContact($contactId, $channel);
                    model(\App\Models\ConversationModel::class)->update((int) $conv['id'], $payload);
                    $this->contacts->update($contactId, ['assigned_to' => null]);
                }
                break;

            case 'update_chat_status':
                if ($contactId > 0) {
                    $status  = \App\Libraries\InboxStatus::normalize((string) ($config['status'] ?? $config['value'] ?? 'open'));
                    if (! \App\Libraries\InboxStatus::isWritable($status)) {
                        $status = \App\Libraries\InboxStatus::OPEN;
                    }
                    $channel = strtolower((string) ($context['channel'] ?? 'whatsapp')) ?: 'whatsapp';
                    $conv    = model(\App\Models\ConversationModel::class)->findOrCreateForContact($contactId, $channel);
                    $payload = ['status' => $status];
                    if ($status === \App\Libraries\InboxStatus::INTERVENED
                        && db_connect()->fieldExists('intervened_at', 'conversations')) {
                        $payload['intervened_at'] = date('Y-m-d H:i:s');
                    }
                    model(\App\Models\ConversationModel::class)->update((int) $conv['id'], $payload);
                }
                break;

            case 'send_email':
                $this->sendContactEmail($config, $context);
                break;

            case 'add_tag':
                $tagId = (int) ($config['tag_id'] ?? 0);
                if ($tagId <= 0) {
                    $tagId = $this->resolveTagId((string) ($config['tag_name'] ?? $config['labelName'] ?? ''));
                }
                $this->syncTag($contactId, $tagId, true);
                break;

            case 'remove_tag':
                $tagId = (int) ($config['tag_id'] ?? 0);
                if ($tagId <= 0) {
                    $tagId = $this->resolveTagId((string) ($config['tag_name'] ?? $config['labelName'] ?? ''));
                }
                $this->syncTag($contactId, $tagId, false);
                break;

            case 'set_attribute':
                if ($contactId <= 0) {
                    break;
                }
                $attr  = trim((string) ($config['attribute'] ?? ''));
                $value = $this->interpolate((string) ($config['text'] ?? $config['value'] ?? $config['attributeNewValue'] ?? ''), $context);
                if ($attr === '') {
                    break;
                }
                $columns = ['name', 'email', 'country', 'notes', 'status', 'birthday', 'mobile'];
                if (! isset($context['attributes']) || ! is_array($context['attributes'])) {
                    $context['attributes'] = [];
                }
                if (in_array($attr, $columns, true)) {
                    $this->contacts->update($contactId, [$attr => $value !== '' ? $value : null]);
                    if (! isset($context['contact']) || ! is_array($context['contact'])) {
                        $context['contact'] = [];
                    }
                    $context['contact'][$attr]       = $value;
                    $context['attributes'][$attr]    = $value;
                    $context[$attr]                  = $value;
                    break;
                }
                $contact = $this->contacts->find($contactId);
                $fields  = [];
                if (is_array($contact)) {
                    $raw = $contact['custom_fields'] ?? [];
                    if (is_string($raw)) {
                        $decoded = json_decode($raw, true);
                        $fields  = is_array($decoded) ? $decoded : [];
                    } elseif (is_array($raw)) {
                        $fields = $raw;
                    }
                }
                $fields[$attr] = $value;
                $this->contacts->update($contactId, ['custom_fields' => $fields]);
                // Keep runtime context in sync for later webhook mapping in same run
                $context['contact'] ??= [];
                if (is_array($context['contact'])) {
                    $context['contact']['custom_fields'] = $fields;
                    $context['contact'][$attr]           = $value;
                }
                $context['attributes'][$attr] = $value;
                $context[$attr]               = $value;
                break;

            case 'webhook_call':
            case 'http_request':
                $this->httpRequest($config, $context);
                break;

            case 'add_note':
                if ($contactId > 0) {
                    $note = $this->interpolate((string) ($config['text'] ?? $config['note'] ?? $config['value'] ?? ''), $context);
                    if ($note !== '') {
                        model(\App\Models\InternalNoteModel::class)->skipValidation(true)->insert([
                            'contact_id'  => $contactId,
                            'user_id'     => (int) ($config['user_id'] ?? $context['user_id'] ?? 1) ?: 1,
                            'note'        => $note,
                            'is_internal' => 1,
                        ]);
                    }
                }
                break;

            case 'email_notification':
                $this->sendEmailNotification($config, $context);
                break;

            default:
                // Cheerio / unknown nodes that still carry outbound text should reply
                $fallbackText = trim((string) ($config['text'] ?? $config['message'] ?? $config['body'] ?? ''));
                if ($contactId > 0 && $fallbackText !== '') {
                    $this->queue->enqueue($contactId, 'text', [
                        'text' => $this->interpolate($fallbackText, $context),
                    ], null, (int) ($config['priority'] ?? 3));
                    break;
                }
                log_message('warning', 'Unknown automation action: {type}', ['type' => $actionType]);
        }
    }

    /**
     * Process delayed / scheduled automation follow-ups already in the queue
     * and time-based triggers (e.g. birthday). Returns number of triggers processed.
     */
    public function processPending(): int
    {
        $count = 0;
        $count += $this->processDelayedJobs();
        $count += $this->processBirthdayTriggers();

        return $count;
    }

    /**
     * Resume workflows paused on Delay nodes.
     */
    public function processDelayedJobs(): int
    {
        $db = db_connect();
        if (! $db->tableExists('automation_delayed_jobs')) {
            return 0;
        }

        $now  = date('Y-m-d H:i:s');
        $jobs = $db->table('automation_delayed_jobs')
            ->where('status', 'pending')
            ->where('run_at <=', $now)
            ->orderBy('run_at', 'ASC')
            ->limit(50)
            ->get()
            ->getResultArray();

        $count = 0;
        foreach ($jobs as $job) {
            $jobId = (int) $job['id'];
            // Claim atomically — skip if another worker already took it
            $db->table('automation_delayed_jobs')
                ->where('id', $jobId)
                ->where('status', 'pending')
                ->update(['status' => 'processing', 'updated_at' => $now]);
            if ($db->affectedRows() !== 1) {
                continue;
            }

            try {
                $context = $this->decodeJson($job['context_json'] ?? null);
                $context['contact_id'] = (int) ($job['contact_id'] ?? $context['contact_id'] ?? 0);
                unset($context['_stop_automation'], $context['_delayed_until'], $context['_delay_scheduled']);
                $resumeRuleId = isset($job['resume_rule_id']) && $job['resume_rule_id'] !== null && $job['resume_rule_id'] !== ''
                    ? (int) $job['resume_rule_id']
                    : null;
                $this->resumeFromRule((int) $job['automation_id'], $resumeRuleId, $context);
                $db->table('automation_delayed_jobs')->where('id', $jobId)->update([
                    'status'     => 'done',
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
                $count++;
            } catch (Throwable $e) {
                $db->table('automation_delayed_jobs')->where('id', $jobId)->update([
                    'status'     => 'failed',
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
                log_message('error', 'Delayed automation job #{id} failed: {msg}', [
                    'id'  => $jobId,
                    'msg' => $e->getMessage(),
                ]);
            }
        }

        return $count;
    }

    protected function processBirthdayTriggers(): int
    {
        // Time-based birthday trigger for contacts celebrating today
        $today = date('m-d');
        $contacts = db_connect()->table('contacts')
            ->where('status', 'active')
            ->where('deleted_at', null)
            ->where("DATE_FORMAT(birthday, '%m-%d')", $today)
            ->get()
            ->getResultArray();

        $count = 0;
        foreach ($contacts as $contact) {
            $contactId = (int) $contact['id'];
            $cacheKey  = 'automation_birthday_' . $contactId . '_' . date('Y-m-d');

            if (cache()->get($cacheKey)) {
                continue;
            }

            // Persistent dedupe across workers / cache flush
            $already = db_connect()->table('activity_logs')
                ->where('module', 'automations')
                ->where('action', 'birthday')
                ->like('metadata', '"contact_id":' . $contactId, 'both')
                ->where('created_at >=', date('Y-m-d 00:00:00'))
                ->countAllResults();

            if ($already > 0) {
                cache()->save($cacheKey, 1, 86400);
                continue;
            }

            $this->processTrigger('birthday', [
                'contact_id' => $contactId,
                'contact'    => $contact,
            ]);

            $this->logger->log('birthday', 'automations', 'Birthday automation fired', [
                'contact_id' => $contactId,
                'date'       => date('Y-m-d'),
            ]);
            cache()->save($cacheKey, 1, 86400);
            $count++;
        }

        return $count;
    }

    /**
     * @param array<string, mixed> $currentRule Delay rule row
     * @param array<string, mixed> $context
     */
    protected function scheduleDelayedResume(int $automationId, array $currentRule, array $context, string $runAt): void
    {
        $db = db_connect();
        if (! $db->tableExists('automation_delayed_jobs')) {
            return;
        }

        $resumeRuleId = null;
        $rules = $this->rules
            ->where('automation_id', $automationId)
            ->orderBy('step_order', 'ASC')
            ->findAll();
        $byId   = [];
        $byStep = [];
        foreach ($rules as $rule) {
            $byId[(int) $rule['id']] = $rule;
            $byStep[(int) $rule['step_order']] = $rule;
        }
        // next_on_true from WorkflowGraph is 1-based step_order (not always DB id).
        $next = $this->resolveNext($rules, $byId, $byStep, $currentRule, $currentRule['next_on_true'] ?? null);
        if (is_array($next)) {
            $resumeRuleId = (int) $next['id'];
        }

        // Strip non-serializable / control keys
        $ctx = $context;
        unset(
            $ctx['_stop_automation'],
            $ctx['_delayed_until'],
            $ctx['_delay_scheduled'],
            $ctx['_action_failed'],
            $ctx['_paused_for_images'],
            $ctx['_current_rule']
        );

        $db->table('automation_delayed_jobs')->insert([
            'automation_id'  => $automationId,
            'contact_id'     => (int) ($context['contact_id'] ?? 0) ?: null,
            'resume_rule_id' => $resumeRuleId,
            'context_json'   => json_encode($ctx),
            'run_at'         => $runAt,
            'status'         => 'pending',
            'created_at'     => date('Y-m-d H:i:s'),
            'updated_at'     => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Send email to contact (or explicit `to`) via EmailProvider.
     *
     * @param array<string, mixed> $config
     * @param array<string, mixed> $context
     */
    protected function sendContactEmail(array $config, array &$context): void
    {
        $to = trim((string) ($config['to'] ?? ''));
        if ($to === '') {
            $contact = $context['contact'] ?? null;
            if (! is_array($contact) && ! empty($context['contact_id'])) {
                $contact = $this->contacts->find((int) $context['contact_id']);
            }
            $to = is_array($contact) ? trim((string) ($contact['email'] ?? '')) : '';
        }
        $to = $this->interpolate($to, $context);
        $subject = $this->interpolate((string) ($config['subject'] ?? 'Message'), $context);
        $body    = $this->interpolate((string) ($config['text'] ?? $config['body'] ?? $config['message'] ?? ''), $context);

        if ($to === '' || $body === '') {
            $context['_action_failed'] = true;
            log_message('warning', 'send_email skipped: missing to/body');

            return;
        }

        $result = service('emailProvider')->send($to, $subject, $body);
        if (! ($result['ok'] ?? false)) {
            $context['_action_failed'] = true;
            log_message('error', 'Automation send_email failed: {debug}', ['debug' => $result['message'] ?? 'unknown']);
        }
    }

    /**
     * @param array<string, mixed> $triggerConfig
     * @param array<string, mixed> $context
     */
    protected function matchesTriggerConfig(array $triggerConfig, array $context): bool
    {
        if ($triggerConfig === []) {
            return true;
        }

        if (isset($triggerConfig['conditions']) && is_array($triggerConfig['conditions'])) {
            return $this->evaluateCondition([
                'operator'   => $triggerConfig['logic'] ?? 'and',
                'conditions' => $triggerConfig['conditions'],
            ], $context);
        }

        foreach ($triggerConfig as $key => $value) {
            if (in_array($key, [
                'logic', 'conditions', '_flow', 'flow_graph',
                'label', 'prompt', 'description', 'message', 'help', 'help_text',
                'adName', 'labelName',
            ], true)) {
                continue;
            }
            if ($value === null || $value === '') {
                continue;
            }
            if ($key === 'keyword' || $key === 'content') {
                $hay = (string) ($context['content'] ?? $context['text'] ?? $context['keyword'] ?? '');
                if (! str_contains(mb_strtolower($hay), mb_strtolower((string) $value))) {
                    return false;
                }
                continue;
            }
            if ($key === 'shopify_topic') {
                $actual = (string) ($context['shopify_topic'] ?? $context['event_topic'] ?? $context['topic'] ?? '');
                if ($actual !== '' && ! $this->looseEquals($actual, $value)) {
                    return false;
                }
                continue;
            }
            $actual = $this->resolveField($key, $context);
            // Missing context key = don't filter yet (event not wired / optional)
            if ($actual === null || $actual === '') {
                continue;
            }
            if (! $this->looseEquals($actual, $value)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<array<string, mixed>> $conditions
     * @param array<string, mixed>       $context
     */
    protected function evaluateComposite(array $conditions, array $context, bool $requireAll): bool
    {
        if ($conditions === []) {
            return true;
        }

        foreach ($conditions as $condition) {
            $result = $this->evaluateCondition($condition, $context);
            if ($requireAll && ! $result) {
                return false;
            }
            if (! $requireAll && $result) {
                return true;
            }
        }

        return $requireAll;
    }

    /**
     * @param array<string, mixed> $context
     */
    protected function resolveField(string $field, array $context): mixed
    {
        if ($field === '') {
            return null;
        }

        // Friendly aliases from workflow UI
        $aliases = [
            'content' => ['content', 'text', 'message', 'body'],
            'text'    => ['text', 'content', 'message'],
            'message' => ['content', 'text', 'message'],
        ];
        if (isset($aliases[$field])) {
            foreach ($aliases[$field] as $key) {
                if (array_key_exists($key, $context) && $context[$key] !== null && $context[$key] !== '') {
                    return $context[$key];
                }
            }
        }

        if (array_key_exists($field, $context)) {
            return $context[$field];
        }

        // Dot notation: contact.name, message.text, attributes.city
        $parts = explode('.', $field);
        $value = $context;
        foreach ($parts as $i => $part) {
            if (! is_array($value) || ! array_key_exists($part, $value)) {
                // Lazy-load contact row
                if ($part === 'contact' && ! empty($context['contact_id']) && ! isset($context['contact'])) {
                    $contact = $this->contacts->find((int) $context['contact_id']);
                    if (is_array($contact)) {
                        $value = ['contact' => $contact];
                        continue;
                    }
                }

                // contact.customField → look inside custom_fields / attributes
                if ($i > 0 && is_array($value)) {
                    $cf = $value['custom_fields'] ?? null;
                    if (is_string($cf)) {
                        $decoded = json_decode($cf, true);
                        $cf      = is_array($decoded) ? $decoded : null;
                    }
                    if (is_array($cf) && array_key_exists($part, $cf)) {
                        $value = $cf[$part];
                        continue;
                    }
                }

                return null;
            }
            $value = $value[$part];
        }

        return $value;
    }

    protected function looseEquals(mixed $a, mixed $b): bool
    {
        if (is_string($a) && is_string($b)) {
            return mb_strtolower($a) === mb_strtolower($b);
        }

        return $a == $b; // intentional loose comparison for numeric strings
    }

    /**
     * @param list<array<string, mixed>> $rules
     * @param array<string, mixed>       $current
     *
     * @return array<string, mixed>|null
     */
    protected function nextSequential(array $rules, array $current): ?array
    {
        $found = false;
        foreach ($rules as $rule) {
            if ($found) {
                return $rule;
            }
            if ((int) $rule['id'] === (int) $current['id']) {
                $found = true;
            }
        }

        return null;
    }

    protected function syncTag(int $contactId, int $tagId, bool $add): void
    {
        if ($contactId <= 0 || $tagId <= 0) {
            return;
        }

        $db = db_connect();
        if ($add) {
            $exists = $db->table('contact_tags')
                ->where(['contact_id' => $contactId, 'tag_id' => $tagId])
                ->countAllResults();
            if ($exists === 0) {
                $db->table('contact_tags')->insert([
                    'contact_id' => $contactId,
                    'tag_id'     => $tagId,
                ]);
            }
        } else {
            $db->table('contact_tags')
                ->where(['contact_id' => $contactId, 'tag_id' => $tagId])
                ->delete();
        }
    }

    /**
     * Ensure contact + flattened attributes are available for {{placeholders}} / webhook values.
     *
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    protected function enrichContactContext(array $context): array
    {
        $contactId = (int) ($context['contact_id'] ?? 0);
        $contact   = $context['contact'] ?? null;

        if (! is_array($contact) && $contactId > 0) {
            $contact = $this->contacts->find($contactId);
        }

        if (! is_array($contact)) {
            return $context;
        }

        $context['contact'] = $contact;
        if ($contactId <= 0 && ! empty($contact['id'])) {
            $context['contact_id'] = (int) $contact['id'];
        }

        $attrs = ContactAttributes::flatten($contact);
        $context['attributes'] = array_merge($attrs, is_array($context['attributes'] ?? null) ? $context['attributes'] : []);

        // Bare attribute keys for Cheerio-style {{name}} / values[] lookups
        foreach ($attrs as $key => $value) {
            if (! array_key_exists($key, $context) || $context[$key] === null || $context[$key] === '') {
                $context[$key] = $value;
            }
        }

        return $context;
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $context
     */
    protected function httpRequest(array $config, array &$context): void
    {
        $url    = $this->interpolate((string) ($config['url'] ?? ''), $context);
        $method = strtoupper((string) ($config['method'] ?? 'POST'));
        if ($url === '') {
            throw new RuntimeException('webhook_call/http_request requires url.');
        }

        $body = $this->buildWebhookBody($config, $context);
        $headers = $this->normalizeWebhookHeaders($config, $context);

        $client = Services::curlrequest([
            'timeout'     => (int) ($config['timeout'] ?? 15),
            'http_errors' => false,
        ], null, null, false);

        $options = ['headers' => $headers];
        if ($method !== 'GET') {
            $options['json'] = is_array($body) ? $body : ['payload' => $body];
        } else {
            $options['query'] = is_array($body) ? $body : [];
        }

        try {
            $response = $client->request($method, $url, $options);
            $status   = $response->getStatusCode();
            log_message('info', 'Automation HTTP {method} {url} => {status}', [
                'method' => $method,
                'url'    => $url,
                'status' => $status,
            ]);
            $context['_http_status']   = $status;
            $context['_action_failed'] = $status < 200 || $status >= 300;

            if (! empty($config['saveVariables']) || ! empty($config['savedAttributes'])) {
                $this->persistWebhookResponse($config, $context, (string) $response->getBody());
            }
        } catch (Throwable $e) {
            $context['_action_failed'] = true;
            log_message('error', 'Automation HTTP failed: {error}', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Build JSON body from Cheerio `values` (attribute keys), `mapping`, or explicit `body`.
     *
     * @param array<string, mixed> $config
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>|string
     */
    protected function buildWebhookBody(array $config, array $context): array|string
    {
        $values = $config['values'] ?? null;
        if (is_array($values) && $values !== []) {
            $body = [];
            foreach ($values as $entry) {
                if (is_array($entry)) {
                    $key    = trim((string) ($entry['key'] ?? $entry['name'] ?? $entry['param'] ?? ''));
                    $source = (string) ($entry['value'] ?? $entry['source'] ?? $entry['attribute'] ?? $key);
                    if ($key === '') {
                        continue;
                    }
                    $body[$key] = $this->resolveMappedValue($source, $context);
                    continue;
                }

                $key = trim((string) $entry);
                if ($key === '' || str_starts_with($key, '@')) {
                    // Cheerio system tokens like @business_details — skip or leave blank
                    if ($key !== '') {
                        $body[ltrim($key, '@')] = $this->resolveMappedValue($key, $context);
                    }
                    continue;
                }
                $body[$key] = $this->resolveMappedValue($key, $context);
            }

            return $body;
        }

        $mapping = $config['mapping'] ?? $config['param_map'] ?? null;
        if (is_array($mapping) && $mapping !== []) {
            $body = [];
            foreach ($mapping as $key => $source) {
                $key = trim((string) $key);
                if ($key === '') {
                    continue;
                }
                if (is_array($source)) {
                    $source = (string) ($source['value'] ?? $source['source'] ?? $source['attribute'] ?? '');
                }
                $body[$key] = $this->resolveMappedValue((string) $source, $context);
            }

            return $body;
        }

        if (array_key_exists('body', $config)) {
            $body = $config['body'];
            if (is_string($body)) {
                return $this->interpolate($body, $context);
            }
            if (is_array($body)) {
                return $this->interpolateDeep($body, $context);
            }
        }

        // Sensible default: contact attributes + ids (not full runtime context)
        $attrs = is_array($context['attributes'] ?? null) ? $context['attributes'] : [];
        $out   = $attrs;
        if (! empty($context['contact_id'])) {
            $out['contact_id'] = (int) $context['contact_id'];
        }
        if (! empty($context['automation_id'])) {
            $out['automation_id'] = (int) $context['automation_id'];
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $context
     *
     * @return array<string, string>
     */
    protected function normalizeWebhookHeaders(array $config, array $context): array
    {
        $headers = [];

        // Cheerio singular header: { key, value }
        $header = $config['header'] ?? null;
        if (is_array($header)) {
            $hk = trim((string) ($header['key'] ?? $header['name'] ?? ''));
            $hv = $this->interpolate((string) ($header['value'] ?? ''), $context);
            if ($hk !== '') {
                $headers[$hk] = $hv;
            }
        }

        $raw = $config['headers'] ?? null;
        if (is_array($raw)) {
            $isList = array_is_list($raw);
            foreach ($raw as $k => $v) {
                if ($isList && is_array($v)) {
                    $hk = trim((string) ($v['key'] ?? $v['name'] ?? ''));
                    $hv = $this->interpolate((string) ($v['value'] ?? ''), $context);
                    if ($hk !== '') {
                        $headers[$hk] = $hv;
                    }
                    continue;
                }
                if (is_string($k) && $k !== '') {
                    $headers[$k] = $this->interpolate(is_scalar($v) ? (string) $v : '', $context);
                }
            }
        }

        if ($headers === []) {
            $headers['Content-Type'] = 'application/json';
        }

        return $headers;
    }

    /**
     * Resolve a mapped value: literal, {{placeholder}}, or attribute key.
     *
     * @param array<string, mixed> $context
     */
    protected function resolveMappedValue(string $source, array $context): mixed
    {
        $source = trim($source);
        if ($source === '') {
            return '';
        }

        if (str_contains($source, '{{')) {
            return $this->interpolate($source, $context);
        }

        // Prefer attributes / contact fields for bare keys (Cheerio values[])
        $resolved = $this->resolveField($source, $context);
        if ($resolved !== null && $resolved !== '') {
            return is_scalar($resolved) ? $resolved : json_encode($resolved);
        }

        $resolved = $this->resolveField('contact.' . $source, $context);
        if ($resolved !== null && $resolved !== '') {
            return is_scalar($resolved) ? $resolved : json_encode($resolved);
        }

        $resolved = $this->resolveField('attributes.' . $source, $context);
        if ($resolved !== null) {
            return is_scalar($resolved) ? $resolved : json_encode($resolved);
        }

        return '';
    }

    /**
     * Optionally store webhook JSON response fields into contact attributes.
     *
     * @param array<string, mixed> $config
     * @param array<string, mixed> $context
     */
    protected function persistWebhookResponse(array $config, array &$context, string $rawBody): void
    {
        $contactId = (int) ($context['contact_id'] ?? 0);
        if ($contactId <= 0 || $rawBody === '') {
            return;
        }

        $decoded = json_decode($rawBody, true);
        if (! is_array($decoded)) {
            return;
        }

        $saved = $config['savedAttributes'] ?? [];
        if (! is_array($saved) || $saved === []) {
            return;
        }

        $contact = $this->contacts->find($contactId);
        if (! is_array($contact)) {
            return;
        }

        $fields = [];
        $raw    = $contact['custom_fields'] ?? [];
        if (is_string($raw)) {
            $decodedFields = json_decode($raw, true);
            $fields        = is_array($decodedFields) ? $decodedFields : [];
        } elseif (is_array($raw)) {
            $fields = $raw;
        }

        $columns = ['name', 'email', 'country', 'notes', 'status', 'birthday', 'mobile'];
        $columnUpdates = [];

        foreach ($saved as $entry) {
            $attrKey = '';
            $path    = '';
            if (is_string($entry)) {
                $attrKey = trim($entry);
                $path    = $attrKey;
            } elseif (is_array($entry)) {
                $attrKey = trim((string) ($entry['attribute'] ?? $entry['key'] ?? $entry['name'] ?? ''));
                $path    = trim((string) ($entry['path'] ?? $entry['from'] ?? $entry['value'] ?? $attrKey));
            }
            if ($attrKey === '') {
                continue;
            }

            $value = $this->resolveField($path, $decoded);
            if ($value === null) {
                $value = $decoded[$path] ?? null;
            }
            if ($value === null) {
                continue;
            }
            if (! is_scalar($value)) {
                $value = json_encode($value);
            }

            if (in_array($attrKey, $columns, true)) {
                $columnUpdates[$attrKey] = $value;
            } else {
                $fields[$attrKey] = $value;
            }
            $context['attributes'][$attrKey] = $value;
            $context[$attrKey]               = $value;
        }

        $update = $columnUpdates;
        if ($fields !== []) {
            $update['custom_fields'] = $fields;
        }
        if ($update !== []) {
            $this->contacts->update($contactId, $update);
        }
    }

    protected function resolveTagId(string $name): int
    {
        $name = trim($name);
        if ($name === '') {
            return 0;
        }

        $row = db_connect()->table('tags')->where('name', $name)->get()->getRowArray();
        if ($row) {
            return (int) $row['id'];
        }

        // Create missing tag so Cheerio labelName workflows keep working
        db_connect()->table('tags')->insert([
            'name'       => $name,
            'color'      => '#6B7280',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        return (int) db_connect()->insertID();
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $context
     */
    protected function sendEmailNotification(array $config, array $context): void
    {
        $to      = (string) ($config['to'] ?? '');
        $subject = $this->interpolate((string) ($config['subject'] ?? 'Automation notification'), $context);
        $message = $this->interpolate((string) ($config['message'] ?? $config['body'] ?? ''), $context);

        if ($to === '') {
            // Fall back to notifying assigned agent / session user via notifications table
            $userId = (int) ($config['user_id'] ?? $context['user_id'] ?? 0);
            if ($userId > 0) {
                model(NotificationModel::class)->insert([
                    'user_id' => $userId,
                    'title'   => $subject,
                    'message' => $message,
                    'type'    => 'automation',
                    'is_read' => 0,
                ]);
            }

            return;
        }

        $result = service('emailProvider')->send($to, $subject, $message);
        if (! ($result['ok'] ?? false)) {
            log_message('error', 'Automation email failed: {debug}', ['debug' => $result['message'] ?? 'unknown']);
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    protected function interpolate(string $text, array $context): string
    {
        return (string) preg_replace_callback('/\{\{\s*([\w.]+)\s*\}\}/', function (array $m) use ($context) {
            $value = $this->resolveField($m[1], $context);

            return is_scalar($value) ? (string) $value : '';
        }, $text);
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    protected function interpolateDeep(array $data, array $context): array
    {
        foreach ($data as $key => $value) {
            if (is_string($value)) {
                $data[$key] = $this->interpolate($value, $context);
            } elseif (is_array($value)) {
                $data[$key] = $this->interpolateDeep($value, $context);
            }
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    protected function decodeJson(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }
}
