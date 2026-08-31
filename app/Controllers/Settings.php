<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Libraries\ActivityLogger;
use App\Libraries\TimeZoneOptions;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;

/**
 * Application & WhatsApp provider (Cheerio / Meta) + SMTP settings.
 */
class Settings extends BaseController
{
    /**
     * Safe wrapper: returns decoded JSON body or empty array if body is not JSON.
     */
    private function safeGetJSON(): array
    {
        try {
            $json = $this->request->getJSON(true);
            return is_array($json) ? $json : [];
        } catch (\Throwable) {
            return [];
        }
    }

    public function index(): string|ResponseInterface
    {
        if ($denied = $this->requirePermission('settings.view')) {
            return $denied;
        }

        $settings = service('settingsService');
        $provider = $settings->getWhatsAppProvider();
        $emailProvider = $settings->getEmailProvider();
        $cheerio  = $settings->getCheerioConfig();
        $meta     = $settings->getMetaConfig();
        $activeWh = $settings->getActiveWebhookConfig();

        // Ensure active provider always has a verify token for Live Chat webhooks.
        if (trim((string) ($activeWh['verify_token'] ?? '')) === '') {
            $autoToken = 'whstapp_' . bin2hex(random_bytes(12));
            $settings->setActiveVerifyToken($autoToken);
            $activeWh = $settings->getActiveWebhookConfig();
            $cheerio  = $settings->getCheerioConfig();
            $meta     = $settings->getMetaConfig();
        }

        $cheerioDisplay = $cheerio;
        $cheerioDisplay['api_key']        = $this->maskSecret($cheerio['api_key']);
        $cheerioDisplay['webhook_secret'] = $this->maskSecret($cheerio['webhook_secret']);

        $metaDisplay = $meta;
        $metaDisplay['access_token']      = $this->maskSecret($meta['access_token']);
        $metaDisplay['app_secret']        = $this->maskSecret($meta['app_secret']);
        $metaDisplay['page_access_token'] = $this->maskSecret((string) ($meta['page_access_token'] ?? ''));
        $metaDisplay['two_step_pin']      = $this->maskSecret((string) ($meta['two_step_pin'] ?? ''));

        $embeddedSignup = (new \App\Libraries\MetaEmbeddedSignup($settings))->clientConfig();

        $webhookPublic = $settings->ensureLiveWebhookPublicBasePersisted();
        $localCallback = (string) ($webhookPublic['local_callback'] ?? site_url('webhooks'));
        $publicBase    = (string) ($webhookPublic['public_base'] ?? '');
        $publicCallback = (string) ($webhookPublic['public_callback'] ?? $localCallback);

        $sendGrid = $settings->getSendGridConfig();
        $sendGridDisplay = $sendGrid;
        $sendGridDisplay['api_key'] = $this->maskSecret($sendGrid['api_key']);

        $data = [
            'pageTitle' => 'Settings',
            'provider'  => $provider,
            'emailProvider' => $emailProvider,
            'cheerio'   => $cheerioDisplay,
            'meta'      => $metaDisplay,
            'embeddedSignup' => $embeddedSignup,
            'sendgrid'  => $sendGridDisplay,
            'cheerioEmail' => $settings->getCheerioEmailConfig(),
            'campaigns' => model(\App\Models\CampaignModel::class)
                ->select('id, name, status')
                ->orderBy('name', 'ASC')
                ->findAll(),
            'app'       => [
                'app_name'      => (string) $settings->get('app_name', 'WhatsApp Automation'),
                'app_tagline'   => (string) $settings->get('app_tagline', 'Automation console'),
                'app_timezone'  => (string) ($settings->get('app_timezone') ?: $settings->get('timezone', 'UTC')),
                'app_email'     => (string) $settings->get('app_email', ''),
                'app_url'       => (string) $settings->get('app_url', site_url()),
                'site_logo'     => (string) $settings->get('site_logo', ''),
                'site_favicon'  => (string) $settings->get('site_favicon', ''),
            ],
            'smtp' => [
                'smtp_host'       => (string) $settings->get('smtp_host', ''),
                'smtp_port'       => (string) $settings->get('smtp_port', '587'),
                'smtp_user'       => (string) $settings->get('smtp_user', ''),
                'smtp_password'   => $this->maskSecret((string) ($settings->get('smtp_password') ?: $settings->get('smtp_pass', ''))),
                'smtp_encryption' => (string) $settings->get('smtp_encryption', 'tls'),
                'smtp_from_email' => (string) $settings->get('smtp_from_email', ''),
                'smtp_from_name'  => (string) $settings->get('smtp_from_name', ''),
            ],
            'webhook' => [
                'provider'        => $activeWh['provider'],
                'verify_token'    => (string) $activeWh['verify_token'],
                'callback_url'    => $localCallback,
                'public_base'     => $publicBase,
                'public_callback' => $publicCallback,
                'mode'            => (string) ($webhookPublic['mode'] ?? 'local'),
                'source'          => (string) ($webhookPublic['source'] ?? 'none'),
                'auto_base'       => (string) ($webhookPublic['auto_base'] ?? ''),
                'auto_callback'   => (string) ($webhookPublic['auto_callback'] ?? ''),
                'hint'            => (string) ($webhookPublic['hint'] ?? ''),
                'step1_done'      => trim((string) $activeWh['verify_token']) !== '',
                'step2_done'      => $publicBase !== '' && str_starts_with($publicBase, 'https://'),
                'step3_ready'     => trim((string) $activeWh['verify_token']) !== ''
                    && $publicBase !== ''
                    && str_starts_with($publicBase, 'https://'),
            ],
            'timezoneOptions' => (new TimeZoneOptions())->grouped(),
        ];

        $elintomCfg = (new \App\Libraries\ElintOmCustomerSyncService($settings))->getConfig();
        $data['elintom'] = [
            'base_url'    => (string) ($elintomCfg['base_url'] ?? ''),
            'private_key' => $this->maskSecret((string) ($elintomCfg['private_key'] ?? '')),
        ];

        return $this->render('settings/index', $data);
    }

