<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Libraries\MasterTenantRepository;
use App\Libraries\PlatformStatsService;
use App\Libraries\SchemaRepairService;
use App\Libraries\TenantConnection;
use App\Libraries\TenantContext;
use App\Libraries\TenantProvisionService;
use App\Models\UserModel;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Platform super-admin: manage all clients (create, Meta, login credentials).
 */
class PlatformClients extends BaseController
{
    public function index(): string|ResponseInterface
    {
        $dash = (new PlatformStatsService())->dashboard($this->platformAdminId());

        return view('platform/clients/index', [
            'pageTitle'    => 'Platform dashboard',
            'navActive'    => 'dashboard',
            'totals'       => $dash['totals'],
            'clients'      => $dash['clients'],
            'charts'       => $dash['charts'],
            'chartsJson'   => json_encode($dash['charts'], JSON_UNESCAPED_UNICODE),
            'tenantCount'  => (int) ($dash['totals']['clients'] ?? 0),
            'platformName' => (string) session('platform_admin_name'),
        ]);
    }

    public function all(): string
    {
        $adminId = $this->platformAdminId();
        $cache   = new \App\Libraries\ClientStatsCache();
        $repo    = new MasterTenantRepository();

        // Search, filters, sorting and paging run in SQL on the stats cache; the URL carries the state.
        $get = $this->request->getGet();
        $f = ['q' => trim((string) ($get['q'] ?? ''))];
        foreach (\App\Libraries\ClientStatsCache::FILTERS as $name => $opts) {
            $v = (string) ($get[$name] ?? '');
            $f[$name] = isset($opts[$v]) ? $v : '';
        }
        $f['country'] = strtolower(trim((string) ($get['country'] ?? '')));
        $sort = isset(\App\Libraries\ClientStatsCache::SORTS[(string) ($get['sort'] ?? '')]) ? (string) $get['sort'] : 'name';
        $dir  = ($get['dir'] ?? '') === 'desc' ? 'desc' : 'asc';
        $per  = in_array((int) ($get['per'] ?? 0), [10, 25, 50, 100], true) ? (int) $get['per'] : 10;
        $page = max(1, (int) ($get['page'] ?? 1));

        // Only a few stale clients are recomputed per view; `php spark stats:refresh` (cron) keeps the rest warm.
        $cache->refreshStale($adminId);
        $keys = array_map(static fn (array $t): string => strtolower(trim((string) ($t['key'] ?? ''))), $repo->listActiveTenants($adminId));

        $res   = $cache->query($f, $sort, $dir, $page, $per, $keys);
        $pages = max(1, (int) ceil($res['total'] / $per));
        if ($page > $pages) {
            $page = $pages;
            $res  = $cache->query($f, $sort, $dir, $page, $per, $keys);
        }

        $missing = $res['wallet_missing'];

        return view('platform/clients/all', [
            'pageTitle'       => 'All clients',
            'navActive'       => 'clients',
            'clients'         => $res['rows'],
            'counts'          => $res['counts'],
            'total'           => $res['total'],
            'page'            => $page,
            'pages'           => $pages,
            'per'             => $per,
            'q'               => $f['q'],
            'f'               => array_diff_key($f, ['q' => 1, 'country' => 1]),
            'country'         => $f['country'],
            'countries'       => $res['countries'],
            'sort'            => $sort,
            'dir'             => $dir,
            'refreshedAt'     => $res['refreshed_at'],
            'walletTotal'     => $res['wallet_total'],
            'walletTotalNote' => $missing !== [] ? 'Excludes ' . implode(', ', $missing) . ' (no exchange rate)' : '',
            'platformName'    => (string) session('platform_admin_name'),
        ]);
    }

    /** "Refresh" button on All clients: recompute every client now. */
    public function refreshStats(): ResponseInterface
    {
        $res = (new \App\Libraries\ClientStatsCache())->refreshStale($this->platformAdminId(), true, 0);

        return redirect()->to(site_url('platform/clients/all'))->with('success', 'Refreshed ' . $res['refreshed'] . ' client' . ($res['refreshed'] === 1 ? '' : 's') . '.');
    }

    public function create(): string|ResponseInterface
    {
        return view('platform/clients/create', [
            'pageTitle'    => 'Create client',
            'navActive'    => 'create',
            'platformName' => (string) session('platform_admin_name'),
        ]);
    }

    public function metaTech(): string|ResponseInterface
    {
        $repo = new MasterTenantRepository();
        $tech = $repo->getPlatformMetaTechProvider($this->platformAdminId());
        $webhookTokenSet = trim((string) ($tech['webhook_verify_token'] ?? '')) !== '';
        $tech['app_secret'] = $this->maskSecret((string) ($tech['app_secret'] ?? ''));

        $baseUrl = rtrim(site_url(), '/');
        $clients = [];
        foreach ($repo->listActiveTenants($this->platformAdminId()) as $row) {
            $clients[] = [
                'key'  => (string) ($row['key'] ?? ''),
                'name' => (string) (($row['name'] ?? '') !== '' ? $row['name'] : ($row['key'] ?? '')),
            ];
        }

        return view('platform/meta_tech', [
            'pageTitle'          => 'Embedded Signup (Tech Provider)',
            'navActive'          => 'meta-tech',
            'canEditTech'        => true,
            'ownsAllClients'     => $repo->isPrimaryPlatformAdmin($this->platformAdminId()),
            'tech'               => $tech,
            'clients'            => $clients,
            'platformName'       => (string) session('platform_admin_name'),
            'sdkOrigin'          => $baseUrl,
            'webhookUrl'         => site_url('webhooks'),
            'webhookVerifyToken' => (string) ($tech['webhook_verify_token'] ?? ''),
            'webhookTokenSet'    => $webhookTokenSet,
            'privacyUrl'         => site_url('privacy-policy'),
            'termsUrl'           => site_url('terms'),
            'dataDeletionUrl'    => site_url('data-deletion'),
        ]);
    }

