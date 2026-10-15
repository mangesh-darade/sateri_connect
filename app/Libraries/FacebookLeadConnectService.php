<?php

declare(strict_types=1);

namespace App\Libraries;

use Config\Services;
use RuntimeException;
use Throwable;

/**
 * Facebook Login for the Lead workflow: profile, business portfolio, ad account, then Page.
 * The Page token is saved in Settings so lead webhooks can load the form answers.
 */
class FacebookLeadConnectService
{
    private const SESSION_TOKEN = 'fb_lead_user_token';

    private const SESSION_PAGES = 'fb_lead_profile_pages';

    public function appId(): string
    {
        return $this->credentials()['app_id'];
    }

    public function apiVersion(): string
    {
        return $this->credentials()['version'];
    }

    /**
     * Exchange the browser login token and list business portfolios.
     *
     * @return array{user_name: string, businesses: list<array{id: string, name: string}>, profile_pages: int}
     */
    public function start(string $userToken): array
    {
        $userToken = $this->assertUserToken($userToken);

        $long  = $this->exchangeToken($userToken, $this->requireCredentials());
        session()->set(self::SESSION_TOKEN, $long);

        $me = $this->graph('me', ['fields' => 'id,name'], $long);
        $businesses = $this->listBusinesses($long);
        $profilePages = $this->listProfilePages($long);
        session()->set(self::SESSION_PAGES, $profilePages);

        return [
            'user_name'     => trim((string) ($me['name'] ?? '')),
            'businesses'    => $businesses,
            'profile_pages' => count($profilePages),
        ];
    }

    /**
     * @return list<array{id: string, name: string}>
     */
    public function adAccounts(string $businessId): array
    {
        $businessId = trim($businessId);
        if ($businessId === 'profile') {
            return [['id' => 'none', 'name' => 'No ad account']];
        }
        $this->assertId($businessId, 'Portfolio');

        $token = $this->userToken();
        $rows  = array_merge(
            $this->collect($businessId . '/owned_ad_accounts', ['fields' => 'id,name,account_id', 'limit' => '100'], $token),
            $this->collect($businessId . '/client_ad_accounts', ['fields' => 'id,name,account_id', 'limit' => '100'], $token)
        );

        return $this->uniqueOptions($rows, static function (array $row): array {
            $id = trim((string) ($row['id'] ?? ''));
            if ($id !== '' && ! str_starts_with($id, 'act_')) {
                $id = 'act_' . $id;
            }

            return [
                'id'   => $id,
                'name' => trim((string) ($row['name'] ?? $id)),
            ];
        });
    }

    /**
     * @return list<array{id: string, name: string}>
     */
    public function pages(string $businessId, string $adAccountId): array
    {
        $businessId  = trim($businessId);
        $adAccountId = trim($adAccountId);
        $token       = $this->userToken();

        if ($businessId === 'profile') {
            $stored = session(self::SESSION_PAGES);
            $stored = is_array($stored) ? $stored : [];

            return $this->uniqueOptions($stored, static fn (array $row): array => [
                'id'   => trim((string) ($row['id'] ?? '')),
                'name' => trim((string) ($row['name'] ?? '')),
            ]);
        }

        $this->assertId($businessId, 'Portfolio');
        $rows = array_merge(
            $this->collect($businessId . '/owned_pages', ['fields' => 'id,name', 'limit' => '100'], $token),
            $this->collect($businessId . '/client_pages', ['fields' => 'id,name', 'limit' => '100'], $token)
        );

        if ($adAccountId !== '' && $adAccountId !== 'none') {
            $act = $this->assertAdAccount($adAccountId);
            try {
                $rows = array_merge($rows, $this->collect($act . '/promote_pages', ['fields' => 'id,name', 'limit' => '100'], $token));
            } catch (Throwable) {
                // Some ad accounts cannot list promote_pages. Business pages still work.
            }
        }

        return $this->uniqueOptions($rows, static fn (array $row): array => [
            'id'   => trim((string) ($row['id'] ?? '')),
            'name' => trim((string) ($row['name'] ?? '')),
        ]);
    }

