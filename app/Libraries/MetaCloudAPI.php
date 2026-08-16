<?php

declare(strict_types=1);

namespace App\Libraries;

use Config\Services;
use Config\WhatsApp as WhatsAppConfig;
use RuntimeException;
use Throwable;

/**
 * Meta WhatsApp Cloud API (graph.facebook.com) client.
 *
 * Public method names match CheerioDirectAPI / WhatsAppCloudAPI facade.
 */
class MetaCloudAPI
{
    protected SettingsService $settings;
    protected WhatsAppConfig $config;

    protected string $accessToken = '';
    protected string $phoneNumberId = '';
    protected string $wabaId = '';
    protected string $apiVersion = 'v25.0';

    public function __construct(?SettingsService $settings = null, ?WhatsAppConfig $config = null)
    {
        $this->settings = $settings ?? new SettingsService();
        $this->config   = $config ?? config(WhatsAppConfig::class);
        $this->loadCredentials();
    }

    public function loadCredentials(): void
    {
        $meta = $this->settings->getMetaConfig();

        $this->accessToken   = (string) ($meta['access_token'] ?? '');
        $this->phoneNumberId = (string) ($meta['phone_number_id'] ?? '');
        $this->wabaId        = (string) ($meta['waba_id'] ?? '');
        $this->apiVersion    = (string) ($meta['api_version'] ?? $this->config->graphApiVersion ?? 'v25.0') ?: 'v25.0';
    }

