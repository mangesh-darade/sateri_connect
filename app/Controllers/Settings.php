<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Libraries\ActivityLogger;
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

        $localCallback  = site_url('webhooks');
        $publicBase     = rtrim((string) $settings->get('webhook_public_base', ''), '/');
        $publicCallback = $this->buildPublicWebhookUrl($publicBase, $localCallback);

        $sendGrid = $settings->getSendGridConfig();
        $sendGridDisplay = $sendGrid;
        $sendGridDisplay['api_key'] = $this->maskSecret($sendGrid['api_key']);

        $data = [
            'pageTitle' => 'Settings',
            'provider'  => $provider,
            'emailProvider' => $emailProvider,
            'cheerio'   => $cheerioDisplay,
            'meta'      => $metaDisplay,
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
                'step1_done'      => trim((string) $activeWh['verify_token']) !== '',
                'step2_done'      => $publicBase !== '' && str_starts_with($publicBase, 'https://'),
                'step3_ready'     => trim((string) $activeWh['verify_token']) !== ''
                    && $publicBase !== ''
                    && str_starts_with($publicBase, 'https://'),
            ],
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

                $settings->setMetaConfig(array_filter(
                    $meta,
                    static fn ($v) => $v !== null && $v !== ''
                ));
            }

            // Live Chat webhook public HTTPS base (ngrok / production domain)
            if (in_array($section, ['all', 'cheerio', 'meta', 'webhooks'], true)) {
                $publicBase = trim((string) $this->request->getPost('webhook_public_base'));
                if ($publicBase !== '') {
                    $publicBase = $this->normalizePublicWebhookBase($publicBase);
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

            (new ActivityLogger())->log('update', 'settings', 'Settings updated', [
                'section'  => $section,
                'provider' => $settings->getWhatsAppProvider(),
            ]);

            if ($this->request->isAJAX()) {
                return $this->jsonResponse(true, [
                    'provider'       => $settings->getWhatsAppProvider(),
                    'provider_short' => $settings->isMetaProvider() ? 'Meta' : 'Cheerio',
                    'provider_label' => $settings->isMetaProvider() ? 'Meta Cloud API' : 'Cheerio Direct API',
                    'email_provider' => $settings->getEmailProvider(),
                    'email_provider_label' => $this->emailProviderLabel($settings->getEmailProvider()),
                    'section'        => $section,
                ], 'Settings saved successfully.');
            }

            return redirect()->to('/settings')->with('success', 'Settings saved successfully.');
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

            if ($action === 'save_public_url') {
                $input = (string) ($this->request->getPost('webhook_public_base')
                    ?? ($this->safeGetJSON()['webhook_public_base'] ?? ''));
                $base = $this->normalizePublicWebhookBase($input);
                if ($base === '' || ! str_starts_with($base, 'https://')) {
                    return $this->jsonResponse(
                        false,
                        null,
                        'Paste your ngrok HTTPS URL, e.g. https://xxxx.ngrok-free.app',
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
                if (is_array($metaSubscribe) && ! empty($metaSubscribe['ok'])) {
                    $msg .= ' Meta WABA override pinned to this URL.';
                } elseif (is_array($metaSubscribe) && isset($metaSubscribe['error'])) {
                    $msg .= ' (Meta subscribe warning: ' . $metaSubscribe['error'] . ')';
                }

                return $this->jsonResponse(true, [
                    'public_base'     => $base,
                    'public_callback' => $callback,
                    'step2_done'      => true,
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
                    ? ('Step 3 local test OK — now paste URL in ' . $providerLabel . '.')
                    : 'Local verify failed (HTTP ' . $status . '). Check Apache / app URL.');
            }

            return $this->jsonResponse(false, null, 'Unknown action.', [], 422);
        } catch (\Throwable $e) {
            return $this->jsonResponse(false, null, $e->getMessage(), [], 500);
        }
    }

    /**
     * Normalize ngrok / production HTTPS base (host only or full webhook URL).
     */
    protected function normalizePublicWebhookBase(string $input): string
    {
        $input = trim($input);
        if ($input === '') {
            return '';
        }

        // Allow pasting full webhook URL — strip path back to origin.
        if (preg_match('#^https?://#i', $input)) {
            $parts = parse_url($input);
            if (! is_array($parts) || empty($parts['host'])) {
                return '';
            }
            $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
            if ($scheme !== 'https') {
                $scheme = 'https';
            }
            $host = (string) $parts['host'];
            $port = isset($parts['port']) ? (':' . (int) $parts['port']) : '';

            return $scheme . '://' . $host . $port;
        }

        // Bare host: abcd.ngrok-free.app
        $host = preg_replace('#^/+|#+$|/.*$#', '', $input) ?: '';
        if ($host === '') {
            return '';
        }

        return 'https://' . $host;
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
        $settings->set($key, 'uploads/branding/' . $newName, 'general');
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
    }
}
