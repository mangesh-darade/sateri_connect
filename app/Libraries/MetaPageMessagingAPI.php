<?php

declare(strict_types=1);

namespace App\Libraries;

use Config\Services;
use Config\WhatsApp as WhatsAppConfig;
use RuntimeException;
use Throwable;

/**
 * Meta Page Messaging API for Instagram DMs and Facebook Messenger.
 *
 * Uses Page Access Token (not WhatsApp Cloud token).
 */
class MetaPageMessagingAPI
{
    protected SettingsService $settings;
    protected WhatsAppConfig $config;

    protected string $pageAccessToken = '';
    protected string $pageId = '';
    protected string $apiVersion = 'v21.0';
    protected string $graphBaseUrl = 'https://graph.facebook.com';

    public function __construct(?SettingsService $settings = null, ?WhatsAppConfig $config = null)
    {
        $this->settings = $settings ?? new SettingsService();
        $this->config   = $config ?? config(WhatsAppConfig::class);
        $this->loadCredentials();
    }

    public function loadCredentials(): void
    {
        $meta = $this->settings->getMetaConfig();

        $this->pageAccessToken = (string) ($meta['page_access_token'] ?? '');
        $this->pageId          = (string) ($meta['page_id'] ?? '');
        $this->apiVersion      = (string) ($meta['api_version'] ?? 'v21.0') ?: 'v21.0';
        $this->graphBaseUrl    = rtrim((string) ($meta['graph_base_url'] ?? 'https://graph.facebook.com'), '/');
    }

    public function isConfigured(): bool
    {
        return $this->pageAccessToken !== '' && $this->pageId !== '';
    }

    /**
     * Lead Ads form submission. The webhook only sends the lead id.
     *
     * @return array<string, mixed>
     */
    public function fetchLead(string $leadId): array
    {
        $this->ensureConfigured();
        $leadId = trim($leadId);
        if (preg_match('/^\d{5,32}$/', $leadId) !== 1) {
            throw new RuntimeException('Facebook lead id is not valid.');
        }

        return $this->request('GET', $leadId, [
            'fields' => 'id,created_time,ad_id,ad_name,form_id,field_data,campaign_id,campaign_name',
        ]);
    }