    /**
     * Save the Page token and return that Page's lead forms.
     *
     * @return array{page_id: string, page_name: string, forms: list<array{id: string, name: string}>, warning: string}
     */
    public function connectPage(string $pageId, string $businessId, string $adAccountId): array
    {
        $this->assertId($pageId, 'Lead page');
        $token = $this->userToken();
        $page  = $this->graph($pageId, ['fields' => 'id,name,access_token'], $token);
        $pageToken = trim((string) ($page['access_token'] ?? ''));
        $pageName  = $this->safeLabel((string) ($page['name'] ?? ''), $pageId);
        if ($pageToken === '') {
            throw new RuntimeException('Facebook did not give a Page token. Login again and allow access to this Page.');
        }

        $settings = new SettingsService();
        $payload  = [
            'page_id'           => $pageId,
            'page_access_token' => $pageToken,
        ];
        if ($businessId !== '' && $businessId !== 'profile') {
            $this->assertId($businessId, 'Portfolio');
            $payload['business_id'] = $businessId;
        }
        $settings->setMetaConfig($payload);

        $warning = '';
        try {
            (new MetaPageMessagingAPI())->ensureLeadgenSubscription();
        } catch (Throwable $e) {
            $warning = 'Page saved, but the lead webhook could not be subscribed yet. ' . $e->getMessage();
            log_message('warning', 'Facebook lead page subscribe failed: {msg}', ['msg' => $e->getMessage()]);
        }

        $forms = [];
        try {
            $rows = $this->collect($pageId . '/leadgen_forms', ['fields' => 'id,name,status', 'limit' => '100'], $pageToken);
            foreach ($rows as $row) {
                $status = strtoupper(trim((string) ($row['status'] ?? 'ACTIVE')));
                if ($status !== '' && $status !== 'ACTIVE') {
                    continue;
                }
                $id = trim((string) ($row['id'] ?? ''));
                if ($id === '') {
                    continue;
                }
                if (preg_match('/^\d+$/', $id) !== 1) {
                    continue;
                }
                $forms[] = [
                    'id'   => $id,
                    'name' => $this->safeLabel((string) ($row['name'] ?? ''), $id),
                ];
            }
        } catch (Throwable $e) {
            if ($warning === '') {
                $warning = 'Page connected. Lead forms could not be listed yet. ' . $e->getMessage();
            }
        }

        log_activity('update', 'automations', 'Connected Facebook lead page ' . $pageName, [
            'page_id'       => $pageId,
            'business_id'   => $businessId,
            'ad_account_id' => $adAccountId,
            'forms'         => count($forms),
        ]);

        return [
            'page_id'   => $pageId,
            'page_name' => $pageName,
            'forms'     => $forms,
            'warning'   => $warning,
        ];
    }

    /**
     * @return array{app_id: string, secret: string, version: string}
     */
    protected function credentials(): array
    {
        $meta    = (new SettingsService())->getMetaConfig();
        $appId   = trim((string) ($meta['app_id'] ?? ''));
        $secret  = trim((string) ($meta['app_secret'] ?? ''));
        $version = $this->apiVersionOrDefault((string) ($meta['api_version'] ?? ''));

        if ($appId === '' || $secret === '') {
            $appId  = '';
            $secret = '';
            if (MasterTenantRepository::masterConfigured()) {
                try {
                    $tech    = (new MasterTenantRepository())->getPlatformMetaTechProvider();
                    $appId   = trim((string) ($tech['app_id'] ?? ''));
                    $secret  = trim((string) ($tech['app_secret'] ?? ''));
                    $techVer = trim((string) ($tech['api_version'] ?? ''));
                    $version = $this->apiVersionOrDefault($techVer !== '' ? $techVer : $version);
                } catch (Throwable) {
                    $appId  = '';
                    $secret = '';
                }
            }
        }

        return [
            'app_id'  => $appId,
            'secret'  => $secret,
            'version' => $version !== '' ? $version : 'v21.0',
        ];
    }

