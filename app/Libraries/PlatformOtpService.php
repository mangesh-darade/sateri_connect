<?php

declare(strict_types=1);

namespace App\Libraries;

use Config\Services;
use Config\WhatsApp as WhatsAppConfig;
use Throwable;

/**
 * Sends a password reset OTP on WhatsApp from the platform's own number (not a client's number).
 * Two providers, chosen by the platform admin in Platform Settings: Meta Cloud API (phone number id + token)
 * or External Direct API (x-api-key). Both send the same approved Authentication template.
 */
class PlatformOtpService
{
    protected MasterTenantRepository $repo;

    public function __construct()
    {
        $this->repo = new MasterTenantRepository();
    }

    /** 'meta' or 'external' */
    public function provider(): string
    {
        return $this->repo->getPlatformSetting('otp_wa_provider', 'meta') === 'external' ? 'external' : 'meta';
    }

    /** WhatsApp is offered on the Forgot password screen only when the chosen provider is fully saved. */
    public function isConfigured(): bool
    {
        if ($this->repo->getPlatformSetting('otp_wa_template') === '') {
            return false;
        }

        if ($this->provider() === 'external') {
            return $this->repo->getPlatformSecret('otp_wa_api_key') !== '';
        }

        return $this->repo->getPlatformSetting('otp_wa_phone_number_id') !== ''
            && $this->repo->getPlatformSecret('otp_wa_token') !== '';
    }

    /**
     * @return array{ok: bool, status: int, message: string}
     */
    public function sendOtp(string $mobile, string $otp): array
    {
        if (! $this->isConfigured()) {
            return ['ok' => false, 'status' => 503, 'message' => 'WhatsApp OTP is not set up.'];
        }

        $template = trim($this->repo->getPlatformSetting('otp_wa_template'));
        $language = trim($this->repo->getPlatformSetting('otp_wa_language', 'en')) ?: 'en';
        $to       = ltrim(normalize_phone($mobile), '0');
        // User mobiles are free text; 10 digits without a country code are Indian numbers (the app default, as in contact import).
        if (strlen($to) === 10) {
            $to = '91' . $to;
        }
        if (strlen($to) < 11) {
            return ['ok' => false, 'status' => 422, 'message' => 'The mobile number saved on this user is not valid.'];
        }

        $components = [
            ['type' => 'body', 'parameters' => [['type' => 'text', 'text' => $otp]]],
        ];
        // An Authentication template has a Copy code button that needs the code again; a custom text template has none.
        if ($this->repo->getPlatformSetting('otp_wa_button', '1') === '1') {
            $components[] = ['type' => 'button', 'sub_type' => 'url', 'index' => '0', 'parameters' => [['type' => 'text', 'text' => $otp]]];
        }

        try {
            if ($this->provider() === 'external') {
                $waConfig = config(WhatsAppConfig::class);
                $url      = rtrim($waConfig->baseUrl, '/') . '/v1/whatsapp/template/send';
                $headers  = ['x-api-key' => trim($this->repo->getPlatformSecret('otp_wa_api_key')), 'Accept' => 'application/json'];
                $json     = [
                    'to'   => $to,
                    'data' => ['name' => $template, 'language' => ['code' => $language], 'components' => $components],
                ];
            } else {
                $phoneId    = trim($this->repo->getPlatformSetting('otp_wa_phone_number_id'));
                $apiVersion = trim($this->repo->getPlatformSetting('meta_tech_api_version', 'v25.0')) ?: 'v25.0';
                $url        = 'https://graph.facebook.com/' . $apiVersion . '/' . rawurlencode($phoneId) . '/messages';
                $headers    = ['Authorization' => 'Bearer ' . trim($this->repo->getPlatformSecret('otp_wa_token'))];
                $json       = [
                    'messaging_product' => 'whatsapp',
                    'to'                => $to,
                    'type'              => 'template',
                    'template'          => ['name' => $template, 'language' => ['code' => $language], 'components' => $components],
                ];
            }

            $client = Services::curlrequest([
                'timeout'         => 15,
                'http_errors'     => false,
                'connect_timeout' => 10,
            ], null, null, false);

            $response = $client->request('POST', $url, ['headers' => $headers, 'json' => $json]);
            $status   = $response->getStatusCode();

            if ($status >= 200 && $status < 300) {
                return ['ok' => true, 'status' => 200, 'message' => 'OTP sent.'];
            }

            log_message('error', 'PlatformOtpService::sendOtp {provider} status {status}: {body}', [
                'provider' => $this->provider(),
                'status'   => $status,
                'body'     => mb_substr((string) $response->getBody(), 0, 500),
            ]);
        } catch (Throwable $e) {
            log_message('error', 'PlatformOtpService::sendOtp failed: ' . $e->getMessage());
        }

        return ['ok' => false, 'status' => 502, 'message' => 'Could not send the OTP on WhatsApp.'];
    }
}
