<?php

namespace App\Libraries;

use App\Models\ContactModel;
use App\Models\KeywordModel;
use App\Models\MessageModel;
use RuntimeException;
use Throwable;

/**
 * Keyword / menu bot matching and auto-replies.
 * Sends via Settings → active WhatsApp provider (Cheerio or Meta),
 * or a temporary override when inbound webhook identifies the source number.
 */
class KeywordBot
{
    protected KeywordModel $keywords;
    protected ContactModel $contacts;
    protected MessageModel $messages;
    protected WhatsAppCloudAPI $api;
    protected ActivityLogger $logger;

    /** Temporary send provider for one inbound webhook cycle. */
    protected ?string $sendProvider = null;

    public function __construct(
        ?KeywordModel $keywords = null,
        ?ContactModel $contacts = null,
        ?MessageModel $messages = null,
        ?WhatsAppCloudAPI $api = null,
        ?ActivityLogger $logger = null
    ) {
        $this->keywords = $keywords ?? model(KeywordModel::class);
        $this->contacts = $contacts ?? model(ContactModel::class);
        $this->messages = $messages ?? model(MessageModel::class);
        $this->api      = $api ?? (function_exists('service') ? service('whatsApp') : new WhatsAppCloudAPI());
        $this->logger   = $logger ?? new ActivityLogger();
    }

    /**
     * Force Cheerio/Meta for the next reply (null = Settings active provider).
     */
    public function setSendProvider(?string $provider): self
    {
        $this->sendProvider = $provider !== null && $provider !== ''
            ? strtolower(trim($provider))
            : null;

        return $this;
    }

    /**
     * Match inbound text against active keywords and send a reply.
     *
     * @return array{matched: bool, keyword_id: int|null, response: array<string, mixed>|null}
     */
    public function matchAndReply(int $contactId, string $messageText): array
    {
        $match = $this->findMatch($messageText);
        if ($match === null) {
            return ['matched' => false, 'keyword_id' => null, 'response' => null];
        }

        return $this->sendMatchedReply($contactId, $match);
    }

    /**
     * Reply using a specific keyword row (e.g. interactive button id kw_{id}).
     *
     * @return array{matched: bool, keyword_id: int|null, response: array<string, mixed>|null}
     */
    public function replyByKeywordId(int $contactId, int $keywordId): array
    {
        $match = $this->keywords->where('is_active', 1)->find($keywordId);
        if ($match === null) {
            return ['matched' => false, 'keyword_id' => null, 'response' => null];
        }

        return $this->sendMatchedReply($contactId, $match);
    }

    /**
     * @param array<string, mixed> $match
     *
     * @return array{matched: bool, keyword_id: int|null, response: array<string, mixed>|null}
     */
    protected function sendMatchedReply(int $contactId, array $match): array
    {
        $contact = $this->contacts->find($contactId);
        if ($contact === null) {
            throw new RuntimeException('Contact not found: ' . $contactId);
        }

        $to = $this->api->normalizePhone((string) ($contact['mobile'] ?? ''));
        if ($to === '') {
            throw new RuntimeException('Contact has no valid mobile number.');
        }

        $provider = $this->sendProvider;
        if ($provider === null || $provider === '') {
            $provider = (new SettingsService())->getWhatsAppProvider();
        } else {
            // Never send on the non-active provider — Settings is source of truth.
            $active = (new SettingsService())->getWhatsAppProvider();
            if ($provider !== $active) {
                log_message('notice', 'KeywordBot ignoring sendProvider={want}; using Settings active={active}.', [
                    'want'   => $provider,
                    'active' => $active,
                ]);
                $provider = $active;
            }
        }

        try {
            $this->api->forceProvider($provider);
            $match['_contact_id'] = $contactId;
            $apiResponse = $this->sendKeywordResponse($to, $match);
            $waId        = $apiResponse['messages'][0]['id'] ?? null;

            $this->messages->insert([
                'contact_id'    => $contactId,
                'campaign_id'   => null,
                'direction'     => 'outbound',
                'message_type'  => (string) ($match['response_type'] ?? 'text'),
                'wa_message_id' => $waId,
                'wamid'         => $waId,
                'content'       => $match['response_content'] ?? null,
                'payload'       => json_encode([
                    'keyword_id' => $match['id'],
                    'provider'   => $provider,
                    'response'   => $apiResponse,
                ]),
                'status'  => 'sent',
                'is_read' => 0,
            ]);

            $this->logger->log('keyword_reply', 'keywords', 'Keyword bot replied via ' . $provider, [
                'contact_id' => $contactId,
                'keyword_id' => $match['id'],
                'provider'   => $provider,
            ]);

            return [
                'matched'    => true,
                'keyword_id' => (int) $match['id'],
                'response'   => $apiResponse,
            ];
        } catch (Throwable $e) {
            log_message('error', 'KeywordBot reply failed ({provider}): {msg}', [
                'provider' => $provider,
                'msg'      => $e->getMessage(),
            ]);

            throw new RuntimeException('Keyword reply failed: ' . $e->getMessage(), 0, $e);
        } finally {
            $this->api->clearForcedProvider();
            $this->sendProvider = null;
        }
    }

