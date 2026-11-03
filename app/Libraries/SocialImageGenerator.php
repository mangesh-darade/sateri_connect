<?php

declare(strict_types=1);

namespace App\Libraries;

use Config\Services;
use Throwable;

/**
 * Makes one post picture from a text prompt and saves it as a JPEG in writable/social_ai.
 * Claude cannot draw, so this uses OpenAI (gpt-image-1) when its key is saved on Social Setup, otherwise Gemini
 * with the key from Settings > AI. Returns ['ok','status','message','data'? => ['file','provider']]; nothing throws.
 */
class SocialImageGenerator
{
    private const OPENAI_MODEL = 'gpt-image-1';
    private const GEMINI_MODEL = 'gemini-2.5-flash-image';

    private SocialCredentials $creds;
    private SettingsService $settings;

    public function __construct(?SettingsService $settings = null)
    {
        $this->settings = $settings ?? new SettingsService();
        $this->creds    = new SocialCredentials($this->settings);
    }

    public static function dir(): string
    {
        return WRITEPATH . 'social_ai' . DIRECTORY_SEPARATOR;
    }

    public function provider(): string
    {
        if ($this->creds->get('openai_api_key') !== '') {
            return 'openai';
        }

        return trim((string) ($this->settings->getAiConfig()['api_key'] ?? '')) !== '' ? 'gemini' : '';
    }

    public function label(): string
    {
        return match ($this->provider()) {
            'openai' => 'OpenAI image',
            'gemini' => 'Gemini image',
            default  => 'not set up',
        };
    }

    /**
     * @return array{ok: bool, status: int, message: string, data?: array{file: string, provider: string}}
     */
    public function generate(string $prompt): array
    {
        $prompt   = trim(mb_substr($prompt, 0, 1500));
        $provider = $this->provider();
        if ($prompt === '') {
            return $this->err(422, 'There is no image description.');
        }
        if ($provider === '') {
            return $this->err(503, 'No image AI is set up. Save an OpenAI key on Social Setup, or a Gemini key in Settings > AI.');
        }

        try {
            $bytes = $provider === 'openai' ? $this->openai($prompt) : $this->gemini($prompt);
            if (is_array($bytes)) {
                return $bytes;
            }

            return $this->save($bytes, $provider);
        } catch (Throwable $e) {
            log_message('error', 'SocialImageGenerator::generate ' . $provider . ' ' . $e::class . ': ' . $e->getMessage());

            return $this->err(502, 'The image AI could not make the picture right now. Please try again.');
        }
    }

    /** @return string|array<string, mixed> raw image bytes, or an error result */
    private function openai(string $prompt): string|array
    {
        $res  = SocialAiClient::postJson('https://api.openai.com/v1/images/generations', ['Authorization' => 'Bearer ' . $this->creds->get('openai_api_key'), 'Content-Type' => 'application/json'], [
            'model' => self::OPENAI_MODEL, 'prompt' => $prompt, 'size' => '1024x1024', 'n' => 1,
        ], 150);
        $code = $res['code'];
        $body = $res['json'];
        if ($code !== 200 || ! is_array($body)) {
            return $this->httpError($code, 'openai');
        }
        $b64 = (string) ($body['data'][0]['b64_json'] ?? '');
        $raw = $b64 !== '' ? base64_decode($b64, true) : false;

        return is_string($raw) && $raw !== '' ? $raw : $this->err(502, 'The image AI returned no picture. Please try again.');
    }

    /** @return string|array<string, mixed> */
    private function gemini(string $prompt): string|array
    {
        $key = trim((string) ($this->settings->getAiConfig()['api_key'] ?? ''));
        $res  = SocialAiClient::postJson(
            'https://generativelanguage.googleapis.com/v1beta/models/' . self::GEMINI_MODEL . ':generateContent',
            ['x-goog-api-key' => $key, 'Content-Type' => 'application/json'],
            ['contents' => [['parts' => [['text' => $prompt]]]], 'generationConfig' => ['responseModalities' => ['TEXT', 'IMAGE']]],
            150
        );
        $code = $res['code'];
        $body = $res['json'];
        if ($code !== 200 || ! is_array($body)) {
            return $this->httpError($code, 'gemini');
        }
        foreach ((array) ($body['candidates'][0]['content']['parts'] ?? []) as $part) {
            $b64 = (string) ($part['inlineData']['data'] ?? $part['inline_data']['data'] ?? '');
            $raw = $b64 !== '' ? base64_decode($b64, true) : false;
            if (is_string($raw) && $raw !== '') {
                return $raw;
            }
        }

        return $this->err(502, 'The image AI returned no picture. Try a simpler description.');
    }

    /**
     * Instagram only accepts JPEG, so the picture is always stored as JPEG when GD can convert it.
     *
     * @return array{ok: bool, status: int, message: string, data?: array{file: string, provider: string}}
     */
    private function save(string $bytes, string $provider): array
    {
        $dir = self::dir();
        if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
            return $this->err(500, 'The picture folder is not writable.');
        }

        $ext = 'jpg';
        if (function_exists('imagecreatefromstring') && ($img = @imagecreatefromstring($bytes)) !== false) {
            ob_start();
            imagejpeg($img, null, 92);
            $jpeg = (string) ob_get_clean();
            imagedestroy($img);
            $bytes = $jpeg !== '' ? $jpeg : $bytes;
        } elseif (str_starts_with($bytes, "\x89PNG")) {
            $ext = 'png';
        }

        $file = bin2hex(random_bytes(16)) . '.' . $ext;
        if (@file_put_contents($dir . $file, $bytes) === false) {
            return $this->err(500, 'The picture could not be saved.');
        }

        return ['ok' => true, 'status' => 200, 'message' => 'OK', 'data' => ['file' => $file, 'provider' => $provider]];
    }

    /**
     * @return array{ok: bool, status: int, message: string}
     */
    private function httpError(int $http, string $provider): array
    {
        log_message('error', 'SocialImageGenerator ' . $provider . ' http=' . $http);

        return match (true) {
            in_array($http, [401, 403], true) => $this->err(502, 'The image AI key was rejected or this account cannot make images yet. Check the key.'),
            $http === 429                     => $this->err(429, 'The image AI is busy or its limit is reached. Try again in a minute.'),
            $http === 400                     => $this->err(422, 'The image AI refused this description. Edit it and try again.'),
            default                           => $this->err(502, 'The image AI could not make the picture right now. Please try again.'),
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