    /**
     * @return array{app_id: string, secret: string, version: string}
     */
    protected function requireCredentials(): array
    {
        $creds = $this->credentials();
        if ($creds['app_id'] === '' || $creds['secret'] === '') {
            throw new RuntimeException('Set the Meta App ID and App Secret in Settings before connecting Facebook.');
        }

        return $creds;
    }

    protected function exchangeToken(string $userToken, array $creds): string
    {
        $url = 'https://graph.facebook.com/' . $creds['version'] . '/oauth/access_token';

        try {
            $client = Services::curlrequest([
                'timeout'     => 20,
                'http_errors' => false,
            ], null, null, false);
            $response = $client->post($url, [
                'form_params' => [
                    'grant_type'        => 'fb_exchange_token',
                    'client_id'         => $creds['app_id'],
                    'client_secret'     => $creds['secret'],
                    'fb_exchange_token' => $userToken,
                ],
            ]);
            $decoded = json_decode((string) $response->getBody(), true);
            $long = is_array($decoded) ? trim((string) ($decoded['access_token'] ?? '')) : '';
            if ($long !== '') {
                return $long;
            }
            $message = is_array($decoded['error'] ?? null) ? (string) ($decoded['error']['message'] ?? '') : '';
            if ($message !== '') {
                log_message('warning', 'Facebook token exchange failed, using the login token: {msg}', ['msg' => $message]);
            }
        } catch (Throwable $e) {
            log_message('warning', 'Facebook token exchange failed, using the login token: {msg}', ['msg' => $e->getMessage()]);
        }

        return $userToken;
    }

    /**
     * @return list<array{id: string, name: string}>
     */
    protected function listBusinesses(string $token): array
    {
        $rows = $this->collect('me/businesses', ['fields' => 'id,name', 'limit' => '100'], $token);

        return $this->uniqueOptions($rows, static fn (array $row): array => [
            'id'   => trim((string) ($row['id'] ?? '')),
            'name' => trim((string) ($row['name'] ?? '')),
        ]);
    }

    /**
     * @return list<array{id: string, name: string}>
     */
    protected function listProfilePages(string $token): array
    {
        $rows = $this->collect('me/accounts', ['fields' => 'id,name', 'limit' => '100'], $token);

        return $this->uniqueOptions($rows, static fn (array $row): array => [
            'id'   => trim((string) ($row['id'] ?? '')),
            'name' => trim((string) ($row['name'] ?? '')),
        ]);
    }

    protected function userToken(): string
    {
        $token = trim((string) (session(self::SESSION_TOKEN) ?: ''));
        if ($token === '') {
            throw new RuntimeException('Login with Facebook again, then pick the portfolio.');
        }

        return $token;
    }

    protected function assertId(string $id, string $label): void
    {
        if (preg_match('/^\d{5,32}$/', $id) !== 1) {
            throw new RuntimeException($label . ' is not a valid Facebook id.');
        }
    }

    protected function assertAdAccount(string $id): string
    {
        $id = trim($id);
        if (preg_match('/^\d{5,32}$/', $id) === 1) {
            return 'act_' . $id;
        }
        if (preg_match('/^act_\d{5,32}$/', $id) === 1) {
            return $id;
        }

        throw new RuntimeException('Ad account is not a valid Facebook id.');
    }

    protected function assertUserToken(string $token): string
    {
        $token = trim($token);
        $length = strlen($token);
        if ($length < 20 || $length > 4096 || preg_match('/^[A-Za-z0-9_\-\.]+$/', $token) !== 1) {
            throw new RuntimeException('Facebook login token is not valid. Login again.');
        }

        return $token;
    }

    protected function apiVersionOrDefault(string $version): string
    {
        $version = trim($version);

        return preg_match('/^v\d{1,3}\.\d{1,3}$/', $version) === 1 ? $version : 'v21.0';
    }

    protected function safeLabel(string $label, string $fallback): string
    {
        $label = trim(strip_tags($label));
        if ($label === '') {
            return $fallback;
        }

        return mb_substr($label, 0, 150);
    }