    public function save(): ResponseInterface
    {
        if ($denied = $this->requirePermission('settings.edit')) {
            return $denied;
        }

        $settings = service('settingsService');
        $section  = (string) ($this->request->getPost('section') ?? 'all');
        $metaWebhookSync = null;

        try {
            if (in_array($section, ['all', 'email', 'provider'], true)) {
                $emailProviderPost = strtolower(trim((string) $this->request->getPost('email_provider')));
                if ($emailProviderPost === '') {
                    $json = $this->safeGetJSON();
                    if (is_array($json)) {
                        $emailProviderPost = strtolower(trim((string) ($json['email_provider'] ?? '')));
                    }
                }
                if (in_array($emailProviderPost, ['smtp', 'sendgrid', 'cheerio'], true)) {
                    $settings->setEmailProvider($emailProviderPost);
                } elseif ($section === 'email' && $emailProviderPost !== '') {
                    return $this->jsonResponse(false, null, 'Choose SMTP, SendGrid, or Cheerio.', [], 422);
                }
            }

            if (in_array($section, ['all', 'sendgrid', 'email'], true)) {
                $sendGrid = [
                    'from_email'             => trim((string) $this->request->getPost('sendgrid_from_email')),
                    'from_name'              => trim((string) $this->request->getPost('sendgrid_from_name')),
                    'sender_id'              => trim((string) $this->request->getPost('sendgrid_sender_id')),
                    'suppression_group_id'   => trim((string) $this->request->getPost('sendgrid_suppression_group_id')),
                    'custom_unsubscribe_url' => trim((string) $this->request->getPost('sendgrid_custom_unsubscribe_url')),
                    'ip_pool'                => trim((string) $this->request->getPost('sendgrid_ip_pool')),
                ];

                $apiKey = trim((string) $this->request->getPost('sendgrid_api_key'));
                if ($apiKey !== '' && ! str_contains($apiKey, '•')) {
                    $sendGrid['api_key'] = $apiKey;
                }

                $settings->setSendGridConfig(array_filter(
                    $sendGrid,
                    static fn ($v) => $v !== null && $v !== ''
                ));
            }

            if (in_array($section, ['all', 'cheerio_email', 'email'], true)) {
                $campaign = trim((string) $this->request->getPost('cheerio_email_campaign_name'));
                if ($campaign !== '') {
                    $settings->setCheerioEmailConfig(['default_campaign' => $campaign]);
                }
            }

            if (in_array($section, ['all', 'provider', 'cheerio', 'meta'], true)) {
                $providerPost = strtolower(trim((string) $this->request->getPost('whatsapp_provider')));
                // JSON body fallback (AJAX provider switch)
                if ($providerPost === '') {
                    $json = $this->safeGetJSON();
                    if (is_array($json)) {
                        $providerPost = strtolower(trim((string) ($json['whatsapp_provider'] ?? '')));
                    }
                }
                if (in_array($providerPost, ['cheerio', 'meta'], true)) {
                    $settings->setWhatsAppProvider($providerPost);
                } elseif ($section === 'provider') {
                    return $this->jsonResponse(false, null, 'Choose Cheerio or Meta.', [], 422);
                }
            }

            if (in_array($section, ['all', 'cheerio'], true)) {
                $cheerio = [
                    'verify_token'  => $this->request->getPost('cheerio_webhook_verify_token'),
                    'display_phone' => $this->request->getPost('cheerio_display_phone'),
                ];

                $apiKey = trim((string) $this->request->getPost('cheerio_api_key'));
                if ($apiKey !== '' && ! str_contains($apiKey, '•')) {
                    $cheerio['api_key'] = $apiKey;
                }

                $secret = trim((string) $this->request->getPost('cheerio_webhook_secret'));
                if ($secret !== '' && ! str_contains($secret, '•')) {
                    $cheerio['webhook_secret'] = $secret;
                }

                $settings->setCheerioConfig(array_filter(
                    $cheerio,
                    static fn ($v) => $v !== null && $v !== ''
                ));
            }

            if (in_array($section, ['all', 'meta'], true)) {
                $meta = [
                    'phone_number_id'         => trim((string) $this->request->getPost('meta_phone_number_id')),
                    'waba_id'                 => trim((string) $this->request->getPost('meta_waba_id')),
                    'app_id'                  => trim((string) $this->request->getPost('meta_app_id')),
                    'embedded_config_id'      => trim((string) $this->request->getPost('meta_embedded_config_id')),
                    'api_version'             => trim((string) $this->request->getPost('meta_api_version')) ?: 'v21.0',
                    'verify_token'            => $this->request->getPost('meta_webhook_verify_token'),
                    'page_id'                 => trim((string) $this->request->getPost('meta_page_id')),
                    'instagram_account_id'    => trim((string) $this->request->getPost('meta_instagram_account_id')),
                    'inbox_instagram_enabled' => $this->request->getPost('inbox_instagram_enabled') ? '1' : '0',
                    'inbox_messenger_enabled' => $this->request->getPost('inbox_messenger_enabled') ? '1' : '0',
                ];

                $token = trim((string) $this->request->getPost('meta_access_token'));
                if ($token !== '' && ! str_contains($token, '•')) {
                    $meta['access_token'] = $token;
                }

                $secret = trim((string) $this->request->getPost('meta_webhook_secret'));
                if ($secret !== '' && ! str_contains($secret, '•')) {
                    $meta['app_secret'] = $secret;
                }

                $pageToken = trim((string) $this->request->getPost('meta_page_access_token'));
                if ($pageToken !== '' && ! str_contains($pageToken, '•')) {
                    $meta['page_access_token'] = $pageToken;
                }

                $pin = trim((string) $this->request->getPost('meta_two_step_pin'));
                if ($pin !== '' && ! str_contains($pin, '•')) {
                    if (preg_match('/^\d{6}$/', $pin) !== 1) {
                        return $this->jsonResponse(false, null, 'Meta two-step PIN must be exactly 6 digits.', [], 422);
                    }
                    $meta['two_step_pin'] = $pin;
                }

                $settings->setMetaConfig(array_filter(
                    $meta,
                    static fn ($v) => $v !== null && $v !== ''
                ));
            }

            // Live Chat webhook public HTTPS base (ngrok / production domain)
            if (in_array($section, ['all', 'cheerio', 'meta', 'webhooks'], true)) {
                $publicBase = trim((string) $this->request->getPost('webhook_public_base'));
                if ($publicBase !== '') {
                    $publicBase = $settings->normalizeHttpsOrigin($publicBase);
                    $settings->set('webhook_public_base', $publicBase, 'whatsapp');
                }
            }

            if (in_array($section, ['all', 'app'], true)) {
                $appKeys = [
                    'app_name'     => 'general',
                    'app_tagline'  => 'general',
                    'app_timezone' => 'general',
                    'app_email'    => 'general',
                    'app_url'      => 'general',
                ];
                foreach ($appKeys as $key => $group) {
                    $val = $this->request->getPost($key);
                    if ($val !== null) {
                        $settings->set($key, (string) $val, $group);
                        if ($key === 'app_timezone') {
                            $settings->set('timezone', (string) $val, $group);
                        }
                    }
                }

                if ((string) $this->request->getPost('remove_site_logo') === '1') {
                    $this->deleteBrandingFile((string) $settings->get('site_logo', ''));
                    $settings->set('site_logo', '', 'general');
                }
                if ((string) $this->request->getPost('remove_site_favicon') === '1') {
                    $this->deleteBrandingFile((string) $settings->get('site_favicon', ''));
                    $settings->set('site_favicon', '', 'general');
                }

                $this->saveBrandingUpload($settings, 'site_logo', [
                    'image/png', 'image/jpeg', 'image/webp', 'image/gif',
                ], 2 * 1024 * 1024);
                $this->saveBrandingUpload($settings, 'site_favicon', [
                    'image/png', 'image/x-icon', 'image/vnd.microsoft.icon', 'image/jpeg', 'image/webp', 'image/gif',
                ], 512 * 1024);
            }

            if (in_array($section, ['all', 'smtp'], true)) {
                $smtpMap = [
                    'smtp_host'       => false,
                    'smtp_port'       => false,
                    'smtp_user'       => false,
                    'smtp_encryption' => false,
                    'smtp_from_email' => false,
                    'smtp_from_name'  => false,
                    'smtp_password'   => true,
                ];

                foreach ($smtpMap as $key => $encrypted) {
                    $val = $this->request->getPost($key);
                    if ($val === null && $key === 'smtp_password') {
                        $val = $this->request->getPost('smtp_pass');
                    }
                    if ($val === null) {
                        continue;
                    }
                    $val = (string) $val;
                    if ($encrypted && (str_contains($val, '•') || $val === '')) {
                        continue;
                    }
                    $settings->set($key, $val, 'smtp', $encrypted);
                    if ($key === 'smtp_password') {
                        $settings->set('smtp_pass', $val, 'smtp', true);
                    }
                }

                $this->applySmtpConfig();
            }

            if (in_array($section, ['all', 'elintom'], true)) {
                $elintom = new \App\Libraries\ElintOmCustomerSyncService($settings);
                $payload = [
                    'base_url' => trim((string) $this->request->getPost('elintom_base_url')),
                ];
                $privateKey = trim((string) $this->request->getPost('elintom_api_private_key'));
                if ($privateKey !== '' && ! str_contains($privateKey, '•')) {
                    $payload['private_key'] = $privateKey;
                }
                $elintom->setConfig($payload);
            }

            // Saving Meta/Webhook settings also performs the Graph API equivalent of
            // Meta Dashboard's "Verify and save" for this configured App + WABA.
            if (
                $settings->isMetaProvider()
                && in_array($section, ['all', 'provider', 'meta', 'webhooks'], true)
            ) {
                try {
                    $resolved = $settings->resolveWebhookPublicConfig();
                    $callback = trim((string) ($resolved['public_callback'] ?? ''));
                    $metaWebhookSync = (new \App\Libraries\MetaCloudAPI($settings))
                        ->subscribeWabaWebhook($callback);
                } catch (\Throwable $e) {
                    log_message('warning', 'Settings saved but Meta webhook sync failed: {msg}', [
                        'msg' => $e->getMessage(),
                    ]);
                    $metaWebhookSync = [
                        'ok'               => false,
                        'fully_configured' => false,
                        'error'            => $e->getMessage(),
                    ];
                }
            }

            (new ActivityLogger())->log('update', 'settings', 'Settings updated', [
                'section'  => $section,
                'provider' => $settings->getWhatsAppProvider(),
                'meta_webhook_synced' => is_array($metaWebhookSync)
                    ? ! empty($metaWebhookSync['fully_configured'])
                    : null,
            ]);

            $saveMessage = 'Settings saved successfully.';
            if (is_array($metaWebhookSync)) {
                if (! empty($metaWebhookSync['fully_configured'])) {
                    $saveMessage .= ' Meta callback URL, verify token, webhook fields, and WABA override were synced.';
                } else {
                    $syncError = trim((string) (
                        $metaWebhookSync['error']
                        ?? $metaWebhookSync['webhook_fields']['error']
                        ?? 'Meta did not confirm the complete webhook configuration.'
                    ));
                    $saveMessage .= ' Meta webhook sync warning: ' . $syncError;
                }
            }

            if ($this->request->isAJAX()) {
                return $this->jsonResponse(true, [
                    'provider'       => $settings->getWhatsAppProvider(),
                    'provider_short' => $settings->isMetaProvider() ? 'Meta' : 'Cheerio',
                    'provider_label' => $settings->isMetaProvider() ? 'Meta Cloud API' : 'Cheerio Direct API',
                    'email_provider' => $settings->getEmailProvider(),
                    'email_provider_label' => $this->emailProviderLabel($settings->getEmailProvider()),
                    'section'        => $section,
                    'meta_webhook_sync' => $metaWebhookSync,
                ], $saveMessage);
            }

            return redirect()->to('/settings')->with('success', $saveMessage);
        } catch (\Throwable $e) {
            log_message('error', 'Settings save failed: {msg}', ['msg' => $e->getMessage()]);

            if ($this->request->isAJAX()) {
                return $this->jsonResponse(false, null, $e->getMessage(), [], 500);
            }

            return redirect()->back()->withInput()->with('error', $e->getMessage());
        }
    }

