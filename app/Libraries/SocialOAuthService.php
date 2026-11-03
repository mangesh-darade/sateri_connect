<?php

declare(strict_types=1);

namespace App\Libraries;

use App\Models\SocialAccountModel;
use Config\Services;
use Throwable;

/**
 * OAuth connect for YouTube (Google) and LinkedIn. Facebook/Instagram live in SocialAccountService.
 * Credentials come from .env: google.oauthClientId/Secret and linkedin.clientId/Secret.
 * Services return ['ok','status','message','data'?] and never throw.
 */
class SocialOAuthService
{
    public const PROVIDERS = ['youtube', 'linkedin'];

    private SocialAccountModel $accounts;
    private EncryptionService $encryption;

    public function __construct()
    {
        $this->accounts   = model(SocialAccountModel::class);
        $this->encryption = new EncryptionService();
    }

    public function isConfigured(string $provider): bool
    {
        return $this->clientId($provider) !== '' && $this->clientSecret($provider) !== '';
    }

    public function redirectUri(string $provider): string
    {
        return site_url('social/accounts/callback/' . $provider);
    }

    public function authUrl(string $provider, string $state): string
    {
        if ($provider === 'youtube') {
            return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
                'client_id'     => $this->clientId($provider),
                'redirect_uri'  => $this->redirectUri($provider),
                'response_type' => 'code',
                'scope'         => 'https://www.googleapis.com/auth/youtube.upload https://www.googleapis.com/auth/youtube.readonly https://www.googleapis.com/auth/youtube',
                'access_type'   => 'offline',
                'prompt'        => 'consent',
                'state'         => $state,
            ]);
        }

        return 'https://www.linkedin.com/oauth/v2/authorization?' . http_build_query([
            'client_id'     => $this->clientId($provider),
            'redirect_uri'  => $this->redirectUri($provider),
            'response_type' => 'code',
            'scope'         => 'openid profile w_member_social',
            'state'         => $state,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function handleCallback(string $provider, string $code, ?int $userId): array
    {
        if (! in_array($provider, self::PROVIDERS, true) || ! $this->isConfigured($provider)) {
            return $this->err(503, 'This login is not set up yet.');
        }

        try {
            $tokenUrl = $provider === 'youtube' ? 'https://oauth2.googleapis.com/token' : 'https://www.linkedin.com/oauth/v2/accessToken';
            $tok      = $this->request('POST', $tokenUrl, ['form_params' => [
                'grant_type'    => 'authorization_code',
                'code'          => $code,
                'redirect_uri'  => $this->redirectUri($provider),
                'client_id'     => $this->clientId($provider),
                'client_secret' => $this->clientSecret($provider),
            ]]);
            if (empty($tok['access_token'])) {
                return $this->err(502, 'The login was not accepted. Please try again.');
            }
            $access = (string) $tok['access_token'];

            if ($provider === 'youtube') {
                $me   = $this->request('GET', 'https://www.googleapis.com/youtube/v3/channels', ['query' => ['part' => 'snippet', 'mine' => 'true'], 'headers' => ['Authorization' => 'Bearer ' . $access]]);
                $item = $me['items'][0] ?? null;
                if (! is_array($item) || empty($item['id'])) {
                    return $this->err(422, 'This Google account has no YouTube channel.');
                }
                $externalId = (string) $item['id'];
                $name       = (string) ($item['snippet']['title'] ?? 'YouTube channel');
            } else {
                $me = $this->request('GET', 'https://api.linkedin.com/v2/userinfo', ['headers' => ['Authorization' => 'Bearer ' . $access]]);
                if (empty($me['sub'])) {
                    return $this->err(502, 'Could not read the LinkedIn profile.');
                }
                $externalId = (string) $me['sub'];
                $name       = (string) ($me['name'] ?? 'LinkedIn member');
            }

            $existing = $this->accounts->where('platform', $provider)->where('external_id', $externalId)->first();
            $data     = [
                'name'             => mb_substr($name, 0, 191),
                'access_token'     => $this->encryption->encrypt($access),
                'token_expires_at' => date('Y-m-d H:i:s', time() + (int) ($tok['expires_in'] ?? 3600)),
                'status'           => 'active',
            ];
            if (! empty($tok['refresh_token'])) {
                $data['refresh_token'] = $this->encryption->encrypt((string) $tok['refresh_token']);
            }
            if (is_array($existing)) {
                $this->accounts->update((int) $existing['id'], $data);
            } else {
                $this->accounts->insert($data + ['platform' => $provider, 'external_id' => $externalId, 'created_by' => $userId]);
            }
            log_activity('social_accounts_connected', 'social', 'Connected ' . $provider . ' account', ['platform' => $provider]);

            return ['ok' => true, 'status' => 200, 'message' => ucfirst($provider) . ' account connected.'];
        } catch (Throwable $e) {
            log_message('error', 'SocialOAuthService::handleCallback ' . $provider . ' ' . $e::class);

            return $this->err(502, 'Could not complete the connection. Please try again.');
        }
    }

    /**
     * Connect a YouTube channel from an existing refresh token (no browser login). The token must have been issued to
     * the saved Google OAuth client. The channel is read from YouTube, so nothing is saved unless Google accepts it.
     *
     * @return array<string, mixed>
     */
    public function connectYouTubeWithRefreshToken(string $refreshToken, ?int $userId): array
    {
        $refreshToken = trim($refreshToken) !== '' ? trim($refreshToken) : trim((string) env('social.youtubeRefreshToken', ''));
        if (! $this->isConfigured('youtube')) {
            return $this->err(503, 'Save the Google OAuth client ID and secret in Setup first.');
        }
        if ($refreshToken === '') {
            return $this->err(422, 'Enter the YouTube refresh token.');
        }

        try {
            $tok = $this->request('POST', 'https://oauth2.googleapis.com/token', ['form_params' => [
                'grant_type'    => 'refresh_token',
                'refresh_token' => $refreshToken,
                'client_id'     => $this->clientId('youtube'),
                'client_secret' => $this->clientSecret('youtube'),
            ]]);
            if (empty($tok['access_token'])) {
                return $this->err(422, 'Google did not accept this refresh token for the saved OAuth client.');
            }
            $me   = $this->request('GET', 'https://www.googleapis.com/youtube/v3/channels', ['query' => ['part' => 'snippet', 'mine' => 'true'], 'headers' => ['Authorization' => 'Bearer ' . $tok['access_token']]]);
            $item = $me['items'][0] ?? null;
            if (! is_array($item) || empty($item['id'])) {
                return $this->err(422, 'This Google account has no YouTube channel.');
            }

            $data = [
                'name'             => mb_substr((string) ($item['snippet']['title'] ?? 'YouTube channel'), 0, 191),
                'access_token'     => $this->encryption->encrypt((string) $tok['access_token']),
                'refresh_token'    => $this->encryption->encrypt($refreshToken),
                'token_expires_at' => date('Y-m-d H:i:s', time() + (int) ($tok['expires_in'] ?? 3600)),
                'status'           => 'active',
            ];
            $existing = $this->accounts->where('platform', 'youtube')->where('external_id', (string) $item['id'])->first();
            if (is_array($existing)) {
                $this->accounts->update((int) $existing['id'], $data);
            } else {
                $this->accounts->insert($data + ['platform' => 'youtube', 'external_id' => (string) $item['id'], 'created_by' => $userId]);
            }
            log_activity('social_accounts_connected', 'social', 'Connected a YouTube channel with a refresh token', ['platform' => 'youtube']);

            return ['ok' => true, 'status' => 200, 'message' => 'YouTube channel connected: ' . ($item['snippet']['title'] ?? $item['id']) . '.'];
        } catch (Throwable $e) {
            log_message('error', 'SocialOAuthService::connectYouTubeWithRefreshToken ' . $e::class);

            return $this->err(502, 'Could not connect the YouTube channel. Please try again.');
        }
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function request(string $method, string $url, array $options): array
    {
        $client = Services::curlrequest(['timeout' => 30, 'http_errors' => false], null, null, false);
        $res    = $client->request($method, $url, $options);
        $body   = json_decode((string) $res->getBody(), true);
        if ($res->getStatusCode() >= 300 || ! is_array($body)) {
            log_message('error', 'SocialOAuthService::request ' . parse_url($url, PHP_URL_HOST) . ' http=' . $res->getStatusCode());

            return [];
        }

        return $body;
    }

    private function clientId(string $provider): string
    {
        return (new SocialCredentials())->get($provider === 'youtube' ? 'google_client_id' : 'linkedin_client_id');
    }

    private function clientSecret(string $provider): string
    {
        return (new SocialCredentials())->get($provider === 'youtube' ? 'google_client_secret' : 'linkedin_client_secret');
    }

    /**
     * @return array<string, mixed>
     */
    private function err(int $status, string $message): array
    {
        return ['ok' => false, 'status' => $status, 'message' => $message];
    }
}
