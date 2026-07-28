<?php

declare(strict_types=1);

namespace App\Libraries;

use App\Libraries\Email\CheerioEmailDriver;
use App\Libraries\Email\EmailDriverInterface;
use App\Libraries\Email\SendGridEmailDriver;
use App\Libraries\Email\SmtpEmailDriver;
use Config\EmailProviders;

/**
 * Provider-aware email facade.
 *
 * Callers use service('emailProvider')->send(...) / sendHtml(...).
 * Active transport is chosen from settings `email_provider`:
 *   - smtp     → SmtpEmailDriver (CodeIgniter SMTP)
 *   - sendgrid → SendGridEmailDriver (API v3)
 *   - cheerio  → CheerioEmailDriver (Direct API announcements)
 */
class EmailProvider
{
    protected SettingsService $settings;
    protected EmailProviders $config;
    protected EmailDriverInterface $driver;

    public function __construct(?SettingsService $settings = null, ?EmailProviders $config = null)
    {
        $this->settings = $settings ?? new SettingsService();
        $this->config   = $config ?? config(EmailProviders::class);
        $this->driver   = $this->resolveDriver();
    }

    public function getProvider(): string
    {
        $this->ensureDriver();

        return $this->settings->getEmailProvider();
    }

    public function getDriver(): EmailDriverInterface
    {
        $this->ensureDriver();

        return $this->driver;
    }

    public function loadCredentials(): void
    {
        $this->driver = $this->resolveDriver();
        $this->driver->loadCredentials();
    }

    /**
     * @param string|list<string>  $to
     * @param array<string, mixed> $options
     *
     * @return array{ok: bool, message: string, provider: string, data?: mixed}
     */
    public function send(string|array $to, string $subject, string $body, array $options = []): array
    {
        $this->ensureDriver();

        return $this->driver->send($to, $subject, $body, $options);
    }

    /**
     * @param string|list<string>  $to
     * @param array<string, mixed> $options
     *
     * @return array{ok: bool, message: string, provider: string, data?: mixed}
     */
    public function sendHtml(string|array $to, string $subject, string $html, array $options = []): array
    {
        $this->ensureDriver();

        return $this->driver->sendHtml($to, $subject, $html, $options);
    }

    /**
     * @return array{ok: bool, message: string, provider: string, data?: mixed}
     */
    public function testConnection(string $to): array
    {
        $this->ensureDriver();

        return $this->driver->testConnection($to);
    }

    /**
     * @param array<string, mixed> $campaign
     *
     * @return array{ok: bool, message: string, provider: string, data?: mixed}
     */
    public function sendCampaign(array $campaign): array
    {
        $this->ensureDriver();

        return $this->driver->sendCampaign($campaign);
    }

    protected function ensureDriver(): void
    {
        $want = $this->settings->getEmailProvider();
        $have = $this->driver->getName();

        if ($want !== $have) {
            $this->driver = $this->resolveDriver();
            $this->driver->loadCredentials();
        }
    }

    protected function resolveDriver(): EmailDriverInterface
    {
        return match ($this->settings->getEmailProvider()) {
            SettingsService::EMAIL_PROVIDER_SENDGRID => new SendGridEmailDriver($this->settings, $this->config),
            SettingsService::EMAIL_PROVIDER_CHEERIO  => new CheerioEmailDriver($this->settings, $this->config),
            default                                  => new SmtpEmailDriver($this->settings),
        };
    }
}