    /**
     * @param array<string, scalar> $query
     *
     * @return array<string, mixed>
     */
    protected function graph(string $path, array $query, string $token): array
    {
        $creds = $this->credentials();
        $path  = ltrim($path, '/');
        if (preg_match('#^[A-Za-z0-9_./-]+$#', $path) !== 1 || str_contains($path, '..')) {
            throw new RuntimeException('Facebook request was rejected.');
        }
        $url = 'https://graph.facebook.com/' . $creds['version'] . '/' . $path;
        $headers = ['Accept' => 'application/json'];
        if ($token !== '') {
            $headers['Authorization'] = 'Bearer ' . $token;
        }

        try {
            $client = Services::curlrequest([
                'timeout'     => 20,
                'http_errors' => false,
            ], null, null, false);
            $response = $client->get($url, ['query' => $query, 'headers' => $headers]);
        } catch (Throwable $e) {
            throw new RuntimeException('Facebook could not be reached. Try again.');
        }

        $decoded = json_decode((string) $response->getBody(), true);
        if (! is_array($decoded)) {
            throw new RuntimeException('Facebook returned an unexpected response.');
        }
        if ($response->getStatusCode() >= 400 || isset($decoded['error'])) {
            $message = is_array($decoded['error'] ?? null)
                ? (string) ($decoded['error']['message'] ?? 'Facebook request failed.')
                : 'Facebook request failed.';
            throw new RuntimeException(mb_substr($message, 0, 300));
        }

        return $decoded;
    }

    /**
     * @param array<string, scalar> $query
     *
     * @return list<array<string, mixed>>
     */
    protected function collect(string $path, array $query, string $token): array
    {
        $rows = [];
        $page = 0;
        $next = null;

        do {
            $decoded = $next === null
                ? $this->graph($path, $query, $token)
                : $this->graphUrl($next);
            $batch = $decoded['data'] ?? [];
            if (is_array($batch)) {
                foreach ($batch as $row) {
                    if (is_array($row)) {
                        $rows[] = $row;
                    }
                }
            }
            $next = is_array($decoded['paging'] ?? null) ? ($decoded['paging']['next'] ?? null) : null;
            $next = is_string($next) && $next !== '' ? $next : null;
            $page++;
        } while ($next !== null && $page < 5);

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    protected function graphUrl(string $url): array
    {
        $parts = parse_url($url);
        $host  = strtolower((string) ($parts['host'] ?? ''));
        if (($parts['scheme'] ?? '') !== 'https' || $host !== 'graph.facebook.com') {
            throw new RuntimeException('Facebook returned an unexpected page link.');
        }

        try {
            $client = Services::curlrequest([
                'timeout'     => 20,
                'http_errors' => false,
            ], null, null, false);
            $response = $client->get($url);
        } catch (Throwable) {
            throw new RuntimeException('Facebook could not be reached. Try again.');
        }

        $decoded = json_decode((string) $response->getBody(), true);
        if (! is_array($decoded)) {
            throw new RuntimeException('Facebook returned an unexpected response.');
        }
        if ($response->getStatusCode() >= 400 || isset($decoded['error'])) {
            $message = is_array($decoded['error'] ?? null)
                ? (string) ($decoded['error']['message'] ?? 'Facebook request failed.')
                : 'Facebook request failed.';
            throw new RuntimeException(mb_substr($message, 0, 300));
        }

        return $decoded;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param callable(array<string, mixed>): array{id: string, name: string} $map
     *
     * @return list<array{id: string, name: string}>
     */
    protected function uniqueOptions(array $rows, callable $map): array
    {
        $out = [];
        $seen = [];
        foreach ($rows as $row) {
            $item = $map($row);
            $id   = trim((string) ($item['id'] ?? ''));
            $name = $this->safeLabel((string) ($item['name'] ?? ''), $id);
            if ($id === '' || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $out[] = ['id' => $id, 'name' => $name !== '' ? $name : $id];
        }

        return $out;
    }
}