    /**
     * Whether a client already has WhatsApp token + WABA + phone number ID.
     */
    public function clientWhatsAppStatus(string $key): ResponseInterface
    {
        $switched = $this->switchToClient($key);
        if ($switched instanceof ResponseInterface) {
            return $switched;
        }

        $meta  = (new \App\Libraries\SettingsService(new \App\Models\SettingModel()))->getMetaConfig();
        $token = trim((string) ($meta['access_token'] ?? ''));
        $waba  = trim((string) ($meta['waba_id'] ?? ''));
        $phone = trim((string) ($meta['phone_number_id'] ?? ''));

        return $this->jsonResponse(true, [
            'tenant_key'      => strtolower(trim($key)),
            'connected'       => $token !== '' && $waba !== '' && $phone !== '',
            'waba_id'         => $waba,
            'phone_number_id' => $phone,
        ]);
    }

    /**
     * Finish Embedded Signup for one client: exchange code and store token, WABA ID, phone number ID.
     */
    public function connectEmbeddedSignup(): ResponseInterface
    {
        $key  = strtolower(trim((string) $this->request->getPost('tenant_key')));
        $code = trim((string) $this->request->getPost('code'));
        $wabaId = trim((string) $this->request->getPost('waba_id'));
        $phoneNumberId = trim((string) $this->request->getPost('phone_number_id'));
        $businessId = trim((string) $this->request->getPost('business_id'));
        $pin = trim((string) $this->request->getPost('pin'));
        $redirectUri = trim((string) $this->request->getPost('redirect_uri'));

        if ($code === '') {
            return $this->jsonResponse(false, null, 'Missing Embedded Signup auth code.', [], 422);
        }

        $tech = (new MasterTenantRepository())->getPlatformMetaTechProvider($this->platformAdminId());
        $providerCreds = [
            'app_id'      => trim((string) ($tech['app_id'] ?? '')),
            'app_secret'  => trim((string) ($tech['app_secret'] ?? '')),
            'api_version' => trim((string) ($tech['api_version'] ?? '')),
            'config_id'   => trim((string) ($tech['config_id'] ?? '')),
        ];

        $switched = $this->switchToClient($key);
        if ($switched instanceof ResponseInterface) {
            return $switched;
        }

        try {
            $result = (new \App\Libraries\MetaEmbeddedSignup(
                new \App\Libraries\SettingsService(new \App\Models\SettingModel())
            ))->complete(
                $code,
                $wabaId,
                $phoneNumberId,
                $businessId !== '' ? $businessId : null,
                preg_match('/^\d{6}$/', $pin) === 1 ? $pin : null,
                $redirectUri !== '' ? $redirectUri : null,
                $providerCreds
            );

            log_activity('update', 'platform', 'WhatsApp connected via Platform Embedded Signup for ' . $key, [
                'tenant_key'        => $key,
                'waba_id'           => $result['waba_id'] ?? '',
                'phone_number_id'   => $result['phone_number_id'] ?? '',
                'warnings'          => $result['warnings'] ?? [],
                'platform_admin_id' => (int) session('platform_admin_id'),
            ]);

            $msg = 'WhatsApp connected for ' . $key . '. Access token, WABA ID, and Phone Number ID saved.';
            $warnings = $result['warnings'] ?? [];
            if (is_array($warnings) && $warnings !== []) {
                $msg .= ' Notes: ' . implode(' · ', $warnings);
            }

            return $this->jsonResponse(true, $result, $msg);
        } catch (\Throwable $e) {
            return $this->jsonResponse(false, null, $this->safeError($e, 'Could not complete the WhatsApp connection. See the application log for details.'), [], 500);
        }
    }

    /**
     * Point the request at one client database. Returns an error response when that fails.
     */
    protected function switchToClient(string $key): ?ResponseInterface
    {
        $key = strtolower(trim($key));
        if ($key === '') {
            return $this->jsonResponse(false, null, 'Choose a client before connecting WhatsApp.', [], 422);
        }

        $repo = new MasterTenantRepository();
        if ($repo->findActiveTenant($key) === null || ! $repo->platformAdminOwnsTenant($this->platformAdminId(), $key)) {
            return $this->jsonResponse(false, null, 'Client not found.', [], 404);
        }

        if (! (new TenantConnection())->apply($key, 'platform')) {
            return $this->jsonResponse(false, null, 'Cannot connect to client database.', [], 500);
        }
        TenantContext::set($key, 'platform');

        return null;
    }