    /**
     * @param array<string, mixed>|null $data
     *
     * @return array<string, mixed>
     */
    public function request(string $method, string $endpoint, ?array $data = null, bool $isMultipart = false): array
    {
        $this->ensureConfigured(false);

        $method    = strtoupper($method);
        $url       = $this->buildUrl($endpoint);
        $attempts  = 0;
        $lastError = 'Unknown Meta Graph API error';

        while ($attempts <= $this->config->maxRetries) {
            $attempts++;

            try {
                $client = Services::curlrequest($this->baseCurlOptions(), null, null, false);

                $options = [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $this->accessToken,
                        'Accept'        => 'application/json',
                    ],
                ];

                if ($isMultipart && $data !== null) {
                    // CodeIgniter CurlRequest expects CURLFile values keyed by field name.
                    // Guzzle-style ['contents'=>..., 'headers'=>...] arrays cause "Array to string conversion".
                    $multipart = [];
                    foreach ($data as $name => $value) {
                        if ($value instanceof \CURLFile) {
                            $multipart[$name] = $value;
                            continue;
                        }

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
                                    $tmp = tempnam(sys_get_temp_dir(), 'meta_up_');
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
                                $tmp = tempnam(sys_get_temp_dir(), 'meta_up_');
                                if ($tmp === false || file_put_contents($tmp, $contents) === false) {
                                    throw new RuntimeException('Unable to stage media upload contents.');
                                }
                                $multipart[$name] = new \CURLFile($tmp, $mime, $filename);
                            } else {
                                throw new RuntimeException('Unsupported multipart file contents for field: ' . $name);
                            }
                            continue;
                        }

                        $multipart[$name] = is_scalar($value) || $value === null
                            ? (string) $value
                            : (string) json_encode($value);
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
                    return $decoded;
                }

                $parsed     = MetaApiErrorMapper::parseDecoded($decoded);
                $apiMessage = $this->extractApiError($decoded);
                $lastError  = sprintf('HTTP %d: %s', $status, $apiMessage);

                MetaGraphLogger::log('graph.request.error', [
                    'waba_id'          => $this->wabaId,
                    'phone_number_id'  => $this->phoneNumberId,
                    'meta_status'      => $status,
                    'meta_error_code'  => $parsed['code'] ?? '',
                    'detail'           => $method . ' ' . $endpoint . ' :: ' . mb_substr($apiMessage, 0, 400),
                ], 'error');

                if (in_array($status, [429, 500, 502, 503, 504], true) && $attempts <= $this->config->maxRetries) {
                    $delay = $this->config->retryDelaySeconds * (2 ** ($attempts - 1));
                    usleep((int) ($delay * 1_000_000));
                    continue;
                }

                $human = MetaApiErrorMapper::humanize($apiMessage, $status, $parsed['code'] ?? null);
                throw new RuntimeException($human, $status);
            } catch (RuntimeException $e) {
                throw $e;
            } catch (Throwable $e) {
                $lastError = $e->getMessage();
                if ($attempts <= $this->config->maxRetries) {
                    $delay = $this->config->retryDelaySeconds * (2 ** ($attempts - 1));
                    usleep((int) ($delay * 1_000_000));
                    continue;
                }

                throw new RuntimeException('Meta WhatsApp API request failed: ' . $lastError, 0, $e);
            }
        }

        throw new RuntimeException('Meta WhatsApp API error after retries: ' . $lastError);
    }

    /**
     * @return array<string, mixed>
     */
    public function sendText(string $to, string $text, bool $previewUrl = false): array
    {
        return $this->sendMessage($to, [
            'type' => 'text',
            'text' => [
                'preview_url' => $previewUrl,
                'body'        => $text,
            ],
        ]);
    }

    /**
     * Meta Graph API: POST /{phone-number-id}/messages
     * Body: { messaging_product, to, type: template, template: { name, language, components? } }
     *
     * No Cheerio auto-fill — caller must supply required header/body/button components.
     *
     * @param list<array<string, mixed>> $components
     *
     * @return array<string, mixed>
     */
    public function sendTemplate(string $to, string $templateName, string $language, array $components = []): array
    {
        $lang = $language !== '' ? $language : 'en_US';
        $components = $this->prepareTemplateComponents($templateName, $lang, $components);

        $payload = [
            'type'     => 'template',
            'template' => [
                'name'     => $templateName,
                'language' => ['code' => $lang],
            ],
        ];

        if ($components !== []) {
            $payload['template']['components'] = $components;
        }

        MetaGraphLogger::log('messages.template.send', [
            'waba_id'         => $this->wabaId,
            'phone_number_id' => $this->phoneNumberId,
            'template_name'   => $templateName,
            'detail'          => 'language=' . $lang,
        ]);

        try {
            $result = $this->sendMessage($to, $payload);
            MetaGraphLogger::log('messages.template.sent', [
                'waba_id'         => $this->wabaId,
                'phone_number_id' => $this->phoneNumberId,
                'template_name'   => $templateName,
                'meta_status'     => 200,
            ]);

            return $result;
        } catch (Throwable $e) {
            MetaGraphLogger::log('messages.template.error', [
                'waba_id'         => $this->wabaId,
                'phone_number_id' => $this->phoneNumberId,
                'template_name'   => $templateName,
                'meta_status'     => (string) $e->getCode(),
                'detail'          => $e->getMessage(),
            ], 'error');

            throw $e;
        }
    }

    /**
     * Cheerio-only bulk campaign API. Meta campaigns use the local message queue.
     *
     * @param list<array{to: string, components?: list<array<string, mixed>>}> $recipients
     *
     * @return array<string, mixed>
     *
     * @throws RuntimeException
     */
    public function sendBulkCampaign(
        string $campaignName,
        string $templateName,
        string $language,
        array $recipients,
        int $batchSize = 100
    ): array {
        unset($campaignName, $templateName, $language, $recipients, $batchSize);

        throw new RuntimeException(
            'Bulk campaign send (/v1/whatsapp/multiple) is Cheerio-only. Use the local queue for Meta campaigns.'
        );
    }

    /**
     * Cheerio-only campaign analytics.
     *
     * @return array<string, mixed>
     *
     * @throws RuntimeException
     */
    public function getCampaignSummary(string $id): array
    {
        unset($id);

        throw new RuntimeException(
            'Campaign summary analytics is Cheerio-only (GET /v1/analytics/summary/:id).'
        );
    }

    /**
     * Meta Graph prepare: rewrite local media URLs → media ids, validate required header.
     * Does NOT auto-fill body/button/carousel samples (that is Cheerio-only).
     *
     * @param list<array<string, mixed>> $components
     *
     * @return list<array<string, mixed>>
     */
    public function ensureTemplateComponents(string $templateName, string $language, array $components): array
    {
        return $this->prepareTemplateComponents($templateName, $language, $components);
    }

    /**
     * @param list<array<string, mixed>> $components
     *
     * @return list<array<string, mixed>>
     */
    protected function prepareTemplateComponents(string $templateName, string $language, array $components): array
    {
        $components = array_values(array_filter($components, 'is_array'));
        $components = $this->rewriteLocalHeaderMediaToIds($components);
        $this->assertRequiredMediaHeader($templateName, $language, $components);

        return array_values($components);
    }

    /**
     * Localhost /media/serve links are not reachable by WhatsApp. Prefer uploaded Meta media IDs.
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
                            . $link . '). Re-upload the header media, then send again.'
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
        $filename = LocalMediaUrl::filenameFromUrl($url);

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
     * Meta requires the caller to supply media headers — no Cheerio-style sample auto-fill.
     *
     * @param list<array<string, mixed>> $components
     */
    protected function assertRequiredMediaHeader(string $templateName, string $language, array $components): void
    {
        if (WhatsAppTemplatePayload::hasMediaHeader($components)) {
            return;
        }

        $tpl = $this->findLocalTemplate($templateName, $language);
        if ($tpl === null) {
            return;
        }

        $templateType = strtolower((string) ($tpl['template_type'] ?? 'default'));
        if ($templateType === 'carousel') {
            return;
        }

        $headerType = WhatsAppTemplatePayload::headerTypeFromTemplate($tpl);
        if ($headerType === '') {
            return;
        }

        throw new RuntimeException(
            'Template "' . $templateName . '" requires a '
            . strtoupper($headerType)
            . ' header at send time (Meta Cloud API). Upload matching media in Chat → Send Template'
            . ' or the campaign wizard, then send again.'
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function findLocalTemplate(string $name, string $language): ?array
    {
        $name = trim($name);
        if ($name === '') {
            return null;
        }

        $model = model(\App\Models\TemplateModel::class);
        $row   = $model->where('name', $name)->where('language', $language)->first();
        if (! is_array($row) && $language !== '') {
            $row = $model->where('name', $name)->orderBy('id', 'DESC')->first();
        }

        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function sendImage(string $to, string $linkOrId, ?string $caption = null, bool $byId = false): array
    {
        $image = $byId ? ['id' => $linkOrId] : ['link' => $linkOrId];
        if ($caption !== null && $caption !== '') {
            $image['caption'] = $caption;
        }

        return $this->sendMessage($to, ['type' => 'image', 'image' => $image]);
    }

    /**
     * @return array<string, mixed>
     */
    public function sendDocument(
        string $to,
        string $linkOrId,
        ?string $caption = null,
        ?string $filename = null,
        bool $byId = false
    ): array {
        $doc = $byId ? ['id' => $linkOrId] : ['link' => $linkOrId];
        if ($caption !== null && $caption !== '') {
            $doc['caption'] = $caption;
        }
        if ($filename !== null && $filename !== '') {
            $doc['filename'] = $filename;
        }

        return $this->sendMessage($to, ['type' => 'document', 'document' => $doc]);
    }

    /**
     * @return array<string, mixed>
     */
    public function sendVideo(string $to, string $linkOrId, ?string $caption = null, bool $byId = false): array
    {
        $video = $byId ? ['id' => $linkOrId] : ['link' => $linkOrId];
        if ($caption !== null && $caption !== '') {
            $video['caption'] = $caption;
        }

        return $this->sendMessage($to, ['type' => 'video', 'video' => $video]);
    }

    /**
     * @return array<string, mixed>
     */
    public function sendAudio(string $to, string $linkOrId, bool $byId = false): array
    {
        $audio = $byId ? ['id' => $linkOrId] : ['link' => $linkOrId];

        return $this->sendMessage($to, ['type' => 'audio', 'audio' => $audio]);
    }

    /**
     * @return array<string, mixed>
     */
    public function sendLocation(
        string $to,
        float $latitude,
        float $longitude,
        ?string $name = null,
        ?string $address = null
    ): array {
        $location = [
            'latitude'  => $latitude,
            'longitude' => $longitude,
        ];
        if ($name !== null) {
            $location['name'] = $name;
        }
        if ($address !== null) {
            $location['address'] = $address;
        }

        return $this->sendMessage($to, ['type' => 'location', 'location' => $location]);
    }

    /**
     * @param list<array<string, mixed>> $buttons
     *
     * @return array<string, mixed>
     */
    public function sendInteractiveButtons(string $to, string $bodyText, array $buttons, ?string $header = null, ?string $footer = null): array
    {
        $actionButtons = [];
        foreach (array_slice($buttons, 0, 3) as $i => $btn) {
            $actionButtons[] = [
                'type'  => 'reply',
                'reply' => [
                    'id'    => (string) ($btn['id'] ?? ('btn_' . $i)),
                    'title' => mb_substr((string) ($btn['title'] ?? $btn['text'] ?? 'Option'), 0, 20),
                ],
            ];
        }

        $interactive = [
            'type'   => 'button',
            'body'   => ['text' => $bodyText],
            'action' => ['buttons' => $actionButtons],
        ];
        if ($header) {
            $interactive['header'] = ['type' => 'text', 'text' => $header];
        }
        if ($footer) {
            $interactive['footer'] = ['text' => $footer];
        }

        return $this->sendMessage($to, ['type' => 'interactive', 'interactive' => $interactive]);
    }

    /**
     * @param list<array<string, mixed>> $buttons
     *
     * @return array<string, mixed>
     */
    public function sendQuickReply(string $to, string $bodyText, array $buttons): array
    {
        return $this->sendInteractiveButtons($to, $bodyText, $buttons);
    }

    /**
     * @param list<array<string, mixed>> $sections
     *
     * @return array<string, mixed>
     */
    public function sendInteractiveList(
        string $to,
        string $bodyText,
        string $buttonText,
        array $sections,
        ?string $header = null,
        ?string $footer = null
    ): array {
        $interactive = [
            'type'   => 'list',
            'body'   => ['text' => $bodyText],
            'action' => [
                'button'   => mb_substr($buttonText, 0, 20),
                'sections' => $sections,
            ],
        ];
        if ($header) {
            $interactive['header'] = ['type' => 'text', 'text' => $header];
        }
        if ($footer) {
            $interactive['footer'] = ['text' => $footer];
        }

        return $this->sendMessage($to, ['type' => 'interactive', 'interactive' => $interactive]);
    }

    /**
     * @return array<string, mixed>
     */
    public function uploadMedia(string $filePath, string $mimeType): array
    {
        $this->ensureConfigured(true);
        if (! is_file($filePath) || ! is_readable($filePath)) {
            throw new RuntimeException('Media file not found: ' . $filePath);
        }

        // Use native cURL + CURLFile. CodeIgniter CurlRequest multipart is easy to
        // misconfigure and previously caused "Array to string conversion" here.
        $url = $this->buildUrl($this->phoneNumberId . '/media');
        $ch  = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Unable to initialize media upload request.');
        }

        $postFields = [
            'messaging_product' => 'whatsapp',
            'type'              => $mimeType,
            'file'              => new \CURLFile($filePath, $mimeType, basename($filePath)),
        ];

        $curlOpts = [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $postFields,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $this->accessToken,
                'Accept: application/json',
            ],
            CURLOPT_TIMEOUT        => (int) ($this->config->defaultTimeout ?: 60),
        ];

        // Match request() / Cheerio SSL handling — WAMP often has empty curl.cainfo.
        $verify = $this->resolveSslVerify();
        if ($verify === false) {
            $curlOpts[CURLOPT_SSL_VERIFYPEER] = false;
            $curlOpts[CURLOPT_SSL_VERIFYHOST] = 0;
        } elseif (is_string($verify)) {
            $curlOpts[CURLOPT_SSL_VERIFYPEER] = true;
            $curlOpts[CURLOPT_SSL_VERIFYHOST] = 2;
            $curlOpts[CURLOPT_CAINFO]         = $verify;
        } else {
            $curlOpts[CURLOPT_SSL_VERIFYPEER] = true;
            $curlOpts[CURLOPT_SSL_VERIFYHOST] = 2;
        }

        curl_setopt_array($ch, $curlOpts);

        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false || $errno !== 0) {
            throw new RuntimeException(
                'Meta media upload failed: ' . $this->humanizeConnectionError($error !== '' ? $error : 'cURL error ' . $errno)
            );
        }

        $decoded = json_decode((string) $body, true);
        if (! is_array($decoded)) {
            $decoded = ['raw' => $body];
        }

        if ($status < 200 || $status >= 300) {
            log_message('error', 'MetaCloudAPI media upload error body: {body}', [
                'body' => mb_substr((string) $body, 0, 4000),
            ]);
            throw new RuntimeException(
                'Meta WhatsApp API error: HTTP ' . $status . ': ' . $this->extractApiError($decoded),
                $status
            );
        }

        $mediaId = trim((string) ($decoded['id'] ?? ''));
        if ($mediaId === '') {
            throw new RuntimeException('Meta media upload did not return a media ID.');
        }

        return [
            'id'       => $mediaId,
            'raw'      => $decoded,
            'provider' => 'meta',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getMediaUrl(string $mediaId): array
    {
        $result = $this->request('GET', $mediaId);

        return [
            'url'      => (string) ($result['url'] ?? ''),
            'mime_type'=> (string) ($result['mime_type'] ?? ''),
            'sha256'   => (string) ($result['sha256'] ?? ''),
            'file_size'=> $result['file_size'] ?? null,
            'raw'      => $result,
        ];
    }

    /**
     * @return array{contents: string, mime_type: string, url: string}
     */
    public function downloadMedia(string $mediaId): array
    {
        $info = $this->getMediaUrl($mediaId);
        $url  = (string) ($info['url'] ?? '');
        if ($url === '') {
            throw new RuntimeException('Meta media URL missing for id ' . $mediaId);
        }

        $client = Services::curlrequest($this->baseCurlOptions(), null, null, false);
        $response = $client->request('GET', $url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->accessToken,
            ],
        ]);

        return [
            'contents'  => (string) $response->getBody(),
            'mime_type' => (string) ($info['mime_type'] ?? $response->getHeaderLine('Content-Type')),
            'url'       => $url,
        ];
    }

    /**
     * List all message templates for the WABA (paginated).
     * Full pagination is required so sync prune does not disable templates beyond page 1.
     *
     * @return array<string, mixed>
     */
    public function getTemplates(?string $wabaId = null): array
    {
        $waba = $wabaId ?: $this->wabaId;
        if ($waba === '') {
            throw new RuntimeException('Meta WABA ID is required to list templates.');
        }

        $all    = [];
        $after  = null;
        $guard  = 0;
        $lastRaw = [];

        do {
            $query = ['limit' => 100];
            if (is_string($after) && $after !== '') {
                $query['after'] = $after;
            }

            $result = $this->request('GET', $waba . '/message_templates', $query);
            $lastRaw = $result;
            $rows    = $result['data'] ?? [];
            if (is_array($rows)) {
                foreach ($rows as $row) {
                    $all[] = $row;
                }
            }

            $after = $result['paging']['cursors']['after'] ?? null;
            if (! is_string($after) || $after === '') {
                $after = null;
            }
            $guard++;
        } while ($after !== null && $guard < 50);

        return [
            'data'     => $all,
            'raw'      => $lastRaw,
            'paging'   => ['cursors' => ['after' => null]],
            'total'    => count($all),
            'provider' => 'meta',
        ];
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function createTemplate(array $payload): array
    {
        if ($this->wabaId === '') {
            throw new RuntimeException('Meta WABA ID is required to create templates.');
        }

        if (is_array($payload['components'] ?? null)) {
            $payload['components'] = $this->normalizeCreateComponents($payload['components']);
        }

        return $this->request('POST', $this->wabaId . '/message_templates', $payload);
    }

    /**
     * Graph rejects unknown example keys (Cheerio uses `header_url` / `link`),
     * so only Meta's documented example fields are forwarded. Media header samples
     * are also converted to Resumable Upload handles, which Graph requires.
     *
     * @param list<array<string, mixed>> $components
     *
     * @return list<array<string, mixed>>
     */
    protected function normalizeCreateComponents(array $components): array
    {
        $allowedExampleKeys = ['header_handle', 'header_text', 'header_text_named_params', 'body_text', 'body_text_named_params'];

        foreach ($components as $index => $component) {
            if (! is_array($component)) {
                continue;
            }

            if (is_array($component['example'] ?? null)) {
                $example = array_intersect_key($component['example'], array_flip($allowedExampleKeys));

                $format = strtoupper((string) ($component['format'] ?? ''));
                if (in_array($format, ['IMAGE', 'VIDEO', 'DOCUMENT'], true) && isset($example['header_handle'])) {
                    $sample = is_array($example['header_handle'])
                        ? (string) ($example['header_handle'][0] ?? '')
                        : (string) $example['header_handle'];

                    // Cheerio-only keys are stripped below, but they still point at the
                    // same upload and make the handle lookup work on localhost.
                    $fallbacks = [];
                    foreach (['header_url', 'link'] as $key) {
                        $value = $component['example'][$key] ?? null;
                        if (is_string($value) && trim($value) !== '') {
                            $fallbacks[] = trim($value);
                        }
                    }

                    $handle = $this->resolveTemplateHeaderHandle($sample, $format, $fallbacks);
                    if ($handle === '') {
                        unset($example['header_handle']);
                    } else {
                        $example['header_handle'] = [$handle];
                    }
                }

                if ($example === []) {
                    unset($component['example']);
                } else {
                    $component['example'] = $example;
                }
            }

            if (is_array($component['cards'] ?? null)) {
                foreach ($component['cards'] as $cardIndex => $card) {
                    if (is_array($card) && is_array($card['components'] ?? null)) {
                        $card['components'] = $this->normalizeCreateComponents($card['components']);
                        $component['cards'][$cardIndex] = $card;
                    }
                }
            }

            $components[$index] = $component;
        }

        return $components;
    }

    /**
     * Graph only accepts a Resumable Upload handle as a media header sample —
     * a media ID or public URL is reported back as "missing sample parameter".
     */
    protected function resolveTemplateHeaderHandle(string $sample, string $format, array $fallbackSamples = []): string
    {
        $sample = trim($sample);
        if ($sample === '' || $this->looksLikeUploadHandle($sample)) {
            return $sample;
        }

        $path = '';
        $mime = '';
        $isTemp = false;
        foreach (array_merge([$sample], $fallbackSamples) as $candidate) {
            $candidate = trim((string) $candidate);
            if ($candidate === '') {
                continue;
            }

            [$path, $mime, $isTemp] = $this->resolveSampleFile($candidate, $format);
            if ($path !== '') {
                break;
            }
        }

        if ($path === '') {
            throw new RuntimeException(
                'Could not read the header sample file for this template. Re-upload the header media and try again.'
            );
        }

        try {
            return $this->uploadResumableFile($path, $mime);
        } finally {
            if ($isTemp && is_file($path)) {
                @unlink($path);
            }
        }
    }

    /**
     * Graph handles look like `4:<base64 name>:<base64 mime>:AR...` — never a URL or bare media ID.
     */
    protected function looksLikeUploadHandle(string $value): bool
    {
        if (preg_match('#^https?://#i', $value)) {
            return false;
        }

        return (bool) preg_match('/^\d+::?[^:]+:/', $value);
    }

    /**
     * @return array{0: string, 1: string, 2: bool} path, mime type, whether the file is temporary
     */
    protected function resolveSampleFile(string $sample, string $format): array
    {
        $fallbackMime = match ($format) {
            'VIDEO'    => 'video/mp4',
            'DOCUMENT' => 'application/pdf',
            default    => 'image/jpeg',
        };

        if (is_file($sample)) {
            return [$sample, $this->detectMimeType($sample, $fallbackMime), false];
        }

        $row = $this->findMediaRowForSample($sample);
        if ($row !== null) {
            $path = WRITEPATH . ltrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, (string) ($row['path'] ?? '')), DIRECTORY_SEPARATOR);
            if (is_file($path)) {
                $mime = trim((string) ($row['mime_type'] ?? ''));

                return [$path, $mime !== '' ? $mime : $this->detectMimeType($path, $fallbackMime), false];
            }
        }

        if (preg_match('#^https?://#i', $sample) && ! $this->isNonPublicMediaUrl($sample)) {
            $downloaded = $this->downloadSampleToTempFile($sample, $fallbackMime);
            if ($downloaded !== null) {
                return [$downloaded[0], $downloaded[1], true];
            }
        }

        return ['', $fallbackMime, false];
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function findMediaRowForSample(string $sample): ?array
    {
        $model = model(\App\Models\MediaModel::class);

        $row = $model->where('url', $sample)->orderBy('id', 'DESC')->first();
        if (is_array($row)) {
            return $row;
        }

        // On localhost the stored sample is the provider media ID, because the
        // /media/serve URL is not reachable by Meta.
        if (! preg_match('#^https?://#i', $sample)) {
            $row = $model->where('wa_media_id', $sample)->orderBy('id', 'DESC')->first();
            if (is_array($row)) {
                return $row;
            }
        }

        $filename = LocalMediaUrl::filenameFromUrl($sample);
        if ($filename === '' && ! preg_match('#^https?://#i', $sample)) {
            $filename = basename($sample);
        }

        if ($filename === '') {
            return null;
        }

        $row = $model->where('filename', $filename)->orderBy('id', 'DESC')->first();

        return is_array($row) ? $row : null;
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    protected function downloadSampleToTempFile(string $url, string $fallbackMime): ?array
    {
        try {
            $client   = Services::curlrequest($this->baseCurlOptions(), null, null, false);
            $response = $client->request('GET', $url);
            if ($response->getStatusCode() >= 400) {
                return null;
            }

            $body = (string) $response->getBody();
            if ($body === '') {
                return null;
            }

            $tmp = tempnam(sys_get_temp_dir(), 'meta_tpl_');
            if ($tmp === false || file_put_contents($tmp, $body) === false) {
                return null;
            }

            $mime = trim(explode(';', $response->getHeaderLine('Content-Type'))[0]);

            return [$tmp, $mime !== '' ? $mime : $fallbackMime];
        } catch (Throwable $e) {
            log_message('warning', 'Template sample download failed for {url}: {msg}', [
                'url' => $url,
                'msg' => $e->getMessage(),
            ]);

            return null;
        }
    }

    protected function detectMimeType(string $path, string $fallback): string
    {
        if (function_exists('mime_content_type')) {
            $mime = @mime_content_type($path);
            if (is_string($mime) && $mime !== '') {
                return $mime;
            }
        }

        return $fallback;
    }

    /**
     * Meta Resumable Upload API: create a session, then push the bytes to get the handle.
     *
     * @see https://developers.facebook.com/docs/graph-api/guides/upload
     */
    public function uploadResumableFile(string $filePath, string $mimeType): string
    {
        $this->ensureConfigured(false);

        if (! is_file($filePath)) {
            throw new RuntimeException('Template sample file not found: ' . $filePath);
        }

        $appId = $this->resolveAppId();
        if ($appId === '') {
            throw new RuntimeException(
                'Meta App ID is required to upload template header samples. Save it in Settings → WhatsApp Provider → Meta.'
            );
        }

        $client = Services::curlrequest($this->baseCurlOptions(), null, null, false);

        $sessionResponse = $client->request('POST', $this->buildUrl($appId . '/uploads'), [
            'headers' => [
                'Authorization' => 'OAuth ' . $this->accessToken,
                'Accept'        => 'application/json',
            ],
            'form_params' => [
                'file_name'   => basename($filePath),
                'file_length' => (string) filesize($filePath),
                'file_type'   => $mimeType,
            ],
        ]);

        $session   = json_decode((string) $sessionResponse->getBody(), true);
        $sessionId = is_array($session) ? trim((string) ($session['id'] ?? '')) : '';
        if ($sessionId === '') {
            throw new RuntimeException(
                'Meta could not start the header sample upload: ' . (string) $sessionResponse->getBody()
            );
        }

        $uploadResponse = $client->request('POST', $this->buildUrl($sessionId), [
            'headers' => [
                'Authorization' => 'OAuth ' . $this->accessToken,
                'file_offset'   => '0',
                'Content-Type'  => 'application/octet-stream',
                'Accept'        => 'application/json',
            ],
            'body' => file_get_contents($filePath),
        ]);

        $uploaded = json_decode((string) $uploadResponse->getBody(), true);
        $handle   = is_array($uploaded) ? $this->firstUploadHandle((string) ($uploaded['h'] ?? '')) : '';
        if ($handle === '') {
            throw new RuntimeException(
                'Meta did not return a header sample handle: ' . (string) $uploadResponse->getBody()
            );
        }

        return $handle;
    }

    /**
     * Graph can return several newline-separated handles for one upload; only the first is valid input.
     */
    protected function firstUploadHandle(string $raw): string
    {
        foreach (preg_split('/\R/', $raw) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '') {
                return $line;
            }
        }

        return '';
    }

    protected function resolveAppId(): string
    {
        $meta  = $this->settings->getMetaConfig();
        $appId = trim((string) ($meta['app_id'] ?? ''));

        if ($appId === '') {
            $appId = $this->resolveMetaAppIdFromWaba();
            if ($appId !== '') {
                $this->settings->setMetaConfig(['app_id' => $appId]);
            }
        }

        return $appId;
    }

    /**
     * @return array<string, mixed>
     */
    public function deleteTemplate(string $name, ?string $hsmId = null): array
    {
        if ($this->wabaId === '') {
            throw new RuntimeException('Meta WABA ID is required to delete templates.');
        }

        $query = ['name' => $name];
        if ($hsmId) {
            $query['hsm_id'] = $hsmId;
        }

        return $this->request('DELETE', $this->wabaId . '/message_templates', $query);
    }

    /**
     * @return array<string, mixed>
     */
    public function getTemplateByNameOrId(string $nameOrId, string $by = 'name'): array
    {
        $all = $this->getTemplates();
        foreach ($all['data'] as $tpl) {
            if (! is_array($tpl)) {
                continue;
            }
            if ($by === 'id' && (string) ($tpl['id'] ?? '') === $nameOrId) {
                return $tpl;
            }
            if ($by !== 'id' && (string) ($tpl['name'] ?? '') === $nameOrId) {
                return $tpl;
            }
        }

        throw new RuntimeException('Template not found: ' . $nameOrId);
    }

    /**
     * @return array<string, mixed>
     */
    public function getMessageStatus(string $wamid): array
    {
        return ['id' => $wamid, 'status' => 'unknown', 'provider' => 'meta'];
    }

    /**
     * Meta Graph has no Cheerio-style contact directory.
     *
     * @return array{data: list<array<string,mixed>>, provider: string}
     */
    public function getContacts(?string $search = null, int $maxPages = 50): array
    {
        return ['data' => [], 'provider' => 'meta', 'unsupported' => true];
    }

    /**
     * @return array{data: list<array<string,mixed>>, provider: string}
     */
    public function getWorkflows(): array
    {
        return ['data' => [], 'provider' => 'meta', 'unsupported' => true];
    }

    /**
     * @return array<string, mixed>
     */
    public function getPhoneNumberInfo(): array
    {
        $this->ensureConfigured(true);
        $info = $this->request('GET', $this->phoneNumberId, [
            'fields' => 'display_phone_number,verified_name,quality_rating,code_verification_status',
        ]);

        return [
            'phone_number_id' => $this->phoneNumberId,
            'waba_id'         => $this->wabaId,
            'display_phone'   => (string) ($info['display_phone_number'] ?? ''),
            'verified_name'   => (string) ($info['verified_name'] ?? ''),
            'quality_rating'  => (string) ($info['quality_rating'] ?? ''),
            'provider'        => 'meta',
            'raw'             => $info,
        ];
    }

    /**
     * WhatsApp Business profile for the connected phone number (includes profile picture).
     *
     * @return array{profile_picture_url: string, about: string, description: string, raw: array<string, mixed>}
     */
    public function getBusinessProfile(): array
    {
        $this->ensureConfigured(true);
        $raw = $this->request('GET', $this->phoneNumberId . '/whatsapp_business_profile', [
            'fields' => 'profile_picture_url,about,address,description,email,vertical,websites',
        ]);

        $row = [];
        if (isset($raw['data'][0]) && is_array($raw['data'][0])) {
            $row = $raw['data'][0];
        } elseif (is_array($raw)) {
            $row = $raw;
        }

        return [
            'profile_picture_url' => (string) ($row['profile_picture_url'] ?? ''),
            'about'               => (string) ($row['about'] ?? ''),
            'description'         => (string) ($row['description'] ?? ''),
            'raw'                 => $raw,
        ];
    }

    /**
     * Fetch + cache verified name, display phone, and profile picture for UI chrome.
     *
     * @return array{display_name: string, phone: string, profile_picture_url: string}
     */
    public function refreshCachedIdentity(): array
    {
        $identity = (new WhatsAppIdentityService($this->settings))->refreshFromMeta(true);

        return [
            'display_name'        => (string) ($identity['display_name'] ?? ''),
            'phone'               => (string) ($identity['phone'] ?? ''),
            'profile_picture_url' => (string) ($identity['profile_picture_url'] ?? ''),
        ];
    }

    /**
     * Register a business phone number for Cloud API (two-step PIN).
     *
     * @return array<string, mixed>
     */
    public function registerPhoneNumber(?string $phoneNumberId = null, ?string $pin = null): array
    {
        $this->loadCredentials();
        $pnid = trim((string) ($phoneNumberId ?: $this->phoneNumberId));
        if ($pnid === '') {
            throw new RuntimeException('Meta Phone Number ID is required to register.');
        }

        $meta = $this->settings->getMetaConfig();
        $pin  = trim((string) ($pin ?: ($meta['two_step_pin'] ?? '')));
        if (preg_match('/^\d{6}$/', $pin) !== 1) {
            throw new RuntimeException('Set a 6-digit Meta two-step PIN in Settings before registering.');
        }

        return $this->request('POST', $pnid . '/register', [
            'messaging_product' => 'whatsapp',
            'pin'               => $pin,
        ]);
    }

    /**
     * Point this WABA's inbound webhooks at our public callback (Cloudflare / production HTTPS).
     *
     * Without override_callback_uri, Meta may keep events on the DevX test app and
     * Live Chat never receives customer replies.
     *
     * @return array<string, mixed>
     */
    public function subscribeWabaWebhook(?string $callbackUrl = null, ?string $verifyToken = null): array
    {
        $this->loadCredentials();
        if ($this->wabaId === '') {
            throw new RuntimeException('Meta WABA ID is required to subscribe webhooks.');
        }

        $settings = $this->settings;
        $meta     = $settings->getMetaConfig();
        $resolved = $settings->resolveWebhookPublicConfig();
        $base     = rtrim((string) ($resolved['public_base'] ?? ''), '/');
        $localPath = parse_url(site_url('webhooks'), PHP_URL_PATH) ?: '/webhooks';
        $callback  = $callbackUrl ?: ($base !== '' ? $base . $localPath : (string) ($resolved['public_callback'] ?? site_url('webhooks')));
        if ($callback === '' || ! str_starts_with($callback, 'https://')) {
            if ($base !== '') {
                $callback = $base . $localPath;
            }
        }
        if (($callback === '' || ! str_starts_with($callback, 'https://')) && ! empty($resolved['auto_callback'])) {
            $callback = (string) $resolved['auto_callback'];
        }
        if ($callback === '' || ! str_starts_with($callback, 'https://')) {
            throw new RuntimeException('Set a public HTTPS webhook base (Cloudflare/ngrok) in Settings → Webhooks first.');
        }

        $token = $verifyToken ?: (string) ($meta['verify_token'] ?? '');
        if ($token === '') {
            throw new RuntimeException('Meta webhook verify token is empty. Generate it in Settings → Webhooks.');
        }

        // Configure the app-level default first. This is the Graph API equivalent of
        // WhatsApp → Configuration → Callback URL / Verify token → Verify and save.
        // It also keeps non-overridable template/account events on the current URL.
        $webhookFields = $this->diagnoseAndEnsureWebhookFields($callback, $token);

        // A WABA must be subscribed before Meta accepts an override callback.
        // Calling this edge without an override is idempotent for the configured app.
        $this->request('POST', $this->wabaId . '/subscribed_apps');

        $result = $this->request('POST', $this->wabaId . '/subscribed_apps', [
            'override_callback_uri' => $callback,
            'verify_token'          => $token,
        ]);

        return [
            'ok'                    => ! empty($result['success']),
            'fully_configured'      => ! empty($result['success']) && ! empty($webhookFields['ok']),
            'callback'              => $callback,
            'subscribed_apps'       => $this->request('GET', $this->wabaId . '/subscribed_apps'),
            'raw'                   => $result,
            'provider'              => 'meta',
            'webhook_fields'        => $webhookFields,
        ];
    }

    /**
     * Ensure Meta App webhook subscription includes `messages` (required for Live Chat inbound).
     *
     * URL verify alone is not enough — without field subscribe, Meta never POSTs customer replies.
     *
     * @return array{
     *   ok: bool,
     *   messages_subscribed: bool,
     *   fields: list<string>,
     *   auto_fixed: bool,
     *   detail: string,
     *   error: ?string,
     *   app_id: string
     * }
     */
    public function diagnoseAndEnsureWebhookFields(?string $callbackUrl = null, ?string $verifyToken = null): array
    {
        $this->loadCredentials();
        $meta       = $this->settings->getMetaConfig();
        $appId      = trim((string) ($meta['app_id'] ?? ''));
        $appSecret  = trim((string) ($meta['app_secret'] ?? ''));
        $verify     = trim((string) ($verifyToken ?: ($meta['verify_token'] ?? '')));
        $callback   = trim((string) ($callbackUrl ?? ''));

        if ($callback === '') {
            $resolved = $this->settings->resolveWebhookPublicConfig();
            $callback = (string) ($resolved['public_callback'] ?? $resolved['auto_callback'] ?? '');
        }

        if ($appId === '') {
            $appId = $this->resolveMetaAppIdFromWaba();
            if ($appId !== '') {
                $this->settings->setMetaConfig(['app_id' => $appId]);
            }
        }

        $empty = static function (string $detail, ?string $error = null) use ($appId): array {
            return [
                'ok'                   => false,
                'messages_subscribed'  => false,
                'fields'               => [],
                'auto_fixed'           => false,
                'detail'               => $detail,
                'error'                => $error,
                'app_id'               => $appId,
            ];
        };

        if ($appId === '') {
            return $empty(
                'Meta App ID missing',
                'Save Meta App ID in Settings → Meta, then Test connection. Without it we cannot check messages field subscribe.'
            );
        }
        if ($appSecret === '') {
            return $empty(
                'Meta App Secret missing',
                'Save App Secret in Settings → Meta. Required to subscribe webhook fields (messages).'
            );
        }
        if ($callback === '' || ! str_starts_with($callback, 'https://')) {
            return $empty(
                'Public HTTPS webhook URL missing',
                'Set Cloudflare/ngrok URL in Settings → Webhooks (Step 2).'
            );
        }
        if ($verify === '') {
            return $empty(
                'Verify token missing',
                'Generate verify token in Settings → Webhooks (Step 1).'
            );
        }

        try {
            $current     = $this->appGraphRequest('GET', $appId . '/subscriptions', [], $appId, $appSecret);
            $before      = $this->extractSubscriptionFieldNames($current);
            $hadMessages = in_array('messages', $before, true);

            // Always POST the subscription. Even when `messages` is already subscribed,
            // the saved callback URL or verify token may have changed.
            $desired = 'messages,message_template_status_update,account_update';
            $post    = $this->appGraphRequest('POST', $appId . '/subscriptions', [
                'object'       => 'whatsapp_business_account',
                'callback_url' => $callback,
                'verify_token' => $verify,
                'fields'       => $desired,
            ], $appId, $appSecret);

            $after  = $this->appGraphRequest('GET', $appId . '/subscriptions', [], $appId, $appSecret);
            $fields = $this->extractSubscriptionFieldNames($after);
            $ok     = in_array('messages', $fields, true);

            if (! $ok) {
                return [
                    'ok'                  => false,
                    'messages_subscribed' => false,
                    'fields'              => $fields,
                    'auto_fixed'          => false,
                    'detail'              => 'Could not subscribe messages field',
                    'error'               => 'Open Meta App → WhatsApp → Configuration → Webhook fields → Subscribe messages. '
                        . 'Raw: ' . json_encode($post),
                    'app_id'              => $appId,
                ];
            }

            return [
                'ok'                  => true,
                'messages_subscribed' => true,
                'fields'              => $fields,
                'auto_fixed'          => ! $hadMessages,
                'detail'              => ($hadMessages ? 'Callback/token synced; fields OK (' : 'Callback/token synced; subscribed messages (')
                    . implode(', ', $fields) . ')',
                'error'               => null,
                'app_id'              => $appId,
            ];
        } catch (Throwable $e) {
            return $empty(
                'Webhook fields check failed',
                $this->humanizeWebhookFieldsError($e->getMessage())
            );
        }
    }

    /**
     * @return list<string>
     */
    protected function extractSubscriptionFieldNames(array $subscriptionsPayload): array
    {
        $names = [];
        $rows  = $subscriptionsPayload['data'] ?? [];
        if (! is_array($rows)) {
            return [];
        }
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $fields = $row['fields'] ?? [];
            if (! is_array($fields)) {
                continue;
            }
            foreach ($fields as $field) {
                if (is_array($field) && isset($field['name'])) {
                    $names[] = (string) $field['name'];
                } elseif (is_string($field) && $field !== '') {
                    $names[] = $field;
                }
            }
        }

        return array_values(array_unique($names));
    }

    protected function resolveMetaAppIdFromWaba(): string
    {
        if ($this->wabaId === '' || $this->accessToken === '') {
            return '';
        }
        try {
            $subs = $this->request('GET', $this->wabaId . '/subscribed_apps');
            $rows = $subs['data'] ?? [];
            if (! is_array($rows) || $rows === []) {
                return '';
            }
            $first = $rows[0] ?? [];
            if (! is_array($first)) {
                return '';
            }
            $id = (string) ($first['whatsapp_business_api_data']['id'] ?? '');

            return $id;
        } catch (Throwable $e) {
            return '';
        }
    }

    /**
     * App-token Graph calls (app_id|app_secret) — required for /{app-id}/subscriptions.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    protected function appGraphRequest(
        string $method,
        string $endpoint,
        array $data,
        string $appId,
        string $appSecret
    ): array {
        $method = strtoupper($method);
        $url    = $this->buildUrl($endpoint);
        $token  = $appId . '|' . $appSecret;
        $client = Services::curlrequest($this->baseCurlOptions(), null, null, false);

        $options = [
            'headers' => ['Accept' => 'application/json'],
        ];
        if ($method === 'GET') {
            $options['query'] = array_merge($data, ['access_token' => $token]);
        } else {
            $options['form_params'] = array_merge($data, ['access_token' => $token]);
        }

        $response = $client->request($method, $url, $options);
        $status   = $response->getStatusCode();
        $body     = (string) $response->getBody();
        $decoded  = json_decode($body, true);
        if (! is_array($decoded)) {
            $decoded = ['raw' => $body];
        }

        if ($status < 200 || $status >= 300) {
            $msg = (string) ($decoded['error']['message'] ?? ('HTTP ' . $status));
            throw new RuntimeException('Meta app subscriptions API: ' . $msg, $status);
        }

        return $decoded;
    }

    protected function humanizeWebhookFieldsError(string $raw): string
    {
        $lower = strtolower($raw);
        if (str_contains($lower, 'permissions') || str_contains($lower, '1929002')) {
            return 'Meta rejected field subscribe (permissions). In App Dashboard → WhatsApp → Configuration, '
                . 'manually Subscribe the messages field. Raw: ' . $raw;
        }
        if (str_contains($lower, 'application secret') || str_contains($lower, 'app access_token')) {
            return 'App Secret invalid or missing. Paste the correct App Secret from Meta App settings. Raw: ' . $raw;
        }

        return $raw;
    }

    /**
     * @return array<string, mixed>
     */
    public function testConnection(): array
    {
        $this->loadCredentials();

        $checklist = [];
        $checklist[] = [
            'id'     => 'access_token',
            'label'  => 'Meta access token configured',
            'ok'     => $this->accessToken !== '',
            'detail' => $this->accessToken !== ''
                ? 'Access token is set'
                : 'Paste a permanent System User token in Settings → Meta, then Save',
        ];
        $checklist[] = [
            'id'     => 'phone_number_id',
            'label'  => 'Phone Number ID configured',
            'ok'     => $this->phoneNumberId !== '',
            'detail' => $this->phoneNumberId !== ''
                ? $this->phoneNumberId
                : 'Add Phone Number ID from Meta Developer → WhatsApp → API setup',
        ];
        $checklist[] = [
            'id'     => 'waba_id',
            'label'  => 'WABA ID configured',
            'ok'     => $this->wabaId !== '',
            'detail' => $this->wabaId !== ''
                ? $this->wabaId
                : 'Needed for template sync (WhatsApp Business Account ID)',
        ];

        $appId = trim((string) ($this->settings->getMetaConfig()['app_id'] ?? ''));
        $checklist[] = [
            'id'     => 'app_id',
            'label'  => 'Meta App ID configured',
            'ok'     => $appId !== '',
            'detail' => $appId !== '' ? $appId : 'Save Meta App ID in Settings',
        ];

        $info      = null;
        $apiOk     = false;
        $apiDetail = 'Skipped until token + Phone Number ID are set.';

        if ($this->accessToken !== '' && $this->phoneNumberId !== '') {
            try {
                $identity  = $this->refreshCachedIdentity();
                $info      = [
                    'verified_name'   => $identity['display_name'],
                    'display_phone'   => $identity['phone'],
                    'phone_number_id' => $this->phoneNumberId,
                    'profile_picture_url' => $identity['profile_picture_url'],
                ];
                $apiOk     = true;
                $apiDetail = trim(($identity['display_name'] ?? '') . ' · ' . ($identity['phone'] ?: $this->phoneNumberId));
            } catch (Throwable $e) {
                $apiDetail = $this->humanizeConnectionError($e->getMessage());
            }
        }

        $checklist[] = [
            'id'     => 'graph_api',
            'label'  => 'Graph API phone lookup',
            'ok'     => $apiOk,
            'detail' => $apiDetail,
        ];

        $templateCount    = 0;
        $approvedCount    = 0;
        $pendingCount     = 0;
        $rejectedCount    = 0;
        $templatesOk      = false;
        $templatesDetail  = 'Skipped until WABA ID + token are set.';
        $helloWorldExists = false;

        if ($this->accessToken !== '' && $this->wabaId !== '') {
            try {
                $tplResult = $this->getTemplates($this->wabaId);
                $rows      = is_array($tplResult['data'] ?? null) ? $tplResult['data'] : [];
                $templatesOk   = true;
                $templateCount = count($rows);
                foreach ($rows as $row) {
                    if (! is_array($row)) {
                        continue;
                    }
                    $status = strtoupper((string) ($row['status'] ?? ''));
                    if ($status === 'APPROVED') {
                        $approvedCount++;
                    } elseif (in_array($status, ['PENDING', 'IN_REVIEW', 'IN_PROGRESS'], true)) {
                        $pendingCount++;
                    } elseif ($status === 'REJECTED') {
                        $rejectedCount++;
                    }
                    if (strtolower((string) ($row['name'] ?? '')) === 'hello_world') {
                        $helloWorldExists = true;
                    }
                }
                $templatesDetail = sprintf(
                    'Templates: %d · Approved: %d · Pending: %d · Rejected: %d%s',
                    $templateCount,
                    $approvedCount,
                    $pendingCount,
                    $rejectedCount,
                    $helloWorldExists ? ' · hello_world present on WABA' : ' · hello_world NOT on this WABA'
                );
            } catch (Throwable $e) {
                $templatesDetail = MetaApiErrorMapper::humanize($e->getMessage(), (int) $e->getCode());
            }
        }

        $checklist[] = [
            'id'     => 'templates_api',
            'label'  => 'WABA templates reachable',
            'ok'     => $templatesOk,
            'detail' => $templatesDetail,
        ];
        $checklist[] = [
            'id'     => 'approved_templates',
            'label'  => 'At least one APPROVED template',
            'ok'     => $approvedCount > 0,
            'detail' => $approvedCount > 0
                ? sprintf('%d approved', $approvedCount)
                : 'Sync Templates after Meta approves at least one template. Do not rely on Meta API Setup hello_world sample.',
        ];

        $webhookOk = false;
        $webhookDetail = 'Skipped';
        $fieldsDiag = null;
        if ($apiOk && $this->wabaId !== '') {
            try {
                $sub = $this->subscribeWabaWebhook();
                $webhookOk = ! empty($sub['ok']);
                $webhookDetail = $webhookOk
                    ? ('WABA → ' . ($sub['callback'] ?? ''))
                    : 'Subscribe failed';
                $fieldsDiag = is_array($sub['webhook_fields'] ?? null) ? $sub['webhook_fields'] : null;
            } catch (Throwable $e) {
                $webhookDetail = $e->getMessage();
            }
        }
        if ($fieldsDiag === null && $apiOk) {
            $fieldsDiag = $this->diagnoseAndEnsureWebhookFields();
        }

        $checklist[] = [
            'id'     => 'waba_webhook',
            'label'  => 'WABA webhook override',
            'ok'     => $webhookOk,
            'detail' => $webhookDetail,
        ];

        $fieldsOk = is_array($fieldsDiag) && ! empty($fieldsDiag['messages_subscribed']);
        $fieldsDetail = is_array($fieldsDiag)
            ? (string) ($fieldsDiag['detail'] ?? '')
            : 'Not checked';
        if (is_array($fieldsDiag) && ! empty($fieldsDiag['error'])) {
            $fieldsDetail = (string) $fieldsDiag['error'];
        } elseif (is_array($fieldsDiag) && ! empty($fieldsDiag['auto_fixed'])) {
            $fieldsDetail = 'Fixed automatically: ' . $fieldsDetail;
        }
        $checklist[] = [
            'id'     => 'webhook_fields',
            'label'  => 'Webhook field: messages',
            'ok'     => $fieldsOk,
            'detail' => $fieldsDetail !== ''
                ? $fieldsDetail
                : 'Meta App must Subscribe the messages field or Live Chat will never receive replies',
        ];

        $ok = $apiOk;
        foreach ($checklist as $item) {
            if (in_array($item['id'], ['access_token', 'phone_number_id', 'graph_api'], true) && ! $item['ok']) {
                $ok = false;
                break;
            }
        }
        if ($apiOk && ! $fieldsOk) {
            $ok = false;
        }

        $message = $ok
            ? ('Meta Cloud API OK — ' . $apiDetail . ($webhookOk ? ' · webhook pinned' : '')
                . ($fieldsOk ? ' · messages subscribed' : '')
                . ($templatesOk ? ' · ' . $templatesDetail : ''))
            : (! $fieldsOk && is_array($fieldsDiag) && ! empty($fieldsDiag['error'])
                ? (string) $fieldsDiag['error']
                : $apiDetail);

        return [
            'ok'                  => $ok,
            'provider'            => 'meta',
            'message'             => $message,
            'info'                => $info,
            'checklist'           => $checklist,
            'webhook_fields'      => $fieldsDiag,
            'connection'          => [
                'whatsapp'      => $apiOk ? 'Connected' : 'Not connected',
                'waba'          => $this->wabaId !== '' ? 'Connected' : 'Not connected',
                'phone_number'  => $this->phoneNumberId !== '' && $apiOk ? 'Connected' : 'Not connected',
                'app_id'        => $appId,
                'waba_id'       => $this->wabaId,
                'phone_number_id' => $this->phoneNumberId,
            ],
            'templates_reachable' => $templatesOk,
            'template_count'      => $templateCount,
            'approved_templates'  => $approvedCount,
            'pending_templates'   => $pendingCount,
            'rejected_templates'  => $rejectedCount,
            'hello_world_exists'  => $helloWorldExists,
        ];
    }

    protected function humanizeConnectionError(string $raw): string
    {
        $lower = strtolower($raw);
        if (str_contains($lower, 'ssl certificate problem') || str_contains($lower, 'unable to get local issuer')) {
            return 'SSL CA missing on this server (WAMP). Place cacert.pem in writable/certs/ '
                . 'or set Config\\WhatsApp::$sslVerify. Raw: ' . $raw;
        }
        if (str_contains($lower, 'access token is not configured')) {
            return $raw;
        }
        if (str_contains($lower, 'phone number id is not configured')) {
            return $raw;
        }
        if (str_contains($lower, 'http 401') || str_contains($lower, 'http 190') || str_contains($lower, 'session has expired')) {
            return 'Meta token invalid/expired. Generate a new permanent token. Raw: ' . $raw;
        }
        if (str_contains($lower, 'http 100') || str_contains($lower, 'unsupported get request')) {
            return 'Phone Number ID looks wrong for this token/app. Raw: ' . $raw;
        }

        return $raw;
    }

    /**
     * @return array<string, mixed>
     */
    public function markAsRead(string $messageId): array
    {
        return $this->request('POST', $this->phoneNumberId . '/messages', [
            'messaging_product' => 'whatsapp',
            'status'            => 'read',
            'message_id'        => $messageId,
        ]);
    }

    public function normalizePhone(string $phone): string
    {
        $phone = preg_replace('/\D+/', '', $phone) ?? '';

        return ltrim($phone, '0');
    }

    /**
     * @param array<string, mixed> $message
     *
     * @return array<string, mixed>
     */
    protected function sendMessage(string $to, array $message): array
    {
        $this->ensureConfigured(true);
        $phone = $this->normalizePhone($to);
        if ($phone === '') {
            throw new RuntimeException('Recipient phone number is required.');
        }

        $payload = array_merge([
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                => $phone,
        ], $message);

        return $this->request('POST', $this->phoneNumberId . '/messages', $payload);
    }

    protected function ensureConfigured(bool $needPhoneId): void
    {
        if ($this->accessToken === '') {
            throw new RuntimeException('Meta access token is not configured. Settings → WhatsApp Provider → Meta.');
        }
        if ($needPhoneId && $this->phoneNumberId === '') {
            throw new RuntimeException('Meta Phone Number ID is not configured.');
        }
    }

    protected function buildUrl(string $endpoint): string
    {
        $endpoint = ltrim($endpoint, '/');
        $base     = rtrim($this->config->graphBaseUrl ?: 'https://graph.facebook.com', '/');

        return $base . '/' . $this->apiVersion . '/' . $endpoint;
    }

    /**
     * @return array<string, mixed>
     */
    protected function baseCurlOptions(): array
    {
        return [
            'timeout'         => $this->config->defaultTimeout,
            'http_errors'     => false,
            'connect_timeout' => 15,
            'verify'          => $this->resolveSslVerify(),
        ];
    }

    /**
     * Same CA resolution as CheerioDirectAPI — required on WAMP where php.ini
     * often has no curl.cainfo and Graph HTTPS fails with "unable to get local issuer".
     *
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
     * @param array<string, mixed> $decoded
     */
    protected function extractApiError(array $decoded): string
    {
        if (isset($decoded['error']) && is_array($decoded['error'])) {
            $error = $decoded['error'];
            $parts = array_filter([
                isset($error['message']) ? (string) $error['message'] : null,
                isset($error['error_user_title']) ? (string) $error['error_user_title'] : null,
                isset($error['error_user_msg']) ? (string) $error['error_user_msg'] : null,
                $this->stringifyErrorDetails($error['error_data']['details'] ?? null),
            ]);

            return implode(' | ', $parts) ?: 'Unknown error';
        }

        $flatParts = array_filter([
            isset($decoded['message']) && is_string($decoded['message']) ? $decoded['message'] : null,
            isset($decoded['error_user_title']) && is_string($decoded['error_user_title']) ? $decoded['error_user_title'] : null,
            isset($decoded['error_user_msg']) && is_string($decoded['error_user_msg']) ? $decoded['error_user_msg'] : null,
        ]);
        if ($flatParts !== []) {
            return implode(' | ', $flatParts);
        }

        return 'Unknown error';
    }

    protected function stringifyErrorDetails(mixed $details): ?string
    {
        if ($details === null || $details === '') {
            return null;
        }
        if (is_string($details)) {
            return $details;
        }
        if (is_scalar($details)) {
            return (string) $details;
        }

        $json = json_encode($details);

        return is_string($json) ? $json : null;
    }
}
