<?php

declare(strict_types=1);

namespace App\Libraries\Email;

use App\Libraries\SettingsService;
use Config\EmailProviders;

/**
 * Amazon Simple Email Service (SES) API v2 transport via Native AWS SigV4.
 *
 * Zero external SDK dependencies. Uses official SES API v2:
 * POST https://email.{region}.amazonaws.com/v2/email/outbound-emails
 */
class SesEmailDriver extends AbstractEmailDriver
{
    protected EmailProviders $config;
    protected string $accessKey = '';
    protected string $secretKey = '';
    protected string $region = 'ap-south-1';

    public function __construct(?SettingsService $settings = null, ?EmailProviders $config = null)
    {
        parent::__construct($settings);
        $this->config = $config ?? config(EmailProviders::class);
    }

    public function getName(): string
    {
        return SettingsService::EMAIL_PROVIDER_SES;
    }

    public function loadCredentials(): void
    {
        $this->accessKey = trim((string) $this->settings->get('ses_access_key', ''));
        $this->secretKey = trim((string) $this->settings->get('ses_secret_key', ''));
        $this->region    = trim((string) $this->settings->get('ses_region', 'ap-south-1')) ?: 'ap-south-1';
    }

    /**
     * @param array<string, mixed> $options
     */
    public function send(string|array $to, string $subject, string $body, array $options = []): array
    {
        $this->loadCredentials();

        if ($this->accessKey === '' || $this->secretKey === '') {
            return $this->result(false, 'Amazon SES Access Key or Secret Key is not configured in Settings.');
        }

        $recipients = $this->normalizeRecipients($to);
        if ($recipients === []) {
            return $this->result(false, 'No valid recipient email addresses.');
        }

        $isHtml = (bool) ($options['html'] ?? false);
        if (! $isHtml && (str_contains($body, '<html') || str_contains($body, '<body') || str_contains($body, '<p>') || str_contains($body, '<br') || str_contains($body, '<div>'))) {
            $isHtml = true;
        }

        $from = $this->resolveFrom($options, 'ses_from_email', 'ses_from_name');
        if (empty($from['email'])) {
            return $this->result(false, 'Amazon SES From Email is not configured.');
        }

        if (count($recipients) === 1) {
            return $this->sendSingle($recipients[0], $subject, $body, $isHtml, $from, $options);
        }

        return $this->sendManySingles($recipients, $subject, $body, $isHtml, $from, $options);
    }

    /**
     * @param array<string, mixed> $options
     */
    public function sendHtml(string|array $to, string $subject, string $html, array $options = []): array
    {
        $options['html'] = true;

        return $this->send($to, $subject, $html, $options);
    }

    public function sendCampaign(array $campaign): array
    {
        $recipients = $this->normalizeRecipients($campaign['recipients'] ?? []);
        if ($recipients === []) {
            return $this->result(
                false,
                'Amazon SES campaign send requires explicit `recipients` list.'
            );
        }

        $subject = trim((string) ($campaign['subject'] ?? ''));
        if ($subject === '') {
            return $this->result(false, 'Campaign subject is required.');
        }

        $html = (string) ($campaign['html'] ?? '');
        $body = $html !== '' ? $html : (string) ($campaign['plain_text'] ?? '');
        if ($body === '') {
            return $this->result(false, 'Campaign body is required.');
        }

        $options = [
            'from_email'    => $campaign['from_email'] ?? null,
            'from_name'     => $campaign['from_name'] ?? null,
            'reply_to'      => $campaign['reply_to'] ?? null,
            'campaign_name' => $campaign['name'] ?? $campaign['campaign_name'] ?? null,
            'html'          => $html !== '',
        ];

        return $this->send($recipients, $subject, $body, array_filter(
            $options,
            static fn ($value) => $value !== null && $value !== ''
        ));
    }

    /**
     * @param array{email: string, name: string} $from
     * @param array<string, mixed>              $options
     */
    protected function sendSingle(string $toEmail, string $subject, string $body, bool $isHtml, array $from, array $options): array
    {
        $contact = $options['contact'] ?? null;
        $subject = $this->personalizeText($subject, $toEmail, $contact);
        $body    = $this->personalizeText($body, $toEmail, $contact);

        $fromAddress = $from['name'] !== ''
            ? sprintf('%s <%s>', $from['name'], $from['email'])
            : $from['email'];

        $payload = [
            'FromEmailAddress' => $fromAddress,
            'Destination'      => [
                'ToAddresses' => [$toEmail],
            ],
            'Content' => [
                'Simple' => [
                    'Subject' => [
                        'Data'    => $subject,
                        'Charset' => 'UTF-8',
                    ],
                    'Body' => [],
                ],
            ],
        ];

        if ($isHtml) {
            $payload['Content']['Simple']['Body']['Html'] = [
                'Data'    => $body,
                'Charset' => 'UTF-8',
            ];
            $plainText = strip_tags($body);
            if (trim($plainText) !== '') {
                $payload['Content']['Simple']['Body']['Text'] = [
                    'Data'    => $plainText,
                    'Charset' => 'UTF-8',
                ];
            }
        } else {
            $payload['Content']['Simple']['Body']['Text'] = [
                'Data'    => $body,
                'Charset' => 'UTF-8',
            ];
        }

        $replyTo = trim((string) ($options['reply_to'] ?? ''));
        if ($replyTo !== '' && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
            $payload['ReplyToAddresses'] = [$replyTo];
        }

        return $this->executeApiV2Request($payload, $toEmail);
    }

