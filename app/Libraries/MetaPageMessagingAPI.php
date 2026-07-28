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
