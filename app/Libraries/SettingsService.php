<?php

namespace App\Libraries;

use App\Models\SettingModel;
use Config\WhatsApp as WhatsAppConfig;
use RuntimeException;

/**
 * Application settings facade with WhatsApp provider (Cheerio | Meta) helpers.
 */
class SettingsService
{
    public const PROVIDER_CHEERIO = 'cheerio';
    public const PROVIDER_META    = 'meta';

    public const EMAIL_PROVIDER_SMTP     = 'smtp';
    public const EMAIL_PROVIDER_SENDGRID = 'sendgrid';
    public const EMAIL_PROVIDER_CHEERIO  = 'cheerio';

    protected SettingModel $settings;
    protected EncryptionService $encryption;

    /**
     * Per-request memo of decoded setting values (shared service = one page load).
     * Avoids N identical SELECTs from layout setting() / provider helpers.
     *
     * @var array<string, mixed>
     */
    protected array $valueCache = [];

    /** @var list<string> */
    protected array $encryptedKeys = [
        'cheerio_api_key',
        'cheerio_webhook_secret',
        'meta_access_token',
        'meta_webhook_secret',
        'meta_page_access_token',
        'meta_two_step_pin',
        'smtp_password',
        'sendgrid_api_key',
        'elintom_api_private_key',
    ];

    public function __construct(?SettingModel $settings = null, ?EncryptionService $encryption = null)
    {
        $this->settings   = $settings ?? model(SettingModel::class);
        $this->encryption = $encryption ?? new EncryptionService();
    }