    /**
     * @param list<string>                       $recipients
     * @param array{email: string, name: string} $from
     * @param array<string, mixed>              $options
     */
    protected function sendManySingles(array $recipients, string $subject, string $body, bool $isHtml, array $from, array $options): array
    {
        $sent   = 0;
        $failed = [];
        $contactsMap = $this->preloadContacts($recipients);

        foreach ($recipients as $email) {
            $opt = $options;
            $opt['contact'] = $contactsMap[strtolower(trim($email))] ?? null;
            $result = $this->sendSingle($email, $subject, $body, $isHtml, $from, $opt);
            if ($result['ok']) {
                $sent++;
            } else {
                $failed[] = ['email' => $email, 'message' => $result['message']];
            }
        }

        if ($failed === []) {
            return $this->result(true, sprintf('Sent %d email(s) via Amazon SES.', $sent), [
                'sent' => $sent,
            ]);
        }

        if ($sent > 0) {
            return $this->result(false, sprintf('Sent %d of %d via Amazon SES. %d failed.', $sent, count($recipients), count($failed)), [
                'sent'   => $sent,
                'failed' => $failed,
            ]);
        }

        return $this->result(false, 'All Amazon SES email sends failed.', ['failed' => $failed]);
    }

    /**
     * Executes AWS SigV4 signed HTTPS POST to SES API v2.
     *
     * @param array<string, mixed> $payload
     */
    protected function executeApiV2Request(array $payload, string $toEmail): array
    {
        $region   = $this->region;
        $endpoint = sprintf('https://email.%s.amazonaws.com/v2/email/outbound-emails', $region);
        $host     = sprintf('email.%s.amazonaws.com', $region);
        $jsonBody = (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $amzDate   = gmdate('Ymd\THis\Z');
        $dateStamp = gmdate('Ymd');

        $canonicalUri    = '/v2/email/outbound-emails';
        $canonicalQuery  = '';
        $payloadHash     = hash('sha256', $jsonBody);

        $canonicalHeaders = "content-type:application/json\n" .
                            "host:{$host}\n" .
                            "x-amz-date:{$amzDate}\n";
        $signedHeaders   = 'content-type;host;x-amz-date';

        $canonicalRequest = "POST\n" .
                            "{$canonicalUri}\n" .
                            "{$canonicalQuery}\n" .
                            "{$canonicalHeaders}\n" .
                            "{$signedHeaders}\n" .
                            "{$payloadHash}";

        $algorithm       = 'AWS4-HMAC-SHA256';
        $credentialScope = "{$dateStamp}/{$region}/ses/aws4_request";
        $stringToSign    = "{$algorithm}\n" .
                           "{$amzDate}\n" .
                           "{$credentialScope}\n" .
                           hash('sha256', $canonicalRequest);

        // Derive AWS SigV4 signing key
        $kDate    = hash_hmac('sha256', $dateStamp, 'AWS4' . $this->secretKey, true);
        $kRegion  = hash_hmac('sha256', $region, $kDate, true);
        $kService = hash_hmac('sha256', 'ses', $kRegion, true);
        $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
        $signature = hash_hmac('sha256', $stringToSign, $kSigning);

        $authHeader = "{$algorithm} Credential={$this->accessKey}/{$credentialScope}, SignedHeaders={$signedHeaders}, Signature={$signature}";

        $headers = [
            'Content-Type: application/json',
            "Host: {$host}",
            "x-amz-date: {$amzDate}",
            "Authorization: {$authHeader}",
        ];

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $jsonBody,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $rawResponse = curl_exec($ch);
        $httpCode    = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError   = curl_error($ch);
        curl_close($ch);

        if ($curlError !== '') {
            log_message('error', 'SesEmailDriver cURL error: {err}', ['err' => $curlError]);

            return $this->result(false, 'Amazon SES network error: ' . $curlError);
        }

        $decoded = json_decode((string) $rawResponse, true);

        if ($httpCode >= 200 && $httpCode < 300) {
            $messageId = is_array($decoded) ? ($decoded['MessageId'] ?? '') : '';

            return $this->result(
                true,
                'Email sent via Amazon SES to ' . $toEmail . ($messageId !== '' ? ' (MessageId: ' . $messageId . ')' : ''),
                $decoded
            );
        }

        $errorMsg = 'Amazon SES error (HTTP ' . $httpCode . ')';
        if (is_array($decoded)) {
            if (! empty($decoded['message'])) {
                $errorMsg .= ': ' . (string) $decoded['message'];
            } elseif (! empty($decoded['Message'])) {
                $errorMsg .= ': ' . (string) $decoded['Message'];
            } elseif (! empty($decoded['Error']['Message'])) {
                $errorMsg .= ': ' . (string) $decoded['Error']['Message'];
            }
        }

        log_message('error', 'SesEmailDriver error: {msg}', ['msg' => $errorMsg]);

        return $this->result(false, $errorMsg, $decoded);
    }
}
