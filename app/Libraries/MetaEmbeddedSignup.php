<?php

declare(strict_types=1);

namespace App\Libraries;

use Config\Services;
use Config\WhatsApp as WhatsAppConfig;
use RuntimeException;

/**
 * Meta WhatsApp Embedded Signup onboarding (Tech Provider / Solution Partner).
 *
 * Flow: Connect WhatsApp → FB.login (config_id) → code + WABA/phone IDs →
 * exchange code for business token → store credentials → register phone → subscribe webhooks.
 *
 * @see https://developers.facebook.com/docs/whatsapp/embedded-signup/implementation/
 */
class MetaEmbeddedSignup
{
    protected SettingsService $settings;
    protected WhatsAppConfig $config;

    public function __construct(?SettingsService $settings = null, ?WhatsAppConfig $config = null)
    {
        $this->settings = $settings ?? new SettingsService();
        $this->config   = $config ?? config(WhatsAppConfig::class);
    }

    /**
     * Whether Settings has enough Meta app credentials to launch Embedded Signup.
     */
    public function isLaunchReady(): bool
    {
        $meta = $this->settings->getMetaConfig();

        return trim((string) ($meta['app_id'] ?? '')) !== ''
            && trim((string) ($meta['embedded_config_id'] ?? '')) !== ''
            && trim((string) ($meta['app_secret'] ?? '')) !== '';
    }

    /**
     * Public values safe to expose to the Settings page for FB.init / FB.login.
     *
     * @return array{app_id: string, config_id: string, api_version: string, ready: bool}
     */
    public function clientConfig(): array
    {
        $meta = $this->settings->getMetaConfig();

        return [
            'app_id'      => (string) ($meta['app_id'] ?? ''),
            'config_id'   => (string) ($meta['embedded_config_id'] ?? ''),
            'api_version' => (string) ($meta['api_version'] ?? 'v21.0') ?: 'v21.0',
            'ready'       => $this->isLaunchReady(),
        ];
    }

    /**
     * Exchange Embedded Signup code, persist token + IDs, register phone, subscribe webhooks.
     *
     * @return array<string, mixed>
     */
    public function complete(
        string $code,
        string $wabaId,
        string $phoneNumberId,
        ?string $businessId = null,
        ?string $pin = null
    ): array {
        $code          = trim($code);
        $wabaId        = trim($wabaId);
        $phoneNumberId = trim($phoneNumberId);
        $businessId    = $businessId !== null ? trim($businessId) : '';

        if ($code === '') {
            throw new RuntimeException('Embedded Signup auth code is missing. Retry Connect WhatsApp.');
        }
        if ($wabaId === '') {
            throw new RuntimeException('WABA ID was not returned by Embedded Signup.');
        }
        if ($phoneNumberId === '') {
            throw new RuntimeException(
                'Phone Number ID was not returned. Complete the phone verification step in Meta, then try again.'
            );
        }

        $tokenResult = $this->exchangeCodeForToken($code);
        $accessToken = trim((string) ($tokenResult['access_token'] ?? ''));
        if ($accessToken === '') {
            throw new RuntimeException('Meta did not return an access token for the Embedded Signup code.');
        }

        $metaUpdate = [
            'access_token'    => $accessToken,
            'waba_id'         => $wabaId,
            'phone_number_id' => $phoneNumberId,
        ];
        if ($businessId !== '') {
            $metaUpdate['business_id'] = $businessId;
        }
        if ($pin !== null && preg_match('/^\d{6}$/', $pin) === 1) {
            $metaUpdate['two_step_pin'] = $pin;
        }

        $this->settings->setMetaConfig($metaUpdate);
        $this->settings->setWhatsAppProvider(SettingsService::PROVIDER_META);

        $warnings = [];
        $register = null;
        $subscribe = null;

        $effectivePin = $pin;
        if ($effectivePin === null || preg_match('/^\d{6}$/', $effectivePin) !== 1) {
            $stored = (string) ($this->settings->getMetaConfig()['two_step_pin'] ?? '');
            $effectivePin = preg_match('/^\d{6}$/', $stored) === 1 ? $stored : '000000';
        }

        try {
            $register = $this->registerPhoneNumber($phoneNumberId, $accessToken, $effectivePin);
        } catch (\Throwable $e) {
            $warnings[] = 'Phone register: ' . $e->getMessage();
            log_message('warning', 'MetaEmbeddedSignup register failed: {msg}', ['msg' => $e->getMessage()]);
        }

        try {
            $api = new MetaCloudAPI($this->settings);
            $subscribe = $api->subscribeWabaWebhook();
        } catch (\Throwable $e) {
            $warnings[] = 'Webhook subscribe: ' . $e->getMessage();
            log_message('warning', 'MetaEmbeddedSignup webhook subscribe failed: {msg}', [
                'msg' => $e->getMessage(),
            ]);
        }

        $displayPhone = '';
        $verifiedName = '';
        try {
            $info = (new MetaCloudAPI($this->settings))->getPhoneNumberInfo();
            $displayPhone = (string) ($info['display_phone_number'] ?? '');
            $verifiedName = (string) ($info['verified_name'] ?? '');
        } catch (\Throwable $e) {
            $warnings[] = 'Phone info: ' . $e->getMessage();
        }

        return [
            'ok'              => true,
            'provider'        => 'meta',
            'waba_id'         => $wabaId,
            'phone_number_id' => $phoneNumberId,
            'business_id'     => $businessId,
            'display_phone'   => $displayPhone,
            'verified_name'   => $verifiedName,
            'token_type'      => (string) ($tokenResult['token_type'] ?? 'bearer'),
            'registered'      => is_array($register) && ! empty($register['success']),
            'webhook'         => $subscribe,
            'warnings'        => $warnings,
        ];
    }