    public function testSmtp(): ResponseInterface
    {
        return $this->testEmail();
    }

    public function testEmail(): ResponseInterface
    {
        if ($denied = $this->requirePermission('settings.edit')) {
            return $denied;
        }

        $to = (string) ($this->request->getPost('to') ?: ($this->safeGetJSON()['to'] ?? ''));

        if ($to === '' || ! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return $this->jsonResponse(false, null, 'A valid recipient email is required.', [], 422);
        }

        try {
            $mailer = service('emailProvider');
            $result = $mailer->testConnection($to);
            $ok     = (bool) ($result['ok'] ?? false);
            $msg    = (string) ($result['message'] ?? ($ok ? 'Test email sent.' : 'Email test failed.'));

            return $this->jsonResponse($ok, $result, $ok ? ('Test email sent to ' . $to . ' via ' . $this->emailProviderLabel($mailer->getProvider())) : $msg);
        } catch (\Throwable $e) {
            return $this->jsonResponse(false, null, $e->getMessage(), [], 500);
        }
    }

    protected function emailProviderLabel(string $provider): string
    {
        return match ($provider) {
            'sendgrid' => 'SendGrid',
            'cheerio'  => 'Cheerio Email API',
            default    => 'SMTP',
        };
    }

    public function testCheerio(): ResponseInterface
    {
        return $this->testProviderConnection('cheerio');
    }

