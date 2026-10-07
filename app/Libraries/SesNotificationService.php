<?php

declare(strict_types=1);

namespace App\Libraries;

use App\Models\EmailRecipientEventModel;
use App\Models\EmailUnsubscribeModel;
use Throwable;

/**
 * Handles Amazon SNS deliveries of SES bounce / complaint / delivery notifications.
 *
 * - Verifies the SNS message signature against the AWS signing certificate.
 * - Pins the SNS Topic ARN (setting `ses_sns_topic_arn`, saved on first subscription).
 * - Hard bounces and complaints are added to `email_unsubscribes` so they are never mailed again.
 */
class SesNotificationService
{
    protected SettingsService $settings;

    public function __construct(?SettingsService $settings = null)
    {
        $this->settings = $settings ?? new SettingsService();
    }

    /**
     * @return array{status: int, message: string}
     */
    public function handle(string $rawBody): array
    {
        $msg = json_decode($rawBody, true);
        if (! is_array($msg) || empty($msg['Type'])) {
            return ['status' => 400, 'message' => 'Invalid SNS payload.'];
        }

        if (! $this->verifySignature($msg)) {
            log_message('warning', 'SES SNS webhook: signature verification failed.');

            return ['status' => 403, 'message' => 'Invalid SNS signature.'];
        }

        $topicArn = (string) ($msg['TopicArn'] ?? '');
        $pinned   = trim((string) $this->settings->get('ses_sns_topic_arn', ''));
        if ($pinned !== '' && ! hash_equals($pinned, $topicArn)) {
            log_message('warning', 'SES SNS webhook: unexpected topic {topic}.', ['topic' => $topicArn]);

            return ['status' => 403, 'message' => 'Unexpected SNS topic.'];
        }
        if ($pinned === '' && $topicArn !== '') {
            $this->settings->setSesConfig(['sns_topic_arn' => $topicArn]);
        }

        return match ((string) $msg['Type']) {
            'SubscriptionConfirmation' => $this->confirmSubscription($msg),
            'Notification'             => $this->processNotification((string) ($msg['Message'] ?? '')),
            default                    => ['status' => 200, 'message' => 'Ignored.'],
        };
    }

    /**
     * @param array<string, mixed> $msg
     *
     * @return array{status: int, message: string}
     */
    protected function confirmSubscription(array $msg): array
    {
        $url = (string) ($msg['SubscribeURL'] ?? '');
        if (! $this->isAwsSnsUrl($url)) {
            return ['status' => 400, 'message' => 'Invalid SubscribeURL.'];
        }

        $response = $this->httpGet($url);
        if ($response === null) {
            return ['status' => 502, 'message' => 'Could not confirm SNS subscription.'];
        }

        log_activity('ses_sns_subscribed', 'settings', 'Amazon SES bounce/complaint notifications connected', [
            'topic_arn' => (string) ($msg['TopicArn'] ?? ''),
        ]);

        return ['status' => 200, 'message' => 'Subscription confirmed.'];
    }