    public function saveMetaTech(): ResponseInterface
    {
        $repo = new MasterTenantRepository();
        $adminId = $this->platformAdminId();
        try {
            $leadConfigId = trim((string) $this->request->getPost('lead_config_id'));
            if ($leadConfigId !== '' && preg_match('/^\d{5,32}$/', $leadConfigId) !== 1) {
                return redirect()->back()->withInput()->with('error', 'Lead Login Config ID must be the numeric Facebook configuration ID.');
            }

            $repo->setPlatformMetaTechProvider([
                'app_id'               => (string) $this->request->getPost('app_id'),
                'config_id'            => (string) $this->request->getPost('config_id'),
                'lead_config_id'       => $leadConfigId,
                'app_secret'           => (string) $this->request->getPost('app_secret'),
                'api_version'          => (string) ($this->request->getPost('api_version') ?: 'v25.0'),
                'webhook_verify_token' => (string) $this->request->getPost('webhook_verify_token'),
            ], $adminId);
        } catch (\Throwable $e) {
            return redirect()->back()->withInput()->with('error', $this->safeError($e, 'Could not save the Meta settings. See the application log for details.'));
        }

        log_activity('update', 'platform', 'Saved this super admin Meta App ID and Embedded Signup Config ID', [
            'platform_admin_id' => $adminId,
            'has_lead_config'   => $leadConfigId !== '',
        ]);

        return redirect()->to('/platform/meta-tech')->with('success', 'Your Meta App ID and Embedded Signup Config ID are saved. Connect your clients from the list below.');
    }

    /**
     * Live test of Meta Tech Provider credentials against Meta Graph API.
     */
    public function testMetaTech(): ResponseInterface
    {
        $repo = new MasterTenantRepository();
        $tech = $repo->getPlatformMetaTechProvider($this->platformAdminId());

        $appId      = trim((string) ($this->request->getPost('app_id') ?: ($tech['app_id'] ?? '')));
        $appSecret  = trim((string) ($this->request->getPost('app_secret') ?: ''));
        $configId   = trim((string) ($this->request->getPost('config_id') ?: ($tech['config_id'] ?? '')));
        $apiVersion = trim((string) ($this->request->getPost('api_version') ?: ($tech['api_version'] ?? 'v25.0'))) ?: 'v25.0';

        // If secret was not re-typed in form, fall back to decrypted stored secret
        if ($appSecret === '' || str_contains($appSecret, '•')) {
            $appSecret = trim((string) ($tech['app_secret'] ?? ''));
        }

        if ($appId === '' || $appSecret === '') {
            return $this->response->setStatusCode(422)->setJSON([
                'status'  => 'error',
                'message' => 'App ID and App Secret are required to test the Meta connection.',
            ]);
        }

        if (preg_match('/^\d{5,32}$/', $appId) !== 1 || preg_match('/^v\d{1,2}\.\d{1,2}$/', $apiVersion) !== 1) {
            return $this->response->setStatusCode(422)->setJSON([
                'status'  => 'error',
                'message' => 'App ID must be numeric and API version must look like v25.0.',
            ]);
        }

        $appAccessToken = $appId . '|' . $appSecret;
        $url = 'https://graph.facebook.com/' . $apiVersion . '/' . $appId . '?fields=id,name,category,link&access_token=' . urlencode($appAccessToken);

        try {
            $client = \Config\Services::curlrequest([
                'timeout'         => 15,
                'http_errors'     => false,
                'connect_timeout' => 10,
                'verify'          => true,
            ]);

            $res = $client->request('GET', $url, [
                'headers' => ['Accept' => 'application/json'],
            ]);

            $status = $res->getStatusCode();
            $body   = (string) $res->getBody();
            $data   = json_decode($body, true);

            if ($status >= 200 && $status < 300 && ! empty($data['id'])) {
                $appName  = (string) ($data['name'] ?? 'Meta App');
                $category = (string) ($data['category'] ?? '');
                return $this->response->setStatusCode(200)->setJSON([
                    'status'  => 'success',
                    'message' => 'Connected successfully to Meta Graph API! App: "' . $appName . '" (ID: ' . $data['id'] . ')' . ($category !== '' ? ' · Category: ' . $category : ''),
                    'data'    => $data,
                ]);
            }

            $metaError = (string) ($data['error']['message'] ?? ('HTTP ' . $status . ': ' . $body));
            return $this->response->setStatusCode(400)->setJSON([
                'status'  => 'error',
                'message' => 'Meta API returned error: ' . $metaError,
            ]);
        } catch (\Throwable $e) {
            return $this->response->setStatusCode(500)->setJSON([
                'status'  => 'error',
                'message' => $this->safeError($e, 'Could not reach Meta right now. Check the server internet connection and try again.'),
            ]);
        }
    }

