<?php

declare(strict_types=1);

namespace App\Libraries;

use App\Models\EmailSenderModel;
use InvalidArgumentException;

/**
 * Choosable From addresses for email campaigns. No sender chosen = the provider's default
 * marketing From (Settings / Email Manager → Senders), exactly as before.
 */
class EmailSenderService
{
    protected SettingsService $settings;

    public function __construct(?SettingsService $settings = null)
    {
        $this->settings = $settings ?? new SettingsService();
    }

    /**
     * Cheerio sends from the sender configured in the Cheerio dashboard, so no picker there.
     */
    public function supportsSenderChoice(): bool
    {
        return $this->settings->getEmailProvider() !== SettingsService::EMAIL_PROVIDER_CHEERIO;
    }

    /**
     * @return array{enabled: bool, default: array{email: string, name: string}, senders: list<array{id: int, email: string, name: string, purpose: string}>}
     */
    public function campaignSenderOptions(): array
    {
        if (! $this->supportsSenderChoice()) {
            return ['enabled' => false, 'default' => ['email' => '', 'name' => ''], 'senders' => []];
        }

        $default = $this->defaultFrom();
        $senders = [];
        foreach ($this->selectableSenders() as $row) {
            $email = strtolower(trim((string) $row['email']));
            if ($email === strtolower($default['email'])) {
                continue;
            }
            $senders[] = [
                'id'      => (int) $row['id'],
                'email'   => $email,
                'name'    => trim((string) ($row['name'] ?? '')),
                'purpose' => EmailSenderModel::normalizePurpose($row['purpose'] ?? null),
            ];
        }

        return ['enabled' => true, 'default' => $default, 'senders' => $senders];
    }

    /**
     * Validate a chosen sender id (null / 0 = default sender).
     *
     * @throws InvalidArgumentException when the sender cannot be used
     */
    public function assertSelectable(?int $senderId): ?int
    {
        if ($senderId === null || $senderId <= 0) {
            return null;
        }
        if (! $this->supportsSenderChoice()) {
            throw new InvalidArgumentException('The active email provider sends from its own configured sender — choose Default sender.');
        }
        foreach ($this->selectableSenders() as $row) {
            if ((int) $row['id'] === $senderId) {
                return $senderId;
            }
        }

        throw new InvalidArgumentException('Selected sender is not verified for the active email provider. Pick another sender or use Default.');
    }

    /**
     * Driver options for a campaign's sender. Empty = driver uses its default From.
     *
     * @return array{from_email?: string, from_name?: string}
     */
    public function fromOptions(?int $senderId): array
    {
        if ($senderId === null || $senderId <= 0 || ! $this->supportsSenderChoice()) {
            return [];
        }
        foreach ($this->selectableSenders() as $row) {
            if ((int) $row['id'] === $senderId) {
                $out  = ['from_email' => trim((string) $row['email'])];
                $name = trim((string) ($row['name'] ?? ''));
                if ($name !== '') {
                    $out['from_name'] = $name;
                }

                return $out;
            }
        }

        log_message('warning', 'Email campaign sender #{id} is no longer usable; sending from the default sender.', ['id' => $senderId]);

        return [];
    }

    /**
     * The From address a marketing send uses when no sender is chosen.
     *
     * @return array{email: string, name: string}
     */
    public function defaultFrom(): array
    {
        $s = $this->settings;

        $from = match ($s->getEmailProvider()) {
            SettingsService::EMAIL_PROVIDER_SES => trim((string) $s->get('ses_marketing_from_email', '')) !== ''
                ? ['email' => trim((string) $s->get('ses_marketing_from_email', '')), 'name' => trim((string) $s->get('ses_marketing_from_name', ''))]
                : ['email' => trim((string) $s->get('ses_from_email', '')), 'name' => trim((string) $s->get('ses_from_name', ''))],
            SettingsService::EMAIL_PROVIDER_SENDGRID => ['email' => trim((string) $s->get('sendgrid_from_email', '')), 'name' => trim((string) $s->get('sendgrid_from_name', ''))],
            default => ['email' => trim((string) $s->get('smtp_from_email', '')), 'name' => trim((string) $s->get('smtp_from_name', ''))],
        };

        // Same fallback as AbstractEmailDriver::resolveFrom().
        if ($from['email'] === '') {
            $from['email'] = trim((string) $s->get('app_email', ''));
        }
        if ($from['name'] === '') {
            $from['name'] = trim((string) $s->get('app_name', ''));
        }

        return $from;
    }

    /**
     * Verified sender rows usable with the active provider (SES only accepts SES-verified identities).
     *
     * @return list<array<string, mixed>>
     */
    protected function selectableSenders(): array
    {
        if (! db_connect()->tableExists('email_senders')) {
            return [];
        }

        $builder = model(EmailSenderModel::class)
            ->where('type', 'sender')
            ->where('status', 'verified')
            ->where('email IS NOT NULL', null, false)
            ->where('email !=', '');
        if ($this->settings->getEmailProvider() === SettingsService::EMAIL_PROVIDER_SES) {
            $builder->where('provider', SesIdentityService::PROVIDER);
        }

        return $builder->orderBy('is_default', 'DESC')->orderBy('name', 'ASC')->findAll(200);
    }
}
