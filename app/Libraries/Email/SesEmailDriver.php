<?php

declare(strict_types=1);

namespace App\Libraries\Email;

use App\Libraries\SettingsService;
use App\Libraries\TenantContext;
use App\Models\EmailSenderModel;
use Config\EmailProviders;

/**
 * Amazon Simple Email Service (SES) API v2 transport via Native AWS SigV4.
 *
 * Zero external SDK dependencies. Uses official SES API v2:
 * POST https://email.{region}.amazonaws.com/v2/email/outbound-emails
 * GET  https://email.{region}.amazonaws.com/v2/email/account
 */
class SesEmailDriver extends AbstractEmailDriver
{
    protected const DEFAULT_SEND_RATE = 10.0;
    protected const MAX_RETRIES       = 3;

    protected EmailProviders $config;
    protected string $accessKey = '';
    protected string $secretKey = '';
    protected string $region = 'ap-south-1';
    protected string $configurationSet = '';
    protected string $marketingConfigurationSet = '';
    protected float $maxSendRate = 0.0;
    protected float $lastSendAt = 0.0;

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
        $this->accessKey        = trim((string) $this->settings->get('ses_access_key', ''));
        $this->secretKey        = trim((string) $this->settings->get('ses_secret_key', ''));
        $this->region           = trim((string) $this->settings->get('ses_region', 'ap-south-1')) ?: 'ap-south-1';
        $this->configurationSet = trim((string) $this->settings->get('ses_configuration_set', ''));
        $this->marketingConfigurationSet = trim((string) $this->settings->get('ses_marketing_configuration_set', ''));
        $this->maxSendRate      = max(0.0, (float) $this->settings->get('ses_max_send_rate', '0'));
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

        $options['purpose'] = EmailSenderModel::normalizePurpose((string) ($options['purpose'] ?? ''));
        if ($options['purpose'] === EmailSenderModel::PURPOSE_MARKETING && trim((string) ($options['from_email'] ?? '')) === '') {
            $marketingFrom = trim((string) $this->settings->get('ses_marketing_from_email', ''));
            if ($marketingFrom !== '') {
                $options['from_email'] = $marketingFrom;
                $marketingName = trim((string) $this->settings->get('ses_marketing_from_name', ''));
                if (trim((string) ($options['from_name'] ?? '')) === '' && $marketingName !== '') {
                    $options['from_name'] = $marketingName;
                }
            }
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
            'from_email'      => $campaign['from_email'] ?? null,
            'from_name'       => $campaign['from_name'] ?? null,
            'reply_to'        => $campaign['reply_to'] ?? null,
            'campaign_name'   => $campaign['name'] ?? $campaign['campaign_name'] ?? null,
            'html'            => $html !== '',
            'attachments'     => $campaign['attachments'] ?? [],
            'unsubscribe_url' => $campaign['unsubscribe_url'] ?? null,
            'purpose'         => $campaign['purpose'] ?? EmailSenderModel::PURPOSE_MARKETING,
        ];

