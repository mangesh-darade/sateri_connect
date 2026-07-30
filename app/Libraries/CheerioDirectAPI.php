<?php

namespace App\Libraries;

use Config\Services;
use Config\WhatsApp as WhatsAppConfig;
use RuntimeException;
use Throwable;

/**
 * Cheerio Direct API WhatsApp client (newprod.api.cheerio.in/direct-apis).
 *
 * Public method names match the previous Cloud API client so Chat, Queue,
 * Keywords, Templates, and Media keep working without caller changes.
 */
class CheerioDirectAPI
{
    protected SettingsService $settings;
    protected WhatsAppConfig $config;

    protected string $apiKey = '';

    public function __construct(?SettingsService $settings = null, ?WhatsAppConfig $config = null)
    {
        $this->settings = $settings ?? new SettingsService();
        $this->config   = $config ?? config(WhatsAppConfig::class);
        $this->loadCredentials();
    }

    /**
     * Reload credentials from settings (useful after config updates).
     */
    public function loadCredentials(): void
    {
        $cheerio = $this->settings->getCheerioConfig();

        $this->apiKey = $cheerio['api_key'];
    }

    /**
     * Low-level Cheerio Direct API request with retries and error parsing.
     *
     * @param array<string, mixed>|null $data
     *
     * @return array<string, mixed>
     *
     * @throws RuntimeException
     */
    public function request(string $method, string $endpoint, ?array $data = null, bool $isMultipart = false): array
    {
        $this->ensureConfigured();

        $method    = strtoupper($method);
        $url       = $this->buildUrl($endpoint);
        $attempts  = 0;
        $lastError = 'Unknown Cheerio API error';

        while ($attempts <= $this->config->maxRetries) {
            $attempts++;

            try {
                $client = Services::curlrequest($this->baseCurlOptions(), null, null, false);

                $options = [
                    'headers' => [
                        'x-api-key' => $this->apiKey,
                        'Accept'    => 'application/json',
                    ],
                ];

                if ($isMultipart && $data !== null) {
                    $multipart = [];
                    foreach ($data as $name => $value) {
                        if ($value instanceof \CURLFile) {
                            $multipart[$name] = $value;
                            continue;
                        }

                        // Guzzle-style part: ['contents' => ..., 'filename' => ..., 'headers' => ...]
                        if (is_array($value) && array_key_exists('contents', $value)) {
                            $contents = $value['contents'];
                            $filename = (string) ($value['filename'] ?? $name);
                            $mime     = 'application/octet-stream';
                            if (isset($value['headers']['Content-Type']) && is_string($value['headers']['Content-Type'])) {
                                $mime = $value['headers']['Content-Type'];
                            }

                            if (is_resource($contents)) {
                                $meta = stream_get_meta_data($contents);
                                $uri  = is_string($meta['uri'] ?? null) ? $meta['uri'] : '';
                                if ($uri !== '' && is_file($uri)) {
                                    $multipart[$name] = new \CURLFile($uri, $mime, $filename);
                                } else {
                                    $tmp = tempnam(sys_get_temp_dir(), 'wa_up_');
                                    if ($tmp === false) {
                                        throw new RuntimeException('Unable to create temp file for media upload.');
                                    }
                                    $out = fopen($tmp, 'wb');
                                    if ($out === false) {
                                        throw new RuntimeException('Unable to open temp file for media upload.');
                                    }
                                    stream_copy_to_stream($contents, $out);
                                    fclose($out);
                                    $multipart[$name] = new \CURLFile($tmp, $mime, $filename);
                                }
                            } elseif (is_string($contents) && is_file($contents)) {
                                $multipart[$name] = new \CURLFile($contents, $mime, $filename);
                            } elseif (is_string($contents)) {
                                $tmp = tempnam(sys_get_temp_dir(), 'wa_up_');
                                if ($tmp === false || file_put_contents($tmp, $contents) === false) {
                                    throw new RuntimeException('Unable to stage media upload contents.');
                                }
                                $multipart[$name] = new \CURLFile($tmp, $mime, $filename);
                            } else {
                                throw new RuntimeException('Unsupported multipart file contents for field: ' . $name);
                            }
                            continue;
                        }

                        $multipart[$name] = is_scalar($value) ? (string) $value : (string) json_encode($value);
                    }
                    $options['multipart'] = $multipart;
                } elseif ($data !== null) {
                    if ($method === 'GET') {
                        $options['query'] = $data;
                    } else {
                        $options['headers']['Content-Type'] = 'application/json';
                        $options['json']                    = $data;
                    }
                }

                $response = $client->request($method, $url, $options);
                $status   = $response->getStatusCode();
                $body     = (string) $response->getBody();
                $decoded  = json_decode($body, true);

                if (! is_array($decoded)) {
                    $decoded = ['raw' => $body];
                }

                if ($status >= 200 && $status < 300) {
                    return $this->normalizeSuccessPayload($decoded);
                }

                $apiMessage = $this->extractApiError($decoded);
                $lastError  = sprintf('HTTP %d: %s', $status, $apiMessage);

                // Persist full body for debugging opaque Cheerio/axios 400s.
                log_message('error', 'CheerioDirectAPI raw error body: {body}', [
                    'body' => mb_substr($body, 0, 4000),
                ]);
                if ($data !== null && ! $isMultipart) {
                    log_message('error', 'CheerioDirectAPI request payload: {payload}', [
                        'payload' => mb_substr(json_encode($data) ?: '', 0, 4000),
                    ]);
                }

                if (in_array($status, [429, 500, 502, 503, 504], true) && $attempts <= $this->config->maxRetries) {
                    $delay = $this->config->retryDelaySeconds * (2 ** ($attempts - 1));
                    log_message('warning', 'CheerioDirectAPI retry {attempt} after {status}: {err}', [
                        'attempt' => $attempts,
                        'status'  => $status,
                        'err'     => $apiMessage,
                    ]);
                    usleep((int) ($delay * 1_000_000));
                    continue;
                }

                log_message('error', 'CheerioDirectAPI error: {err} | endpoint={endpoint}', [
                    'err'      => $lastError,
                    'endpoint' => $endpoint,
                ]);

                throw new RuntimeException('Cheerio WhatsApp API error: ' . $lastError, $status);
            } catch (RuntimeException $e) {
                throw $e;
            } catch (Throwable $e) {
                $lastError = $e->getMessage();
                log_message('error', 'CheerioDirectAPI transport error: {msg}', ['msg' => $lastError]);

                if ($attempts <= $this->config->maxRetries) {
                    $delay = $this->config->retryDelaySeconds * (2 ** ($attempts - 1));
                    usleep((int) ($delay * 1_000_000));
                    continue;
                }

                throw new RuntimeException('Cheerio WhatsApp API request failed: ' . $lastError, 0, $e);
            }
        }

        throw new RuntimeException('Cheerio WhatsApp API error after retries: ' . $lastError);
    }

