<?php

declare(strict_types=1);

namespace App\Libraries\Email;

use App\Libraries\SettingsService;
use CodeIgniter\HTTP\ResponseInterface;
use Config\EmailProviders;
use Config\Services;
use RuntimeException;

/**
 * SendGrid Mail Send API v3 transport.
 */
class SendGridEmailDriver extends AbstractEmailDriver
{
    protected EmailProviders $config;
    protected string $apiKey = '';

    public function __construct(?SettingsService $settings = null, ?EmailProviders $config = null)
    {
        parent::__construct($settings);
        $this->config = $config ?? config(EmailProviders::class);
    }

    public function getName(): string
    {
        return SettingsService::EMAIL_PROVIDER_SENDGRID;
    }

    public function loadCredentials(): void
    {
        $this->apiKey = (string) $this->settings->get('sendgrid_api_key', '');
    }

    /**
     * @param array<string, mixed> $options
     */
    public function send(string|array $to, string $subject, string $body, array $options = []): array
    {
        $this->loadCredentials();

        if ($this->apiKey === '') {
            return $this->result(false, 'SendGrid API key is not configured.');
        }

        $recipients = $this->normalizeRecipients($to);
        if ($recipients === []) {
            return $this->result(false, 'No valid recipient email addresses.');
        }

        $from   = $this->resolveFrom($options, 'sendgrid_from_email', 'sendgrid_from_name');
        $isHtml = (bool) ($options['html'] ?? false);

        $personalizations = [
            [
                'to' => array_map(static fn (string $email) => ['email' => $email], $recipients),
            ],
        ];

        $payload = [
            'personalizations' => $personalizations,
            'from'             => [
                'email' => $from['email'],
                'name'  => $from['name'],
            ],
            'subject' => $subject,
            'content' => [
                [
                    'type'  => $isHtml ? 'text/html' : 'text/plain',
                    'value' => $body,
                ],
            ],
        ];

        $replyTo = trim((string) ($options['reply_to'] ?? ''));
        if ($replyTo !== '' && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
            $payload['reply_to'] = ['email' => $replyTo];
        }

        try {
            $response = $this->request('POST', $this->config->sendGridApiUrl, $payload);
            $status   = (int) ($response['status'] ?? 0);

            if ($status === ResponseInterface::HTTP_ACCEPTED || ($status >= 200 && $status < 300)) {
                return $this->result(true, 'Email sent via SendGrid to ' . implode(', ', $recipients), [
                    'status' => $status,
                ]);
            }

            $detail = (string) ($response['body']['errors'][0]['message'] ?? json_encode($response['body'] ?? ''));

            return $this->result(false, 'SendGrid error (HTTP ' . $status . '): ' . $detail, $response['body'] ?? null);
        } catch (\Throwable $e) {
            log_message('error', 'SendGridEmailDriver send failed: {msg}', ['msg' => $e->getMessage()]);

            return $this->result(false, $e->getMessage());
        }
    }