    public function store(): ResponseInterface
    {
        $dbHost = (string) ($this->request->getPost('hostname') ?: 'localhost');
        $dbUser = (string) ($this->request->getPost('username') ?: 'root');
        $dbPass = (string) $this->request->getPost('db_password');
        $dbPort = (int) ($this->request->getPost('port') ?: 3306);

        // Only the main platform admin may point a client at a custom MySQL server/account.
        // Everyone else always gets this server's own database account (no arbitrary host/port/user).
        if (! (new MasterTenantRepository())->isPrimaryPlatformAdmin($this->platformAdminId())) {
            $tenancy = config(\Config\Tenancy::class);
            $dbHost  = $tenancy->masterHostname;
            $dbUser  = $tenancy->masterUsername;
            $dbPass  = $tenancy->masterPassword;
            $dbPort  = $tenancy->masterPort;
        }

        try {
            $result = $this->provisionFromRequest($dbHost, $dbUser, $dbPass, $dbPort);
        } catch (\Throwable $e) {
            return redirect()->back()->withInput()->with('error', $this->safeError($e, 'Could not create the client. See the application log for details.'));
        }

        if (! ($result['ok'] ?? false)) {
            return redirect()->back()->withInput()->with('error', (string) ($result['message'] ?? 'Could not create the client.'));
        }

        $msg = (string) ($result['message'] ?? 'Created');
        if (! empty($result['admin_email'])) {
            $msg .= ' · Login: ' . $result['admin_email'];
        }

        return redirect()->to('/platform/clients/' . rawurlencode((string) $result['key']))
            ->with('success', $msg);
    }

    /**
     * @return array<string, mixed>
     */
    protected function provisionFromRequest(string $dbHost, string $dbUser, string $dbPass, int $dbPort): array
    {
        return (new TenantProvisionService())->provision([
            'key'            => (string) $this->request->getPost('key'),
            'name'           => (string) $this->request->getPost('name'),
            'database'       => (string) $this->request->getPost('database'),
            'hostname'       => $dbHost,
            'username'       => $dbUser,
            'password'       => $dbPass,
            'port'           => $dbPort,
            'admin_email'    => (string) $this->request->getPost('admin_email'),
            'admin_password' => (string) $this->request->getPost('admin_password'),
            'country_code'   => (string) $this->request->getPost('country_code'),
            'admin_phone'    => (string) $this->request->getPost('admin_phone'),
            'admin_name'         => (string) ($this->request->getPost('admin_name') ?: 'Admin'),
            'platform_admin_id'  => $this->platformAdminId(),
        ]);
    }

    /**
     * Log the real exception, show only a safe message: our own validation text, or the fallback for
     * database/driver errors (which carry host names, accounts and SQL).
     */
    protected function safeError(\Throwable $e, string $fallback): string
    {
        log_message('error', '[PlatformClients] {class}: {msg}', ['class' => $e::class, 'msg' => $e->getMessage()]);

        if ($e instanceof \CodeIgniter\Database\Exceptions\DatabaseException || $e instanceof \mysqli_sql_exception) {
            return $fallback;
        }

        $msg = trim($e->getMessage());

        return ($msg !== '' && mb_strlen($msg) <= 200 && ! preg_match('/SQLSTATE|mysql|`|SELECT|INSERT|UPDATE/i', $msg)) ? $msg : $fallback;
    }

    public function show(string $key): string|ResponseInterface
    {
        $key = strtolower(trim($key));
        $repo = new MasterTenantRepository();
        $tenant = $repo->findActiveTenant($key);
        if ($tenant === null || ! $repo->platformAdminOwnsTenant($this->platformAdminId(), $key)) {
            return redirect()->to('/platform/clients')->with('error', 'Client not found.');
        }

        $connected = (new TenantConnection())->apply($key, 'platform');
        if ($connected) {
            TenantContext::set($key, 'platform');
        }

        $stats = (new PlatformStatsService())->clientDeep($tenant, $connected);

        $metaDisplay = [
            'app_id'          => '',
            'waba_id'         => '',
            'phone_number_id' => '',
            'access_token'    => '',
            'app_secret'      => '',
            'verify_token'    => '',
            'business_id'     => '',
        ];
        $appName = (string) ($tenant['name'] ?? $key);
        $plan = new \App\Libraries\TenantPlanLimits();
        $caps = $plan->limitsFor($key);
        $seats = [
            'users'     => (int) ($stats['users'] ?? 0),
            'roles'     => 0,
            'max_users' => $caps['max_users'],
            'max_roles' => $caps['max_roles'],
        ];

        if ($connected) {
            $settings = new \App\Libraries\SettingsService();
            $meta     = $settings->getMetaConfig();
            $metaDisplay = $meta;
            $metaDisplay['access_token'] = $this->maskSecret((string) ($meta['access_token'] ?? ''));
            $metaDisplay['app_secret']   = $this->maskSecret((string) ($meta['app_secret'] ?? ''));
            $appName = (string) $settings->get('app_name', $tenant['name'] ?? $key);
            $seats['users'] = $plan->userCount();
            $seats['roles'] = $plan->roleCount();
        }

        return view('platform/clients/show', [
            'pageTitle'    => (string) ($tenant['name'] ?? $key),
            'canAssignOwner' => $repo->isPrimaryPlatformAdmin($this->platformAdminId()),
            'platformAdmins' => $repo->listPlatformAdmins(),
            'navActive'    => 'clients',
            'tenant'       => $tenant,
            'stats'        => $stats,
            'meta'         => $metaDisplay,
            'adminEmail'   => (string) ($stats['admin_email'] ?? ''),
            'adminName'    => (string) ($stats['admin_name'] ?? ''),
            'adminPhone'   => (string) ($stats['admin_phone'] ?? ''),
            'appName'      => $appName,
            'platformName' => (string) session('platform_admin_name'),
            'seats'        => $seats,
        ]);
    }

