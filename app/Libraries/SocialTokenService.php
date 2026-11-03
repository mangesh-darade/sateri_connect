<?php

declare(strict_types=1);

namespace App\Libraries;

use App\Models\SocialAccountModel;
use Config\Services;
use Throwable;

/**
 * Hands the publisher an account row with a usable, decrypted access token.
 * Facebook/Instagram Page tokens do not expire. YouTube access tokens last 1 hour and are refreshed here.
 * LinkedIn tokens last ~60 days and cannot be refreshed, so an expired one marks the account for reconnect.
 */
class SocialTokenService
{
    private SocialAccountModel $accounts;
    private EncryptionService $encryption;

    public function __construct()
    {
        $this->accounts   = model(SocialAccountModel::class);
        $this->encryption = new EncryptionService();
    }

    /**
     * @param array<string, mixed> $account social_accounts row
     * @return array<string, mixed>|null account with plain access_token, or null when it must be reconnected
     */
    public function prepare(array $account): ?array
    {
        if (($account['platform'] ?? '') === 'whatsapp') {
            return $account; // uses the campaign engine, no social token
        }

        $account['access_token'] = $this->encryption->decryptIfNeeded((string) ($account['access_token'] ?? ''));
        $expires = ! empty($account['token_expires_at']) ? strtotime((string) $account['token_expires_at']) : null;
        $stale   = $expires !== null && $expires < time() + 120;
        $platform = (string) ($account['platform'] ?? '');

        if (! $stale || ! in_array($platform, ['youtube', 'linkedin'], true)) {
            return $account['access_token'] !== '' ? $account : null;
        }

        if ($platform === 'youtube' && ! empty($account['refresh_token'])) {
            $fresh = $this->refreshGoogle((int) $account['id'], $this->encryption->decryptIfNeeded((string) $account['refresh_token']));
            if ($fresh !== null) {
                $account['access_token'] = $fresh;

                return $account;
            }
        }

        $this->accounts->update((int) $account['id'], ['status' => 'expired']);
        log_activity('social_account_expired', 'social', 'Social account needs reconnecting', ['account_id' => (int) $account['id'], 'platform' => $platform]);

        return null;
    }

    private function refreshGoogle(int $accountId, string $refreshToken): ?string
    {
        try {
            $client = Services::curlrequest(['timeout' => 30, 'http_errors' => false], null, null, false);
            $res    = $client->post('https://oauth2.googleapis.com/token', ['form_params' => [
                'grant_type'    => 'refresh_token',
                'refresh_token' => $refreshToken,
                'client_id'     => (new SocialCredentials())->get('google_client_id'),
                'client_secret' => (new SocialCredentials())->get('google_client_secret'),
            ]]);
            $body = json_decode((string) $res->getBody(), true);
            if ($res->getStatusCode() !== 200 || empty($body['access_token'])) {
                log_message('error', 'SocialTokenService::refreshGoogle http=' . $res->getStatusCode());

                return null;
            }
            $this->accounts->update($accountId, [
                'access_token'     => $this->encryption->encrypt((string) $body['access_token']),
                'token_expires_at' => date('Y-m-d H:i:s', time() + (int) ($body['expires_in'] ?? 3600)),
            ]);

            return (string) $body['access_token'];
        } catch (Throwable $e) {
            log_message('error', 'SocialTokenService::refreshGoogle ' . $e::class);

            return null;
        }
    }
}