    public function testMeta(): ResponseInterface
    {
        return $this->testProviderConnection('meta');
    }

    public function testElintOm(): ResponseInterface
    {
        if ($denied = $this->requirePermission('settings.view')) {
            return $denied;
        }

        // Persist posted credentials before testing (optional — same pattern as other tests).
        if ($this->request->getPost('elintom_base_url') !== null || $this->request->getPost('elintom_api_private_key') !== null) {
            if ($deniedEdit = $this->requirePermission('settings.edit')) {
                return $deniedEdit;
            }
            $svc = new \App\Libraries\ElintOmCustomerSyncService();
            $payload = [
                'base_url' => trim((string) $this->request->getPost('elintom_base_url')),
            ];
            $key = trim((string) $this->request->getPost('elintom_api_private_key'));
            if ($key !== '' && ! str_contains($key, '•')) {
                $payload['private_key'] = $key;
            }
            $svc->setConfig($payload);
        }

        $result = (new \App\Libraries\ElintOmCustomerSyncService())->testConnection();
        $ok = ! empty($result['ok']);

        return $this->jsonResponse($ok, $result, (string) ($result['message'] ?? ($ok ? 'OK' : 'Failed')), [], $ok ? 200 : 422);
    }

    public function syncElintOm(): ResponseInterface
    {
        if ($denied = $this->requireAnyPermission(['contacts.import', 'settings.edit'])) {
            return $denied;
        }

        try {
            $stats = (new \App\Libraries\ElintOmCustomerSyncService())->sync();
            $msg = sprintf(
                'ElintOm sync done: %d created, %d updated, %d skipped, %d failed (of %d).',
                (int) ($stats['created'] ?? 0),
                (int) ($stats['updated'] ?? 0),
                (int) ($stats['skipped'] ?? 0),
                (int) ($stats['failed'] ?? 0),
                (int) ($stats['total'] ?? 0)
            );

            return $this->jsonResponse(true, $stats, $msg);
        } catch (\Throwable $e) {
            return $this->jsonResponse(false, null, $e->getMessage(), [], 500);
        }
    }

