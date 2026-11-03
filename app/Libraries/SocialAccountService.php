<?php

declare(strict_types=1);

namespace App\Libraries;

use App\Models\SocialAccountModel;
use Config\Services;
use Throwable;

/**
 * Connects Facebook Pages and their linked Instagram Business accounts through Facebook Login (OAuth).
 * App credentials: `social.metaAppId` / `social.metaAppSecret` in .env (Sateri's shared app), else the tenant's Meta settings.
 * Services return ['ok','status','message','data'?] and never throw.
 */
class SocialAccountService
{
    private const API    = 'https://graph.facebook.com/v21.0';
    private const SCOPES = 'pages_show_list,pages_manage_posts,pages_read_engagement,instagram_basic,instagram_content_publish';

    private SocialAccountModel $accounts;
    private EncryptionService $encryption;

    public function __construct()
    {
        $this->accounts   = model(SocialAccountModel::class);
        $this->encryption = new EncryptionService();
    }

    public function isConfigured(): bool
    {
        return $this->appId() !== '' && $this->appSecret() !== '';
    }

    public function redirectUri(): string
    {
        return site_url('social/accounts/callback/facebook');
    }

    public function authUrl(string $state): string
    {
        return 'https://www.facebook.com/v21.0/dialog/oauth?' . http_build_query([
            'client_id'     => $this->appId(),
            'redirect_uri'  => $this->redirectUri(),
            'state'         => $state,
            'scope'         => self::SCOPES,
            'response_type' => 'code',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function handleCallback(string $code, ?int $userId): array
    {
        if (! $this->isConfigured()) {
            return $this->err(503, 'Facebook login is not set up yet.');
        }

        try {
            $short = $this->get('/oauth/access_token', [
                'client_id'     => $this->appId(),
                'client_secret' => $this->appSecret(),
                'redirect_uri'  => $this->redirectUri(),
                'code'          => $code,
            ]);
            if (empty($short['access_token'])) {
                return $this->err(502, 'Facebook did not accept the login. Please try again.');
            }

            $long = $this->get('/oauth/access_token', [
                'grant_type'        => 'fb_exchange_token',
                'client_id'         => $this->appId(),
                'client_secret'     => $this->appSecret(),
                'fb_exchange_token' => (string) $short['access_token'],
            ]);
            $userToken = (string) ($long['access_token'] ?? $short['access_token']);

            // Page tokens obtained from a long-lived user token do not expire.
            $pages = $this->get('/me/accounts', [
                'fields'       => 'id,name,access_token,instagram_business_account{id,username}',
                'limit'        => 100,
                'access_token' => $userToken,
            ]);
            $rows = is_array($pages['data'] ?? null) ? $pages['data'] : [];
            if ($rows === []) {
                return $this->err(422, 'No Facebook Pages were found. Select at least one Page when Facebook asks.');
            }

            $saved = 0;
            foreach ($rows as $p) {
                if (empty($p['id']) || empty($p['access_token'])) {
                    continue;
                }
                $token = $this->encryption->encrypt((string) $p['access_token']);
                $this->upsert('facebook', (string) $p['id'], (string) ($p['name'] ?? 'Facebook Page'), $token, $userId);
                $saved++;
                $ig = $p['instagram_business_account'] ?? null;
                if (is_array($ig) && ! empty($ig['id'])) {
                    // Instagram publishing uses the linked Page's token.
                    $this->upsert('instagram', (string) $ig['id'], '@' . ($ig['username'] ?? $ig['id']), $token, $userId);
                    $saved++;
                }
            }

            log_activity('social_accounts_connected', 'social', 'Connected ' . $saved . ' social account(s)', ['count' => $saved]);

            return ['ok' => true, 'status' => 200, 'message' => $saved . ' account(s) connected.', 'data' => ['count' => $saved]];
        } catch (Throwable $e) {
            log_message('error', 'SocialAccountService::handleCallback ' . $e::class);

            return $this->err(502, 'Could not complete the Facebook connection. Please try again.');
        }
    }

    /**
     * Connect one Page (and its Instagram account) from a pasted access token, without Facebook Login.
     * Useful for a quick test. Page tokens made from a short-lived token expire within hours, so use Facebook Login
     * for real use. Nothing is saved unless Facebook confirms the token can act on that Page.
     *
     * @return array<string, mixed>
     */
    public function connectWithToken(string $token, string $pageId, ?int $userId): array
    {
        $token  = trim($token);
        $pageId = trim($pageId);
        if ($token === '' || preg_match('/^\d{5,32}$/', $pageId) !== 1) {
            return $this->err(422, 'Enter the access token and the numeric Facebook Page ID.');
        }

        try {
            $page = $this->get('/' . $pageId, [
                'fields'       => 'id,name,access_token,instagram_business_account{id,username}',
                'access_token' => $token,
            ]);
        } catch (Throwable $e) {
            log_message('error', 'SocialAccountService::connectWithToken ' . $e::class);

            return $this->err(502, 'Could not reach Facebook. Please try again.');
        }
        if (empty($page['id'])) {
            return $this->err(422, 'Facebook did not accept this token for that Page. The token may have expired, or it lacks Page permissions.');
        }

        // A user token returns the Page token in access_token; a Page token may not, so fall back to the pasted one.
        $pageToken = $this->encryption->encrypt((string) ($page['access_token'] ?? $token));
        $saved     = 0;
        $this->upsert('facebook', (string) $page['id'], (string) ($page['name'] ?? 'Facebook Page'), $pageToken, $userId);
        $saved++;
        $ig = $page['instagram_business_account'] ?? null;
        if (is_array($ig) && ! empty($ig['id'])) {
            $this->upsert('instagram', (string) $ig['id'], '@' . ($ig['username'] ?? $ig['id']), $pageToken, $userId);
            $saved++;
        }
        log_activity('social_accounts_connected', 'social', 'Connected ' . $saved . ' social account(s) with an access token', ['count' => $saved]);

        return ['ok' => true, 'status' => 200, 'message' => $saved . ' account(s) connected (' . ($page['name'] ?? 'Page') . ').', 'data' => ['count' => $saved]];
    }

    /**
     * The WhatsApp broadcast channel is not a login: one fixed row so broadcasts can sit on the calendar like any account.
     */
    public function ensureWhatsAppChannel(): void
    {
        if ($this->accounts->where('platform', 'whatsapp')->first() === null) {
            $this->accounts->insert(['platform' => 'whatsapp', 'external_id' => 'broadcast', 'name' => 'WhatsApp broadcast', 'status' => 'active']);
        }
    }

    /**
     * @return list<array<string, mixed>> accounts without tokens
     */
    public function list(): array
    {
        $rows = $this->accounts->select('id,platform,external_id,name,status,created_at')->orderBy('platform')->orderBy('name')->findAll();

        return is_array($rows) ? $rows : [];
    }

    /**
     * @return array<string, mixed>
     */
    public function disconnect(int $id): array
    {
        $acc = $this->accounts->find($id);
        if (! is_array($acc)) {
            return $this->err(404, 'Account not found.');
        }
        $pending = model(\App\Models\SocialPostTargetModel::class)
            ->where('account_id', $id)->whereIn('status', ['scheduled', 'publishing'])->countAllResults();
        if ($pending > 0) {
            return $this->err(409, 'This account still has ' . $pending . ' scheduled post(s). Cancel them first.');
        }
        $this->accounts->delete($id);
        log_activity('social_account_disconnected', 'social', 'Disconnected ' . $acc['platform'] . ' ' . $acc['name'], ['account_id' => $id]);

        return ['ok' => true, 'status' => 200, 'message' => 'Account disconnected.'];
    }

    private function upsert(string $platform, string $externalId, string $name, string $token, ?int $userId): void
    {
        $existing = $this->accounts->where('platform', $platform)->where('external_id', $externalId)->first();
        $data     = ['name' => mb_substr($name, 0, 191), 'access_token' => $token, 'status' => 'active', 'scopes' => self::SCOPES];
        if (is_array($existing)) {
            $this->accounts->update((int) $existing['id'], $data);

            return;
        }
        $this->accounts->insert($data + ['platform' => $platform, 'external_id' => $externalId, 'created_by' => $userId]);
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    private function get(string $path, array $query): array
    {
        $client = Services::curlrequest(['timeout' => 30, 'http_errors' => false], null, null, false);
        $res    = $client->get(self::API . $path, ['query' => $query]);
        $body   = json_decode((string) $res->getBody(), true);
        if ($res->getStatusCode() >= 300 || ! is_array($body) || isset($body['error'])) {
            log_message('error', 'SocialAccountService::get ' . $path . ' http=' . $res->getStatusCode());

            return [];
        }

        return $body;
    }

    private function appId(): string
    {
        $id = (new SocialCredentials())->get('meta_app_id');

        return $id !== '' ? $id : (string) ((new SettingsService())->getMetaConfig()['app_id'] ?? '');
    }

    private function appSecret(): string
    {
        $secret = (new SocialCredentials())->get('meta_app_secret');

        return $secret !== '' ? $secret : (string) ((new SettingsService())->getMetaConfig()['app_secret'] ?? '');
    }

    /**
     * @return array<string, mixed>
     */
    private function err(int $status, string $message): array
    {
        return ['ok' => false, 'status' => $status, 'message' => $message];
    }
}