    public function sendCampaign(array $campaign): array
    {
        $this->loadCredentials();

        if ($this->apiKey === '') {
            return $this->result(false, 'SendGrid API key is not configured.');
        }

        $name    = trim((string) ($campaign['name'] ?? ''));
        $subject = trim((string) ($campaign['subject'] ?? ''));
        $html    = (string) ($campaign['html'] ?? '');
        $plain   = (string) ($campaign['plain_text'] ?? '');

        if ($name === '') {
            return $this->result(false, 'SendGrid campaign `name` is required.');
        }

        if ($subject === '' && trim((string) ($campaign['design_id'] ?? '')) === '') {
            return $this->result(false, 'SendGrid campaign subject is required unless `design_id` is used.');
        }

        $sendTo = [
            'list_ids'    => array_values(array_filter((array) ($campaign['list_ids'] ?? []), 'is_string')),
            'segment_ids' => array_values(array_filter((array) ($campaign['segment_ids'] ?? []), 'is_string')),
            'all'         => (bool) ($campaign['all'] ?? false),
        ];

        if (! $sendTo['all'] && $sendTo['list_ids'] === [] && $sendTo['segment_ids'] === []) {
            return $this->result(
                false,
                'SendGrid marketing campaigns need `list_ids`, `segment_ids`, or `all=true`.'
            );
        }

        $senderId = (int) ($campaign['sender_id'] ?? $this->settings->get('sendgrid_sender_id', 0));
        if ($senderId <= 0) {
            return $this->result(false, 'SendGrid marketing campaigns require a verified `sender_id`.');
        }

        $suppressionGroupId   = (int) ($campaign['suppression_group_id'] ?? $this->settings->get('sendgrid_suppression_group_id', 0));
        $customUnsubscribeUrl = trim((string) ($campaign['custom_unsubscribe_url'] ?? $this->settings->get('sendgrid_custom_unsubscribe_url', '')));

        if ($suppressionGroupId <= 0 && $customUnsubscribeUrl === '') {
            return $this->result(
                false,
                'SendGrid marketing campaigns require `suppression_group_id` or `custom_unsubscribe_url`.'
            );
        }

        $emailConfig = [
            'sender_id'              => $senderId,
            'editor'                 => trim((string) ($campaign['editor'] ?? 'code')) ?: 'code',
            'generate_plain_content' => (bool) ($campaign['generate_plain_content'] ?? true),
        ];

        $designId = trim((string) ($campaign['design_id'] ?? ''));
        if ($designId !== '') {
            $emailConfig['design_id'] = $designId;
        } else {
            $emailConfig['subject']       = $subject;
            $emailConfig['html_content']  = $html;
            $emailConfig['plain_content'] = $plain;
        }

        if ($suppressionGroupId > 0) {
            $emailConfig['suppression_group_id'] = $suppressionGroupId;
        } else {
            $emailConfig['custom_unsubscribe_url'] = $customUnsubscribeUrl;
        }

        $ipPool = trim((string) ($campaign['ip_pool'] ?? $this->settings->get('sendgrid_ip_pool', '')));
        if ($ipPool !== '') {
            $emailConfig['ip_pool'] = $ipPool;
        }

        $payload = [
            'name'         => $name,
            'categories'   => array_values(array_filter((array) ($campaign['categories'] ?? []), 'is_string')),
            'send_to'      => $sendTo,
            'email_config' => $emailConfig,
        ];

        $sendAt = trim((string) ($campaign['send_at'] ?? 'now'));

        try {
            $draft  = $this->request('POST', $this->config->sendGridSingleSendsApiUrl, $payload);
            $status = (int) ($draft['status'] ?? 0);
            $body   = $draft['body'] ?? [];

            if ($status !== ResponseInterface::HTTP_CREATED && ($status < 200 || $status >= 300)) {
                $detail = (string) ($body['errors'][0]['message'] ?? json_encode($body));

                return $this->result(false, 'SendGrid draft create failed: ' . $detail, $body);
            }

            $id = (string) ($body['id'] ?? '');
            if ($id === '') {
                return $this->result(false, 'SendGrid draft created but no campaign id was returned.', $body);
            }

            $schedule = $this->request(
                'PUT',
                $this->config->sendGridSingleSendsApiUrl . '/' . rawurlencode($id) . '/schedule',
                ['send_at' => $sendAt !== '' ? $sendAt : 'now']
            );

            $scheduleStatus = (int) ($schedule['status'] ?? 0);
            $scheduleBody   = $schedule['body'] ?? [];

            if ($scheduleStatus !== ResponseInterface::HTTP_CREATED && ($scheduleStatus < 200 || $scheduleStatus >= 300)) {
                $detail = (string) ($scheduleBody['errors'][0]['message'] ?? json_encode($scheduleBody));

                return $this->result(false, 'SendGrid campaign schedule failed: ' . $detail, [
                    'draft'    => $body,
                    'schedule' => $scheduleBody,
                ]);
            }

            $scheduledAt = (string) ($scheduleBody['send_at'] ?? $sendAt);

            return $this->result(true, 'SendGrid marketing campaign scheduled.', [
                'id'         => $id,
                'name'       => $name,
                'status'     => $scheduleBody['status'] ?? 'scheduled',
                'send_at'    => $scheduledAt,
                'draft'      => $body,
                'schedule'   => $scheduleBody,
                'categories' => $payload['categories'],
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'SendGridEmailDriver campaign failed: {msg}', ['msg' => $e->getMessage()]);

            return $this->result(false, $e->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array{status: int, body: array<string, mixed>|null}
     */
    protected function request(string $method, string $url, array $data): array
    {
        $attempts  = 0;
        $lastError = 'Unknown SendGrid error';

        while ($attempts <= $this->config->maxRetries) {
            $attempts++;

            try {
                $client = Services::curlrequest([
                    'timeout'     => $this->config->timeout,
                    'http_errors' => false,
                ], null, null, false);

                $response = $client->request($method, $url, [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $this->apiKey,
                        'Content-Type'  => 'application/json',
                        'Accept'        => 'application/json',
                    ],
                    'json' => $data,
                ]);

                $status = $response->getStatusCode();
                $raw    = (string) $response->getBody();
                $body   = json_decode($raw, true);

                if (! is_array($body)) {
                    $body = $raw !== '' ? ['raw' => $raw] : null;
                }

                if ($status === ResponseInterface::HTTP_ACCEPTED || ($status >= 200 && $status < 300)) {
                    return ['status' => $status, 'body' => $body];
                }

                $lastError = 'HTTP ' . $status;
                if (is_array($body) && isset($body['errors'][0]['message'])) {
                    $lastError .= ': ' . $body['errors'][0]['message'];
                }

                if (in_array($status, [429, 500, 502, 503, 504], true) && $attempts <= $this->config->maxRetries) {
                    usleep((int) ($this->config->retryDelaySeconds * 1_000_000 * (2 ** ($attempts - 1))));
                    continue;
                }

                return ['status' => $status, 'body' => $body];
            } catch (\Throwable $e) {
                $lastError = $e->getMessage();
                if ($attempts <= $this->config->maxRetries) {
                    usleep((int) ($this->config->retryDelaySeconds * 1_000_000 * (2 ** ($attempts - 1))));
                    continue;
                }

                throw new RuntimeException('SendGrid request failed: ' . $lastError, 0, $e);
            }
        }

        throw new RuntimeException('SendGrid request failed after retries: ' . $lastError);
    }
}