    public function saveMeta(string $key): ResponseInterface
    {
        $key = strtolower(trim($key));
        $repo = new MasterTenantRepository();
        if ($repo->findActiveTenant($key) === null || ! $repo->platformAdminOwnsTenant($this->platformAdminId(), $key)) {
            return redirect()->to('/platform/clients')->with('error', 'Client not found.');
        }
        $meta = [
            'app_name'        => trim((string) $this->request->getPost('app_name')),
            'app_id'          => trim((string) $this->request->getPost('app_id')),
            'waba_id'         => trim((string) $this->request->getPost('waba_id')),
            'phone_number_id' => trim((string) $this->request->getPost('phone_number_id')),
            'access_token'    => trim((string) $this->request->getPost('access_token')),
            'app_secret'      => trim((string) $this->request->getPost('app_secret')),
            'verify_token'    => trim((string) $this->request->getPost('verify_token')),
            'business_id'     => trim((string) $this->request->getPost('business_id')),
        ];

        // Keep existing secrets if masked / blank.
        if ($meta['access_token'] === '' || str_contains($meta['access_token'], '•')) {
            unset($meta['access_token']);
        }
        if ($meta['app_secret'] === '' || str_contains($meta['app_secret'], '•')) {
            unset($meta['app_secret']);
        }

        $result = (new TenantProvisionService())->saveClientMeta($key, $meta);
        if (! ($result['ok'] ?? false)) {
            return redirect()->back()->withInput()->with('error', (string) $result['message']);
        }

        return redirect()->to('/platform/clients/' . rawurlencode($key))->with('success', (string) $result['message']);
    }

    public function saveLogin(string $key): ResponseInterface
    {
        $key = strtolower(trim($key));
        $repo = new MasterTenantRepository();
        if ($repo->findActiveTenant($key) === null || ! $repo->platformAdminOwnsTenant($this->platformAdminId(), $key)) {
            return redirect()->to('/platform/clients')->with('error', 'Client not found.');
        }
        $password = trim((string) $this->request->getPost('admin_password'));

        // Mobile: optional on edit (older clients have none). When given it must fit the chosen country.
        $phoneFull = null;
        $phoneIn   = trim((string) $this->request->getPost('admin_phone'));
        if ($phoneIn !== '') {
            $phone = \App\Libraries\WhatsAppCountries::validatePhone((string) $this->request->getPost('phone_country'), $phoneIn);
            if (! $phone['ok']) {
                return redirect()->to('/platform/clients/' . rawurlencode($key) . '#access')->withInput()->with('error', $phone['message']);
            }
            $phoneFull = $phone['full'];
        }

        $result = (new TenantProvisionService())->setClientLogin(
            $key,
            (string) $this->request->getPost('admin_email'),
            $password,
            (string) ($this->request->getPost('admin_name') ?: 'Admin'),
            $phoneFull
        );

        if (! ($result['ok'] ?? false)) {
            return redirect()->back()->withInput()->with('error', (string) $result['message']);
        }

        log_activity('update', 'platform', 'Platform admin updated client login: ' . $key, [
            'tenant_key'        => $key,
            'admin_email'       => (string) $this->request->getPost('admin_email'),
            'password_changed'  => $password !== '',
            'phone_changed'     => $phoneFull !== null,
            'platform_admin_id' => (int) session('platform_admin_id'),
        ]);

        return redirect()->to('/platform/clients/' . rawurlencode($key))->with('success', (string) $result['message']);
    }

    /**
     * Change the client's country. The wallet currency follows it while the wallet is unused.
     */
    public function saveCountry(string $key): ResponseInterface
    {
        $key  = strtolower(trim($key));
        $repo = new MasterTenantRepository();
        if ($repo->findActiveTenant($key) === null || ! $repo->platformAdminOwnsTenant($this->platformAdminId(), $key)) {
            return redirect()->to('/platform/clients')->with('error', 'Client not found.');
        }

        $country = strtolower(trim((string) $this->request->getPost('country_code')));
        if (\App\Libraries\WhatsAppCountries::nameOf($country) === '' || $country === 'other') {
            return redirect()->back()->with('error', 'Choose a country.');
        }
        if (! $repo->setTenantCountry($key, $country)) {
            return redirect()->back()->with('error', 'Could not save the country.');
        }

        $msg = 'Country saved.';
        if ((new TenantConnection())->apply($key, 'platform')) {
            TenantContext::set($key, 'platform');
            $wallet = new \App\Libraries\WalletService();
            $result = $wallet->setCurrency((new \App\Libraries\WalletRateCard())->currencyForCountry($country), true);
            $msg .= $result['ok'] ? ' Wallet currency is ' . $wallet->currency() . '.' : ' ' . $result['message'];
        }

        log_activity('update', 'platform', 'Platform admin set client country: ' . $key, [
            'tenant_key'        => $key,
            'country_code'      => $country,
            'platform_admin_id' => $this->platformAdminId(),
        ]);

        try {
            $row = $repo->findActiveTenant($key);
            if (is_array($row)) {
                (new \App\Libraries\ClientStatsCache())->refresh($row);
            }
        } catch (\Throwable $e) {
            log_message('warning', 'Stats cache refresh after country change failed: {msg}', ['msg' => $e->getMessage()]);
        }

        return redirect()->to('/platform/clients/' . rawurlencode($key))->with('success', $msg);
    }

