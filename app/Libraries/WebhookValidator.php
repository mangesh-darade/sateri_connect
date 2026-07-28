<?php

namespace App\Libraries;

/**
 * Validates WhatsApp webhook verification challenges and signatures.
 * Protocol is Meta-shaped (hub.* + X-Hub-Signature-256) for both Cheerio and Meta.
 *
 * Accepts credentials from either provider so both dashboards can deliver
 * while Settings → active provider controls Chat / default keyword sends.
 */
class WebhookValidator
{
    protected SettingsService $settings;

    public function __construct(?SettingsService $settings = null)
    {
        $this->settings = $settings ?? new SettingsService();
    }

    /**
     * Handle webhook subscription challenge (hub.mode / hub.verify_token / hub.challenge).
     *
     * @return string|false Challenge string on success, false on failure
     */
    public function verifyChallenge(?string $mode, ?string $token, ?string $challenge): string|false
    {
        if ($mode !== 'subscribe' || $token === null || $token === '' || $challenge === null || $challenge === '') {
            return false;
        }

        foreach ($this->webhookCredentialSets() as $set) {
            $verifyToken = (string) ($set['verify_token'] ?? '');
            if ($verifyToken !== '' && hash_equals($verifyToken, $token)) {
                return $challenge;
            }
        }

        log_message('warning', 'Webhook challenge failed: verify token mismatch (tried cheerio+meta).');

        return false;
    }

    /**
     * Validate X-Hub-Signature-256 HMAC SHA256 signature against Cheerio or Meta secret.
     *
     * @param string $rawBody         Raw request body
     * @param string $signatureHeader Value of X-Hub-Signature-256 (sha256=...)
     */
    public function validateSignature(string $rawBody, ?string $signatureHeader): bool
    {
        return $this->matchSignatureProvider($rawBody, $signatureHeader) !== null;
    }

    /**
     * Which provider secret matched the signature (null if none).
     */
    public function matchSignatureProvider(string $rawBody, ?string $signatureHeader): ?string
    {
        if ($signatureHeader === null || $signatureHeader === '') {
            log_message('warning', 'Webhook signature missing.');

            return null;
        }

        foreach ($this->webhookCredentialSets() as $set) {
            $appSecret = (string) ($set['webhook_secret'] ?? '');
            if ($appSecret === '') {
                continue;
            }

            $expected = 'sha256=' . hash_hmac('sha256', $rawBody, $appSecret);
            if (hash_equals($expected, $signatureHeader)) {
                return (string) $set['provider'];
            }
        }

        log_message('warning', 'Webhook signature mismatch (tried cheerio+meta secrets).');

        return null;
    }

    /**
     * Active provider first, then the other — so Settings preference wins when both match.
     *
     * @return list<array{provider: string, verify_token: string, webhook_secret: string}>
     */
    protected function webhookCredentialSets(): array
    {
        $active   = $this->settings->getWhatsAppProvider();
        $cheerio  = $this->settings->getCheerioConfig();
        $meta     = $this->settings->getMetaConfig();
        $sets     = [
            SettingsService::PROVIDER_CHEERIO => [
                'provider'       => SettingsService::PROVIDER_CHEERIO,
                'verify_token'   => (string) ($cheerio['verify_token'] ?? ''),
                'webhook_secret' => (string) ($cheerio['webhook_secret'] ?? ''),
            ],
            SettingsService::PROVIDER_META => [
                'provider'       => SettingsService::PROVIDER_META,
                'verify_token'   => (string) ($meta['verify_token'] ?? ''),
                'webhook_secret' => (string) ($meta['app_secret'] ?? ''),
            ],
        ];

        $ordered = [$sets[$active]];
        $other   = $active === SettingsService::PROVIDER_META
            ? SettingsService::PROVIDER_CHEERIO
            : SettingsService::PROVIDER_META;
        $ordered[] = $sets[$other];

        return $ordered;
    }
}
