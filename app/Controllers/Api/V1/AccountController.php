<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Libraries\SettingsService;
use App\Models\ApiTokenModel;
use CodeIgniter\HTTP\ResponseInterface;
use Throwable;

/**
 * REST API v1 Account & System Health Controller.
 * Provides diagnostics, connected WhatsApp Business Account (WABA) details,
 * and API key profile information.
 */
class AccountController extends BaseV1Controller
{
    /**
     * Account and integration status overview.
     * GET /api/v1/account
     */
    public function index(): ResponseInterface
    {
        $settings = new SettingsService();
        $provider = $settings->getWhatsAppProvider();

        $wabaInfo = [
            'provider' => $provider,
            'status'   => 'unconfigured',
        ];

        try {
            if ($provider === SettingsService::PROVIDER_META) {
                $meta = $settings->getMetaConfig();
                $hasToken = ! empty($meta['access_token']);
                $hasPhone = ! empty($meta['phone_number_id']);

                $wabaInfo['waba_id']         = $meta['waba_id'] ?? null;
                $wabaInfo['phone_number_id'] = $meta['phone_number_id'] ?? null;
                $wabaInfo['status']          = ($hasToken && $hasPhone) ? 'connected' : 'incomplete_config';

                // Try fetching live phone number info if credentials exist
                if ($hasToken && $hasPhone) {
                    try {
                        $wa = service('whatsApp');
                        $driver = $wa->getDriver();
                        if (method_exists($driver, 'getPhoneNumberInfo')) {
                            $phoneDetails = $driver->getPhoneNumberInfo();
                            $wabaInfo['display_phone_number'] = $phoneDetails['display_phone_number'] ?? null;
                            $wabaInfo['verified_name']        = $phoneDetails['verified_name'] ?? null;
                            $wabaInfo['quality_rating']       = $phoneDetails['quality_rating'] ?? null;
                            $wabaInfo['messaging_limit']      = $phoneDetails['messaging_limit'] ?? null;
                        }
                    } catch (Throwable) {
                        // Keep soft failure for live lookup without crashing the account endpoint
                    }
                }
            } else {
                $cheerio = $settings->getCheerioConfig();
                $hasKey  = ! empty($cheerio['api_key']);
                $hasApp  = ! empty($cheerio['app_id']);

                $wabaInfo['app_id'] = $cheerio['app_id'] ?? null;
                $wabaInfo['status'] = ($hasKey && $hasApp) ? 'connected' : 'incomplete_config';
            }
        } catch (Throwable $e) {
            $wabaInfo['status'] = 'error';
            $wabaInfo['error']  = $e->getMessage();
        }

        // Token and User details from session
        $apiUserId = (int) (session()->get('api_user_id') ?? 0);
        $tokenId   = (int) (session()->get('api_token_id') ?? 0);
        $user      = session()->get('api_user') ?? [];

        $tokenData = null;
        if ($tokenId > 0) {
            $tokenRow = model(ApiTokenModel::class)->find($tokenId);
            if ($tokenRow) {
                $tokenData = [
                    'id'           => (int) $tokenRow['id'],
                    'name'         => $tokenRow['name'],
                    'abilities'    => $tokenRow['abilities'] ?? ['*'],
                    'created_at'   => $tokenRow['created_at'] ?? null,
                    'expires_at'   => $tokenRow['expires_at'] ?? null,
                    'last_used_at' => $tokenRow['last_used_at'] ?? null,
                ];
            }
        }

        return $this->respondSuccess([
            'account' => [
                'user_id'     => $apiUserId,
                'name'        => $user['name'] ?? null,
                'email'       => $user['email'] ?? null,
                'role'        => session()->get('api_role_slug') ?? 'developer',
                'permissions' => session()->get('api_permissions') ?? ['*'],
            ],
            'whatsapp' => $wabaInfo,
            'api_token' => $tokenData,
            'system' => [
                'version'     => 'v1.0.0',
                'time_utc'    => gmdate('Y-m-d H:i:s') . ' UTC',
                'environment' => ENVIRONMENT,
            ],
        ], 'Account profile and WhatsApp status retrieved.');
    }

    /**
     * API Health / Ping check.
     * GET /api/v1/health
     */
    public function health(): ResponseInterface
    {
        return $this->respondSuccess([
            'status'    => 'healthy',
            'timestamp' => time(),
            'time_utc'  => gmdate('Y-m-d H:i:s'),
            'service'   => 'Sateri Connect REST API v1',
        ], 'Service is operational.');
    }
}