    public function saveOwner(string $key): ResponseInterface
    {
        $key = strtolower(trim($key));
        $repo = new MasterTenantRepository();
        if (! $repo->isPrimaryPlatformAdmin($this->platformAdminId())) {
            return redirect()->to('/platform/clients')->with('error', 'Only the main platform super admin can move a client.');
        }
        if ($repo->findActiveTenant($key) === null) {
            return redirect()->to('/platform/clients')->with('error', 'Client not found.');
        }

        $ownerId = (int) $this->request->getPost('platform_admin_id');
        if (! $repo->assignTenantOwner($key, $ownerId)) {
            return redirect()->back()->with('error', 'Could not assign this client.');
        }

        log_activity('update', 'platform', 'Platform admin assigned client owner: ' . $key, [
            'tenant_key'        => $key,
            'platform_admin_id' => $ownerId,
        ]);

        return redirect()->to('/platform/clients/' . rawurlencode($key))
            ->with('success', 'Client owner updated. That super admin can now see and connect this client.');
    }

    public function saveLimits(string $key): ResponseInterface
    {
        $key = strtolower(trim($key));
        $limits = new \App\Libraries\TenantPlanLimits();
        $repo = new MasterTenantRepository();
        if ($repo->findActiveTenant($key) === null || ! $repo->platformAdminOwnsTenant($this->platformAdminId(), $key)) {
            return redirect()->to('/platform/clients')->with('error', 'Client not found.');
        }

        $maxUsers = $this->optionalLimit('max_users');
        $maxRoles = $this->optionalLimit('max_roles');
        if ($maxUsers === false || $maxRoles === false) {
            return redirect()->back()->withInput()->with('error', 'User and role limits must be a number of at least 1, or left empty for no limit.');
        }

        if (! $limits->save($key, $maxUsers, $maxRoles)) {
            return redirect()->back()->withInput()->with('error', 'Could not save workspace limits.');
        }

        log_activity('update', 'platform', 'Platform admin set workspace limits: ' . $key, [
            'tenant_key'        => $key,
            'max_users'         => $maxUsers,
            'max_roles'         => $maxRoles,
            'platform_admin_id' => (int) session('platform_admin_id'),
        ]);

        return redirect()->to('/platform/clients/' . rawurlencode($key) . '#limits')
            ->with('success', 'Workspace limits saved.');
    }

    /**
     * @return int|null|false null = unlimited, false = invalid
     */
    protected function optionalLimit(string $field): int|null|false
    {
        $raw = trim((string) $this->request->getPost($field));
        if ($raw === '') {
            return null;
        }
        if (! ctype_digit($raw) || (int) $raw < 1) {
            return false;
        }

        return (int) $raw;
    }

    public function enter(string $key): ResponseInterface
    {
        $key = strtolower(trim($key));
        $repo = new MasterTenantRepository();
        if ($repo->findActiveTenant($key) === null || ! $repo->platformAdminOwnsTenant($this->platformAdminId(), $key)) {
            return redirect()->to('/platform/clients')->with('error', 'Client not found.');
        }

        if (! (new TenantConnection())->apply($key, 'platform')) {
            return redirect()->to('/platform/clients')->with('error', 'Cannot connect to client DB.');
        }
        TenantContext::set($key, 'platform');

        $emails = [];
        try {
            $rows = MasterTenantRepository::masterConnection()
                ->table('tenant_login_index')
                ->where('tenant_key', $key)
                ->orderBy('id', 'ASC')
                ->get()
                ->getResultArray();
            foreach ($rows as $row) {
                $e = trim((string) ($row['email'] ?? ''));
                if ($e !== '') {
                    $emails[] = $e;
                }
            }
        } catch (\Throwable) {
            $emails = [];
        }

        if ($emails === []) {
            return redirect()->to('/platform/clients/' . rawurlencode($key))
                ->with('error', 'No login email set for this client. Save login details first.');
        }

        // Index rows can be stale; use the first email that really exists as a user in this tenant DB.
        \CodeIgniter\Config\Factories::reset('models');
        $users = model(UserModel::class, false);
        $user  = null;
        foreach ($emails as $candidate) {
            $user = $users->findByEmail($candidate);
            if ($user !== null) {
                break;
            }
        }
        if ($user === null) {
            return redirect()->to('/platform/clients/' . rawurlencode($key))
                ->with('error', 'Client admin user missing.');
        }

        // Keep platform session; also open tenant workspace as client admin.
        $roleModel = model(\App\Models\RoleModel::class);
        $role = $roleModel->find((int) ($user['role_id'] ?? 0));
        session()->set([
            'user_id'     => (int) $user['id'],
            'user_name'   => (string) ($user['name'] ?? ''),
            'user_email'  => (string) ($user['email'] ?? ''),
            'user_avatar' => $user['avatar'] ?? null,
            'role_id'     => $user['role_id'] ?? null,
            'role_name'   => $role['name'] ?? 'Admin',
            'role_slug'   => $role['slug'] ?? 'super-admin',
            'permissions' => ['*'],
            'logged_in'   => true,
            'login_at'    => time(),
            'tenant_key'  => $key,
            'platform_impersonating' => true,
        ]);

        log_activity('impersonate', 'platform', 'Platform admin opened client workspace: ' . $key, [
            'tenant_key'        => $key,
            'platform_admin_id' => (int) session('platform_admin_id'),
        ], (int) $user['id']);

        return redirect()->to('/dashboard')->with('success', 'Opened workspace: ' . $key);
    }