    /**
     * Complete Meta Embedded Signup: exchange code → store token/WABA/phone → register → webhooks.
     */
    public function embeddedSignup(): ResponseInterface
    {
        if ($denied = $this->requirePermission('settings.edit')) {
            return $denied;
        }

        $json = $this->safeGetJSON();
        $code = trim((string) ($this->request->getPost('code') ?? ($json['code'] ?? '')));
        $wabaId = trim((string) ($this->request->getPost('waba_id') ?? ($json['waba_id'] ?? '')));
        $phoneNumberId = trim((string) (
            $this->request->getPost('phone_number_id') ?? ($json['phone_number_id'] ?? '')
        ));
        $businessId = trim((string) (
            $this->request->getPost('business_id') ?? ($json['business_id'] ?? '')
        ));
        $pin = trim((string) ($this->request->getPost('pin') ?? ($json['pin'] ?? '')));

        if ($code === '') {
            return $this->jsonResponse(false, null, 'Missing Embedded Signup auth code.', [], 422);
        }

        try {
            $result = (new \App\Libraries\MetaEmbeddedSignup(service('settingsService')))->complete(
                $code,
                $wabaId,
                $phoneNumberId,
                $businessId !== '' ? $businessId : null,
                preg_match('/^\d{6}$/', $pin) === 1 ? $pin : null
            );

            (new ActivityLogger())->log('update', 'settings', 'WhatsApp connected via Meta Embedded Signup', [
                'waba_id'         => $result['waba_id'] ?? '',
                'phone_number_id' => $result['phone_number_id'] ?? '',
                'warnings'        => $result['warnings'] ?? [],
            ]);

            $msg = 'WhatsApp connected. Access token, WABA ID, and Phone Number ID saved.';
            $warnings = $result['warnings'] ?? [];
            if (is_array($warnings) && $warnings !== []) {
                $msg .= ' Notes: ' . implode(' · ', $warnings);
            }

            return $this->jsonResponse(true, $result, $msg);
        } catch (\Throwable $e) {
            return $this->jsonResponse(false, null, $e->getMessage(), [], 500);
        }
    }

    public function testPageMessaging(): ResponseInterface
    {
        if ($denied = $this->requirePermission('settings.view')) {
            return $denied;
        }

        try {
            $api    = service('pageMessaging');
            $result = $api->testConnection();
            $name   = (string) ($result['name'] ?? '');
            $id     = (string) ($result['id'] ?? '');
            $ig     = $result['instagram_business_account']['id'] ?? null;

            return $this->jsonResponse(true, $result, 'Page messaging OK'
                . ($name !== '' ? ': ' . $name : '')
                . ($id !== '' ? ' (' . $id . ')' : '')
                . ($ig ? ' · IG linked' : '')
                . '.');
        } catch (\Throwable $e) {
            return $this->jsonResponse(false, null, $e->getMessage(), [], 500);
        }
    }

