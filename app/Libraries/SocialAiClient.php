<?php

declare(strict_types=1);

namespace App\Libraries;

use Config\Services;
use Throwable;

/**
 * One door to the AI used for social captions. The provider is chosen on Social Setup:
 *   - gemini    the app's own AI settings (Settings > AI), same key and model as the rest of the app (used when no Claude key is saved)
 *   - anthropic Claude (default once its key is saved on Social Setup)
 *   - openai    OpenAI, with its own key saved on Social Setup
 * Every method returns ['ok','status','message','text'?]; nothing throws. Provider errors are never shown raw.
 */
class SocialAiClient
{
    public const PROVIDERS = [
        'gemini'    => 'Gemini (app AI)',
        'anthropic' => 'Claude',
        'openai'    => 'OpenAI',
    ];

    /** Claude models offered on Social Setup (id => label). */
    public const CLAUDE_MODELS = [
        'claude-sonnet-5-5' => 'Sonnet 5.5 (Recommended)',
        'claude-haiku-5-5'  => 'Haiku 5.5 (Fast, low cost)',
        'claude-opus-5-5'   => 'Opus 5.5 (Highest quality)',
        'claude-fable-5-1'  => 'Fable 5.1 (Most capable)',
    ];

    public const DEFAULT_MODELS = [
        'anthropic' => 'claude-sonnet-5-5',
        'openai'    => 'gpt-4o-mini',
    ];

    private SocialCredentials $creds;
    private SettingsService $settings;

    public function __construct(?SettingsService $settings = null, ?SocialCredentials $creds = null)
    {
        $this->settings = $settings ?? new SettingsService();
        $this->creds    = $creds ?? new SocialCredentials($this->settings);
    }

    public function provider(): string
    {
        $p = strtolower($this->creds->get('ai_caption_provider'));
        if (isset(self::PROVIDERS[$p])) {
            return $p;
        }

        // Nothing chosen yet: Claude writes the posts as soon as its key is saved, otherwise the app's Gemini.
        return $this->creds->get('anthropic_api_key') !== '' ? 'anthropic' : 'gemini';
    }

    public function label(): string
    {
        return self::PROVIDERS[$this->provider()];
    }

    public function isConfigured(): bool
    {
        return match ($this->provider()) {
            'anthropic' => $this->creds->get('anthropic_api_key') !== '',
            'openai'    => $this->creds->get('openai_api_key') !== '',
            default     => (new AiService($this->settings))->isConfigured(),
        };
    }

    /**
     * @param array{mime: string, data: string}|null $image base64 picture (a photo or a video frame) the model may look at
     * @return array{ok: bool, status: int, message: string, text?: string}
     */
    public function generate(string $system, string $prompt, ?array $image = null, int $maxTokens = 1500): array
    {
        if (! $this->isConfigured()) {
            return $this->err(503, 'The AI is not set up yet. Add its key on Social Setup (or Settings > AI for Gemini).');
        }

        try {
            return match ($this->provider()) {
                'anthropic' => $this->anthropic($system, $prompt, $image, $maxTokens),
                'openai'    => $this->openai($system, $prompt, $image, $maxTokens),
                default     => $this->gemini($system, $prompt, $image, $maxTokens),
            };
        } catch (Throwable $e) {
            log_message('error', 'SocialAiClient::generate ' . $this->provider() . ' ' . $e::class);

            return $this->err(502, 'The AI assistant could not answer right now. Please try again.');
        }
    }

    /**
     * One JSON POST with the same local-WAMP TLS fallback as AiService (no CA bundle: unverified TLS outside production).
     *
     * @param array<string, string> $headers
     * @param array<string, mixed>  $payload
     * @return array{code: int, json: ?array<string, mixed>, error: string}
     */
    public static function postJson(string $url, array $headers, array $payload, int $timeout = 90): array
    {
        $h = [];
        foreach ($headers as $k => $v) {
            $h[] = $k . ': ' . $v;
        }
        $opts = [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $h,
            CURLOPT_POSTFIELDS     => (string) json_encode($payload),
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
        ];
        $ca = ini_get('curl.cainfo') ?: ini_get('openssl.cafile') ?: (getenv('CURL_CA_BUNDLE') ?: '');
        if ($ca !== '' && is_file($ca)) {
            $opts[CURLOPT_CAINFO] = $ca;
        } elseif (ENVIRONMENT !== 'production') {
            $opts[CURLOPT_SSL_VERIFYPEER] = false;
            $opts[CURLOPT_SSL_VERIFYHOST] = 0;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, $opts);
        $out  = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($err !== '') {
            log_message('error', 'SocialAiClient::postJson curl error: ' . $err);
        }
        if ($err === '' && $code !== 200) {
            // The provider's own explanation, so a failure can be diagnosed from the log (never shown to users).
            log_message('error', 'SocialAiClient::postJson http=' . $code . ' url=' . strtok($url, '?') . ' response=' . mb_substr((string) $out, 0, 800));
        }
        $json = is_string($out) ? json_decode($out, true) : null;

        return ['code' => $code, 'json' => is_array($json) ? $json : null, 'error' => $err];
    }

