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
    private bool $tokenScopesRead = false;

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

    public function leadConfigId(): string
    {
        $id = trim((string) ((new SettingsService())->getMetaConfig()['lead_config_id'] ?? ''));
        if (preg_match('/^\d{5,32}$/', $id) === 1) {
            return $id;
        }

        if (! MasterTenantRepository::masterConfigured()) {
            return '';
        }

        try {
            $tech = (new MasterTenantRepository())->getPlatformMetaTechProvider();
            $id = trim((string) ($tech['lead_config_id'] ?? ''));
        } catch (Throwable) {
            return '';
        }

        return preg_match('/^\d{5,32}$/', $id) === 1 ? $id : '';
    }

    /**
     * Exchange the browser login token and list business portfolios.
     *
     * @return array{user_name: string, businesses: list<array{id: string, name: string}>, profile_pages: int, missing_permissions: list<string>}
     */
    public function start(string $userToken, string $code = '', string $redirectUri = ''): array
    {
        if (trim($code) !== '') {
            $userToken = $this->exchangeAuthorizationCode($code, $redirectUri);
        }
        $userToken = $this->assertUserToken($userToken);

        $long = $this->exchangeToken($userToken, $this->requireCredentials());
        $sessionToken = $this->tokenForLeadAds($userToken, $long);
        session()->set(self::SESSION_TOKEN, $sessionToken);

        $me = $this->graph('me', ['fields' => 'id,name'], $sessionToken);
        $businesses = $this->listBusinesses($sessionToken);
        $profilePages = $this->listProfilePages($sessionToken);
        session()->set(self::SESSION_PAGES, $profilePages);

        if ($profilePages !== []) {
            $hasProfile = false;
            foreach ($businesses as $b) {
                if (($b['id'] ?? '') === 'profile') {
                    $hasProfile = true;
                    break;
                }
            }
            if (! $hasProfile) {
                $businesses[] = ['id' => 'profile', 'name' => 'Pages on this Facebook profile'];
            }
        }

        return [
            'user_name'           => trim((string) ($me['name'] ?? '')),
            'businesses'          => $businesses,
            'profile_pages'       => count($profilePages),
            'missing_permissions' => $this->missingLeadPermissions($this->permissionStatus($sessionToken)),
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

        $options = $this->uniqueOptions($rows, static function (array $row): array {
            $id = trim((string) ($row['id'] ?? ''));
            if ($id !== '' && ! str_starts_with($id, 'act_')) {
                $id = 'act_' . $id;
            }

            return [
                'id'   => $id,
                'name' => trim((string) ($row['name'] ?? $id)),
            ];
        });

        // If the portfolio has no ad accounts, provide a fallback so the user can still select pages
        if ($options === []) {
            return [['id' => 'none', 'name' => 'No ad account (Pages only)']];
        }

        $options[] = ['id' => 'none', 'name' => 'None / All Pages (No ad account)'];

        return $options;
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

        $pageRow = $this->resolvePage($pageId, $businessId, $token);
        $tasks   = $this->taskNames($pageRow['tasks'] ?? []);
        $resolvedToken = trim((string) ($pageRow['access_token'] ?? ''));
        if ($resolvedToken !== '') {
            $pageToken = $resolvedToken;
        }
        if (trim((string) ($pageRow['name'] ?? '')) !== '') {
            $pageName = $this->safeLabel((string) $pageRow['name'], $pageId);
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
        $lastError = '';
        $tokens = array_values(array_unique(array_filter([$pageToken, $token])));
        foreach ($tokens as $tryToken) {
            try {
                $forms = $this->leadForms($pageId . '/leadgen_forms', $tryToken);
                $lastError = '';
                break;
            } catch (Throwable $e) {
                $lastError = $e->getMessage();
            }
        }
        if ($forms === [] && $lastError !== '' && $adAccountId !== '' && $adAccountId !== 'none') {
            try {
                $act = $this->assertAdAccount($adAccountId);
            } catch (Throwable) {
                $act = '';
            }
            if ($act !== '') {
                foreach ($tokens as $tryToken) {
                    try {
                        $forms = $this->leadForms($act . '/leadgen_forms', $tryToken);
                        $lastError = '';
                        break;
                    } catch (Throwable $e) {
                        $lastError = $e->getMessage();
                    }
                }
            }
        }
        if ($lastError !== '' && $warning === '') {
            $warning = $this->leadFormsWarning($lastError, $this->permissionStatus($token), $tasks);
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
     * Prefer a Page token that includes the Advertise task. Business pages often
     * omit that token on the plain Page node.
     *
     * @return array{id?: string, name?: string, access_token?: string, tasks?: list<string>}
     */
    protected function resolvePage(string $pageId, string $businessId, string $userToken): array
    {
        $fallback = null;
        foreach ($this->pageCandidates($pageId, $businessId, $userToken) as $row) {
            if (trim((string) ($row['id'] ?? '')) !== $pageId) {
                continue;
            }
            $access = trim((string) ($row['access_token'] ?? ''));
            if ($access === '') {
                continue;
            }
            $tasks = $this->taskNames($row['tasks'] ?? []);
            $row['access_token'] = $access;
            $row['tasks'] = $tasks;
            if (in_array('ADVERTISE', $tasks, true) || in_array('MANAGE', $tasks, true)) {
                return $row;
            }
            $fallback ??= $row;
        }

        return $fallback ?? ['id' => $pageId, 'access_token' => '', 'tasks' => []];
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function pageCandidates(string $pageId, string $businessId, string $userToken): array
    {
        $rows = [];
        try {
            $rows = $this->collect('me/accounts', ['fields' => 'id,name,access_token,tasks', 'limit' => '100'], $userToken);
        } catch (Throwable) {
            $rows = [];
        }

        if ($businessId !== '' && $businessId !== 'profile' && preg_match('/^\d{5,32}$/', $businessId) === 1) {
            foreach (['owned_pages', 'client_pages'] as $edge) {
                try {
                    $rows = array_merge($rows, $this->collect($businessId . '/' . $edge, ['fields' => 'id,name,access_token,tasks', 'limit' => '100'], $userToken));
                } catch (Throwable) {
                    try {
                        $rows = array_merge($rows, $this->collect($businessId . '/' . $edge, ['fields' => 'id,name,access_token', 'limit' => '100'], $userToken));
                    } catch (Throwable) {
                    }
                }
            }
        }

        try {
            $rows[] = $this->graph($pageId, ['fields' => 'id,name,access_token,tasks'], $userToken);
        } catch (Throwable) {
        }

        return $rows;
    }

    /**
     * @return list<array{id: string, name: string}>
     */
    protected function leadForms(string $path, string $token): array
    {
        $forms = [];
        foreach ($this->collect($path, ['fields' => 'id,name,status', 'limit' => '100'], $token) as $row) {
            $status = strtoupper(trim((string) ($row['status'] ?? 'ACTIVE')));
            if ($status !== '' && $status !== 'ACTIVE') {
                continue;
            }
            $id = trim((string) ($row['id'] ?? ''));
            if ($id === '' || preg_match('/^\d+$/', $id) !== 1) {
                continue;
            }
            $forms[] = [
                'id'   => $id,
                'name' => $this->safeLabel((string) ($row['name'] ?? ''), $id),
            ];
        }

        return $forms;
    }

    /**
     * @param array<string, string> $permissions
     * @param list<string> $tasks
     */
    protected function leadFormsWarning(string $graphMessage, array $permissions, array $tasks): string
    {
        if ($this->missingLeadPermissions($permissions) !== []) {
            return 'This Facebook login is still missing pages_manage_ads. Click Login with Facebook, choose Edit settings, then Save. The Lead configuration already includes that permission.';
        }
        if ($tasks !== [] && ! in_array('ADVERTISE', $tasks, true) && ! in_array('MANAGE', $tasks, true)) {
            return 'This Facebook user can see the Page, but does not have the Advertise task. In Meta Business Settings, assign the Advertise task on this Page, then log in again.';
        }

        return 'Page connected. Lead forms could not be listed yet. ' . $graphMessage;
    }

    /**
     * @return array<string, string>
     */
    protected function tokenForLeadAds(string $short, string $long): string
    {
        if ($this->hasPagesManageAds($long)) {
            return $long;
        }
        if ($short !== $long && $this->hasPagesManageAds($short)) {
            return $short;
        }

        return $long !== '' ? $long : $short;
    }

    protected function hasPagesManageAds(string $token): bool
    {
        if ($token === '') {
            return false;
        }

        $status = $this->permissionStatus($token);

        return ($status['pages_manage_ads'] ?? '') === 'granted';
    }

    protected function permissionStatus(string $token): array
    {
        $this->tokenScopesRead = false;
        $status = [];
        try {
            $rows = $this->collect('me/permissions', ['limit' => '200'], $token);
            foreach ($rows as $row) {
                $name = trim((string) ($row['permission'] ?? ''));
                if ($name === '') {
                    continue;
                }
                $status[$name] = trim((string) ($row['status'] ?? ''));
            }
        } catch (Throwable) {
            $status = [];
        }

        $scopes = $this->tokenScopes($token);
        if ($scopes !== null) {
            $this->tokenScopesRead = true;
            foreach ($scopes as $scope) {
                $status[$scope] = 'granted';
            }
        }

        return $status;
    }

    /**
     * Business Login often omits pages_manage_ads from /me/permissions and puts it on the token scopes.
     *
     * @return list<string>|null Null when Facebook did not return the token scopes.
     */
    protected function tokenScopes(string $token): ?array
    {
        try {
            $creds = $this->requireCredentials();
            $decoded = $this->graph('debug_token', ['input_token' => $token], $creds['app_id'] . '|' . $creds['secret']);
        } catch (Throwable) {
            return null;
        }

        $data = is_array($decoded['data'] ?? null) ? $decoded['data'] : [];
        $names = [];
        foreach (is_array($data['scopes'] ?? null) ? $data['scopes'] : [] as $scope) {
            if (is_string($scope) && $scope !== '') {
                $names[] = $scope;
            }
        }
        foreach (is_array($data['granular_scopes'] ?? null) ? $data['granular_scopes'] : [] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $scope = trim((string) ($row['scope'] ?? ''));
            if ($scope !== '') {
                $names[] = $scope;
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * @param array<string, string> $status
     *
     * @return list<string>
     */
    protected function missingLeadPermissions(array $status): array
    {
        if (($status['pages_manage_ads'] ?? '') === 'granted' || ! $this->tokenScopesRead) {
            return [];
        }

        return ['pages_manage_ads'];
    }

    /**
     * @return list<string>
     */
    protected function taskNames(mixed $tasks): array
    {
        if (! is_array($tasks)) {
            return [];
        }

        $names = [];
        foreach ($tasks as $task) {
            if (is_string($task) && $task !== '') {
                $names[] = strtoupper($task);
            }
        }

        return $names;
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

    protected function exchangeAuthorizationCode(string $code, string $redirectUri = ''): string
    {
        $code = trim($code);
        $length = strlen($code);
        if ($length < 20 || $length > 2048 || preg_match('/^[A-Za-z0-9_\-\.]+$/', $code) !== 1) {
            throw new RuntimeException('Facebook login code is not valid. Login again.');
        }

        $creds = $this->requireCredentials();
        $url = 'https://graph.facebook.com/' . $creds['version'] . '/oauth/access_token';

        $query = [
            'client_id'     => $creds['app_id'],
            'client_secret' => $creds['secret'],
            'code'          => $code,
        ];
        if ($redirectUri !== '') {
            $query['redirect_uri'] = $redirectUri;
        }

        try {
            $client = Services::curlrequest([
                'timeout'     => 20,
                'http_errors' => false,
            ], null, null, false);
            $response = $client->get($url, [
                'query' => $query,
            ]);
        } catch (Throwable) {
            throw new RuntimeException('Facebook could not be reached. Try again.');
        }

        $decoded = json_decode((string) $response->getBody(), true);
        $token = is_array($decoded) ? trim((string) ($decoded['access_token'] ?? '')) : '';
        if ($token !== '') {
            return $token;
        }

        $message = is_array($decoded['error'] ?? null) ? (string) ($decoded['error']['message'] ?? '') : '';
        throw new RuntimeException($message !== '' ? mb_substr($message, 0, 300) : 'Facebook login could not be completed. Login again.');
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

    /**
     * Trigger a real test lead on Meta for this specific instant form.
     * Meta generates the lead and dispatches real-time webhook to Sateri Connect.
     *
     * @return array{lead_id: string, form_id: string, page_id: string}
     */
    public function sendTestLead(string $formId, string $pageId = '', string $businessId = ''): array
    {
        $this->assertId($formId, 'Lead form');
        $pageId     = trim($pageId);
        $businessId = trim($businessId);
        if ($pageId !== '') {
            $this->assertId($pageId, 'Lead page');
        }

        $pageToken = '';
        $settings  = new SettingsService();
        $meta      = $settings->getMetaConfig();

        // 1. If page matches current settings and token is saved, use it
        if ($pageId !== '' && ($meta['page_id'] ?? '') === $pageId && ! empty($meta['page_access_token'])) {
            $pageToken = trim((string) $meta['page_access_token']);
        }

        // 2. Try resolving via user session token
        if ($pageToken === '') {
            $userToken = session()->get(self::SESSION_TOKEN);
            if ($userToken && $pageId !== '') {
                // Direct Page node query with user token
                try {
                    $pageObj = $this->graph($pageId, ['fields' => 'id,name,access_token'], $userToken);
                    $tok     = trim((string) ($pageObj['access_token'] ?? ''));
                    if ($tok !== '') {
                        $pageToken = $tok;
                    }
                } catch (\Throwable) {
                }

                // If direct query didn't give token, search portfolio/user pages
                if ($pageToken === '') {
                    try {
                        $pageRow = $this->resolvePage($pageId, $businessId, $userToken);
                        $tok     = trim((string) ($pageRow['access_token'] ?? ''));
                        if ($tok !== '') {
                            $pageToken = $tok;
                        }
                    } catch (\Throwable) {
                    }
                }

                // If resolved, update settings so future requests are instant
                if ($pageToken !== '') {
                    try {
                        $payload = [
                            'page_id'           => $pageId,
                            'page_access_token' => $pageToken,
                        ];
                        if ($businessId !== '' && $businessId !== 'profile') {
                            $payload['business_id'] = $businessId;
                        }
                        $settings->setMetaConfig($payload);
                    } catch (\Throwable) {
                    }
                }
            }
        }

        if ($pageToken === '') {
            throw new \RuntimeException(
                'Facebook authorization required for this page (' . ($pageId ?: 'selected') . '). Please click "Login with Facebook" above first, or use the Meta Lead Testing Tool below.'
            );
        }

        // Meta allows only 1 active test lead per form. Delete previous test lead if exists.
        try {
            $this->graphDelete($formId . '/test_leads', $pageToken);
        } catch (\Throwable) {
        }

        // Create new test lead on Meta
        $res = $this->graphPost($formId . '/test_leads', [], $pageToken);
        $leadId = trim((string) ($res['id'] ?? ''));
        if ($leadId === '') {
            throw new \RuntimeException('Meta did not return a lead ID.');
        }

        log_activity('create', 'automations', 'Sent Facebook test lead for form ' . $formId, [
            'lead_id' => $leadId,
            'form_id' => $formId,
            'page_id' => $pageId,
        ]);

        return [
            'lead_id' => $leadId,
            'form_id' => $formId,
            'page_id' => $pageId,
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function graphPost(string $path, array $data, string $token): array
    {
        $creds = $this->credentials();
        $path  = ltrim($path, '/');
        if (preg_match('#^[A-Za-z0-9_./-]+$#', $path) !== 1 || str_contains($path, '..')) {
            throw new \RuntimeException('Facebook request was rejected.');
        }
        $url = 'https://graph.facebook.com/' . $creds['version'] . '/' . $path;
        $headers = ['Accept' => 'application/json'];
        if ($token !== '') {
            $headers['Authorization'] = 'Bearer ' . $token;
        }

        try {
            $client = \Config\Services::curlrequest([
                'timeout'     => 20,
                'http_errors' => false,
            ], null, null, false);
            $response = $client->post($url, [
                'form_params' => $data,
                'headers'     => $headers,
            ]);
        } catch (\Throwable) {
            throw new \RuntimeException('Facebook could not be reached. Try again.');
        }

        $decoded = json_decode((string) $response->getBody(), true);
        if (! is_array($decoded)) {
            throw new \RuntimeException('Facebook returned an unexpected response.');
        }
        if ($response->getStatusCode() >= 400 || isset($decoded['error'])) {
            $message = is_array($decoded['error'] ?? null)
                ? (string) ($decoded['error']['message'] ?? 'Facebook request failed.')
                : 'Facebook request failed.';
            throw new \RuntimeException(mb_substr($message, 0, 300));
        }

        return $decoded;
    }

    /**
     * @return array<string, mixed>
     */
    protected function graphDelete(string $path, string $token): array
    {
        $creds = $this->credentials();
        $path  = ltrim($path, '/');
        if (preg_match('#^[A-Za-z0-9_./-]+$#', $path) !== 1 || str_contains($path, '..')) {
            throw new \RuntimeException('Facebook request was rejected.');
        }
        $url = 'https://graph.facebook.com/' . $creds['version'] . '/' . $path;
        $headers = ['Accept' => 'application/json'];
        if ($token !== '') {
            $headers['Authorization'] = 'Bearer ' . $token;
        }

        try {
            $client = \Config\Services::curlrequest([
                'timeout'     => 15,
                'http_errors' => false,
            ], null, null, false);
            $response = $client->delete($url, [
                'headers' => $headers,
            ]);

            return (array) json_decode((string) $response->getBody(), true);
        } catch (\Throwable) {
            return [];
        }
    }
}