    public function settings(): string|ResponseInterface
    {
        $repo = new MasterTenantRepository();
        if ($denied = $this->denyUnlessPrimary($repo)) {
            return $denied;
        }
        $branding     = $repo->getPlatformBranding();
        $snapshotFile = SchemaRepairService::SNAPSHOT_FILE;

        return view('platform/settings', [
            'pageTitle'        => 'Platform Settings',
            'navActive'        => 'settings',
            'branding'         => $branding,
            'platformName'     => (string) session('platform_admin_name'),
            'schemaClients'    => $repo->listActiveTenants($this->platformAdminId()),
            'schemaTableCount' => count(SchemaRepairService::snapshot()),
            'schemaSnapshotAt' => is_file($snapshotFile) ? date('d M Y, h:i A', (int) filemtime($snapshotFile)) : null,
        ]);
    }

    public function saveSettings(): ResponseInterface
    {
        $repo = new MasterTenantRepository();
        if ($denied = $this->denyUnlessPrimary($repo)) {
            return $denied;
        }
        $ajax = $this->request->isAJAX();

        $name    = trim((string) $this->request->getPost('site_name'));
        $tagline = trim((string) $this->request->getPost('site_tagline'));

        if ($name === '') {
            if ($ajax) {
                return $this->jsonResponse(false, null, 'Platform name is required.', [], 422);
            }

            return redirect()->to('/platform/settings')->with('error', 'Platform name is required.');
        }

        $repo->setPlatformSetting('platform_site_name', $name);
        $repo->setPlatformSetting('platform_site_tagline', $tagline);

        // Powered by attribution settings
        $poweredByEnabled = (string) $this->request->getPost('powered_by_enabled') === '1' ? '1' : '0';
        $repo->setPlatformSetting('platform_powered_by_enabled', $poweredByEnabled);

        $poweredByName = trim((string) $this->request->getPost('powered_by_name'));
        $repo->setPlatformSetting('platform_powered_by_name', $poweredByName !== '' ? $poweredByName : 'Sateri Technologies');

        $poweredByUrl = trim((string) $this->request->getPost('powered_by_url'));
        $repo->setPlatformSetting('platform_powered_by_url', $poweredByUrl !== '' ? $poweredByUrl : 'https://sateritechnologies.com');

        // Remove logo / favicon / powered_by_logo if requested
        if ((string) $this->request->getPost('remove_logo') === '1') {
            $currentLogo = $repo->getPlatformSetting('platform_logo');
            $this->deletePlatformUpload($currentLogo);
            $repo->setPlatformSetting('platform_logo', '');
        }
        if ((string) $this->request->getPost('remove_favicon') === '1') {
            $currentFavicon = $repo->getPlatformSetting('platform_favicon');
            $this->deletePlatformUpload($currentFavicon);
            $repo->setPlatformSetting('platform_favicon', '');
        }
        if ((string) $this->request->getPost('remove_powered_by_logo') === '1') {
            $currentPLogo = $repo->getPlatformSetting('platform_powered_by_logo');
            $this->deletePlatformUpload($currentPLogo);
            $repo->setPlatformSetting('platform_powered_by_logo', '');
        }

        $warnings = [];
        $uploads  = [
            ['site_logo', 'platform_logo', 2 * 1024 * 1024, 'Logo'],
            ['site_favicon', 'platform_favicon', 512 * 1024, 'Favicon'],
            ['powered_by_logo', 'platform_powered_by', 1024 * 1024, 'Provider icon'],
        ];
        foreach ($uploads as [$field, $settingKey, $maxBytes, $label]) {
            $stored = $this->takePlatformUpload($this->request->getFile($field), $settingKey, $maxBytes, $label);
            if ($stored['error'] !== '') {
                $warnings[] = $stored['error'];
                continue;
            }
            if ($stored['path'] === '') {
                continue;
            }
            $this->deletePlatformUpload((string) $repo->getPlatformSetting($settingKey));
            $repo->setPlatformSetting($settingKey, $stored['path']);
        }

        $branding = $repo->getPlatformBranding();
        log_activity('update', 'platform', 'Updated platform branding and settings', [
            'site_name'          => $branding['site_name'],
            'platform_admin_id'  => (int) session('platform_admin_id'),
        ]);

        $message = $warnings === []
            ? 'Settings saved.'
            : 'Settings saved. ' . implode(' ', $warnings);

        if ($ajax) {
            return $this->jsonResponse(true, ['branding' => $branding], $message);
        }

        return redirect()->to('/platform/settings')->with($warnings === [] ? 'success' : 'error', $message);
    }

    /**
     * @return array{path: string, error: string}
     */
    protected function takePlatformUpload($file, string $prefix, int $maxBytes, string $label): array
    {
        if ($file === null || $file->getError() === UPLOAD_ERR_NO_FILE) {
            return ['path' => '', 'error' => ''];
        }
        if (! $file->isValid()) {
            return ['path' => '', 'error' => $label . ' could not be uploaded.'];
        }

        $path = $this->handlePlatformUpload($file, $prefix, $maxBytes);
        if ($path !== '') {
            return ['path' => $path, 'error' => ''];
        }

        $limit = $maxBytes >= 1048576
            ? (string) (int) round($maxBytes / 1048576) . 'MB'
            : (string) (int) round($maxBytes / 1024) . 'KB';

        return ['path' => '', 'error' => $label . ' must be PNG, JPG, WEBP, GIF, or ICO, under ' . $limit . '.'];
    }