    /** @return array{ok: bool, status: int, message: string, text?: string} */
    private function gemini(string $system, string $prompt, ?array $image, int $maxTokens): array
    {
        $config = $this->settings->getAiConfig();
        $parts  = [];
        if ($image !== null) {
            $parts[] = ['inline_data' => ['mime_type' => $image['mime'], 'data' => $image['data']]];
        }
        $parts[] = ['text' => $prompt];

        $res = (new AiService($this->settings))->requestGemini(trim((string) $config['api_key']), trim((string) ($config['model'] ?: 'gemini-flash-latest')), [
            'system_instruction' => ['parts' => [['text' => $system]]],
            'contents'           => [['role' => 'user', 'parts' => $parts]],
            'generationConfig'   => ['temperature' => 0.9, 'maxOutputTokens' => $maxTokens, 'responseMimeType' => 'application/json'],
        ]);
        if (! $res['success']) {
            return $this->httpError((int) ($res['http_code'] ?? 0), 'gemini');
        }

        return ['ok' => true, 'status' => 200, 'message' => 'OK', 'text' => (string) $res['text']];
    }

    /** @return array{ok: bool, status: int, message: string, text?: string} */
    private function anthropic(string $system, string $prompt, ?array $image, int $maxTokens): array
    {
        $model   = $this->creds->get('anthropic_model') ?: self::DEFAULT_MODELS['anthropic'];
        $content = [];
        if ($image !== null) {
            $content[] = ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $image['mime'], 'data' => $image['data']]];
        }
        $content[] = ['type' => 'text', 'text' => $prompt];

        $res  = self::postJson('https://api.anthropic.com/v1/messages', [
            'x-api-key'         => $this->creds->get('anthropic_api_key'),
            'anthropic-version' => '2023-06-01',
            'content-type'      => 'application/json',
        ], [
            'model'      => $model,
            'max_tokens' => $maxTokens,
            'system'     => $system,
            'messages'   => [['role' => 'user', 'content' => $content]],
        ]);
        $code = $res['code'];
        $body = $res['json'];
        if ($code !== 200 || ! is_array($body)) {
            return $this->httpError($code, 'anthropic');
        }
        $text = '';
        foreach ((array) ($body['content'] ?? []) as $block) {
            if (is_array($block) && ($block['type'] ?? '') === 'text') {
                $text .= (string) ($block['text'] ?? '');
            }
        }

        return $text !== '' ? ['ok' => true, 'status' => 200, 'message' => 'OK', 'text' => $text] : $this->err(502, 'The AI returned an empty answer. Please try again.');
    }

    /** @return array{ok: bool, status: int, message: string, text?: string} */
    private function openai(string $system, string $prompt, ?array $image, int $maxTokens): array
    {
        $model = $this->creds->get('openai_model') ?: self::DEFAULT_MODELS['openai'];
        $user  = [['type' => 'text', 'text' => $prompt]];
        if ($image !== null) {
            $user[] = ['type' => 'image_url', 'image_url' => ['url' => 'data:' . $image['mime'] . ';base64,' . $image['data']]];
        }

        $res  = self::postJson('https://api.openai.com/v1/chat/completions', ['Authorization' => 'Bearer ' . $this->creds->get('openai_api_key'), 'Content-Type' => 'application/json'], [
            'model'           => $model,
            'messages'        => [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $user]],
            'response_format' => ['type' => 'json_object'],
            'max_tokens'      => $maxTokens,
        ]);
        $code = $res['code'];
        $body = $res['json'];
        if ($code !== 200 || ! is_array($body)) {
            return $this->httpError($code, 'openai');
        }
        $text = (string) ($body['choices'][0]['message']['content'] ?? '');

        return $text !== '' ? ['ok' => true, 'status' => 200, 'message' => 'OK', 'text' => $text] : $this->err(502, 'The AI returned an empty answer. Please try again.');
    }

    /**
     * @return array{ok: bool, status: int, message: string}
     */
    private function httpError(int $http, string $provider): array
    {
        log_message('error', 'SocialAiClient ' . $provider . ' http=' . $http);

        return match (true) {
            in_array($http, [401, 403], true) => $this->err(502, 'The AI key was rejected. Check the key and model on Social Setup.'),
            $http === 404                     => $this->err(502, 'The AI model name was not found. Check the model on Social Setup.'),
            $http === 429                     => $this->err(429, 'The AI is busy or its limit is reached. Try again in a minute.'),
            default                           => $this->err(502, 'The AI assistant could not answer right now. Please try again.'),
        };
    }

    /**
     * @return array{ok: bool, status: int, message: string}
     */
    private function err(int $status, string $message): array
    {
        return ['ok' => false, 'status' => $status, 'message' => $message];
    }
}
