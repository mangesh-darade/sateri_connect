<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Libraries\MasterTenantRepository;
use App\Libraries\PlatformStatsService;
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
        $dash = (new PlatformStatsService())->dashboard();

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
        $tech = $repo->getPlatformMetaTechProvider();
        $tech['app_secret'] = $this->maskSecret((string) ($tech['app_secret'] ?? ''));

        $baseUrl = rtrim(site_url(), '/');

        return view('platform/meta_tech', [
            'pageTitle'          => 'Embedded Signup (Tech Provider)',
            'navActive'          => 'meta-tech',
            'tech'               => $tech,
            'platformName'       => (string) session('platform_admin_name'),
            'sdkOrigin'          => $baseUrl,
            'webhookUrl'         => site_url('webhooks'),
            'webhookVerifyToken' => (string) ($tech['webhook_verify_token'] ?? ''),
            'privacyUrl'         => site_url('privacy-policy'),
            'termsUrl'           => site_url('terms'),
            'dataDeletionUrl'    => site_url('data-deletion'),
        ]);
    }

    public function saveMetaTech(): ResponseInterface
    {
        $repo = new MasterTenantRepository();
        try {
            $repo->setPlatformMetaTechProvider([
                'app_id'               => (string) $this->request->getPost('app_id'),
                'config_id'            => (string) $this->request->getPost('config_id'),
                'app_secret'           => (string) $this->request->getPost('app_secret'),
                'api_version'          => (string) ($this->request->getPost('api_version') ?: 'v25.0'),
                'webhook_verify_token' => (string) $this->request->getPost('webhook_verify_token'),
            ]);
        } catch (\Throwable $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->to('/platform/meta-tech')->with('success', 'Tech Provider Embedded Signup credentials saved. Clients can Connect WhatsApp without creating their own Meta app.');
    }

    /**
     * Live test of Meta Tech Provider credentials against Meta Graph API.
     */
    public function testMetaTech(): ResponseInterface
    {
        $repo = new MasterTenantRepository();
        $tech = $repo->getPlatformMetaTechProvider();

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
                'message' => 'Failed to reach Meta servers: ' . $e->getMessage(),
            ]);
        }
    }

    public function store(): ResponseInterface
    {
        $result = (new TenantProvisionService())->provision([
            'key'            => (string) $this->request->getPost('key'),
            'name'           => (string) $this->request->getPost('name'),
            'database'       => (string) $this->request->getPost('database'),
            'hostname'       => (string) ($this->request->getPost('hostname') ?: 'localhost'),
            'username'       => (string) ($this->request->getPost('username') ?: 'root'),
            'password'       => (string) $this->request->getPost('db_password'),
            'port'           => (int) ($this->request->getPost('port') ?: 3306),
            'admin_email'    => (string) $this->request->getPost('admin_email'),
            'admin_password' => (string) $this->request->getPost('admin_password'),
            'admin_name'     => (string) ($this->request->getPost('admin_name') ?: 'Admin'),
        ]);

        if (! ($result['ok'] ?? false)) {
            return redirect()->back()->withInput()->with('error', (string) ($result['message'] ?? 'Failed'));
        }

        $msg = (string) ($result['message'] ?? 'Created');
        if (! empty($result['admin_email'])) {
            $msg .= ' · Login: ' . $result['admin_email'];
            if (! empty($result['admin_password'])) {
                $msg .= ' / ' . $result['admin_password'];
            }
        }

        return redirect()->to('/platform/clients/' . rawurlencode((string) $result['key']))
            ->with('success', $msg);
    }

    public function show(string $key): string|ResponseInterface
    {
        $key = strtolower(trim($key));
        $repo = new MasterTenantRepository();
        $tenant = $repo->findActiveTenant($key);
        if ($tenant === null) {
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

        if ($connected) {
            $settings = new \App\Libraries\SettingsService();
            $meta     = $settings->getMetaConfig();
            $metaDisplay = $meta;
            $metaDisplay['access_token'] = $this->maskSecret((string) ($meta['access_token'] ?? ''));
            $metaDisplay['app_secret']   = $this->maskSecret((string) ($meta['app_secret'] ?? ''));
            $appName = (string) $settings->get('app_name', $tenant['name'] ?? $key);
        }

        return view('platform/clients/show', [
            'pageTitle'    => (string) ($tenant['name'] ?? $key),
            'navActive'    => 'clients',
            'tenant'       => $tenant,
            'stats'        => $stats,
            'meta'         => $metaDisplay,
            'adminEmail'   => (string) ($stats['admin_email'] ?? ''),
            'adminName'    => (string) ($stats['admin_name'] ?? ''),
            'appName'      => $appName,
            'platformName' => (string) session('platform_admin_name'),
        ]);
    }

    public function saveMeta(string $key): ResponseInterface
    {
        $key = strtolower(trim($key));
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
        $password = trim((string) $this->request->getPost('admin_password'));
        $result = (new TenantProvisionService())->setClientLogin(
            $key,
            (string) $this->request->getPost('admin_email'),
            $password,
            (string) ($this->request->getPost('admin_name') ?: 'Admin')
        );

        if (! ($result['ok'] ?? false)) {
            return redirect()->back()->withInput()->with('error', (string) $result['message']);
        }

        return redirect()->to('/platform/clients/' . rawurlencode($key))->with('success', (string) $result['message']);
    }

    public function enter(string $key): ResponseInterface
    {
        $key = strtolower(trim($key));
        $repo = new MasterTenantRepository();
        if ($repo->findActiveTenant($key) === null) {
            return redirect()->to('/platform/clients')->with('error', 'Client not found.');
        }

        if (! (new TenantConnection())->apply($key, 'platform')) {
            return redirect()->to('/platform/clients')->with('error', 'Cannot connect to client DB.');
        }
        TenantContext::set($key, 'platform');

        $email = '';
        try {
            $idx = MasterTenantRepository::masterConnection()
                ->table('tenant_login_index')
                ->where('tenant_key', $key)
                ->orderBy('id', 'ASC')
                ->get()
                ->getRowArray();
            $email = is_array($idx) ? (string) ($idx['email'] ?? '') : '';
        } catch (\Throwable) {
            $email = '';
        }

        if ($email === '') {
            return redirect()->to('/platform/clients/' . rawurlencode($key))
                ->with('error', 'No login email set for this client. Save login details first.');
        }

        $user = model(UserModel::class)->findByEmail($email);
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
            'tenant_key'  => $key,
            'platform_impersonating' => true,
        ]);

        return redirect()->to('/dashboard')->with('success', 'Opened workspace: ' . $key);
    }

    public function settings(): string|ResponseInterface
    {
        $repo     = new MasterTenantRepository();
        $branding = $repo->getPlatformBranding();

        return view('platform/settings', [
            'pageTitle'    => 'Platform Settings',
            'navActive'    => 'settings',
            'branding'     => $branding,
            'platformName' => (string) session('platform_admin_name'),
        ]);
    }

    public function saveSettings(): ResponseInterface
    {
        $repo = new MasterTenantRepository();

        $name    = trim((string) $this->request->getPost('site_name'));
        $tagline = trim((string) $this->request->getPost('site_tagline'));

        if ($name !== '') {
            $repo->setPlatformSetting('platform_site_name', $name);
        }
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

        // Upload new Logo / App Icon
        $logoFile = $this->request->getFile('site_logo');
        if ($logoFile !== null && $logoFile->isValid() && $logoFile->getError() !== UPLOAD_ERR_NO_FILE) {
            $newPath = $this->handlePlatformUpload($logoFile, 'platform_logo', 2 * 1024 * 1024);
            if ($newPath !== '') {
                $currentLogo = $repo->getPlatformSetting('platform_logo');
                $this->deletePlatformUpload($currentLogo);
                $repo->setPlatformSetting('platform_logo', $newPath);
            }
        }

        // Upload new Favicon
        $favFile = $this->request->getFile('site_favicon');
        if ($favFile !== null && $favFile->isValid() && $favFile->getError() !== UPLOAD_ERR_NO_FILE) {
            $newPath = $this->handlePlatformUpload($favFile, 'platform_favicon', 512 * 1024);
            if ($newPath !== '') {
                $currentFav = $repo->getPlatformSetting('platform_favicon');
                $this->deletePlatformUpload($currentFav);
                $repo->setPlatformSetting('platform_favicon', $newPath);
            }
        }

        // Upload new Powered By Logo
        $pLogoFile = $this->request->getFile('powered_by_logo');
        if ($pLogoFile !== null && $pLogoFile->isValid() && $pLogoFile->getError() !== UPLOAD_ERR_NO_FILE) {
            $newPPath = $this->handlePlatformUpload($pLogoFile, 'platform_powered_by', 1024 * 1024);
            if ($newPPath !== '') {
                $currentPLogo = $repo->getPlatformSetting('platform_powered_by_logo');
                $this->deletePlatformUpload($currentPLogo);
                $repo->setPlatformSetting('platform_powered_by_logo', $newPPath);
            }
        }

        return redirect()->to('/platform/settings')->with('success', 'Platform branding and settings updated.');
    }

    protected function handlePlatformUpload($file, string $prefix, int $maxBytes): string
    {
        $ext = strtolower((string) $file->getExtension());
        if (! in_array($ext, ['png', 'jpg', 'jpeg', 'webp', 'gif', 'ico', 'svg'], true)) {
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