    /**
     * GET /oauth/access_token — exchange Embedded Signup code (TTL ~30s) for business token.
     *
     * @return array<string, mixed>
     */
    public function exchangeCodeForToken(string $code): array
    {
        $meta       = $this->settings->getMetaConfig();
        $appId      = trim((string) ($meta['app_id'] ?? ''));
        $appSecret  = trim((string) ($meta['app_secret'] ?? ''));
        $apiVersion = (string) ($meta['api_version'] ?? 'v21.0') ?: 'v21.0';

        if ($appId === '' || $appSecret === '') {
            throw new RuntimeException(
                'Save Meta App ID and App Secret in Settings before Connect WhatsApp.'
            );
        }

        $base = rtrim((string) ($meta['graph_base_url'] ?? $this->config->graphBaseUrl), '/');
        $url  = $base . '/' . $apiVersion . '/oauth/access_token';

        $client = Services::curlrequest([
            'timeout'         => $this->config->defaultTimeout,
            'http_errors'     => false,
            'connect_timeout' => 15,
            'verify'          => $this->resolveSslVerify(),
        ], null, null, false);

        $response = $client->request('GET', $url, [
            'headers' => ['Accept' => 'application/json'],
            'query'   => [
                'client_id'     => $appId,
                'client_secret' => $appSecret,
                'code'          => $code,
            ],
        ]);

        $status  = $response->getStatusCode();
        $body    = (string) $response->getBody();
        $decoded = json_decode($body, true);
        if (! is_array($decoded)) {
            $decoded = ['raw' => $body];
        }

        if ($status < 200 || $status >= 300 || empty($decoded['access_token'])) {
            $msg = $this->extractError($decoded) ?: ('HTTP ' . $status);
            throw new RuntimeException('Token exchange failed: ' . $msg);
        }

        return $decoded;
    }

    /**
     * POST /{phone-number-id}/register — enable Cloud API for the onboarded number.
     *
     * @return array<string, mixed>
     */
    public function registerPhoneNumber(string $phoneNumberId, string $accessToken, string $pin): array
    {
        $phoneNumberId = trim($phoneNumberId);
        $pin           = trim($pin);
        if ($phoneNumberId === '') {
            throw new RuntimeException('Phone Number ID is required to register.');
        }
        if (preg_match('/^\d{6}$/', $pin) !== 1) {
            throw new RuntimeException('Two-step PIN must be exactly 6 digits.');
        }

        $meta       = $this->settings->getMetaConfig();
        $apiVersion = (string) ($meta['api_version'] ?? 'v21.0') ?: 'v21.0';
        $base       = rtrim((string) ($meta['graph_base_url'] ?? $this->config->graphBaseUrl), '/');
        $url        = $base . '/' . $apiVersion . '/' . $phoneNumberId . '/register';

        $client = Services::curlrequest([
            'timeout'         => $this->config->defaultTimeout,
            'http_errors'     => false,
            'connect_timeout' => 15,
            'verify'          => $this->resolveSslVerify(),
        ], null, null, false);

        $response = $client->request('POST', $url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $accessToken,
                'Accept'        => 'application/json',
                'Content-Type'  => 'application/json',
            ],
            'json' => [
                'messaging_product' => 'whatsapp',
                'pin'               => $pin,
            ],
        ]);

        $status  = $response->getStatusCode();
        $body    = (string) $response->getBody();
        $decoded = json_decode($body, true);
        if (! is_array($decoded)) {
            $decoded = ['raw' => $body];
        }

        if ($status < 200 || $status >= 300) {
            $msg = $this->extractError($decoded) ?: ('HTTP ' . $status);
            throw new RuntimeException($msg);
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $decoded
     */
    protected function extractError(array $decoded): string
    {
        if (isset($decoded['error']['message']) && is_string($decoded['error']['message'])) {
            return $decoded['error']['message'];
        }
        if (isset($decoded['error_message']) && is_string($decoded['error_message'])) {
            return $decoded['error_message'];
        }
        if (isset($decoded['message']) && is_string($decoded['message'])) {
            return $decoded['message'];
        }

        return '';
    }

    /**
     * @return bool|string
     */
    protected function resolveSslVerify(): bool|string
    {
        $configured = $this->config->sslVerify;

        if (is_bool($configured)) {
            return $configured;
        }
        if (is_string($configured) && $configured !== '' && is_file($configured)) {
            return $configured;
        }

        $ini = (string) (ini_get('curl.cainfo') ?: ini_get('openssl.cafile') ?: '');
        if ($ini !== '' && is_file($ini)) {
            return $ini;
        }

        $env = (string) (getenv('SSL_CERT_FILE') ?: getenv('CURL_CA_BUNDLE') ?: '');
        if ($env !== '' && is_file($env)) {
            return $env;
        }

        $local = WRITEPATH . 'certs/cacert.pem';
        if (is_file($local)) {
            return $local;
        }

        return true;
    }
}