    /**
     * Send a text message (session / direct).
     *
     * @return array<string, mixed>
     */
    public function sendText(string $to, string $text, bool $previewUrl = false): array
    {
        return $this->sendDirect($to, [
            'type' => 'text',
            'text' => [
                'preview_url' => $previewUrl,
                'body'        => $text,
            ],
        ]);
    }

    /**
     * Send a template message.
     *
     * Always sends a `components` array (Cheerio crashes on undefined.forEach).
     * Auto-fills IMAGE/VIDEO/DOCUMENT header from the local template example when missing.
     *
     * @param list<array<string, mixed>> $components
     *
     * @return array<string, mixed>
     */
    public function sendTemplate(string $to, string $templateName, string $language, array $components = []): array
    {
        $phone = $this->normalizePhone($to);
        if ($phone === '') {
            throw new RuntimeException('Recipient phone number is required.');
        }

        $components = $this->ensureTemplateComponents($templateName, $language, $components);
        $components = $this->rewriteLocalHeaderMediaToIds($components);

        $data = [
            'name'       => $templateName,
            'language'   => ['code' => $language !== '' ? $language : 'en'],
            'components' => array_values($components),
        ];

        return $this->request('POST', 'v1/whatsapp/template/send', [
            'to'   => $phone,
            'data' => $data,
        ]);
    }

    /**
     * Localhost /media/serve links are not reachable by WhatsApp. Prefer uploaded provider media IDs.
     *
     * @param list<array<string, mixed>> $components
     *
     * @return list<array<string, mixed>>
     */
    protected function rewriteLocalHeaderMediaToIds(array $components): array
    {
        foreach ($components as &$component) {
            if (! is_array($component) || strtolower((string) ($component['type'] ?? '')) !== 'header') {
                continue;
            }
            $params = $component['parameters'] ?? null;
            if (! is_array($params)) {
                continue;
            }
            foreach ($params as &$param) {
                if (! is_array($param)) {
                    continue;
                }
                foreach (['image', 'video', 'document'] as $mediaType) {
                    if (! is_array($param[$mediaType] ?? null)) {
                        continue;
                    }
                    $link = trim((string) ($param[$mediaType]['link'] ?? ''));
                    $id   = trim((string) ($param[$mediaType]['id'] ?? ''));
                    if ($id !== '' || $link === '' || ! $this->isNonPublicMediaUrl($link)) {
                        continue;
                    }
                    $resolvedId = $this->resolveLocalMediaUrlToProviderId($link);
                    if ($resolvedId === '') {
                        throw new RuntimeException(
                            'Template header media uses a local URL that WhatsApp cannot fetch ('
                            . $link . '). Re-upload the header media in the campaign wizard.'
                        );
                    }
                    $param[$mediaType] = ['id' => $resolvedId];
                }
            }
            unset($param);
            $component['parameters'] = array_values($params);
        }
        unset($component);

        return array_values($components);
    }

    protected function isNonPublicMediaUrl(string $url): bool
    {
        $host = strtolower((string) (parse_url($url, PHP_URL_HOST) ?: ''));

        return $host === ''
            || $host === 'localhost'
            || $host === '127.0.0.1'
            || str_ends_with($host, '.local');
    }