    /**
     * Test a specific WhatsApp provider connection.
     */
    protected function testProviderConnection(string $provider): ResponseInterface
    {
        if ($denied = $this->requirePermission('settings.view')) {
            return $denied;
        }

        try {
            $settings = service('settingsService');
            if ($provider === 'meta') {
                $api   = new \App\Libraries\MetaCloudAPI($settings);
                $label = 'Meta Cloud API';
            } else {
                $api   = new \App\Libraries\CheerioDirectAPI($settings);
                $label = 'Cheerio Direct API';
            }

            $result = $api->testConnection();
            $ok     = (bool) ($result['ok'] ?? false);
            $detail = trim((string) ($result['message'] ?? ''));
            $msg    = $ok
                ? ($detail !== '' ? $detail : ($label . ' connection OK.'))
                : ($detail !== ''
                    ? ($label . ': ' . $detail)
                    : ($label . ' check completed with issues.'));

            return $this->jsonResponse($ok, $result, $msg);
        } catch (\Throwable $e) {
            return $this->jsonResponse(false, null, $e->getMessage(), [], 500);
        }
    }

    /**
     * AJAX helpers for Live Chat webhook 3-step setup.
     */
    public function setupWebhook(): ResponseInterface
    {
        if ($denied = $this->requirePermission('settings.edit')) {
            return $denied;
        }

        $settings = service('settingsService');
        $action   = (string) ($this->request->getPost('action')
            ?? ($this->safeGetJSON()['action'] ?? ''));
        $providerLabel = $settings->isMetaProvider() ? 'Meta App Dashboard' : 'Cheerio';

        try {
            if ($action === 'generate_token') {
                $token = 'whstapp_' . bin2hex(random_bytes(12));
                $settings->setActiveVerifyToken($token);

                (new ActivityLogger())->log('update', 'settings', 'Webhook verify token generated', [
                    'provider' => $settings->getWhatsAppProvider(),
                ]);

                return $this->jsonResponse(true, [
                    'verify_token' => $token,
                    'step1_done'   => true,
                    'provider'     => $settings->getWhatsAppProvider(),
                ], 'Step 1 done — verify token saved.');
            }

            if ($action === 'auto_public_url') {
                // Force re-detect from current request; on live, persist immediately.
                $resolved = $settings->ensureLiveWebhookPublicBasePersisted();
                if (($resolved['mode'] ?? '') !== 'live' || empty($resolved['auto_persisted'])) {
                    $resolved = $settings->resolveWebhookPublicConfig();
                    $autoBase = (string) ($resolved['auto_base'] ?? '');
                    if ($autoBase === '' || ! str_starts_with($autoBase, 'https://')) {
                        $mode = (string) ($resolved['mode'] ?? 'local');
                        $msg  = $mode === 'live'
                            ? 'Could not auto-detect live HTTPS domain. Open Settings on your live HTTPS URL, or paste the domain.'
                            : 'Could not auto-detect tunnel. Start cloudflared, open Settings via the trycloudflare HTTPS link, then click Auto again — or paste the tunnel URL.';

                        return $this->jsonResponse(false, $resolved, $msg, [], 422);
                    }
                    $settings->set('webhook_public_base', $autoBase, 'whatsapp');
                    $resolved = $settings->resolveWebhookPublicConfig();
                }

                $callback = (string) ($resolved['public_callback'] ?? '');
                $autoBase = (string) ($resolved['public_base'] ?? '');

                $metaSubscribe = null;
                if ($settings->isMetaProvider() && $callback !== '' && str_starts_with($callback, 'https://')) {
                    try {
                        $metaSubscribe = (new \App\Libraries\MetaCloudAPI($settings))
                            ->subscribeWabaWebhook($callback);
                    } catch (\Throwable $e) {
                        $metaSubscribe = ['ok' => false, 'error' => $e->getMessage()];
                    }
                }

                $modeLabel = (($resolved['mode'] ?? '') === 'live') ? 'Live' : 'Local';
                $remoteConfigured = ! $settings->isMetaProvider()
                    || (is_array($metaSubscribe) && ! empty($metaSubscribe['fully_configured']));
                $message = $modeLabel . ' callback auto-detected: ' . $callback;
                if ($settings->isMetaProvider()) {
                    $message .= $remoteConfigured
                        ? ' Meta callback/token synced automatically.'
                        : ' Meta sync warning: ' . (string) (
                            $metaSubscribe['error']
                            ?? $metaSubscribe['webhook_fields']['error']
                            ?? 'Meta did not confirm the complete webhook configuration.'
                        );
                }

                return $this->jsonResponse(true, [
                    'public_base'     => $autoBase,
                    'public_callback' => $callback,
                    'mode'            => (string) ($resolved['mode'] ?? 'local'),
                    'source'          => (string) ($resolved['source'] ?? 'saved'),
                    'step2_done'      => true,
                    'remote_configured' => $remoteConfigured,
                    'meta_subscribe'  => $metaSubscribe,
                ], $message);
            }

            if ($action === 'save_public_url') {
                $input = (string) ($this->request->getPost('webhook_public_base')
                    ?? ($this->safeGetJSON()['webhook_public_base'] ?? ''));
                $base = $settings->normalizeHttpsOrigin($input);
                if ($base === '' || ! str_starts_with($base, 'https://')) {
                    return $this->jsonResponse(
                        false,
                        null,
                        'Paste HTTPS URL (base or full callback), e.g. https://xxxx.trycloudflare.com',
                        [],
                        422
                    );
                }
                $settings->set('webhook_public_base', $base, 'whatsapp');
                $local    = site_url('webhooks');
                $callback = $this->buildPublicWebhookUrl($base, $local);

                $metaSubscribe = null;
                if ($settings->isMetaProvider()) {
                    try {
                        $metaSubscribe = (new \App\Libraries\MetaCloudAPI($settings))
                            ->subscribeWabaWebhook($callback);
                    } catch (\Throwable $e) {
                        log_message('warning', 'Meta WABA webhook subscribe failed: {msg}', [
                            'msg' => $e->getMessage(),
                        ]);
                        $metaSubscribe = ['ok' => false, 'error' => $e->getMessage()];
                    }
                }

                (new ActivityLogger())->log('update', 'settings', 'Webhook public base saved', [
                    'public_base' => $base,
                ]);

                $msg = 'Step 2 done — public webhook URL saved.';
                $remoteConfigured = ! $settings->isMetaProvider()
                    || (is_array($metaSubscribe) && ! empty($metaSubscribe['fully_configured']));
                if (is_array($metaSubscribe) && ! empty($metaSubscribe['fully_configured'])) {
                    $msg .= ' Meta callback URL, verify token, fields, and WABA override synced.';
                } elseif (is_array($metaSubscribe) && isset($metaSubscribe['error'])) {
                    $msg .= ' (Meta subscribe warning: ' . $metaSubscribe['error'] . ')';
                }

                $fields = is_array($metaSubscribe['webhook_fields'] ?? null)
                    ? $metaSubscribe['webhook_fields']
                    : null;
                if (is_array($fields)) {
                    if (! empty($fields['auto_fixed'])) {
                        $msg .= ' Subscribed Meta webhook field: messages.';
                    } elseif (empty($fields['messages_subscribed']) && ! empty($fields['error'])) {
                        $msg .= ' WARNING: ' . $fields['error'];
                    } elseif (! empty($fields['messages_subscribed'])) {
                        $msg .= ' messages field OK.';
                    }
                }

                return $this->jsonResponse(true, [
                    'public_base'     => $base,
                    'public_callback' => $callback,
                    'step2_done'      => true,
                    'remote_configured' => $remoteConfigured,
                    'meta_subscribe'  => $metaSubscribe,
                ], $msg);
            }

            if ($action === 'test_challenge') {
                $active = $settings->getActiveWebhookConfig();
                $token  = (string) ($active['verify_token'] ?? '');
                if ($token === '') {
                    return $this->jsonResponse(false, null, 'Generate verify token first (Step 1).', [], 422);
                }

                $challenge = (string) random_int(100000, 999999999);
                $url       = site_url('webhooks') . '?' . http_build_query([
                    'hub.mode'         => 'subscribe',
                    'hub.verify_token' => $token,
                    'hub.challenge'    => $challenge,
                ]);

                $client = Services::curlrequest([
                    'timeout'     => 10,
                    'http_errors' => false,
                ], null, null, false);
                $response = $client->get($url);
                $status   = $response->getStatusCode();
                $body     = trim((string) $response->getBody());
                $ok       = $status === 200 && $body === $challenge;

                return $this->jsonResponse($ok, [
                    'http_status' => $status,
                    'body'        => mb_substr($body, 0, 200),
                    'expected'    => $challenge,
                    'provider'    => $active['provider'] ?? '',
                ], $ok
                    ? ($settings->isMetaProvider()
                        ? 'Step 3 local test OK — Save URL will sync it to the configured Meta App automatically.'
                        : ('Step 3 local test OK — now paste URL in ' . $providerLabel . '.'))
                    : 'Local verify failed (HTTP ' . $status . '). Check Apache / app URL.');
            }

            return $this->jsonResponse(false, null, 'Unknown action.', [], 422);
        } catch (\Throwable $e) {
            return $this->jsonResponse(false, null, $e->getMessage(), [], 500);
        }
    }