    /**
     * Get a setting value. Decrypts automatically when the setting is flagged encrypted.
     * Results are memoized for the current request (shared SettingsService instance).
     */
    public function get(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, $this->valueCache)) {
            $cached = $this->valueCache[$key];

            return $cached === $this->cacheMissSentinel() ? $default : $cached;
        }

        $row = $this->settings->where('key', $key)->first();

        if ($row === null) {
            if (method_exists($this->settings, 'getValue')) {
                $value = $this->settings->getValue($key, $default);
                if ($value !== $default && in_array($key, $this->encryptedKeys, true) && is_string($value)) {
                    $value = $this->encryption->decryptIfNeeded($value);
                }
                $this->valueCache[$key] = $value === $default ? $this->cacheMissSentinel() : $value;

                return $value;
            }

            $this->valueCache[$key] = $this->cacheMissSentinel();

            return $default;
        }

        $value       = $row['value'] ?? $default;
        $isEncrypted = (bool) ($row['is_encrypted'] ?? false)
            || in_array($key, $this->encryptedKeys, true);

        if ($isEncrypted && is_string($value) && $value !== '') {
            try {
                $value = $this->encryption->decryptIfNeeded($value);
            } catch (RuntimeException $e) {
                log_message('error', 'SettingsService::get decrypt failed for {key}: {msg}', [
                    'key' => $key,
                    'msg' => $e->getMessage(),
                ]);
                $this->valueCache[$key] = $this->cacheMissSentinel();

                return $default;
            }
        }

        $this->valueCache[$key] = $value;

        return $value;
    }

    /**
     * Sentinel for "key missing" so we still short-circuit repeated misses.
     */
    protected function cacheMissSentinel(): object
    {
        static $sentinel;

        return $sentinel ??= new \stdClass();
    }

    /**
     * Persist a setting. Encrypts sensitive keys automatically.
     */
    public function set(string $key, mixed $value, string $group = 'general', ?bool $encrypt = null): bool
    {
        $shouldEncrypt = $encrypt ?? in_array($key, $this->encryptedKeys, true);
        $storeValue    = is_scalar($value) || $value === null
            ? (string) ($value ?? '')
            : json_encode($value);

        if ($shouldEncrypt && $storeValue !== '') {
            $storeValue = $this->encryption->encryptIfNeeded($storeValue);
        }

        // Drop cache for this key; next get() reloads (or we seed plaintext $value).
        unset($this->valueCache[$key]);

        if (method_exists($this->settings, 'setValue')) {
            $ok = (bool) $this->settings->setValue($key, $storeValue, $group, $shouldEncrypt);
            if ($ok) {
                $this->valueCache[$key] = $value;
            }

            return $ok;
        }

        $existing = $this->settings->where('key', $key)->first();
        $data     = [
            'key'          => $key,
            'value'        => $storeValue,
            'group'        => $group,
            'is_encrypted' => $shouldEncrypt ? 1 : 0,
        ];

        if ($existing !== null) {
            $ok = (bool) $this->settings->update($existing['id'], $data);
        } else {
            $ok = (bool) $this->settings->insert($data);
        }

        if ($ok) {
            $this->valueCache[$key] = $value;
        }

        return $ok;
    }

    public function getWhatsAppProvider(): string
    {
        $provider = strtolower(trim((string) $this->get('whatsapp_provider', self::PROVIDER_CHEERIO)));

        return in_array($provider, [self::PROVIDER_CHEERIO, self::PROVIDER_META], true)
            ? $provider
            : self::PROVIDER_CHEERIO;
    }

    public function setWhatsAppProvider(string $provider): void
    {
        $provider = strtolower(trim($provider));
        if (! in_array($provider, [self::PROVIDER_CHEERIO, self::PROVIDER_META], true)) {
            throw new RuntimeException('Invalid WhatsApp provider. Use cheerio or meta.');
        }
        $this->set('whatsapp_provider', $provider, 'whatsapp', false);
    }

    public function isCheerioProvider(): bool
    {
        return $this->getWhatsAppProvider() === self::PROVIDER_CHEERIO;
    }

    public function isMetaProvider(): bool
    {
        return $this->getWhatsAppProvider() === self::PROVIDER_META;
    }

    /**
     * Active webhook verify token + signature secret for the selected provider.
     *
     * @return array{provider: string, verify_token: string, webhook_secret: string}
     */
    public function getActiveWebhookConfig(): array
    {
        if ($this->isMetaProvider()) {
            $meta = $this->getMetaConfig();

            return [
                'provider'       => self::PROVIDER_META,
                'verify_token'   => (string) ($meta['verify_token'] ?? ''),
                'webhook_secret' => (string) ($meta['app_secret'] ?? ''),
            ];
        }

        $cheerio = $this->getCheerioConfig();

        return [
            'provider'       => self::PROVIDER_CHEERIO,
            'verify_token'   => (string) ($cheerio['verify_token'] ?? ''),
            'webhook_secret' => (string) ($cheerio['webhook_secret'] ?? ''),
        ];
    }

    /**
     * Save verify token on the active provider bucket.
     */
    public function setActiveVerifyToken(string $token): void
    {
        if ($this->isMetaProvider()) {
            $this->setMetaConfig(['verify_token' => $token]);

            return;
        }

        $this->setCheerioConfig(['verify_token' => $token]);
    }

    /**
     * @param array<string, mixed> $config
     */
    public function setCheerioConfig(array $config): void
    {
        $map = [
            'api_key'         => ['cheerio_api_key', 'cheerio', true],
            'verify_token'    => ['cheerio_webhook_verify_token', 'cheerio', false],
            'webhook_secret'  => ['cheerio_webhook_secret', 'cheerio', true],
            'phone_number_id' => ['cheerio_phone_number_id', 'cheerio', false],
            'display_phone'   => ['cheerio_display_phone', 'cheerio', false],
        ];

        foreach ($map as $inputKey => [$settingKey, $group, $encrypt]) {
            if (! array_key_exists($inputKey, $config)) {
                continue;
            }

            $value = $config[$inputKey];
            if ($inputKey === 'display_phone') {
                $value = preg_replace('/\D+/', '', (string) $value) ?? '';
            }

            $this->set($settingKey, $value, $group, $encrypt);
        }
    }

    /**
     * Detect which provider owns an inbound webhook (phone_number_id and/or display phone).
     * Falls back to Settings → active provider when unknown.
     */
    public function resolveProviderFromPhoneNumberId(?string $phoneNumberId, ?string $displayPhone = null): string
    {
        $pnid = trim((string) $phoneNumberId);
        $display = preg_replace('/\D+/', '', (string) $displayPhone) ?? '';

        $metaPnid = trim((string) $this->get('meta_phone_number_id', ''));
        if ($pnid !== '' && $metaPnid !== '' && hash_equals($metaPnid, $pnid)) {
            return self::PROVIDER_META;
        }

        $cheerioPnid = trim((string) $this->get('cheerio_phone_number_id', ''));
        if ($pnid !== '' && $cheerioPnid !== '' && hash_equals($cheerioPnid, $pnid)) {
            return self::PROVIDER_CHEERIO;
        }

        $cheerioDisplay = preg_replace('/\D+/', '', (string) $this->get('cheerio_display_phone', '')) ?? '';
        if ($display !== '' && $cheerioDisplay !== '' && hash_equals($cheerioDisplay, $display)) {
            if ($pnid !== '') {
                $this->set('cheerio_phone_number_id', $pnid, 'cheerio', false);
            }

            return self::PROVIDER_CHEERIO;
        }

        // Unknown pnid that is not Meta's → treat as Cheerio and remember it
        if ($pnid !== '' && $metaPnid !== '' && ! hash_equals($metaPnid, $pnid)) {
            $this->set('cheerio_phone_number_id', $pnid, 'cheerio', false);
            if ($display !== '') {
                $this->set('cheerio_display_phone', $display, 'cheerio', false);
            }

            return self::PROVIDER_CHEERIO;
        }

        return $this->getWhatsAppProvider();
    }

    /**
     * @return array{
     *     api_key: string,
     *     verify_token: string,
     *     webhook_secret: string,
     *     base_url: string,
     *     phone_number_id: string,
     *     display_phone: string
     * }
     */
    public function getCheerioConfig(): array
    {
        $waConfig = config(WhatsAppConfig::class);

        $apiKey = (string) $this->get('cheerio_api_key', '');
        // Legacy: some installs stored Cheerio key under meta_access_token before dual-provider.
        if ($apiKey === '' && $this->isCheerioProvider()) {
            $apiKey = (string) $this->get('meta_access_token', '');
        }

        $verify = (string) $this->get('cheerio_webhook_verify_token', '');
        if ($verify === '' && $this->isCheerioProvider()) {
            $verify = (string) $this->get('meta_webhook_verify_token', '');
        }

        $secret = (string) $this->get('cheerio_webhook_secret', '');
        if ($secret === '' && $this->isCheerioProvider()) {
            $secret = (string) $this->get('meta_webhook_secret', '');
        }

        return [
            'api_key'         => $apiKey,
            'verify_token'    => $verify,
            'webhook_secret'  => $secret,
            'base_url'        => $waConfig->baseUrl,
            'phone_number_id' => (string) $this->get('cheerio_phone_number_id', ''),
            'display_phone'   => (string) $this->get('cheerio_display_phone', ''),
        ];
    }

    public function getMetaConfig(): array
    {
        $waConfig = config(WhatsAppConfig::class);

        return [
            'access_token'            => (string) $this->get('meta_access_token', ''),
            'phone_number_id'         => (string) $this->get('meta_phone_number_id', ''),
            'waba_id'                 => (string) $this->get('meta_waba_id', ''),
            'verify_token'            => (string) $this->get('meta_webhook_verify_token', ''),
            'app_secret'              => (string) $this->get('meta_webhook_secret', ''),
            'app_id'                  => (string) $this->get('meta_app_id', ''),
            'embedded_config_id'      => (string) $this->get('meta_embedded_config_id', ''),
            'business_id'             => (string) $this->get('meta_business_id', ''),
            'two_step_pin'            => (string) $this->get('meta_two_step_pin', ''),
            'api_version'             => (string) $this->get('meta_api_version', $waConfig->graphApiVersion ?: 'v21.0'),
            'graph_base_url'          => (string) ($waConfig->graphBaseUrl ?: 'https://graph.facebook.com'),
            'page_id'                 => (string) $this->get('meta_page_id', ''),
            'page_access_token'       => (string) $this->get('meta_page_access_token', ''),
            'instagram_account_id'    => (string) $this->get('meta_instagram_account_id', ''),
            'inbox_instagram_enabled' => (string) $this->get('inbox_instagram_enabled', '0') === '1',
            'inbox_messenger_enabled' => (string) $this->get('inbox_messenger_enabled', '0') === '1',
        ];
    }

    /**
     * @param array<string, mixed> $config
     */
    public function setMetaConfig(array $config): void
    {
        $map = [
            'access_token'            => ['meta_access_token', 'meta', true],
            'phone_number_id'         => ['meta_phone_number_id', 'meta', false],
            'waba_id'                 => ['meta_waba_id', 'meta', false],
            'verify_token'            => ['meta_webhook_verify_token', 'meta', false],
            'app_secret'              => ['meta_webhook_secret', 'meta', true],
            'webhook_secret'          => ['meta_webhook_secret', 'meta', true],
            'app_id'                  => ['meta_app_id', 'meta', false],
            'embedded_config_id'      => ['meta_embedded_config_id', 'meta', false],
            'business_id'             => ['meta_business_id', 'meta', false],
            'two_step_pin'            => ['meta_two_step_pin', 'meta', true],
            'api_version'             => ['meta_api_version', 'meta', false],
            'page_id'                 => ['meta_page_id', 'meta', false],
            'page_access_token'       => ['meta_page_access_token', 'meta', true],
            'instagram_account_id'    => ['meta_instagram_account_id', 'meta', false],
            'inbox_instagram_enabled' => ['inbox_instagram_enabled', 'meta', false],
            'inbox_messenger_enabled' => ['inbox_messenger_enabled', 'meta', false],
        ];

        foreach ($map as $inputKey => [$settingKey, $group, $encrypt]) {
            if (! array_key_exists($inputKey, $config)) {
                continue;
            }

            $value = $config[$inputKey];
            if (is_bool($value)) {
                $value = $value ? '1' : '0';
            }

            $this->set($settingKey, $value, $group, $encrypt);
        }
    }

    public function isInstagramInboxEnabled(): bool
    {
        $meta = $this->getMetaConfig();

        return ! empty($meta['inbox_instagram_enabled'])
            && trim((string) ($meta['page_access_token'] ?? '')) !== ''
            && trim((string) ($meta['page_id'] ?? '')) !== '';
    }

    public function isMessengerInboxEnabled(): bool
    {
        $meta = $this->getMetaConfig();

        return ! empty($meta['inbox_messenger_enabled'])
            && trim((string) ($meta['page_access_token'] ?? '')) !== ''
            && trim((string) ($meta['page_id'] ?? '')) !== '';
    }

    public function isInstalled(): bool
    {
        return (string) $this->get('app_installed', '0') === '1';
    }

    public function getEmailProvider(): string
    {
        $provider = strtolower(trim((string) $this->get('email_provider', self::EMAIL_PROVIDER_SMTP)));

        return in_array($provider, [
            self::EMAIL_PROVIDER_SMTP,
            self::EMAIL_PROVIDER_SENDGRID,
            self::EMAIL_PROVIDER_CHEERIO,
        ], true) ? $provider : self::EMAIL_PROVIDER_SMTP;
    }

    public function setEmailProvider(string $provider): void
    {
        $provider = strtolower(trim($provider));
        if (! in_array($provider, [
            self::EMAIL_PROVIDER_SMTP,
            self::EMAIL_PROVIDER_SENDGRID,
            self::EMAIL_PROVIDER_CHEERIO,
        ], true)) {
            throw new RuntimeException('Invalid email provider. Use smtp, sendgrid, or cheerio.');
        }

        $this->set('email_provider', $provider, 'email', false);
    }

    public function isSmtpEmailProvider(): bool
    {
        return $this->getEmailProvider() === self::EMAIL_PROVIDER_SMTP;
    }

    public function isSendGridEmailProvider(): bool
    {
        return $this->getEmailProvider() === self::EMAIL_PROVIDER_SENDGRID;
    }

    public function isCheerioEmailProvider(): bool
    {
        return $this->getEmailProvider() === self::EMAIL_PROVIDER_CHEERIO;
    }

    /**
     * @return array{
     *     host: string,
     *     port: string,
     *     user: string,
     *     password: string,
     *     encryption: string,
     *     from_email: string,
     *     from_name: string
     * }
     */
    public function getSmtpConfig(): array
    {
        return [
            'host'       => (string) $this->get('smtp_host', ''),
            'port'       => (string) $this->get('smtp_port', '587'),
            'user'       => (string) $this->get('smtp_user', ''),
            'password'   => (string) ($this->get('smtp_password') ?: $this->get('smtp_pass', '')),
            'encryption' => (string) $this->get('smtp_encryption', 'tls'),
            'from_email' => (string) $this->get('smtp_from_email', ''),
            'from_name'  => (string) $this->get('smtp_from_name', ''),
        ];
    }

    /**
     * @param array<string, mixed> $config
     */
    public function setSmtpConfig(array $config): void
    {
        $map = [
            'host'       => ['smtp_host', 'smtp', false],
            'port'       => ['smtp_port', 'smtp', false],
            'user'       => ['smtp_user', 'smtp', false],
            'password'   => ['smtp_password', 'smtp', true],
            'encryption' => ['smtp_encryption', 'smtp', false],
            'from_email' => ['smtp_from_email', 'smtp', false],
            'from_name'  => ['smtp_from_name', 'smtp', false],
        ];

        foreach ($map as $inputKey => [$settingKey, $group, $encrypt]) {
            if (! array_key_exists($inputKey, $config)) {
                continue;
            }

            $this->set($settingKey, $config[$inputKey], $group, $encrypt);
            if ($inputKey === 'password' && $config[$inputKey] !== '') {
                $this->set('smtp_pass', $config[$inputKey], 'smtp', true);
            }
        }
    }

    /**
     * @return array{
     *     api_key: string,
     *     from_email: string,
     *     from_name: string,
     *     sender_id: string,
     *     suppression_group_id: string,
     *     custom_unsubscribe_url: string,
     *     ip_pool: string
     * }
     */
    public function getSendGridConfig(): array
    {
        return [
            'api_key'                => (string) $this->get('sendgrid_api_key', ''),
            'from_email'             => (string) $this->get('sendgrid_from_email', ''),
            'from_name'              => (string) $this->get('sendgrid_from_name', ''),
            'sender_id'              => (string) $this->get('sendgrid_sender_id', ''),
            'suppression_group_id'   => (string) $this->get('sendgrid_suppression_group_id', ''),
            'custom_unsubscribe_url' => (string) $this->get('sendgrid_custom_unsubscribe_url', ''),
            'ip_pool'                => (string) $this->get('sendgrid_ip_pool', ''),
        ];
    }

    /**
     * @param array<string, mixed> $config
     */
    public function setSendGridConfig(array $config): void
    {
        $map = [
            'api_key'                => ['sendgrid_api_key', 'email', true],
            'from_email'             => ['sendgrid_from_email', 'email', false],
            'from_name'              => ['sendgrid_from_name', 'email', false],
            'sender_id'              => ['sendgrid_sender_id', 'email', false],
            'suppression_group_id'   => ['sendgrid_suppression_group_id', 'email', false],
            'custom_unsubscribe_url' => ['sendgrid_custom_unsubscribe_url', 'email', false],
            'ip_pool'                => ['sendgrid_ip_pool', 'email', false],
        ];

        foreach ($map as $inputKey => [$settingKey, $group, $encrypt]) {
            if (! array_key_exists($inputKey, $config)) {
                continue;
            }

            $this->set($settingKey, $config[$inputKey], $group, $encrypt);
        }
    }

    /**
     * Cheerio email uses the same Direct API key as WhatsApp.
     *
     * @return array{api_key: string, default_campaign: string}
     */
    public function getCheerioEmailConfig(): array
    {
        return [
            'api_key'          => (string) ($this->getCheerioConfig()['api_key'] ?? ''),
            'default_campaign' => (string) $this->get('cheerio_email_campaign_name', 'app-direct'),
        ];
    }

    /**
     * @param array<string, mixed> $config
     */
    public function setCheerioEmailConfig(array $config): void
    {
        if (array_key_exists('default_campaign', $config)) {
            $this->set('cheerio_email_campaign_name', $config['default_campaign'], 'email', false);
        }
    }

    /**
     * Resolve public webhook base + callback for local (tunnel) vs live (HTTPS domain).
     *
     * Priority: saved override → auto (live site_url / current HTTPS request / tunnel host).
     *
     * @return array{
     *   mode: string,
     *   source: string,
     *   public_base: string,
     *   public_callback: string,
     *   auto_base: string,
     *   auto_callback: string,
     *   saved_base: string,
     *   local_callback: string,
     *   hint: string,
     *   auto_persisted: bool
     * }
     */
    public function resolveWebhookPublicConfig(?string $requestOrigin = null): array
    {
        $localCallback = site_url('webhooks');
        $savedBase     = rtrim((string) $this->get('webhook_public_base', ''), '/');
        $autoBase      = $this->detectAutoWebhookPublicBase($requestOrigin);
        $mode          = $this->resolveWebhookMode($autoBase !== '' ? $autoBase : $savedBase);
        $effectiveBase = $savedBase !== '' ? $savedBase : $autoBase;
        $source        = $savedBase !== '' ? 'saved' : ($autoBase !== '' ? 'auto' : 'none');

        $path = (string) (parse_url($localCallback, PHP_URL_PATH) ?: '/webhooks');
        $build = static function (string $base) use ($localCallback, $path): string {
            $base = rtrim($base, '/');
            if ($base === '') {
                return $localCallback;
            }

            return $base . $path;
        };

        $hint = match (true) {
            $mode === 'live' && $effectiveBase !== '' => 'Live domain detected — callback auto from HTTPS host. Override only if Meta needs a different URL.',
            $mode === 'live' => 'Live: open Settings on your public HTTPS domain so callback can auto-detect.',
            $autoBase !== '' && SubdomainDatabase::isLocalTunnelHost((string) (parse_url($autoBase, PHP_URL_HOST) ?: '')) => 'Local tunnel detected from this page — callback auto-filled. Save / Auto to pin Meta/Cheerio.',
            default => 'Local: start Cloudflare/ngrok, open Settings via that HTTPS URL, click Auto — or paste tunnel URL.',
        };

        return [
            'mode'            => $mode,
            'source'          => $source,
            'public_base'     => $effectiveBase,
            'public_callback' => $build($effectiveBase),
            'auto_base'       => $autoBase,
            'auto_callback'   => $build($autoBase),
            'saved_base'      => $savedBase,
            'local_callback'  => $localCallback,
            'hint'            => $hint,
            'auto_persisted'  => false,
        ];
    }

    /**
     * On live HTTPS domain: if webhook base is empty, detect + persist automatically.
     *
     * @return array<string, mixed>
     */
    public function ensureLiveWebhookPublicBasePersisted(?string $requestOrigin = null): array
    {
        $resolved = $this->resolveWebhookPublicConfig($requestOrigin);
        $autoBase = rtrim((string) ($resolved['auto_base'] ?? ''), '/');
        $saved    = rtrim((string) ($resolved['saved_base'] ?? ''), '/');
        $mode     = (string) ($resolved['mode'] ?? 'local');

        if ($mode !== 'live' || $autoBase === '' || ! str_starts_with($autoBase, 'https://')) {
            return $resolved;
        }

        // Persist when empty, or when saved value is a stale local tunnel but we're now on live.
        $savedHost = strtolower((string) (parse_url($saved, PHP_URL_HOST) ?: ''));
        $shouldPersist = $saved === ''
            || ($savedHost !== '' && SubdomainDatabase::isLocalTunnelHost($savedHost));

        if (! $shouldPersist) {
            return $resolved;
        }

        $this->set('webhook_public_base', $autoBase, 'whatsapp');
        $resolved = $this->resolveWebhookPublicConfig($requestOrigin);
        $resolved['auto_persisted'] = true;
        $resolved['source'] = 'auto';
        $resolved['hint'] = 'Live callback auto-detected and saved from your HTTPS domain.';

        return $resolved;
    }

    /**
     * live = production env OR public non-tunnel HTTPS host.
     */
    public function resolveWebhookMode(string $httpsOriginOrEmpty = ''): string
    {
        if (defined('ENVIRONMENT') && ENVIRONMENT === 'production') {
            return 'live';
        }

        $origin = $httpsOriginOrEmpty !== '' ? $httpsOriginOrEmpty : $this->detectAutoWebhookPublicBase();
        $host   = strtolower((string) (parse_url($origin, PHP_URL_HOST) ?: ''));
        if ($host === '' || $this->isLocalDevHost($host) || SubdomainDatabase::isLocalTunnelHost($host)) {
            return 'local';
        }

        return 'live';
    }

    /**
     * Auto HTTPS origin for webhooks (no manual paste when possible).
     */
    public function detectAutoWebhookPublicBase(?string $requestOrigin = null): string
    {
        $candidates = [];

        if (is_string($requestOrigin) && $requestOrigin !== '') {
            $candidates[] = $requestOrigin;
        }

        // Current browser request (live domain or tunnel) — prefer real HTTPS / forwarded proto.
        try {
            $req = service('request');
            if ($req !== null) {
                $host = (string) ($req->getServer('HTTP_HOST') ?: $req->getServer('SERVER_NAME') ?: '');
                if ($host !== '') {
                    $https = $req->isSecure()
                        || strtolower((string) $req->getServer('HTTP_X_FORWARDED_PROTO')) === 'https'
                        || (string) $req->getServer('SERVER_PORT') === '443';
                    // Live servers often terminate TLS at proxy; treat public host as https.
                    if (! $https && ! $this->isLocalDevHost(strtolower($host)) && ! SubdomainDatabase::isLocalTunnelHost(strtolower($host))) {
                        $https = true;
                    }
                    $candidates[] = ($https ? 'https' : 'http') . '://' . $host;
                }
            }
        } catch (\Throwable $e) {
            // CLI / early boot
        }

        if (! empty($_SERVER['HTTP_HOST'])) {
            $https = (! empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                || (isset($_SERVER['SERVER_PORT']) && (string) $_SERVER['SERVER_PORT'] === '443')
                || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
            $host = (string) $_SERVER['HTTP_HOST'];
            if (! $https && ! $this->isLocalDevHost(strtolower($host)) && ! SubdomainDatabase::isLocalTunnelHost(strtolower($host))) {
                $https = true;
            }
            $candidates[] = ($https ? 'https' : 'http') . '://' . $host;
        }

        $appUrl = trim((string) $this->get('app_url', ''));
        if ($appUrl !== '') {
            $candidates[] = $appUrl;
        }
        $candidates[] = (string) config('App')->baseURL;
        $candidates[] = site_url('/');

        $livePick   = '';
        $tunnelPick = '';

        foreach ($candidates as $raw) {
            $origin = $this->normalizeHttpsOrigin((string) $raw);
            if ($origin === '') {
                continue;
            }
            $host = strtolower((string) (parse_url($origin, PHP_URL_HOST) ?: ''));
            if ($host === '' || $this->isLocalDevHost($host)) {
                continue;
            }
            if (SubdomainDatabase::isLocalTunnelHost($host)) {
                if ($tunnelPick === '') {
                    $tunnelPick = $origin;
                }
                continue;
            }
            // First real public domain wins (live).
            if ($livePick === '') {
                $livePick = $origin;
            }
        }

        if ($livePick !== '') {
            return $livePick;
        }

        // Local lab: tunnel is OK.
        return $tunnelPick;
    }

    protected function isLocalDevHost(string $host): bool
    {
        $host = strtolower(trim($host));

        return $host === ''
            || $host === 'localhost'
            || $host === '127.0.0.1'
            || str_starts_with($host, '192.168.')
            || str_starts_with($host, '10.')
            || preg_match('/^172\.(1[6-9]|2\d|3[0-1])\./', $host) === 1;
    }

    public function normalizeHttpsOrigin(string $input): string
    {
        $input = trim($input);
        if ($input === '') {
            return '';
        }
        if (! preg_match('#^https?://#i', $input)) {
            $input = 'https://' . ltrim($input, '/');
        }
        $parts = parse_url($input);
        if (! is_array($parts) || empty($parts['host'])) {
            return '';
        }
        $host = (string) $parts['host'];
        $port = isset($parts['port']) ? (':' . (int) $parts['port']) : '';

        return 'https://' . $host . $port;
    }
}
