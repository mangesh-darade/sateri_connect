<?php

declare(strict_types=1);

namespace App\Libraries\Email;

use App\Libraries\SettingsService;

/**
 * Shared helpers for email drivers.
 */
abstract class AbstractEmailDriver implements EmailDriverInterface
{
    protected SettingsService $settings;

    public function __construct(?SettingsService $settings = null)
    {
        $this->settings = $settings ?? new SettingsService();
    }

    public function sendHtml(string|array $to, string $subject, string $html, array $options = []): array
    {
        $options['html'] = true;

        return $this->send($to, $subject, $html, $options);
    }

    public function testConnection(string $to): array
    {
        if (! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return $this->result(false, 'A valid recipient email is required.');
        }

        $appName = (string) $this->settings->get('app_name', 'WhatsApp Automation');

        return $this->send(
            $to,
            'Email Test — ' . $appName,
            'This is a test email from your automation platform. Your email provider is configured correctly.',
            ['campaign_name' => 'connection-test']
        );
    }

    public function sendCampaign(array $campaign): array
    {
        return $this->result(
            false,
            sprintf('%s does not support provider-native marketing campaigns.', $this->getName())
        );
    }

    /**
     * @return list<string>
     */
    protected function normalizeRecipients(string|array $to): array
    {
        if (is_string($to)) {
            $to = preg_split('/[,;]+/', $to) ?: [];
        }

        $out = [];
        foreach ($to as $email) {
            $email = trim((string) $email);
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $out[] = $email;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array{ok: bool, message: string, provider: string, data?: mixed}
     */
    protected function result(bool $ok, string $message, mixed $data = null): array
    {
        $payload = [
            'ok'       => $ok,
            'message'  => $message,
            'provider' => $this->getName(),
        ];

        if ($data !== null) {
            $payload['data'] = $data;
        }

        return $payload;
    }

    /**
     * @return array{email: string, name: string}
     */
    protected function resolveFrom(array $options, string $defaultEmailKey, string $defaultNameKey): array
    {
        $email = trim((string) ($options['from_email'] ?? $this->settings->get($defaultEmailKey, '')));
        $name  = trim((string) ($options['from_name'] ?? $this->settings->get($defaultNameKey, '')));

        if ($email === '') {
            $email = (string) $this->settings->get('app_email', 'noreply@localhost');
        }
        if ($name === '') {
            $name = (string) $this->settings->get('app_name', 'WhatsApp Automation');
        }

        return ['email' => $email, 'name' => $name];
    }

    /**
     * Preloads contacts for an array of email addresses to prevent N+1 queries.
     *
     * @param list<string> $emails
     * @return array<string, array<string, mixed>> keyed by lowercase email
     */
    protected function preloadContacts(array $emails): array
    {
        $clean = [];
        foreach ($emails as $em) {
            $e = strtolower(trim((string) $em));
            if ($e !== '') {
                $clean[] = $e;
            }
        }
        $clean = array_values(array_unique($clean));
        if ($clean === []) {
            return [];
        }

        try {
            $rows = model(\App\Models\ContactModel::class)
                ->select('id, name, email, mobile')
                ->whereIn('email', array_slice($clean, 0, 500))
                ->findAll();

            $map = [];
            foreach ($rows as $row) {
                $em = strtolower(trim((string) ($row['email'] ?? '')));
                if ($em !== '') {
                    $map[$em] = $row;
                }
            }

            return $map;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Replaces personalization merge tags ({{name}}, {{first_name}}, {{email}}, {{mobile}}, etc.)
     *
     * @param array<string, mixed>|null $contact
     */
    protected function personalizeText(string $text, string $email, ?array $contact = null): string
    {
        if (! str_contains($text, '{{')) {
            return $text;
        }

        $emailLower = strtolower(trim($email));

        if ($contact === null && $emailLower !== '') {
            try {
                $contact = model(\App\Models\ContactModel::class)
                    ->select('id, name, email, mobile')
                    ->where('email', $emailLower)
                    ->first();
            } catch (\Throwable $e) {
                $contact = null;
            }
        }

        $name = trim((string) ($contact['name'] ?? ''));
        if ($name === '') {
            $name = 'Customer';
        }
        $firstName = explode(' ', $name)[0] ?? $name;
        $mobile    = trim((string) ($contact['mobile'] ?? ''));

        return (string) preg_replace_callback('/\{\{\s*([a-zA-Z0-9_\.]+)\s*\}\}/', function ($matches) use ($name, $firstName, $email, $mobile) {
            $rawTag = $matches[1];
            $tag    = strtolower($rawTag);

            return match ($tag) {
                'name', 'fullname', 'full_name', 'contact_name', 'customer_name', 'contact.name' => ($rawTag === strtoupper($rawTag) && strlen($rawTag) > 2) ? strtoupper($name) : $name,
                'first_name', 'firstname', 'contact.first_name'                                  => ($rawTag === strtoupper($rawTag) && strlen($rawTag) > 2) ? strtoupper($firstName) : $firstName,
                'email', 'contact.email'                                                         => $email,
                'email_url'                                                                      => rawurlencode($email),
                'mobile', 'phone', 'contact.phone', 'contact.mobile'                             => $mobile,
                default                                                                          => $matches[0],
            };
        }, $text);
    }
}