    /**
     * Normalize ngrok / production HTTPS base (host only or full webhook URL).
     * @deprecated Use SettingsService::normalizeHttpsOrigin()
     */
    protected function normalizePublicWebhookBase(string $input): string
    {
        return service('settingsService')->normalizeHttpsOrigin($input);
    }

    /**
     * Build public callback from ngrok/production origin + local /webhooks path.
     */
    protected function buildPublicWebhookUrl(string $publicBase, string $localCallback): string
    {
        $publicBase = rtrim($publicBase, '/');
        if ($publicBase === '') {
            return $localCallback;
        }

        $path = (string) (parse_url($localCallback, PHP_URL_PATH) ?: '/webhooks');

        return $publicBase . $path;
    }

    protected function applySmtpConfig(): void
    {
        $settings = service('settingsService');
        $config   = config('Email');

        $host = (string) $settings->get('smtp_host', '');
        if ($host === '') {
            return;
        }

        $config->protocol    = 'smtp';
        $config->SMTPHost    = $host;
        $config->SMTPPort    = (int) $settings->get('smtp_port', 587);
        $config->SMTPUser    = (string) $settings->get('smtp_user', '');
        $config->SMTPPass    = (string) ($settings->get('smtp_password') ?: $settings->get('smtp_pass', ''));
        $config->SMTPCrypto  = (string) $settings->get('smtp_encryption', 'tls');
        $config->fromEmail   = (string) $settings->get('smtp_from_email', '');
        $config->fromName    = (string) $settings->get('smtp_from_name', '');
    }