    /**
     * Find the best matching active keyword for a message.
     *
     * @return array<string, mixed>|null
     */
    public function findMatch(string $messageText, ?int $parentId = null): ?array
    {
        $text = trim($messageText);
        if ($text === '') {
            return null;
        }

        $builder = $this->keywords->builder();
        $builder->where('is_active', 1);

        if ($parentId === null) {
            $builder->groupStart()
                ->where('parent_id', null)
                ->orWhere('parent_id', 0)
            ->groupEnd();
        } else {
            $builder->where('parent_id', $parentId);
        }

        $keywords = $builder
            ->orderBy('menu_order', 'ASC')
            ->orderBy('id', 'ASC')
            ->get()
            ->getResultArray();

        $normalized = mb_strtolower($text);

        // Prefer exact, then starts_with, then contains
        $ordered = ['exact' => [], 'starts_with' => [], 'contains' => []];
        foreach ($keywords as $keyword) {
            $type = (string) ($keyword['match_type'] ?? 'exact');
            if (! isset($ordered[$type])) {
                $ordered[$type] = [];
            }
            $ordered[$type][] = $keyword;
        }

        foreach (['exact', 'starts_with', 'contains'] as $type) {
            foreach ($ordered[$type] as $keyword) {
                if ($this->matches($normalized, (string) $keyword['keyword'], $type)) {
                    return $keyword;
                }
            }
        }

        return null;
    }

    /**
     * Send the keyword's configured response (text, buttons, list, menu flow).
     *
     * @param array<string, mixed> $keyword
     *
     * @return array<string, mixed>
     */
    protected function sendKeywordResponse(string $to, array $keyword): array
    {
        $type    = (string) ($keyword['response_type'] ?? 'text');
        $content = (string) ($keyword['response_content'] ?? '');
        $payload = $this->normalizePayload($this->decodeJson($keyword['response_payload'] ?? null), $type, $content);

        return match ($type) {
            'text' => $this->api->sendText(
                $to,
                $content !== '' ? $content : (string) ($payload['text'] ?? '')
            ),

            'buttons', 'interactive_buttons', 'quick_reply' => $this->api->sendInteractiveButtons(
                $to,
                $content !== '' ? $content : (string) ($payload['body'] ?? ''),
                (array) ($payload['buttons'] ?? []),
                $payload['header'] ?? null,
                $payload['footer'] ?? null
            ),

            'list', 'interactive_list' => $this->api->sendInteractiveList(
                $to,
                $content !== '' ? $content : (string) ($payload['body'] ?? ''),
                (string) ($payload['button_text'] ?? 'Menu'),
                (array) ($payload['sections'] ?? $this->buildMenuSections((int) $keyword['id'])),
                $payload['header'] ?? null,
                $payload['footer'] ?? null
            ),

            'menu' => $this->sendMenuFlow($to, $keyword, $content, $payload),

            'template' => $this->api->sendTemplate(
                $to,
                (string) ($payload['template_name'] ?? $payload['name'] ?? $content),
                (string) ($payload['language'] ?? 'en'),
                (array) ($payload['components'] ?? [])
            ),

            'image' => $this->api->sendImage(
                $to,
                (string) ($payload['link'] ?? $payload['id'] ?? (preg_match('#^https?://#i', $content) ? $content : '')),
                $this->payloadCaption($payload, $content),
                isset($payload['id']) && ! isset($payload['link'])
            ),

            'video' => $this->api->sendVideo(
                $to,
                (string) ($payload['link'] ?? $payload['id'] ?? (preg_match('#^https?://#i', $content) ? $content : '')),
                $this->payloadCaption($payload, $content),
                isset($payload['id']) && ! isset($payload['link'])
            ),

            'document' => $this->api->sendDocument(
                $to,
                (string) ($payload['link'] ?? $payload['id'] ?? (preg_match('#^https?://#i', $content) ? $content : '')),
                $this->payloadCaption($payload, $content),
                (string) ($payload['filename'] ?? 'file'),
                isset($payload['id']) && ! isset($payload['link'])
            ),

            'workflow', 'automation' => $this->runWorkflowResponse($to, $keyword, $payload, $content),

            'interactive' => $this->sendMenuFlow($to, $keyword, $content, $payload),

            default => $this->api->sendText($to, $content !== '' ? $content : 'Thanks for your message.'),
        };
    }