    /**
     * Keep the Page subscribed to leadgen without dropping Messenger or Instagram fields.
     */
    public function ensureLeadgenSubscription(): void
    {
        $this->ensureConfigured();

        $fields = ['leadgen'];
        try {
            $current = $this->request('GET', $this->pageId . '/subscribed_apps');
            $rows = is_array($current['data'] ?? null) ? $current['data'] : [];
            foreach ($rows as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $existing = $row['subscribed_fields'] ?? [];
                if (! is_array($existing)) {
                    continue;
                }
                foreach ($existing as $field) {
                    $field = trim((string) $field);
                    if ($field !== '' && ! in_array($field, $fields, true)) {
                        $fields[] = $field;
                    }
                }
            }
        } catch (Throwable $e) {
            log_message('warning', 'Could not read Page webhook fields before leadgen subscribe: {msg}', ['msg' => $e->getMessage()]);
        }

        $this->request('POST', $this->pageId . '/subscribed_apps', [
            'subscribed_fields' => implode(',', $fields),
        ]);

        // App-level Page webhook (object=page, field=leadgen). Without this Meta never
        // delivers leadgen POSTs even when the Page is subscribed to the app.
        // Lead Ads run on the platform app when the tenant has no app and configuration of its own.
        $leadApp = (new FacebookLeadConnectService())->leadApp();
        $appSub  = (new MetaCloudAPI($this->settings))->ensurePageLeadgenAppSubscription(
            null,
            null,
            $leadApp['source'] === 'platform' ? $leadApp : null
        );
        if (empty($appSub['ok'])) {
            $detail = trim((string) ($appSub['error'] ?? $appSub['detail'] ?? 'leadgen app subscribe failed'));
            throw new RuntimeException($detail !== '' ? $detail : 'Could not subscribe Page leadgen on the Meta app.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function testConnection(): array
    {
        $this->ensureConfigured();

        return $this->request('GET', $this->pageId . '?fields=id,name,instagram_business_account');
    }

    /**
     * Send a text message to Instagram or Messenger recipient.
     *
     * @return array<string, mixed>
     */
    public function sendText(string $recipientId, string $text, string $channel = 'messenger'): array
    {
        $this->ensureConfigured();
        $recipientId = trim($recipientId);
        $text        = trim($text);
        if ($recipientId === '' || $text === '') {
            throw new RuntimeException('Recipient and text are required.');
        }

        $payload = [
            'recipient' => ['id' => $recipientId],
            'messaging_type' => 'RESPONSE',
            'message' => ['text' => $text],
        ];

        // Instagram Messaging uses the same Page messages endpoint with Page PAT.
        return $this->request('POST', $this->pageId . '/messages', $payload);
    }

    /**
     * @param array<string, mixed>|null $attachmentPayload
     *
     * @return array<string, mixed>
     */
    public function sendAttachment(string $recipientId, string $type, string $url, ?string $caption = null): array
    {
        $this->ensureConfigured();
        $recipientId = trim($recipientId);
        $url         = trim($url);
        $type        = strtolower(trim($type)) ?: 'file';

        if ($recipientId === '' || $url === '') {
            throw new RuntimeException('Recipient and media URL are required.');
        }

        $message = [
            'attachment' => [
                'type'    => in_array($type, ['image', 'video', 'audio', 'file'], true) ? $type : 'file',
                'payload' => [
                    'url'         => $url,
                    'is_reusable' => true,
                ],
            ],
        ];

        if ($caption !== null && trim($caption) !== '') {
            // Graph attachment messages do not always support caption; send text first if needed by caller.
        }

        return $this->request('POST', $this->pageId . '/messages', [
            'recipient'      => ['id' => $recipientId],
            'messaging_type' => 'RESPONSE',
            'message'        => $message,
        ]);
    }

    /**
     * Mark messages as seen.
     *
     * @return array<string, mixed>
     */
    public function markSeen(string $recipientId): array
    {
        $this->ensureConfigured();

        return $this->request('POST', $this->pageId . '/messages', [
            'recipient' => ['id' => trim($recipientId)],
            'sender_action' => 'mark_seen',
        ]);
    }

    /**
     * Extract outbound message id from Graph response.
     *
     * @param array<string, mixed> $result
     */
    public function extractMessageId(array $result): ?string
    {
        $id = $result['message_id'] ?? $result['id'] ?? null;

        return is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * @param array<string, mixed>|null $data
     *
     * @return array<string, mixed>
     */
    public function request(string $method, string $endpoint, ?array $data = null): array
    {
        $this->ensureConfigured();

        $method   = strtoupper($method);
        $url      = $this->buildUrl($endpoint);
        $attempts = 0;
        $lastError = 'Unknown Meta Page Messaging API error';

        while ($attempts <= $this->config->maxRetries) {
            $attempts++;

            try {
                $client = Services::curlrequest([
                    'timeout'     => $this->config->defaultTimeout,
                    'http_errors' => false,
                ], null, null, false);

                $options = [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $this->pageAccessToken,
                        'Accept'        => 'application/json',
                    ],
                ];

                if ($method === 'GET') {
                    if ($data !== null && $data !== []) {
                        $options['query'] = $data;
                    }
                } else {
                    $options['headers']['Content-Type'] = 'application/json';
                    $options['json'] = $data ?? [];
                }

                $response = $client->request($method, $url, $options);
                $status   = $response->getStatusCode();
                $body     = (string) $response->getBody();
                $decoded  = json_decode($body, true);
                if (! is_array($decoded)) {
                    $decoded = ['raw' => $body];
                }

                if ($status >= 200 && $status < 300) {
                    $decoded['provider'] = 'meta_page';
                    $decoded['channel_transport'] = 'page_messaging';

                    return $decoded;
                }

                $lastError = $this->formatError($decoded, $status);

                if (! in_array($status, [429, 500, 502, 503, 504], true) || $attempts > $this->config->maxRetries) {
                    throw new RuntimeException($lastError);
                }

                usleep((int) (250000 * $attempts));
            } catch (RuntimeException $e) {
                throw $e;
            } catch (Throwable $e) {
                $lastError = $e->getMessage();
                if ($attempts > $this->config->maxRetries) {
                    throw new RuntimeException('Meta Page Messaging request failed: ' . $lastError, 0, $e);
                }
                usleep((int) (250000 * $attempts));
            }
        }

        throw new RuntimeException($lastError);
    }

    protected function ensureConfigured(): void
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException(
                'Meta Page Messaging is not configured. Set Page ID + Page Access Token under Settings → Meta.'
            );
        }
    }

    protected function buildUrl(string $endpoint): string
    {
        $endpoint = ltrim($endpoint, '/');

        return $this->graphBaseUrl . '/' . $this->apiVersion . '/' . $endpoint;
    }

    /**
     * @param array<string, mixed> $decoded
     */
    protected function formatError(array $decoded, int $status): string
    {
        $error = $decoded['error'] ?? null;
        if (is_array($error)) {
            $message = (string) ($error['message'] ?? 'Graph API error');
            $code    = (string) ($error['code'] ?? '');
            $sub     = (string) ($error['error_subcode'] ?? '');

            return trim('Meta Page API ' . $status . ': ' . $message . ($code !== '' ? " [{$code}]" : '') . ($sub !== '' ? "/{$sub}" : ''));
        }

        return 'Meta Page API HTTP ' . $status;
    }
}