    /**
     * Do not leak prefix/suffix of live secrets in HTML forms.
     */
    protected function maskSecret(string $value): string
    {
        if ($value === '') {
            return '';
        }

        return str_repeat('•', min(32, max(8, strlen($value))));
    }

    /**
     * Store logo / favicon under public/uploads/branding and save relative path in settings.
     *
     * @param list<string> $allowedMimes
     */
    protected function saveBrandingUpload(\App\Libraries\SettingsService $settings, string $key, array $allowedMimes, int $maxBytes): void
    {
        $file = $this->request->getFile($key);
        if ($file === null || ! $file->isValid() || $file->getError() === UPLOAD_ERR_NO_FILE) {
            return;
        }

        $mime = (string) $file->getMimeType();
        $ext  = strtolower((string) $file->getExtension());
        // Raster / ICO only — SVG can carry stored XSS when served from /uploads.
        $extOk = in_array($ext, ['png', 'jpg', 'jpeg', 'webp', 'gif', 'ico'], true);

        if (! in_array($mime, $allowedMimes, true) || ! $extOk) {
            throw new \RuntimeException(ucfirst(str_replace('_', ' ', $key)) . ' file type is not allowed.');
        }

        if ($file->getSize() > $maxBytes) {
            throw new \RuntimeException(ucfirst(str_replace('_', ' ', $key)) . ' exceeds size limit.');
        }

        $dir = FCPATH . 'uploads' . DIRECTORY_SEPARATOR . 'branding' . DIRECTORY_SEPARATOR;
        if (! is_dir($dir) && ! mkdir($dir, 0755, true) && ! is_dir($dir)) {
            throw new \RuntimeException('Unable to create branding upload directory.');
        }

        $safeExt = $ext !== '' ? $ext : 'png';
        if ($safeExt === 'jpeg') {
            $safeExt = 'jpg';
        }
        $newName = $key . '-' . bin2hex(random_bytes(8)) . '.' . $safeExt;

        $this->deleteBrandingFile((string) $settings->get($key, ''));

        $file->move($dir, $newName);
        $relative = 'uploads/branding/' . $newName;
        $settings->set($key, $relative, 'general');
        $this->mirrorBrandingToWebroot($relative);
    }

    protected function deleteBrandingFile(string $relativePath): void
    {
        $relativePath = str_replace(['../', '..\\'], '', $relativePath);
        $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');
        if ($relativePath === '' || ! str_starts_with($relativePath, 'uploads/branding/')) {
            return;
        }

        $full = FCPATH . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        if (is_file($full)) {
            @unlink($full);
        }

        // Plesk/nginx project-root docroot may serve a copied /uploads tree.
        $mirror = rtrim(ROOTPATH, '\\/') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        $publicReal = realpath(FCPATH . 'uploads');
        $rootUploads = realpath(rtrim(ROOTPATH, '\\/') . DIRECTORY_SEPARATOR . 'uploads');
        if (
            is_file($mirror)
            && ($publicReal === false || $rootUploads === false || $publicReal !== $rootUploads)
        ) {
            @unlink($mirror);
        }
    }

    /**
     * When DocumentRoot is project root (not public/), nginx serves /uploads from ROOT/uploads.
     * Uploads are written to public/uploads — mirror new branding files so live URLs work.
     */
    protected function mirrorBrandingToWebroot(string $relativePath): void
    {
        $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');
        if ($relativePath === '' || ! str_starts_with($relativePath, 'uploads/branding/')) {
            return;
        }

        $src = FCPATH . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        if (! is_file($src)) {
            return;
        }

        $publicUploads = realpath(FCPATH . 'uploads');
        $rootUploads   = rtrim(ROOTPATH, '\\/') . DIRECTORY_SEPARATOR . 'uploads';
        $rootReal      = realpath($rootUploads);

        // Same path / symlink already points at public — nothing to copy.
        if ($publicUploads !== false && $rootReal !== false && $publicUploads === $rootReal) {
            return;
        }

        $dest = rtrim(ROOTPATH, '\\/') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        $destDir = dirname($dest);
        if (! is_dir($destDir) && ! @mkdir($destDir, 0755, true) && ! is_dir($destDir)) {
            return;
        }

        @copy($src, $dest);
    }
}