        return $this->send($recipients, $subject, $body, array_filter(
            $options,
            static fn ($value) => $value !== null && $value !== '' && $value !== []
        ));
    }

    /**
     * Verifies credentials via GetAccount, sends a test email, and reports sandbox / quota status.
     */
    public function testConnection(string $to): array
    {
        if (! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return $this->result(false, 'A valid recipient email is required.');
        }

        $this->loadCredentials();
        if ($this->accessKey === '' || $this->secretKey === '') {
            return $this->result(false, 'Amazon SES Access Key or Secret Key is not configured in Settings.');
        }

        $account = $this->getAccountStatus();
        if (! $account['ok']) {
            return $account;
        }

        $result  = parent::testConnection($to);
        $status  = (array) ($account['data'] ?? []);
        $notes   = [];
        if (empty($status['production_access'])) {
            $notes[] = 'Account is in the SES sandbox: only verified addresses can receive mail until AWS grants production access.';
        }
        if (isset($status['sending_enabled']) && ! $status['sending_enabled']) {
            $notes[] = 'Sending is currently paused on this SES account.';
        }
        if (! empty($status['max_send_rate'])) {
            $notes[] = sprintf(
                'Quota: %s/sec, %s per 24h (%s used).',
                $status['max_send_rate'],
                $status['max_24h_send'] ?? '?',
                $status['sent_last_24h'] ?? '0'
            );
        }

        if ($notes !== []) {
            $result['notice']  = implode(' ', $notes);
            $result['message'] = trim((string) ($result['message'] ?? '') . ' ' . $result['notice']);
        }
        $result['data'] = ['send' => $result['data'] ?? null, 'account' => $status];

        return $result;
    }

    /**
     * SES GetAccount: sandbox flag, sending status, and quota.
     *
     * @return array{ok: bool, message: string, provider: string, data?: mixed}
     */
    public function getAccountStatus(): array
    {
        if ($this->accessKey === '' || $this->secretKey === '') {
            $this->loadCredentials();
        }

        $response = $this->signedRequest('GET', '/v2/email/account');
        if (! $response['ok']) {
            return $this->result(false, $response['error'], $response['decoded']);
        }

        $d     = (array) $response['decoded'];
        $quota = (array) ($d['SendQuota'] ?? []);

        return $this->result(true, 'Amazon SES account reachable.', [
            'production_access'  => (bool) ($d['ProductionAccessEnabled'] ?? false),
            'sending_enabled'    => (bool) ($d['SendingEnabled'] ?? false),
            'enforcement_status' => (string) ($d['EnforcementStatus'] ?? ''),
            'max_24h_send'       => (float) ($quota['Max24HourSend'] ?? 0),
            'max_send_rate'      => (float) ($quota['MaxSendRate'] ?? 0),
            'sent_last_24h'      => (float) ($quota['SentLast24Hours'] ?? 0),
        ]);
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

        $fromAddress = $this->formatFromAddress($from);
        $attachments = (array) ($options['attachments'] ?? []);
        $replyTo     = trim((string) ($options['reply_to'] ?? ''));

        // If physical file attachments exist, Amazon SES requires Raw MIME message
        if ($attachments !== []) {
            $rawMime = $this->buildRawMimeMessage($toEmail, $subject, $body, $isHtml, $from, $options);
            $payload = [
                'FromEmailAddress' => $fromAddress,
                'Destination'      => [
                    'ToAddresses' => [$toEmail],
                ],
                'Content' => [
                    'Raw' => [
                        'Data' => base64_encode($rawMime),
                    ],
                ],
            ];
            if ($replyTo !== '' && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
                $payload['ReplyToAddresses'] = [$replyTo];
            }

            return $this->executeApiV2Request($this->withDeliveryOptions($payload, $options), $toEmail);
        }

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

        $headers = $this->listUnsubscribeHeaders($options, $toEmail);
        if ($headers !== []) {
            $payload['Content']['Simple']['Headers'] = array_map(
                static fn (string $name, string $value) => ['Name' => $name, 'Value' => $value],
                array_keys($headers),
                array_values($headers)
            );
        }

        if ($replyTo !== '' && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
            $payload['ReplyToAddresses'] = [$replyTo];
        }

        return $this->executeApiV2Request($this->withDeliveryOptions($payload, $options), $toEmail);
    }

    /**
     * RFC 2047 encodes non-ASCII display names; quotes names with RFC 5322 specials.
     *
     * @param array{email: string, name: string} $from
     */
    protected function formatFromAddress(array $from): string
    {
        $name = trim($from['name']);
        if ($name === '') {
            return $from['email'];
        }

        if (preg_match('/[^\x20-\x7E]/', $name) === 1) {
            return sprintf('=?UTF-8?B?%s?= <%s>', base64_encode($name), $from['email']);
        }

        if (preg_match('/[()<>\[\]:;@\\\\,."]/', $name) === 1) {
            return sprintf('"%s" <%s>', addcslashes($name, '"\\'), $from['email']);
        }

        return sprintf('%s <%s>', $name, $from['email']);
    }

    /**
     * Adds ConfigurationSetName + EmailTags so SES event publishing / SNS can map events back.
     *
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    protected function withDeliveryOptions(array $payload, array $options): array
    {
        $isMarketing = ($options['purpose'] ?? '') === EmailSenderModel::PURPOSE_MARKETING;
        $set         = $isMarketing && $this->marketingConfigurationSet !== '' ? $this->marketingConfigurationSet : $this->configurationSet;
        if ($set !== '') {
            $payload['ConfigurationSetName'] = $set;
        }

        $tags = [
            'purpose'     => $isMarketing ? EmailSenderModel::PURPOSE_MARKETING : EmailSenderModel::PURPOSE_TRANSACTIONAL,
            'campaign'    => (string) ($options['campaign_name'] ?? ''),
            'campaign_id' => (string) ($options['campaign_id'] ?? ''),
            'log_id'      => (string) ($options['log_id'] ?? ''),
            'tenant'      => (string) (TenantContext::get() ?? ''),
        ];

        $emailTags = [];
        foreach ($tags as $name => $value) {
            $value = substr((string) preg_replace('/[^A-Za-z0-9_\-]+/', '_', trim($value)), 0, 256);
            if ($value !== '' && $value !== '_') {
                $emailTags[] = ['Name' => $name, 'Value' => $value];
            }
        }
        if ($emailTags !== []) {
            $payload['EmailTags'] = $emailTags;
        }

        return $payload;
    }

    /**
     * Builds RFC 2822 / MIME multipart/mixed message for SES Raw delivery.
     *
     * @param array{email: string, name: string} $from
     * @param array<string, mixed>              $options
     */
    protected function buildRawMimeMessage(
        string $toEmail,
        string $subject,
        string $body,
        bool $isHtml,
        array $from,
        array $options
    ): string {
        $fromHeader = $from['name'] !== ''
            ? sprintf('=?UTF-8?B?%s?= <%s>', base64_encode($from['name']), $from['email'])
            : $from['email'];

        $boundaryMixed = '=_Part_Mixed_' . bin2hex(random_bytes(12));
        $boundaryAlt   = '=_Part_Alt_' . bin2hex(random_bytes(12));

        $headers   = [];
        $headers[] = 'From: ' . $fromHeader;
        $headers[] = 'To: ' . $toEmail;
        $headers[] = 'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=';
        $headers[] = 'MIME-Version: 1.0';

        $replyTo = trim((string) ($options['reply_to'] ?? ''));
        if ($replyTo !== '' && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
            $headers[] = 'Reply-To: ' . $replyTo;
        }

        foreach ($this->listUnsubscribeHeaders($options, $toEmail) as $name => $value) {
            $headers[] = $name . ': ' . $value;
        }

        $headers[] = 'Content-Type: multipart/mixed; boundary="' . $boundaryMixed . '"';

        $mime = implode("\r\n", $headers) . "\r\n\r\n";

        // Body part
        $mime .= '--' . $boundaryMixed . "\r\n";
        $mime .= 'Content-Type: multipart/alternative; boundary="' . $boundaryAlt . '"' . "\r\n\r\n";

        $plain = strip_tags($body);
        $mime .= '--' . $boundaryAlt . "\r\n";
        $mime .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $mime .= "Content-Transfer-Encoding: base64\r\n\r\n";
        $mime .= chunk_split(base64_encode($plain)) . "\r\n";

        if ($isHtml) {
            $mime .= '--' . $boundaryAlt . "\r\n";
            $mime .= "Content-Type: text/html; charset=UTF-8\r\n";
            $mime .= "Content-Transfer-Encoding: base64\r\n\r\n";
            $mime .= chunk_split(base64_encode($body)) . "\r\n";
        }

        $mime .= '--' . $boundaryAlt . "--\r\n\r\n";

        // Physical file attachments
        $attachments = (array) ($options['attachments'] ?? []);
        foreach ($attachments as $att) {
            $filePath = (string) ($att['path'] ?? '');
            if ($filePath !== '' && file_exists($filePath)) {
                $fileName = (string) ($att['name'] ?? basename($filePath));
                $fileMime = (string) ($att['mime'] ?? 'application/octet-stream');
                $content  = @file_get_contents($filePath);
                if ($content !== false) {
                    $mime .= '--' . $boundaryMixed . "\r\n";
                    $mime .= 'Content-Type: ' . $fileMime . '; name="' . addslashes($fileName) . '"' . "\r\n";
                    $mime .= 'Content-Disposition: attachment; filename="' . addslashes($fileName) . '"' . "\r\n";
                    $mime .= "Content-Transfer-Encoding: base64\r\n\r\n";
                    $mime .= chunk_split(base64_encode($content)) . "\r\n";
                }
            }
        }

        $mime .= '--' . $boundaryMixed . "--\r\n";

        return $mime;
    }

    /**
     * @param list<string>                       $recipients
     * @param array{email: string, name: string} $from
     * @param array<string, mixed>              $options
     */
    protected function sendManySingles(array $recipients, string $subject, string $body, bool $isHtml, array $from, array $options): array
    {
        // Bulk sends can run for minutes; never let PHP / a closed browser tab cut them off mid-list.
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }
        ignore_user_abort(true);

        if ($this->maxSendRate <= 0) {
            $account = $this->signedRequest('GET', '/v2/email/account');
            if ($account['ok'] && is_array($account['decoded'])) {
                $this->maxSendRate = (float) ($account['decoded']['SendQuota']['MaxSendRate'] ?? 0);
            }
        }

        $sent    = 0;
        $failed  = [];
        $details = [];
        $contactsMap = $this->preloadContacts($recipients);

        foreach ($recipients as $email) {
            $opt = $options;
            $opt['contact'] = $contactsMap[strtolower(trim($email))] ?? null;
            $result = $this->sendSingle($email, $subject, $body, $isHtml, $from, $opt);
            if ($result['ok']) {
                $sent++;
                $details[] = ['email' => $email, 'ok' => true, 'message_id' => (string) ($result['data']['MessageId'] ?? '')];
            } else {
                $failed[]  = ['email' => $email, 'message' => $result['message']];
                $details[] = ['email' => $email, 'ok' => false, 'error' => $result['message']];
            }
        }

        if ($failed === []) {
            return $this->result(true, sprintf('Sent %d email(s) via Amazon SES.', $sent), [
                'sent'       => $sent,
                'recipients' => $details,
            ]);
        }

        if ($sent > 0) {
            return $this->result(false, sprintf('Sent %d of %d via Amazon SES. %d failed.', $sent, count($recipients), count($failed)), [
                'sent'       => $sent,
                'failed'     => $failed,
                'recipients' => $details,
            ]);
        }

        return $this->result(false, 'All Amazon SES email sends failed.', ['failed' => $failed, 'recipients' => $details]);
    }

    /**
     * Sends one SendEmail call, paced to the account send rate, retrying throttling / 5xx / network errors.
     *
     * @param array<string, mixed> $payload
     */
    protected function executeApiV2Request(array $payload, string $toEmail): array
    {
        $jsonBody = (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $response = ['ok' => false, 'status' => 0, 'decoded' => null, 'error' => '', 'retryable' => false];
        for ($attempt = 0; $attempt <= self::MAX_RETRIES; $attempt++) {
            if ($attempt > 0) {
                usleep((int) (500000 * (2 ** ($attempt - 1))));
            }

            $this->throttle();
            $response = $this->signedRequest('POST', '/v2/email/outbound-emails', $jsonBody);
            if ($response['ok'] || ! $response['retryable']) {
                break;
            }
        }

        if ($response['ok']) {
            $decoded   = $response['decoded'];
            $messageId = is_array($decoded) ? (string) ($decoded['MessageId'] ?? '') : '';

            return $this->result(
                true,
                'Email sent via Amazon SES to ' . $toEmail . ($messageId !== '' ? ' (MessageId: ' . $messageId . ')' : ''),
                $decoded
            );
        }

        log_message('error', 'SesEmailDriver error: {msg}', ['msg' => $response['error']]);

        return $this->result(false, $response['error'], $response['decoded']);
    }

    /**
     * Keeps calls under the SES per-second send quota (setting `ses_max_send_rate`, else account MaxSendRate).
     */
    protected function throttle(): void
    {
        $rate     = $this->maxSendRate > 0 ? $this->maxSendRate : self::DEFAULT_SEND_RATE;
        $interval = 1.0 / $rate;
        $wait     = ($this->lastSendAt + $interval) - microtime(true);
        if ($wait > 0) {
            usleep((int) ($wait * 1000000));
        }
        $this->lastSendAt = microtime(true);
    }

    /**
     * Public SES v2 API call for identity management (CreateEmailIdentity, GetEmailIdentity, ...).
     * `$path` must already be URL-encoded per segment, e.g. '/v2/email/identities/' . rawurlencode($id).
     *
     * @param array<string, mixed>|null $payload
     *
     * @return array{ok: bool, status: int, decoded: mixed, error: string, retryable: bool}
     */
    public function apiRequest(string $method, string $path, ?array $payload = null): array
    {
        $this->loadCredentials();
        if ($this->accessKey === '' || $this->secretKey === '') {
            return ['ok' => false, 'status' => 0, 'decoded' => null, 'error' => 'Amazon SES Access Key or Secret Key is not configured in Settings.', 'retryable' => false];
        }

        $body = $payload !== null ? (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : '';

        return $this->signedRequest(strtoupper($method), $path, $body);
    }

    public function getRegion(): string
    {
        return $this->region;
    }

    /**
     * Amazon SNS Query API call (same AWS credentials / region as SES), e.g. Action=CreateTopic.
     *
     * @param array<string, string> $params
     *
     * @return array{ok: bool, status: int, decoded: mixed, error: string, retryable: bool}
     */
    public function snsRequest(array $params): array
    {
        $this->loadCredentials();
        if ($this->accessKey === '' || $this->secretKey === '') {
            return ['ok' => false, 'status' => 0, 'decoded' => null, 'error' => 'Amazon SES Access Key or Secret Key is not configured in Settings.', 'retryable' => false];
        }

        $body = http_build_query($params + ['Version' => '2010-03-31'], '', '&', PHP_QUERY_RFC3986);

        return $this->signedRequest('POST', '/', $body, 'sns', 'application/x-www-form-urlencoded');
    }

    /**
     * AWS SigV4 signed HTTPS request (SES v2 by default).
     *
     * @return array{ok: bool, status: int, decoded: mixed, error: string, retryable: bool}
     */
    protected function signedRequest(string $method, string $path, string $body = '', string $service = 'ses', string $contentType = 'application/json'): array
    {
        $region   = $this->region;
        $host     = $service === 'ses' ? sprintf('email.%s.amazonaws.com', $region) : sprintf('%s.%s.amazonaws.com', $service, $region);
        $endpoint = 'https://' . $host . $path;
        $label    = $service === 'ses' ? 'Amazon SES' : 'Amazon ' . strtoupper($service);

        $amzDate   = gmdate('Ymd\THis\Z');
        $dateStamp = gmdate('Ymd');

        $payloadHash      = hash('sha256', $body);
        $canonicalHeaders = "content-type:{$contentType}\n" .
                            "host:{$host}\n" .
                            "x-amz-date:{$amzDate}\n";
        $signedHeaders    = 'content-type;host;x-amz-date';

        // SigV4 (non-S3): each already-encoded path segment is URI-encoded again in the canonical request.
        $canonicalUri     = implode('/', array_map('rawurlencode', explode('/', $path)));
        $canonicalRequest = "{$method}\n{$canonicalUri}\n\n{$canonicalHeaders}\n{$signedHeaders}\n{$payloadHash}";

        $algorithm       = 'AWS4-HMAC-SHA256';
        $credentialScope = "{$dateStamp}/{$region}/{$service}/aws4_request";
        $stringToSign    = "{$algorithm}\n{$amzDate}\n{$credentialScope}\n" . hash('sha256', $canonicalRequest);

        // Derive AWS SigV4 signing key
        $kDate     = hash_hmac('sha256', $dateStamp, 'AWS4' . $this->secretKey, true);
        $kRegion   = hash_hmac('sha256', $region, $kDate, true);
        $kService  = hash_hmac('sha256', $service, $kRegion, true);
        $kSigning  = hash_hmac('sha256', 'aws4_request', $kService, true);
        $signature = hash_hmac('sha256', $stringToSign, $kSigning);

        $authHeader = "{$algorithm} Credential={$this->accessKey}/{$credentialScope}, SignedHeaders={$signedHeaders}, Signature={$signature}";

        $ch = curl_init($endpoint);
        $curlOptions = [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => [
                "Content-Type: {$contentType}",
                'Accept: application/json',
                "Host: {$host}",
                "x-amz-date: {$amzDate}",
                "Authorization: {$authHeader}",
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];
        if ($method === 'POST') {
            $curlOptions[CURLOPT_POST] = true;
        }
        if ($method !== 'GET') {
            $curlOptions[CURLOPT_POSTFIELDS] = $body;
        }
        curl_setopt_array($ch, $curlOptions);

        $rawResponse = curl_exec($ch);
        $httpCode    = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError   = curl_error($ch);
        curl_close($ch);

        if ($curlError !== '') {
            log_message('error', 'SesEmailDriver cURL error: {err}', ['err' => $curlError]);

            return ['ok' => false, 'status' => 0, 'decoded' => null, 'error' => $label . ' network error: ' . $curlError, 'retryable' => true];
        }

        $decoded = json_decode((string) $rawResponse, true);

        if ($httpCode >= 200 && $httpCode < 300) {
            return ['ok' => true, 'status' => $httpCode, 'decoded' => $decoded, 'error' => '', 'retryable' => false];
        }

        $errorMsg = $label . ' error (HTTP ' . $httpCode . ')';
        if (is_array($decoded)) {
            if (! empty($decoded['message'])) {
                $errorMsg .= ': ' . (string) $decoded['message'];
            } elseif (! empty($decoded['Message'])) {
                $errorMsg .= ': ' . (string) $decoded['Message'];
            } elseif (! empty($decoded['Error']['Message'])) {
                $errorMsg .= ': ' . (string) $decoded['Error']['Message'];
            }
        }

        return [
            'ok'        => false,
            'status'    => $httpCode,
            'decoded'   => $decoded,
            'error'     => $errorMsg,
            'retryable' => $httpCode === 429 || $httpCode >= 500,
        ];
    }
}
