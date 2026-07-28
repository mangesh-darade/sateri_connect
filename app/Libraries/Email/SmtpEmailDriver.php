<?php

declare(strict_types=1);

namespace App\Libraries\Email;

use App\Libraries\SettingsService;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;
use RuntimeException;

/**
 * SMTP transport via CodeIgniter Email library + DB settings.
 */
class SmtpEmailDriver extends AbstractEmailDriver
{
    public function getName(): string
    {
        return SettingsService::EMAIL_PROVIDER_SMTP;
    }

    public function loadCredentials(): void
    {
        // Applied per-send in applySmtpConfig().
    }

    /**
     * @param array<string, mixed> $options
     */
    public function send(string|array $to, string $subject, string $body, array $options = []): array
    {
        $recipients = $this->normalizeRecipients($to);
        if ($recipients === []) {
            return $this->result(false, 'No valid recipient email addresses.');
        }

        $host = (string) $this->settings->get('smtp_host', '');
        if ($host === '') {
            return $this->result(false, 'SMTP host is not configured. Open Settings → Email Provider → SMTP.');
        }

        try {
            $this->applySmtpConfig();
            $from    = $this->resolveFrom($options, 'smtp_from_email', 'smtp_from_name');
            $email   = Services::email();
            $isHtml  = (bool) ($options['html'] ?? false);

            $email->setFrom($from['email'], $from['name']);
            $email->setTo($recipients);
            $email->setSubject($subject);

            if ($isHtml) {
                $email->setMailType('html');
                $email->setMessage($body);
            } else {
                $email->setMailType('text');
                $email->setMessage($body);
            }

            $replyTo = trim((string) ($options['reply_to'] ?? ''));
            if ($replyTo !== '' && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
                $email->setReplyTo($replyTo);
            }

            if (! $email->send()) {
                return $this->result(false, 'SMTP send failed: ' . $email->printDebugger(['headers']));
            }

            return $this->result(true, 'Email sent via SMTP to ' . implode(', ', $recipients));
        } catch (\Throwable $e) {
            log_message('error', 'SmtpEmailDriver send failed: {msg}', ['msg' => $e->getMessage()]);

            return $this->result(false, $e->getMessage());
        }
    }

    public function sendCampaign(array $campaign): array
    {
        $recipients = $this->normalizeRecipients($campaign['recipients'] ?? []);
        if ($recipients === []) {
            return $this->result(
                false,
                'SMTP marketing send needs explicit `recipients`. SMTP has no native list/segment campaign API.'
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
            'from_email' => $campaign['from_email'] ?? null,
            'from_name'  => $campaign['from_name'] ?? null,
            'reply_to'   => $campaign['reply_to'] ?? null,
            'html'       => $html !== '',
        ];

        return $this->send($recipients, $subject, $body, array_filter(
            $options,
            static fn ($value) => $value !== null && $value !== ''
        ));
    }

    protected function applySmtpConfig(): void
    {
        $config = config('Email');
        $host   = (string) $this->settings->get('smtp_host', '');

        if ($host === '') {
            throw new RuntimeException('SMTP host is not configured.');
        }

        $config->protocol   = 'smtp';
        $config->SMTPHost   = $host;
        $config->SMTPPort   = (int) $this->settings->get('smtp_port', 587);
        $config->SMTPUser   = (string) $this->settings->get('smtp_user', '');
        $config->SMTPPass   = (string) ($this->settings->get('smtp_password') ?: $this->settings->get('smtp_pass', ''));
        $config->SMTPCrypto = (string) $this->settings->get('smtp_encryption', 'tls');
        $config->fromEmail  = (string) $this->settings->get('smtp_from_email', '');
        $config->fromName   = (string) $this->settings->get('smtp_from_name', '');
    }
}