    /**
     * Caption from payload, or non-URL response_content.
     */
    protected function payloadCaption(array $payload, string $content): ?string
    {
        if (! empty($payload['caption']) && is_string($payload['caption'])) {
            return (string) $payload['caption'];
        }
        if ($content !== '' && ! preg_match('#^https?://#i', $content)) {
            return $content;
        }

        return null;
    }

    /**
     * Trigger an automation workflow, optionally still send a short text ack.
     *
     * @param array<string, mixed> $keyword
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    protected function runWorkflowResponse(string $to, array $keyword, array $payload, string $content): array
    {
        $automationId = (int) ($payload['automation_id'] ?? $payload['workflow_id'] ?? 0);
        if ($automationId <= 0 && ctype_digit(trim($content))) {
            $automationId = (int) trim($content);
        }

        if ($automationId <= 0) {
            throw new RuntimeException('Workflow keyword needs automation_id in payload (or numeric content).');
        }

        $contactId = (int) ($keyword['_contact_id'] ?? 0);
        if ($contactId <= 0) {
            $contact   = $this->contacts->findByMobile($this->api->normalizePhone($to));
            $contactId = (int) ($contact['id'] ?? 0);
        }

        service('automationEngine')->runAutomation($automationId, [
            'contact_id' => $contactId,
            'from'       => $to,
            'keyword_id' => (int) ($keyword['id'] ?? 0),
            'content'    => $content,
            'source'     => 'keyword_workflow',
        ]);

        try {
            service('queueService')->processBatch(30);
        } catch (Throwable $e) {
            log_message('warning', 'Keyword workflow queue flush: {msg}', ['msg' => $e->getMessage()]);
        }

        $ack = trim((string) ($payload['ack_text'] ?? ''));
        if ($ack !== '') {
            return $this->api->sendText($to, $ack);
        }

        return [
            'messages' => [['id' => null]],
            'workflow' => ['automation_id' => $automationId, 'started' => true],
        ];
    }

    /**
     * Flatten Cloud API / Cheerio / Meta shaped JSON into KeywordBot keys.
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    protected function normalizePayload(array $payload, string $responseType, string $content): array
    {
        // text: { text: { body } } or { text: "..." }
        if (isset($payload['text'])) {
            if (is_array($payload['text'])) {
                $payload['text'] = (string) ($payload['text']['body'] ?? $payload['text']['text'] ?? '');
            } else {
                $payload['text'] = (string) $payload['text'];
            }
        }

        // interactive list / buttons
        $interactive = is_array($payload['interactive'] ?? null) ? $payload['interactive'] : null;
        if ($interactive !== null) {
            if (empty($payload['body']) && isset($interactive['body']['text'])) {
                $payload['body'] = (string) $interactive['body']['text'];
            }
            if (empty($payload['footer']) && isset($interactive['footer']['text'])) {
                $payload['footer'] = (string) $interactive['footer']['text'];
            }
            if (empty($payload['header']) && isset($interactive['header']['text'])) {
                $payload['header'] = (string) $interactive['header']['text'];
            }
            if (($interactive['type'] ?? '') === 'list' && empty($payload['sections'])) {
                $payload['sections'] = (array) ($interactive['action']['sections'] ?? []);
                if (empty($payload['button_text'])) {
                    $payload['button_text'] = (string) ($interactive['action']['button'] ?? 'Menu');
                }
            }
            if (($interactive['type'] ?? '') === 'button' && empty($payload['buttons'])) {
                $buttons = [];
                foreach ((array) ($interactive['action']['buttons'] ?? []) as $btn) {
                    if (! is_array($btn)) {
                        continue;
                    }
                    $reply = is_array($btn['reply'] ?? null) ? $btn['reply'] : $btn;
                    $buttons[] = [
                        'id'    => (string) ($reply['id'] ?? ''),
                        'title' => (string) ($reply['title'] ?? 'Option'),
                    ];
                }
                $payload['buttons'] = $buttons;
            }
        }

        // template: { template: { name, language: { code }, components } }
        $tpl = is_array($payload['template'] ?? null) ? $payload['template'] : null;
        if ($tpl !== null) {
            if (empty($payload['template_name']) && empty($payload['name'])) {
                $payload['template_name'] = (string) ($tpl['name'] ?? '');
                $payload['name']          = $payload['template_name'];
            }
            if (empty($payload['language'])) {
                $lang = $tpl['language'] ?? 'en';
                $payload['language'] = is_array($lang)
                    ? (string) ($lang['code'] ?? 'en')
                    : (string) $lang;
            }
            if (empty($payload['components']) && isset($tpl['components']) && is_array($tpl['components'])) {
                $payload['components'] = $tpl['components'];
            }
        }
        if (isset($payload['language']) && is_array($payload['language'])) {
            $payload['language'] = (string) ($payload['language']['code'] ?? 'en');
        }

        // image / video / document media blocks
        foreach (['image', 'video', 'document'] as $mediaKey) {
            $media = is_array($payload[$mediaKey] ?? null) ? $payload[$mediaKey] : null;
            if ($media === null) {
                continue;
            }
            if (empty($payload['link']) && ! empty($media['link'])) {
                $payload['link'] = (string) $media['link'];
            }
            if (empty($payload['id']) && ! empty($media['id'])) {
                $payload['id'] = (string) $media['id'];
            }
            if (empty($payload['caption']) && ! empty($media['caption'])) {
                $payload['caption'] = (string) $media['caption'];
            }
            if ($mediaKey === 'document' && empty($payload['filename']) && ! empty($media['filename'])) {
                $payload['filename'] = (string) $media['filename'];
            }
        }

        // Defaults from response_content when still empty
        if ($responseType === 'text' && empty($payload['text']) && $content !== '') {
            $payload['text'] = $content;
        }
        if ($responseType === 'template' && empty($payload['template_name']) && empty($payload['name']) && $content !== '') {
            $payload['template_name'] = $content;
            $payload['name']          = $content;
        }
        if (in_array($responseType, ['image', 'video', 'document'], true) && empty($payload['link']) && preg_match('#^https?://#i', $content)) {
            $payload['link'] = $content;
        }
        if (in_array($responseType, ['workflow', 'automation'], true) && empty($payload['automation_id']) && ctype_digit(trim($content))) {
            $payload['automation_id'] = (int) trim($content);
        }

        return $payload;
    }

    /**
     * Build and send a menu from child keywords.
     *
     * @param array<string, mixed> $keyword
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    protected function sendMenuFlow(string $to, array $keyword, string $content, array $payload): array
    {
        $children = $this->keywords
            ->where('parent_id', (int) $keyword['id'])
            ->where('is_active', 1)
            ->orderBy('menu_order', 'ASC')
            ->findAll();

        if ($children === []) {
            return $this->api->sendText($to, $content !== '' ? $content : 'Menu unavailable.');
        }

        // Prefer buttons when 3 or fewer children; otherwise list
        if (count($children) <= 3) {
            $buttons = [];
            foreach ($children as $child) {
                $buttons[] = [
                    'id'    => 'kw_' . $child['id'],
                    'title' => mb_substr((string) $child['keyword'], 0, 20),
                ];
            }

            return $this->api->sendInteractiveButtons(
                $to,
                $content !== '' ? $content : (string) ($payload['body'] ?? 'Please choose an option:'),
                $buttons,
                $payload['header'] ?? null,
                $payload['footer'] ?? null
            );
        }

        return $this->api->sendInteractiveList(
            $to,
            $content !== '' ? $content : (string) ($payload['body'] ?? 'Please choose an option:'),
            (string) ($payload['button_text'] ?? 'Options'),
            $this->buildMenuSections((int) $keyword['id'], $children),
            $payload['header'] ?? null,
            $payload['footer'] ?? null
        );
    }

    /**
     * @param list<array<string, mixed>>|null $children
     *
     * @return list<array{title: string, rows: list<array{id: string, title: string, description?: string}>}>
     */
    protected function buildMenuSections(int $parentId, ?array $children = null): array
    {
        $children ??= $this->keywords
            ->where('parent_id', $parentId)
            ->where('is_active', 1)
            ->orderBy('menu_order', 'ASC')
            ->findAll();

        $rows = [];
        foreach ($children as $child) {
            $rows[] = [
                'id'          => 'kw_' . $child['id'],
                'title'       => mb_substr((string) $child['keyword'], 0, 24),
                'description' => mb_substr((string) ($child['response_content'] ?? ''), 0, 72),
            ];
        }

        return [[
            'title' => 'Menu',
            'rows'  => $rows,
        ]];
    }

    protected function matches(string $normalizedMessage, string $keyword, string $matchType): bool
    {
        $needle = mb_strtolower(trim($keyword));
        if ($needle === '') {
            return false;
        }

        return match ($matchType) {
            'exact' => $normalizedMessage === $needle,
            'starts_with' => str_starts_with($normalizedMessage, $needle),
            'contains' => str_contains($normalizedMessage, $needle),
            default => $normalizedMessage === $needle,
        };
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
