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