    protected function handlePlatformUpload($file, string $prefix, int $maxBytes): string
    {
        $ext = strtolower((string) $file->getExtension());
        if (! in_array($ext, ['png', 'jpg', 'jpeg', 'webp', 'gif', 'ico'], true)) {
            return '';
        }
        if ($file->getSize() > $maxBytes) {
            return '';
        }

        $dir = FCPATH . 'uploads' . DIRECTORY_SEPARATOR . 'platform' . DIRECTORY_SEPARATOR;
        if (! is_dir($dir) && ! @mkdir($dir, 0755, true)) {
            return '';
        }

        $safeExt = $ext !== '' ? $ext : 'png';
        if ($safeExt === 'jpeg') {
            $safeExt = 'jpg';
        }
        $newName = $prefix . '-' . bin2hex(random_bytes(6)) . '.' . $safeExt;
        $file->move($dir, $newName);

        return 'uploads/platform/' . $newName;
    }

    protected function deletePlatformUpload(string $relativePath): void
    {
        $relativePath = str_replace(['../', '..\\'], '', $relativePath);
        $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');
        if ($relativePath === '' || ! str_starts_with($relativePath, 'uploads/platform/')) {
            return;
        }

        $full = FCPATH . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        if (is_file($full)) {
            @unlink($full);
        }
    }

    public function clientSettings(string $key): string|ResponseInterface
    {
        return $this->runClientSettings($key, 'index');
    }

    public function clientSettingsAction(string $key, string $action): string|ResponseInterface
    {
        $map = [
            'save'                  => 'save',
            'test-smtp'             => 'testSmtp',
            'test-email'            => 'testEmail',
            'test-cheerio'          => 'testCheerio',
            'test-meta'             => 'testMeta',
            'embedded-signup'       => 'embeddedSignup',
            'test-page-messaging'   => 'testPageMessaging',
            'test-elintom'          => 'testElintOm',
            'sync-elintom'          => 'syncElintOm',
            'test-whatsapp'         => 'testCheerio',
            'setup-webhook'         => 'setupWebhook',
            'test-ai'               => 'testAi',
            'api-tokens-generate'   => 'generateApiToken',
        ];

        $method = $map[$action] ?? '';
        if ($method === '') {
            if ($this->request->isAJAX()) {
                return $this->jsonResponse(false, null, 'Unknown settings action.', [], 404);
            }

            return redirect()->to('/platform/clients/' . rawurlencode(strtolower(trim($key))))
                ->with('error', 'Unknown settings action.');
        }

        return $this->runClientSettings($key, $method);
    }

    public function clientSettingsDeleteToken(string $key, int $id): ResponseInterface
    {
        $result = $this->runClientSettings($key, 'deleteApiToken', [$id]);

        return $result instanceof ResponseInterface
            ? $result
            : $this->response->setStatusCode(500)->setJSON(['success' => false, 'message' => 'Could not revoke API key.']);
    }

    /**
     * @param list<mixed> $args
     */
    private function runClientSettings(string $key, string $method, array $args = []): string|ResponseInterface
    {
        $key = strtolower(trim($key));
        $repo = new MasterTenantRepository();
        $tenant = $repo->findActiveTenant($key);
        if ($tenant === null || ! $repo->platformAdminOwnsTenant($this->platformAdminId(), $key)) {
            if ($this->request->isAJAX()) {
                return $this->jsonResponse(false, null, 'Client not found.', [], 404);
            }

            return redirect()->to('/platform/clients')->with('error', 'Client not found.');
        }

        if (! (new TenantConnection())->apply($key, 'platform')) {
            if ($this->request->isAJAX()) {
                return $this->jsonResponse(false, null, 'Cannot connect to this client database.', [], 503);
            }

            return redirect()->to('/platform/clients/' . rawurlencode($key))
                ->with('error', 'Cannot connect to this client database.');
        }
        TenantContext::set($key, 'platform');
        \Config\Services::injectMock('settingsService', new \App\Libraries\SettingsService());

        $settings = new Settings();
        $settings->initController($this->request, $this->response, service('logger'));
        $settings->enablePlatformClientMode($key, (string) ($tenant['name'] ?? $key));

        $result = $settings->{$method}(...$args);

        return $result instanceof ResponseInterface || is_string($result)
            ? $result
            : $this->response->setStatusCode(500)->setJSON(['success' => false, 'message' => 'Settings action failed.']);
    }

    protected function platformAdminId(): int
    {
        return (int) session('platform_admin_id');
    }

    protected function denyUnlessPrimary(MasterTenantRepository $repo): ?ResponseInterface
    {
        if ($repo->isPrimaryPlatformAdmin($this->platformAdminId())) {
            return null;
        }

        return redirect()->to('/platform/clients')->with('error', 'Only the main platform super admin can change platform settings.');
    }

    protected function maskSecret(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        if (strlen($value) <= 8) {
            return str_repeat('•', strlen($value));
        }

        return substr($value, 0, 4) . str_repeat('•', max(4, strlen($value) - 8)) . substr($value, -4);
    }
}