    protected function resolveLocalMediaUrlToProviderId(string $url): string
    {
        $filename = '';
        if (preg_match('#/media/serve/([^/?#]+)#', $url, $m)) {
            $filename = basename(rawurldecode($m[1]));
        }

        $row = null;
        if ($filename !== '') {
            $row = model(\App\Models\MediaModel::class)->where('filename', $filename)->orderBy('id', 'DESC')->first();
        }
        if (! is_array($row)) {
            $row = model(\App\Models\MediaModel::class)->where('url', $url)->orderBy('id', 'DESC')->first();
        }
        if (! is_array($row)) {
            return '';
        }

        $waId = trim((string) ($row['wa_media_id'] ?? ''));
        if ($waId !== '') {
            return $waId;
        }

        $path = WRITEPATH . ltrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, (string) ($row['path'] ?? '')), DIRECTORY_SEPARATOR);
        if (! is_file($path)) {
            return '';
        }

        $uploaded = $this->uploadMedia($path, (string) ($row['mime_type'] ?? 'application/octet-stream'));
        $waId     = trim((string) ($uploaded['id'] ?? ''));
        if ($waId !== '') {
            model(\App\Models\MediaModel::class)->update((int) $row['id'], ['wa_media_id' => $waId]);
        }

        return $waId;
    }

    /**
     * Ensure send payload has required media header + dynamic URL button components.
     *
     * @param list<array<string, mixed>> $components
     *
     * @return list<array<string, mixed>>
     */
    public function ensureTemplateComponents(string $templateName, string $language, array $components): array
    {
        $components = array_values(array_filter($components, 'is_array'));

        $tpl = $this->findLocalTemplate($templateName, $language);
        if ($tpl === null) {
            return $components;
        }

        $templateType = strtolower((string) ($tpl['template_type'] ?? 'default'));

        // Carousel media lives on cards; do not inject a top-level IMAGE header.
        if ($templateType !== 'carousel' && ! $this->componentsHaveMediaHeader($components)) {
            $headerType = strtolower((string) ($tpl['header_type'] ?? ''));
            $mediaUrl   = $this->extractTemplateHeaderMediaUrl($tpl);

            if (
                in_array($headerType, ['image', 'video', 'document'], true)
                && $mediaUrl !== ''
                && $this->isReusableHeaderMediaForType($headerType, $mediaUrl)
            ) {
                array_unshift($components, [
                    'type'       => 'header',
                    'parameters' => [[
                        'type'      => $headerType,
                        $headerType => ['link' => $mediaUrl],
                    ]],
                ]);
            }
        }

        if (! $this->componentsHaveBody($components)) {
            $bodyComponent = $this->buildBodyComponentFromTemplate($tpl);
            if ($bodyComponent !== null) {
                $components[] = $bodyComponent;
            }
        }

        if ($templateType === 'carousel' && ! $this->componentsHaveCarousel($components)) {
            $carouselComponent = $this->buildCarouselSendComponent($tpl);
            if ($carouselComponent !== null) {
                $components[] = $carouselComponent;
            }
        }

        // Dynamic URL buttons require a button component with the {{1}} value.
        foreach ($this->extractMissingUrlButtonComponents($tpl, $components) as $buttonComponent) {
            $components[] = $buttonComponent;
        }

        $headerType = strtolower((string) ($tpl['header_type'] ?? ''));
        if (
            $templateType !== 'carousel'
            && in_array($headerType, ['image', 'video', 'document'], true)
            && ! $this->componentsHaveMediaHeader($components)
        ) {
            throw new RuntimeException(
                'Template "' . $templateName . '" requires a '
                . strtoupper($headerType)
                . ' header at send time. The Meta approval sample cannot be reused'
                . ($headerType === 'document' ? ' — upload a PDF in the campaign wizard.' : ' — upload matching media in the campaign wizard.')
            );
        }

        return array_values($components);
    }

    /**
     * Meta CDN / localhost / wrong-extension samples must not be auto-sent (API accepts, phone never gets it).
     */
    protected function isReusableHeaderMediaForType(string $headerType, string $url): bool
    {
        $url = trim($url);
        if ($url === '' || ! filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        $host = strtolower((string) (parse_url($url, PHP_URL_HOST) ?: ''));
        $scheme = strtolower((string) (parse_url($url, PHP_URL_SCHEME) ?: ''));
        if ($scheme !== 'https' || $host === '') {
            return false;
        }
        if (
            $host === 'localhost'
            || $host === '127.0.0.1'
            || str_ends_with($host, '.local')
            || str_contains($host, 'whatsapp.net')
            || str_contains($host, 'fbcdn.net')
            || str_contains($host, 'facebook.com')
            || str_contains($host, 'scontent.')
        ) {
            return false;
        }

        $path = strtolower((string) (parse_url($url, PHP_URL_PATH) ?: ''));
        $ext  = pathinfo($path, PATHINFO_EXTENSION);

        return match ($headerType) {
            'image' => in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true),
            'video' => in_array($ext, ['mp4', '3gp'], true),
            'document' => in_array($ext, ['pdf', 'doc', 'docx'], true),
            default => false,
        };
    }

    /**
     * @param list<array<string, mixed>> $components
     */
    protected function componentsHaveCarousel(array $components): bool
    {
        foreach ($components as $component) {
            if (strtolower((string) ($component['type'] ?? '')) === 'carousel') {
                return true;
            }
        }

        return false;
    }

    /**
     * Build send-time CAROUSEL component from stored template definition.
     *
     * @param array<string, mixed> $tpl
     *
     * @return array<string, mixed>|null
     */
    protected function buildCarouselSendComponent(array $tpl): ?array
    {
        $raw = $tpl['raw_payload'] ?? null;
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            $raw     = is_array($decoded) ? $decoded : null;
        }
        if (! is_array($raw)) {
            return null;
        }

        $cards = [];
        foreach ($raw['components'] ?? [] as $component) {
            if (! is_array($component) || strtoupper((string) ($component['type'] ?? '')) !== 'CAROUSEL') {
                continue;
            }
            foreach ($component['cards'] ?? [] as $index => $card) {
                if (! is_array($card)) {
                    continue;
                }
                $cardComponents = [];
                foreach ($card['components'] ?? [] as $cardComponent) {
                    if (! is_array($cardComponent)) {
                        continue;
                    }
                    if (strtoupper((string) ($cardComponent['type'] ?? '')) !== 'HEADER') {
                        continue;
                    }
                    $format = strtolower((string) ($cardComponent['format'] ?? 'image'));
                    if (! in_array($format, ['image', 'video'], true)) {
                        continue;
                    }
                    $mediaUrl = (string) ($cardComponent['example']['header_handle'][0]
                        ?? $cardComponent['example']['header_url']
                        ?? $cardComponent['example']['link']
                        ?? '');
                    if ($mediaUrl === '') {
                        continue;
                    }
                    $cardComponents[] = [
                        'type'       => 'header',
                        'parameters' => [[
                            'type'  => $format,
                            $format => ['link' => $mediaUrl],
                        ]],
                    ];
                }
                if ($cardComponents === []) {
                    continue;
                }
                $cards[] = [
                    'card_index' => (int) $index,
                    'components' => $cardComponents,
                ];
            }
        }

        if (count($cards) < 2) {
            return null;
        }

        return [
            'type'  => 'carousel',
            'cards' => $cards,
        ];
    }

    /**
     * @param list<array<string, mixed>> $components
     */
    protected function componentsHaveBody(array $components): bool
    {
        foreach ($components as $component) {
            if (strtolower((string) ($component['type'] ?? '')) === 'body') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $tpl
     *
     * @return array<string, mixed>|null
     */
    protected function buildBodyComponentFromTemplate(array $tpl): ?array
    {
        $body = (string) ($tpl['body'] ?? '');
        if ($body === '' || ! preg_match_all('/\{\{\s*(\d+)\s*\}\}/', $body, $matches)) {
            return null;
        }

        $nums = array_map('intval', $matches[1]);
        sort($nums);
        $nums = array_values(array_unique($nums));
        if ($nums === []) {
            return null;
        }

        $examples = $this->extractBodyExamples($tpl);
        $parameters = [];
        foreach ($nums as $i => $num) {
            $value = $examples[$i] ?? ('Sample' . $num);
            $parameters[] = [
                'type' => 'text',
                'text' => (string) $value,
            ];
        }

        return [
            'type'       => 'body',
            'parameters' => $parameters,
        ];
    }

    /**
     * @param array<string, mixed> $tpl
     *
     * @return list<string>
     */
    protected function extractBodyExamples(array $tpl): array
    {
        $variables = $tpl['variables'] ?? null;
        if (is_string($variables) && $variables !== '') {
            $decoded = json_decode($variables, true);
            $variables = is_array($decoded) ? $decoded : null;
        }
        if (is_array($variables) && $variables !== []) {
            $out = [];
            foreach ($variables as $value) {
                if (is_scalar($value) && (string) $value !== '' && ! preg_match('/^\d+$/', (string) $value)) {
                    $out[] = (string) $value;
                }
            }
            if ($out !== []) {
                return $out;
            }
        }

        $raw = $tpl['raw_payload'] ?? null;
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            $raw     = is_array($decoded) ? $decoded : null;
        }
        if (! is_array($raw)) {
            return [];
        }

        foreach ($raw['components'] ?? [] as $component) {
            if (! is_array($component) || strtoupper((string) ($component['type'] ?? '')) !== 'BODY') {
                continue;
            }
            $bodyText = $component['example']['body_text'][0] ?? null;
            if (is_array($bodyText)) {
                return array_map(static fn ($v) => (string) $v, array_values($bodyText));
            }
        }

        return [];
    }

    /**
     * Build button components for URL buttons that still need {{n}} values.
     *
     * @param array<string, mixed>       $tpl
     * @param list<array<string, mixed>> $existing
     *
     * @return list<array<string, mixed>>
     */
    protected function extractMissingUrlButtonComponents(array $tpl, array $existing): array
    {
        $raw = $tpl['raw_payload'] ?? null;
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            $raw     = is_array($decoded) ? $decoded : null;
        }
        if (! is_array($raw)) {
            return [];
        }

        $haveIndexes = [];
        foreach ($existing as $component) {
            if (strtolower((string) ($component['type'] ?? '')) !== 'button') {
                continue;
            }
            $haveIndexes[(string) ($component['index'] ?? '0')] = true;
        }

        $out = [];
        foreach ($raw['components'] ?? [] as $component) {
            if (! is_array($component) || strtoupper((string) ($component['type'] ?? '')) !== 'BUTTONS') {
                continue;
            }
            $buttons = $component['buttons'] ?? [];
            if (! is_array($buttons)) {
                continue;
            }
            foreach ($buttons as $index => $button) {
                if (! is_array($button)) {
                    continue;
                }
                if (strtoupper((string) ($button['type'] ?? '')) !== 'URL') {
                    continue;
                }
                $url = (string) ($button['url'] ?? '');
                if ($url === '' || ! preg_match('/\{\{\s*\d+\s*\}\}/', $url)) {
                    continue; // static URL — no send-time parameter needed
                }
                $idx = (string) $index;
                if (isset($haveIndexes[$idx])) {
                    continue;
                }

                $example = '';
                $ex      = $button['example'] ?? null;
                if (is_array($ex) && isset($ex[0]) && is_scalar($ex[0])) {
                    $example = trim((string) $ex[0]);
                } elseif (is_string($ex)) {
                    $example = trim($ex);
                }
                if ($example === '') {
                    continue;
                }

                // Provider expects the dynamic portion for {{1}}. Prefer full example URL when provided.
                $paramText = $example;
                if (preg_match('#https?://[^/]+/(.+)$#', $url, $mUrl)
                    && str_contains($mUrl[1], '{{')
                    && filter_var($example, FILTER_VALIDATE_URL)
                ) {
                    // Keep full example URL — Cheerio tracking buttons often expect the destination URL.
                    $paramText = $example;
                }

                $out[] = [
                    'type'       => 'button',
                    'sub_type'   => 'url',
                    'index'      => $idx,
                    'parameters' => [[
                        'type' => 'text',
                        'text' => $paramText,
                    ]],
                ];
            }
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $components
     */
    protected function componentsHaveMediaHeader(array $components): bool
    {
        foreach ($components as $component) {
            $type = strtolower((string) ($component['type'] ?? ''));
            if (in_array($type, ['image', 'video', 'document'], true)) {
                return true;
            }
            if ($type === 'header') {
                $params = $component['parameters'] ?? null;
                if (is_array($params)) {
                    foreach ($params as $param) {
                        $pType = strtolower((string) ($param['type'] ?? ''));
                        if (in_array($pType, ['image', 'video', 'document'], true)) {
                            return true;
                        }
                    }
                }
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function findLocalTemplate(string $name, string $language): ?array
    {
        try {
            $model = model(\App\Models\TemplateModel::class);
            $row   = $model->where('name', $name)->where('language', $language)->first();
            if ($row === null && $language !== '') {
                $row = $model->where('name', $name)->first();
            }

            return is_array($row) ? $row : null;
        } catch (Throwable $e) {
            log_message('warning', 'findLocalTemplate failed: {msg}', ['msg' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * @param array<string, mixed> $tpl
     */
    protected function extractTemplateHeaderMediaUrl(array $tpl): string
    {
        $direct = trim((string) ($tpl['header_content'] ?? ''));
        if ($direct !== '' && filter_var($direct, FILTER_VALIDATE_URL)) {
            return $direct;
        }

        $raw = $tpl['raw_payload'] ?? null;
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            $raw     = is_array($decoded) ? $decoded : null;
        }
        if (! is_array($raw)) {
            return '';
        }

        $components = $raw['components'] ?? [];
        if (! is_array($components)) {
            return '';
        }

        foreach ($components as $component) {
            if (! is_array($component)) {
                continue;
            }
            if (strtoupper((string) ($component['type'] ?? '')) !== 'HEADER') {
                continue;
            }
            $handles = $component['example']['header_handle'] ?? null;
            if (is_array($handles) && isset($handles[0]) && is_string($handles[0]) && $handles[0] !== '') {
                return $handles[0];
            }
            $link = $component['example']['header_url'] ?? $component['example']['link'] ?? null;
            if (is_string($link) && $link !== '') {
                return $link;
            }
        }

        return '';
    }

    /**
     * Send an image by link or media ID.
     *
     * @return array<string, mixed>
     */
    public function sendImage(string $to, string $linkOrId, ?string $caption = null, bool $byId = false): array
    {
        $image = $byId ? ['id' => $linkOrId] : ['link' => $linkOrId];
        if ($caption !== null && $caption !== '') {
            $image['caption'] = $caption;
        }

        return $this->sendDirect($to, [
            'type'  => 'image',
            'image' => $image,
        ]);
    }

    /**
     * Send a document by link or media ID.
     *
     * @return array<string, mixed>
     */
    public function sendDocument(
        string $to,
        string $linkOrId,
        ?string $caption = null,
        ?string $filename = null,
        bool $byId = false
    ): array {
        $document = $byId ? ['id' => $linkOrId] : ['link' => $linkOrId];
        if ($filename !== null && $filename !== '') {
            $document['filename'] = $filename;
        } else {
            $document['filename'] = 'file';
        }
        if ($caption !== null && $caption !== '') {
            $document['caption'] = $caption;
        }

        return $this->sendDirect($to, [
            'type'     => 'document',
            'document' => $document,
        ]);
    }

    /**
     * Send a video by link or media ID.
     *
     * @return array<string, mixed>
     */
    public function sendVideo(string $to, string $linkOrId, ?string $caption = null, bool $byId = false): array
    {
        $video = $byId ? ['id' => $linkOrId] : ['link' => $linkOrId];
        if ($caption !== null && $caption !== '') {
            $video['caption'] = $caption;
        }

        return $this->sendDirect($to, [
            'type'  => 'video',
            'video' => $video,
        ]);
    }

    /**
     * Send an audio by link or media ID.
     *
     * @return array<string, mixed>
     */
    public function sendAudio(string $to, string $linkOrId, bool $byId = false): array
    {
        $audio = $byId ? ['id' => $linkOrId] : ['link' => $linkOrId];

        return $this->sendDirect($to, [
            'type'  => 'audio',
            'audio' => $audio,
        ]);
    }

    /**
     * Send a location message.
     *
     * @return array<string, mixed>
     */
    public function sendLocation(
        string $to,
        float $lat,
        float $lng,
        string $name = '',
        string $address = ''
    ): array {
        $location = [
            'latitude'  => $lat,
            'longitude' => $lng,
        ];
        if ($name !== '') {
            $location['name'] = $name;
        }
        if ($address !== '') {
            $location['address'] = $address;
        }

        return $this->sendDirect($to, [
            'type'     => 'location',
            'location' => $location,
        ]);
    }

    /**
     * Send interactive reply buttons (max 3).
     *
     * @param list<array{id: string, title: string}> $buttons
     * @param array{type?: string, text?: string}|string|null $header
     *
     * @return array<string, mixed>
     */
    public function sendInteractiveButtons(
        string $to,
        string $bodyText,
        array $buttons,
        array|string|null $header = null,
        ?string $footer = null
    ): array {
        if (count($buttons) < 1 || count($buttons) > 3) {
            throw new RuntimeException('Interactive buttons require 1–3 button entries.');
        }

        $actionButtons = [];
        foreach ($buttons as $index => $button) {
            $actionButtons[] = [
                'type'  => 'reply',
                'reply' => [
                    'id'    => (string) ($button['id'] ?? ('btn_' . $index)),
                    'title' => mb_substr((string) ($button['title'] ?? 'Button'), 0, 20),
                ],
            ];
        }

        $interactive = [
            'type'   => 'button',
            'body'   => ['text' => $bodyText],
            'action' => ['buttons' => $actionButtons],
        ];

        $this->applyInteractiveHeaderFooter($interactive, $header, $footer);

        return $this->sendDirect($to, [
            'type'        => 'interactive',
            'interactive' => $interactive,
        ]);
    }

    /**
     * Alias for sendInteractiveButtons — quick reply / reply buttons.
     *
     * @param list<array{id: string, title: string}> $buttons
     * @param array{type?: string, text?: string}|string|null $header
     *
     * @return array<string, mixed>
     */
    public function sendQuickReply(
        string $to,
        string $bodyText,
        array $buttons,
        array|string|null $header = null,
        ?string $footer = null
    ): array {
        return $this->sendInteractiveButtons($to, $bodyText, $buttons, $header, $footer);
    }

    /**
     * Send an interactive list message.
     *
     * @param list<array{title: string, rows: list<array{id: string, title: string, description?: string}>}> $sections
     * @param array{type?: string, text?: string}|string|null $header
     *
     * @return array<string, mixed>
     */
    public function sendInteractiveList(
        string $to,
        string $bodyText,
        string $buttonText,
        array $sections,
        array|string|null $header = null,
        ?string $footer = null
    ): array {
        if ($sections === []) {
            throw new RuntimeException('Interactive list requires at least one section.');
        }

        $interactive = [
            'type'   => 'list',
            'body'   => ['text' => $bodyText],
            'action' => [
                'button'   => mb_substr($buttonText, 0, 20),
                'sections' => $sections,
            ],
        ];

        $this->applyInteractiveHeaderFooter($interactive, $header, $footer);

        return $this->sendDirect($to, [
            'type'        => 'interactive',
            'interactive' => $interactive,
        ]);
    }

    /**
     * Upload media to Cheerio and return a WhatsApp-compatible { id: ... } payload.
     *
     * @return array<string, mixed>
     *
     * @throws RuntimeException
     */
    public function uploadMedia(string $filePath, string $mimeType): array
    {
        if (! is_file($filePath) || ! is_readable($filePath)) {
            throw new RuntimeException('Media file not found or unreadable: ' . $filePath);
        }

        $result = $this->request('POST', 'v1/whatsapp/media-id', [
            'file' => new \CURLFile($filePath, $mimeType, basename($filePath)),
        ], true);

        $mediaId = $this->extractMediaId($result);
        if ($mediaId === '') {
            throw new RuntimeException('Cheerio media upload did not return a media ID.');
        }

        return array_merge($result, ['id' => $mediaId]);
    }

    /**
     * Resolve media metadata / URL for a media ID.
     *
     * @return array<string, mixed>
     */
    public function getMediaUrl(string $mediaId): array
    {
        return $this->request('POST', 'v1/whatsapp/media', [
            'mediaId' => $mediaId,
        ]);
    }

    /**
     * Download media binary content for a media ID.
     *
     * @return array{content: string, mime_type: string, url: string}
     *
     * @throws RuntimeException
     */
    public function downloadMedia(string $mediaId): array
    {
        $meta = $this->getMediaUrl($mediaId);
        $url  = (string) ($meta['url']
            ?? $meta['data']['url']
            ?? $meta['mediaUrl']
            ?? $meta['download_url']
            ?? '');
        $mime = (string) ($meta['mime_type']
            ?? $meta['mimeType']
            ?? $meta['data']['mime_type']
            ?? 'application/octet-stream');

        if ($url === '') {
            // Some Cheerio responses nest binary or base64; try common keys.
            $inline = $meta['content'] ?? $meta['data']['content'] ?? null;
            if (is_string($inline) && $inline !== '') {
                return [
                    'content'   => $inline,
                    'mime_type' => $mime,
                    'url'       => '',
                ];
            }

            throw new RuntimeException('Media URL missing from Cheerio response for ID: ' . $mediaId);
        }

        $client = Services::curlrequest($this->baseCurlOptions(), null, null, false);

        $response = $client->get($url, [
            'headers' => [
                'x-api-key' => $this->apiKey,
            ],
        ]);

        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException('Failed to download media. HTTP ' . $status);
        }

        return [
            'content'   => (string) $response->getBody(),
            'mime_type' => $mime,
            'url'       => $url,
        ];
    }

    /**
     * List message templates from Cheerio.
     *
     * @return array<string, mixed>
     */
    public function getTemplates(?string $wabaId = null): array
    {
        unset($wabaId); // Cheerio scopes templates by API key / account.

        $all   = [];
        $after = null;
        $guard = 0;

        do {
            $query = ['limit' => 500];
            if ($after) {
                $query['after'] = $after;
            }

            $page = $this->request('GET', 'v1/getAllTemplates', $query);
            $rows = $page['data'] ?? [];
            if (is_array($rows)) {
                foreach ($rows as $row) {
                    $all[] = $row;
                }
            }

            $after = $page['paginate']['after'] ?? $page['paging']['cursors']['after'] ?? null;
            $guard++;
        } while ($after && $guard < 50);

        return [
            'data'   => $all,
            'paging' => ['cursors' => ['after' => null]],
            'total'  => count($all),
        ];
    }

    /**
     * Submit a new message template via Cheerio.
     *
     * @param array{
     *     name: string,
     *     language: string,
     *     category: string,
     *     components: list<array<string, mixed>>,
     *     allow_category_change?: bool
     * } $payload
     *
     * @return array<string, mixed>
     */
    public function createTemplate(array $payload): array
    {
        $name = trim((string) ($payload['name'] ?? ''));
        if ($name === '' || ! preg_match('/^[a-z0-9_]+$/', $name)) {
            throw new RuntimeException('Template name must be lowercase letters, numbers, and underscores only.');
        }

        $body = [
            'name'       => $name,
            'language'   => (string) ($payload['language'] ?? 'en'),
            'category'   => strtoupper((string) ($payload['category'] ?? 'UTILITY')),
            'components' => $payload['components'] ?? [],
        ];

        return $this->request('POST', 'v1/whatsapp/create-template', $body);
    }

    /**
     * Delete is not exposed on Cheerio Direct APIs.
     *
     * @return array<string, mixed>
     *
     * @throws RuntimeException
     */
    public function deleteTemplate(string $name, ?string $hsmId = null): array
    {
        unset($name, $hsmId);

        throw new RuntimeException(
            'Delete template is not available via Cheerio Direct APIs. Remove the template from the Cheerio Dashboard.'
        );
    }

    /**
     * Fetch a single template by name or id.
     *
     * @return array<string, mixed>
     */
    public function getTemplateByNameOrId(string $nameOrId, string $by = 'name'): array
    {
        $endpoint = 'v1/whatsapp/template/' . rawurlencode($nameOrId);
        $query    = [];
        if ($by === 'id') {
            $query['by'] = 'id';
        }

        return $this->request('GET', $endpoint, $query !== [] ? $query : null);
    }

    /**
     * Poll delivery status for a WhatsApp message id (wamid).
     *
     * @return array<string, mixed>
     */
    public function getMessageStatus(string $wamid): array
    {
        return $this->request('GET', 'v1/whatsapp-status/' . rawurlencode($wamid));
    }

    /**
     * Map Cheerio status API response → local status (sent|delivered|read|failed).
     *
     * Cheerio sometimes returns a stale failedAt that is earlier than sentAt (race on their side).
     * Always pick the chronologically latest timestamp; on ties prefer the higher delivery rank.
     */
    public function normalizeMessageStatus(array $response): ?string
    {
        $data  = is_array($response['data'] ?? null) ? $response['data'] : $response;
        $times = is_array($data['status_updatedAt'] ?? null) ? $data['status_updatedAt'] : [];

        if ($times !== []) {
            // Higher rank wins on equal timestamps.
            $candidates = [
                'failedAt'   => ['status' => 'failed', 'rank' => 1],
                'queueAt'    => ['status' => 'sent', 'rank' => 2],
                'sentAt'     => ['status' => 'sent', 'rank' => 3],
                'deliveryAt' => ['status' => 'delivered', 'rank' => 4],
                'readAt'     => ['status' => 'read', 'rank' => 5],
            ];

            $bestStatus = null;
            $bestTs     = null;
            $bestRank   = -1;

            foreach ($candidates as $key => $meta) {
                $raw = $times[$key] ?? null;
                if ($raw === null || $raw === '' || $raw === false) {
                    continue;
                }
                $ts = strtotime((string) $raw);
                if ($ts === false) {
                    continue;
                }
                if (
                    $bestTs === null
                    || $ts > $bestTs
                    || ($ts === $bestTs && $meta['rank'] > $bestRank)
                ) {
                    $bestTs     = $ts;
                    $bestRank   = $meta['rank'];
                    $bestStatus = $meta['status'];
                }
            }

            if ($bestStatus !== null) {
                return $bestStatus;
            }
        }

        $raw = strtolower((string) ($data['status'] ?? ''));
        if (in_array($raw, ['sent', 'delivered', 'read', 'failed'], true)) {
            return $raw;
        }

        // Do not treat HTTP/wrapper numeric status (e.g. 200) as a delivery state.
        $wrapper = $response['status'] ?? null;
        if (is_string($wrapper)) {
            $wrapper = strtolower($wrapper);
            if (in_array($wrapper, ['sent', 'delivered', 'read', 'failed'], true)) {
                return $wrapper;
            }
        }

        return null;
    }

    /**
     * Fetch + normalize in one call.
     */
    public function resolveDeliveryStatus(string $wamid): ?string
    {
        if ($wamid === '') {
            return null;
        }

        return $this->normalizeMessageStatus($this->getMessageStatus($wamid));
    }

    /**
     * List contacts from Cheerio (paginated).
     *
     * @return list<array<string, mixed>>
     */
    public function getContacts(?string $search = null, int $maxPages = 50): array
    {
        $all   = [];
        $page  = 1;
        $guard = 0;

        do {
            $query = ['page' => $page, 'limit' => 100];
            if ($search !== null && $search !== '') {
                $query['search'] = $search;
            }

            $response = $this->request('GET', 'v1/contact/getAll', $query);
            $docs     = $this->extractContactDocs($response);

            foreach ($docs as $doc) {
                if (is_array($doc)) {
                    $all[] = $doc;
                }
            }

            $hasNext = false;
            $data    = is_array($response['data'] ?? null) ? $response['data'] : $response;
            if (is_array($data)) {
                $hasNext = ! empty($data['hasNextPage'])
                    || (isset($data['page'], $data['totalPages']) && (int) $data['page'] < (int) $data['totalPages']);
                if (isset($data['nextPage']) && $data['nextPage'] !== null) {
                    $hasNext = true;
                    $page    = (int) $data['nextPage'];
                } else {
                    $page++;
                }
            } else {
                $page++;
            }

            // Stop when this page returned nothing or a short page (end of list).
            if ($docs === [] || count($docs) < 100) {
                $hasNext = false;
            }

            $guard++;
        } while ($hasNext && $guard < $maxPages);

        return $all;
    }

    /**
     * List Cheerio workflows.
     *
     * @return list<array<string, mixed>>
     */
    public function getWorkflows(): array
    {
        $response = $this->request('GET', 'v1/workflows');
        $data     = $response['data'] ?? $response;

        if (! is_array($data)) {
            return [];
        }

        // Envelope: { status, flag, data: [ ... ] }
        if (array_is_list($data)) {
            return $data;
        }

        if (isset($data['docs']) && is_array($data['docs'])) {
            return array_values(array_filter($data['docs'], 'is_array'));
        }

        return [];
    }

    /**
     * @param array<string, mixed> $response
     *
     * @return list<array<string, mixed>>
     */
    protected function extractContactDocs(array $response): array
    {
        $data = $response['data'] ?? $response;

        if (! is_array($data)) {
            return [];
        }

        if (isset($data['docs']) && is_array($data['docs'])) {
            return array_values(array_filter($data['docs'], 'is_array'));
        }

        if (array_is_list($data)) {
            return array_values(array_filter($data, 'is_array'));
        }

        return [];
    }

    /**
     * Phone metadata is not a Cheerio Direct endpoint — return empty for callers.
     *
     * @return array<string, mixed>
     */
    public function getPhoneNumberInfo(): array
    {
        $this->ensureConfigured();

        return [
            'id'                   => (string) $this->settings->get('cheerio_phone_number_id', ''),
            'display_phone_number' => (string) $this->settings->get('cheerio_display_phone', ''),
            'verified_name'        => 'Cheerio Direct API',
            'provider'             => 'cheerio',
        ];
    }

    /**
     * Validate Cheerio API key and return a readiness summary.
     *
     * @return array{
     *     ok: bool,
     *     phone: array<string, mixed>,
     *     templates_reachable: bool,
     *     template_count: int,
     *     looks_like_test: bool,
     *     checklist: list<array{id: string, label: string, ok: bool, detail: string}>
     * }
     */
    public function testConnection(): array
    {
        $this->loadCredentials();

        $checklist = [];

        $checklist[] = [
            'id'     => 'api_key',
            'label'  => 'Cheerio API key configured',
            'ok'     => $this->apiKey !== '',
            'detail' => $this->apiKey !== '' ? 'API key is set' : 'Add a Cheerio API key in Settings',
        ];

        $templatesReachable = false;
        $templateCount      = 0;
        $approvedTemplates  = 0;
        $phone              = $this->getPhoneNumberInfo();

        if ($this->apiKey !== '') {
            try {
                $page = $this->request('GET', 'v1/getAllTemplates', ['limit' => 100]);
                $rows = $page['data'] ?? [];
                $templatesReachable = true;
                if (is_array($rows)) {
                    $templateCount = count($rows);
                    foreach ($rows as $row) {
                        if (strtoupper((string) ($row['status'] ?? '')) === 'APPROVED') {
                            $approvedTemplates++;
                        }
                    }
                }

                $checklist[] = [
                    'id'     => 'templates_api',
                    'label'  => 'Templates reachable via Cheerio API',
                    'ok'     => true,
                    'detail' => sprintf('%d template(s) listed (%d approved)', $templateCount, $approvedTemplates),
                ];
            } catch (Throwable $e) {
                $checklist[] = [
                    'id'     => 'templates_api',
                    'label'  => 'Templates reachable via Cheerio API',
                    'ok'     => false,
                    'detail' => $e->getMessage(),
                ];
            }
        } else {
            $checklist[] = [
                'id'     => 'templates_api',
                'label'  => 'Templates reachable via Cheerio API',
                'ok'     => false,
                'detail' => 'Cannot check templates until API key is set.',
            ];
        }

        $checklist[] = [
            'id'     => 'approved_templates',
            'label'  => 'At least one APPROVED template',
            'ok'     => $approvedTemplates > 0,
            'detail' => $approvedTemplates > 0
                ? sprintf('%d approved', $approvedTemplates)
                : 'Create/submit a template in Cheerio Dashboard (or via Templates → Create) and wait for approval',
        ];

        $ok = true;
        foreach ($checklist as $item) {
            if (in_array($item['id'], ['api_key', 'templates_api'], true) && ! $item['ok']) {
                $ok = false;
                break;
            }
        }

        return [
            'ok'                  => $ok,
            'phone'               => $phone,
            'templates_reachable' => $templatesReachable,
            'template_count'      => $templateCount,
            'approved_templates'  => $approvedTemplates,
            'looks_like_test'     => false,
            'checklist'           => $checklist,
        ];
    }

    /**
     * Cheerio Direct APIs do not expose mark-as-read — no-op for Chat UX.
     *
     * @return array<string, mixed>
     */
    public function markAsRead(string $messageId): array
    {
        return [
            'success'    => true,
            'skipped'    => true,
            'message_id' => $messageId,
            'provider'   => 'cheerio',
        ];
    }

    /**
     * Normalize a phone number to digits only (E.164 without +).
     */
    public function normalizePhone(string $phone): string
    {
        return preg_replace('/\D+/', '', $phone) ?? '';
    }

    /**
     * Build and send a direct/session message payload.
     *
     * @param array<string, mixed> $typePayload
     *
     * @return array<string, mixed>
     */
    protected function sendDirect(string $to, array $typePayload): array
    {
        $phone = $this->normalizePhone($to);
        if ($phone === '') {
            throw new RuntimeException('Recipient phone number is required.');
        }

        $payload = array_merge([
            'recipient_type' => 'individual',
            'to'             => $phone,
        ], $typePayload);

        return $this->request('POST', 'v1/whatsapp/direct/send', $payload);
    }

    /**
     * @deprecated Use sendDirect — kept for any old internal calls.
     *
     * @param array<string, mixed> $typePayload
     *
     * @return array<string, mixed>
     */
    protected function sendMessage(string $to, array $typePayload): array
    {
        return $this->sendDirect($to, $typePayload);
    }

    /**
     * @param array<string, mixed> $interactive
     * @param array{type?: string, text?: string}|string|null $header
     */
    protected function applyInteractiveHeaderFooter(array &$interactive, array|string|null $header, ?string $footer): void
    {
        if (is_string($header) && $header !== '') {
            $interactive['header'] = ['type' => 'text', 'text' => $header];
        } elseif (is_array($header) && $header !== []) {
            $interactive['header'] = $header;
        }

        if ($footer !== null && $footer !== '') {
            $interactive['footer'] = ['text' => $footer];
        }
    }

    protected function buildUrl(string $endpoint): string
    {
        $endpoint = ltrim($endpoint, '/');
        $base     = rtrim($this->config->baseUrl, '/');

        return $base . '/' . $endpoint;
    }

    /**
     * @return array<string, mixed>
     */
    protected function baseCurlOptions(): array
    {
        return [
            'timeout'     => $this->config->defaultTimeout,
            'http_errors' => false,
            'verify'      => $this->resolveSslVerify(),
        ];
    }

    /**
     * @return bool|string
     */
    protected function resolveSslVerify(): bool|string
    {
        $configured = $this->config->sslVerify;

        if (is_bool($configured)) {
            return $configured;
        }

        if (is_string($configured) && $configured !== '' && is_file($configured)) {
            return $configured;
        }

        $candidates = [
            getenv('CURL_CA_BUNDLE') ?: null,
            getenv('SSL_CERT_FILE') ?: null,
            ini_get('curl.cainfo') ?: null,
            ini_get('openssl.cafile') ?: null,
            WRITEPATH . 'certs' . DIRECTORY_SEPARATOR . 'cacert.pem',
        ];

        foreach ($candidates as $path) {
            if (! is_string($path) || $path === '') {
                continue;
            }

            $path = trim($path, " \t\"'");
            if ($path !== '' && is_file($path)) {
                return $path;
            }
        }

        return true;
    }

    /**
     * @throws RuntimeException
     */
    protected function ensureConfigured(): void
    {
        if ($this->apiKey === '') {
            $this->loadCredentials();
        }

        if ($this->apiKey === '') {
            throw new RuntimeException('Cheerio API key is not configured.');
        }
    }

    /**
     * Unwrap Cheerio { status, flag, data } envelopes so callers always see
     * messaging_product / messages / contacts at the top level when present.
     *
     * @param array<string, mixed> $decoded
     *
     * @return array<string, mixed>
     */
    protected function normalizeSuccessPayload(array $decoded): array
    {
        if (isset($decoded['data']) && is_array($decoded['data'])) {
            $inner = $decoded['data'];
            if (isset($inner['messages']) || isset($inner['messaging_product']) || isset($inner['contacts'])) {
                return array_merge($decoded, $inner);
            }
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $decoded
     */
    protected function extractApiError(array $decoded): string
    {
        // Some gateways return a bare string encoded as a char list: ["B","a","d",...].
        if ($decoded !== [] && array_is_list($decoded)) {
            $allSingleChars = true;
            foreach ($decoded as $part) {
                if (! is_string($part) || strlen($part) !== 1) {
                    $allSingleChars = false;
                    break;
                }
            }
            if ($allSingleChars) {
                return implode('', $decoded);
            }

            $stringParts = array_values(array_filter($decoded, static fn ($v) => is_string($v) && $v !== ''));
            if ($stringParts !== []) {
                return implode(' | ', $stringParts);
            }
        }

        if (isset($decoded['error']) && is_array($decoded['error'])) {
            $error = $decoded['error'];
            $parts = array_filter([
                $this->stringifyErrorPart($error['message'] ?? null),
                isset($error['error_user_title']) ? (string) $error['error_user_title'] : null,
                isset($error['error_user_msg']) ? (string) $error['error_user_msg'] : null,
                isset($error['error_data']['details']) ? $this->stringifyErrorPart($error['error_data']['details']) : null,
                isset($error['type']) ? 'type=' . $error['type'] : null,
                isset($error['code']) ? 'code=' . $error['code'] : null,
            ]);

            return implode(' | ', $parts) ?: 'API error object';
        }

        // Cheerio sometimes returns WhatsApp Graph errors flattened at the top level.
        $flatParts = array_filter([
            $this->stringifyErrorPart($decoded['message'] ?? null),
            isset($decoded['error_user_title']) && is_string($decoded['error_user_title']) ? $decoded['error_user_title'] : null,
            isset($decoded['error_user_msg']) && is_string($decoded['error_user_msg']) ? $decoded['error_user_msg'] : null,
            isset($decoded['error_data']['details']) ? $this->stringifyErrorPart($decoded['error_data']['details']) : null,
        ]);
        if ($flatParts !== []) {
            return implode(' | ', $flatParts);
        }

        foreach (['Message', 'error_message', 'msg', 'error', 'raw'] as $key) {
            $value = $this->stringifyErrorPart($decoded[$key] ?? null);
            if ($value !== null && $value !== '') {
                return $value;
            }
        }

        if (isset($decoded['data']) && is_array($decoded['data'])) {
            $nested = $this->extractApiError($decoded['data']);
            if ($nested !== '' && $nested !== '[]' && $nested !== '{}') {
                return $nested;
            }
        }

        if (isset($decoded['flag']) && $decoded['flag'] === false) {
            $flagMessage = $this->stringifyErrorPart($decoded['message'] ?? null);
            if ($flagMessage !== null && $flagMessage !== '') {
                return $flagMessage;
            }
        }

        return json_encode($decoded) ?: 'Unparseable Cheerio error response';
    }

    protected function stringifyErrorPart(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (is_string($value)) {
            return $value !== '' ? $value : null;
        }
        if (is_scalar($value)) {
            return (string) $value;
        }
        if (is_array($value)) {
            if ($value !== [] && array_is_list($value)) {
                $allSingleChars = true;
                foreach ($value as $part) {
                    if (! is_string($part) || strlen($part) !== 1) {
                        $allSingleChars = false;
                        break;
                    }
                }
                if ($allSingleChars) {
                    return implode('', $value);
                }
            }

            return json_encode($value) ?: null;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $result
     */
    protected function extractMediaId(array $result): string
    {
        $candidates = [
            $result['id'] ?? null,
            $result['mediaId'] ?? null,
            $result['media_id'] ?? null,
            $result['data']['id'] ?? null,
            $result['data']['mediaId'] ?? null,
            $result['data']['media_id'] ?? null,
        ];

        foreach ($candidates as $value) {
            if (is_scalar($value) && (string) $value !== '') {
                return (string) $value;
            }
        }

        return '';
    }
}