    /**
     * Supports SES identity notifications (`notificationType`) and event publishing (`eventType`).
     *
     * @return array{status: int, message: string}
     */
    protected function processNotification(string $message): array
    {
        $event = json_decode($message, true);
        if (! is_array($event)) {
            return ['status' => 200, 'message' => 'Non-JSON notification ignored.'];
        }

        $type       = (string) ($event['notificationType'] ?? $event['eventType'] ?? '');
        $tags       = (array) ($event['mail']['tags'] ?? []);
        $logId      = (int) ($tags['log_id'][0] ?? 0);
        $campaignId = (int) ($tags['campaign_id'][0] ?? 0) ?: null;
        $events     = model(EmailRecipientEventModel::class);
        $handled    = 0;

        // Identity notifications carry no tags; the SES MessageId stored at send time links them back.
        if ($logId === 0 && ($sent = $events->findSentByMessageId((string) ($event['mail']['messageId'] ?? ''))) !== null) {
            $logId      = (int) $sent['log_id'];
            $campaignId = $campaignId ?? (isset($sent['campaign_id']) ? (int) $sent['campaign_id'] : null);
        }

        switch ($type) {
            case 'Bounce':
                $bounce    = (array) ($event['bounce'] ?? []);
                $permanent = ($bounce['bounceType'] ?? '') === 'Permanent';
                foreach ((array) ($bounce['bouncedRecipients'] ?? []) as $r) {
                    $email  = (string) ($r['emailAddress'] ?? '');
                    $detail = trim(($bounce['bounceType'] ?? '') . '/' . ($bounce['bounceSubType'] ?? '') . ' ' . ($r['diagnosticCode'] ?? ''));
                    $events->record($email, EmailRecipientEventModel::TYPE_BOUNCE, $logId, $campaignId, $detail);
                    if ($permanent) {
                        $this->suppress($email, $campaignId, 'SES hard bounce: ' . $detail);
                    }
                    $handled++;
                }
                break;

            case 'Complaint':
                $complaint = (array) ($event['complaint'] ?? []);
                foreach ((array) ($complaint['complainedRecipients'] ?? []) as $r) {
                    $email  = (string) ($r['emailAddress'] ?? '');
                    $detail = 'SES spam complaint' . (! empty($complaint['complaintFeedbackType']) ? ': ' . $complaint['complaintFeedbackType'] : '');
                    $events->record($email, EmailRecipientEventModel::TYPE_COMPLAINT, $logId, $campaignId, $detail);
                    $this->suppress($email, $campaignId, $detail);
                    $handled++;
                }
                break;

            case 'Delivery':
                foreach ((array) ($event['delivery']['recipients'] ?? []) as $email) {
                    $events->record((string) $email, EmailRecipientEventModel::TYPE_DELIVERY, $logId, $campaignId);
                    $handled++;
                }
                break;
        }

        return ['status' => 200, 'message' => sprintf('%s processed for %d recipient(s).', $type ?: 'Event', $handled)];
    }

    protected function suppress(string $email, ?int $campaignId, string $reason): void
    {
        $email = strtolower(trim($email));
        if ($email === '') {
            return;
        }

        if (model(EmailUnsubscribeModel::class)->recordUnsubscribe($email, $campaignId, mb_substr($reason, 0, 255), 'aws-ses')) {
            log_activity('email_suppressed', 'emails', 'Suppressed ' . $email . ' (' . mb_substr($reason, 0, 120) . ')', [
                'email'       => $email,
                'campaign_id' => $campaignId,
                'source'      => 'ses_sns',
            ]);
        }
    }

    /**
     * @param array<string, mixed> $msg
     */
    protected function verifySignature(array $msg): bool
    {
        $certUrl   = (string) ($msg['SigningCertURL'] ?? $msg['SigningCertUrl'] ?? '');
        $signature = base64_decode((string) ($msg['Signature'] ?? ''), true);
        if ($signature === false || ! $this->isAwsSnsUrl($certUrl) || ! str_ends_with((string) parse_url($certUrl, PHP_URL_PATH), '.pem')) {
            return false;
        }

        $fields = $msg['Type'] === 'Notification'
            ? ['Message', 'MessageId', 'Subject', 'Timestamp', 'TopicArn', 'Type']
            : ['Message', 'MessageId', 'SubscribeURL', 'Timestamp', 'Token', 'TopicArn', 'Type'];

        $stringToSign = '';
        foreach ($fields as $field) {
            if (! array_key_exists($field, $msg)) {
                continue;
            }
            $stringToSign .= $field . "\n" . $msg[$field] . "\n";
        }

        $cert = $this->signingCertificate($certUrl);
        if ($cert === null) {
            return false;
        }

        $algo = ((string) ($msg['SignatureVersion'] ?? '1')) === '2' ? OPENSSL_ALGO_SHA256 : OPENSSL_ALGO_SHA1;

        return openssl_verify($stringToSign, $signature, $cert, $algo) === 1;
    }

    protected function signingCertificate(string $url): ?string
    {
        $cacheKey = 'sns_cert_' . md5($url);
        try {
            $cached = cache($cacheKey);
            if (is_string($cached) && $cached !== '') {
                return $cached;
            }
        } catch (Throwable) {
            // cache optional
        }

        $pem = $this->httpGet($url);
        if ($pem === null || ! str_contains($pem, 'BEGIN CERTIFICATE')) {
            return null;
        }

        try {
            cache()->save($cacheKey, $pem, DAY);
        } catch (Throwable) {
            // cache optional
        }

        return $pem;
    }

    protected function isAwsSnsUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https') {
            return false;
        }

        return preg_match('/^sns\.[a-z0-9\-]+\.amazonaws\.com(\.cn)?$/', strtolower((string) ($parts['host'] ?? ''))) === 1;
    }

    protected function httpGet(string $url): ?string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ($body !== false && $code >= 200 && $code < 300) ? (string) $body : null;
    }
}
