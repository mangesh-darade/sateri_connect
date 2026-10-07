<?php

declare(strict_types=1);

namespace App\Libraries;

use Config\Database as DatabaseConfig;
use Config\Tenancy;
use Throwable;

/**
 * Read/write routing data in sateri_master.
 */
class MasterTenantRepository
{
    public function updateTenantName(string $key, string $name): bool
    {
        $key  = strtolower(trim($key));
        $name = trim($name);
        if ($key === '' || $name === '') {
            return false;
        }

        try {
            return (bool) $this->db()->table('tenants')->where('key', $key)->update([
                'name'       => $name,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (Throwable $e) {
            log_message('error', 'MasterTenantRepository::updateTenantName failed: {msg}', ['msg' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findPlatformAdminByEmail(string $email): ?array
    {
        $email = strtolower(trim($email));
        if ($email === '') {
            return null;
        }

        try {
            $row = $this->db()->table('platform_admins')->where('email', $email)->get()->getRowArray();
        } catch (Throwable $e) {
            log_message('error', 'MasterTenantRepository::findPlatformAdminByEmail failed: {msg}', ['msg' => $e->getMessage()]);

            return null;
        }

        return is_array($row) ? $row : null;
    }

    public function ensurePlatformAdmin(string $email, string $password, string $name = 'Platform Super Admin'): bool
    {
        $email = strtolower(trim($email));
        if ($email === '' || $password === '') {
            return false;
        }

        try {
            $db  = $this->db();
            $now = date('Y-m-d H:i:s');
            $existing = $db->table('platform_admins')->where('email', $email)->get()->getRowArray();
            $hash = password_hash($password, PASSWORD_DEFAULT);
            if (is_array($existing)) {
                return (bool) $db->table('platform_admins')->where('email', $email)->update([
                    'password'   => $hash,
                    'name'       => $name,
                    'status'     => 'active',
                    'updated_at' => $now,
                ]);
            }

            return (bool) $db->table('platform_admins')->insert([
                'email'      => $email,
                'password'   => $hash,
                'name'       => $name,
                'status'     => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } catch (Throwable $e) {
            log_message('error', 'MasterTenantRepository::ensurePlatformAdmin failed: {msg}', ['msg' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findTenant(string $key): ?array
    {
        $key = strtolower(trim($key));
        if ($key === '') {
            return null;
        }

        try {
            $row = $this->db()->table('tenants')->where('key', $key)->get()->getRowArray();
        } catch (Throwable $e) {
            log_message('error', 'MasterTenantRepository::findTenant failed: {msg}', ['msg' => $e->getMessage()]);

            return null;
        }

        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findActiveTenant(string $key): ?array
    {
        $row = $this->findTenant($key);
        if ($row === null) {
            return null;
        }

        return strtolower((string) ($row['status'] ?? '')) === 'active' ? $row : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listActiveTenants(): array
    {
        try {
            $rows = $this->db()->table('tenants')
                ->where('status', 'active')
                ->orderBy('name', 'ASC')
                ->get()
                ->getResultArray();

            return is_array($rows) ? $rows : [];
        } catch (Throwable $e) {
            log_message('error', 'MasterTenantRepository::listActiveTenants failed: {msg}', ['msg' => $e->getMessage()]);

            return [];
        }
    }

    public function countActiveTenants(): int
    {
        try {
            return (int) $this->db()->table('tenants')->where('status', 'active')->countAllResults();
        } catch (Throwable) {
            return 0;
        }
    }

    public function findTenantKeyByEmail(string $email): ?string
    {
        $email = strtolower(trim($email));
        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        try {
            $row = $this->db()->table('tenant_login_index')->where('email', $email)->get()->getRowArray();
        } catch (Throwable $e) {
            log_message('error', 'MasterTenantRepository::findTenantKeyByEmail failed: {msg}', ['msg' => $e->getMessage()]);

            return null;
        }

        if (! is_array($row)) {
            return null;
        }

        $key = strtolower(trim((string) ($row['tenant_key'] ?? '')));

        return $key !== '' ? $key : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findPhoneRoute(string $phoneNumberId): ?array
    {
        $phoneNumberId = trim($phoneNumberId);
        if ($phoneNumberId === '') {
            return null;
        }

        try {
            $row = $this->db()->table('tenant_phone_routes')
                ->where('phone_number_id', $phoneNumberId)
                ->get()
                ->getRowArray();
        } catch (Throwable $e) {
            log_message('error', 'MasterTenantRepository::findPhoneRoute failed: {msg}', ['msg' => $e->getMessage()]);

            return null;
        }

        return is_array($row) ? $row : null;
    }

    /**
     * @return list<string>
     */
    public function allVerifyTokens(): array
    {
        $tokens = [];

        try {
            $rows = $this->db()->table('tenant_phone_routes')
                ->select('verify_token')
                ->where('verify_token IS NOT NULL', null, false)
                ->where("TRIM(verify_token) <> ''", null, false)
                ->get()
                ->getResultArray();
            foreach ($rows as $row) {
                $t = trim((string) ($row['verify_token'] ?? ''));
                if ($t !== '') {
                    $tokens[] = $t;
                }
            }

            $platform = $this->db()->table('platform_settings')
                ->where('key', 'webhook_verify_token')
                ->get()
                ->getRowArray();
            if (is_array($platform)) {
                $t = trim((string) ($platform['value'] ?? ''));
                if ($t !== '') {
                    $tokens[] = $t;
                }
            }

            $envToken = trim((string) env('meta.webhookVerifyToken', env('META_WEBHOOK_VERIFY_TOKEN', '')));
            if ($envToken !== '') {
                $tokens[] = $envToken;
            }
        } catch (Throwable $e) {
            log_message('debug', 'MasterTenantRepository::allVerifyTokens: {msg}', ['msg' => $e->getMessage()]);
        }

        return array_values(array_unique($tokens));
    }

    /**
     * Secrets allowed to sign a webhook for one phone number: that route's own secret plus
     * platform-level Meta secrets — never another tenant's secret.
     *
     * @return list<string>
     */
    public function appSecretsForPhoneNumber(string $phoneNumberId): array
    {
        $secrets = [];

        try {
            if ($phoneNumberId !== '') {
                $row = $this->db()->table('tenant_phone_routes')
                    ->select('app_secret')
                    ->where('phone_number_id', $phoneNumberId)
                    ->get()
                    ->getRowArray();
                $s = $this->decryptSecret((string) ($row['app_secret'] ?? ''));
                if ($s !== '') {
                    $secrets[] = $s;
                }
            }

            $platformRows = $this->db()->table('platform_settings')
                ->whereIn('key', ['meta_tech_app_secret', 'webhook_app_secret'])
                ->get()
                ->getResultArray();
            foreach ($platformRows as $pRow) {
                $s = $this->decryptSecret((string) ($pRow['value'] ?? ''));
                if ($s !== '') {
                    $secrets[] = $s;
                }
            }

            $envSecret = trim((string) env('meta.techAppSecret', env('META_TECH_APP_SECRET', '')));
            if ($envSecret !== '') {
                $secrets[] = $envSecret;
            }
        } catch (Throwable $e) {
            log_message('debug', 'MasterTenantRepository::appSecretsForPhoneNumber: {msg}', ['msg' => $e->getMessage()]);
        }

        return array_values(array_unique($secrets));
    }

    /**
     * @return list<string>
     */
    public function allAppSecrets(): array
    {
        $secrets = [];

        try {
            $rows = $this->db()->table('tenant_phone_routes')
                ->select('app_secret')
                ->where('app_secret IS NOT NULL', null, false)
                ->where("TRIM(app_secret) <> ''", null, false)
                ->get()
                ->getResultArray();
            foreach ($rows as $row) {
                $s = $this->decryptSecret((string) ($row['app_secret'] ?? ''));
                if ($s !== '') {
                    $secrets[] = $s;
                }
            }

            $platformRows = $this->db()->table('platform_settings')
                ->whereIn('key', ['meta_tech_app_secret', 'webhook_app_secret'])
                ->get()
                ->getResultArray();
            foreach ($platformRows as $pRow) {
                $s = $this->decryptSecret((string) ($pRow['value'] ?? ''));
                if ($s !== '') {
                    $secrets[] = $s;
                }
            }

            $envSecret = trim((string) env('meta.techAppSecret', env('META_TECH_APP_SECRET', '')));
            if ($envSecret !== '') {
                $secrets[] = $envSecret;
            }
        } catch (Throwable $e) {
            log_message('debug', 'MasterTenantRepository::allAppSecrets: {msg}', ['msg' => $e->getMessage()]);
        }

        return array_values(array_unique($secrets));
    }

    public function upsertLoginIndex(string $email, string $tenantKey): bool
    {
        $email     = strtolower(trim($email));
        $tenantKey = strtolower(trim($tenantKey));
        if ($email === '' || $tenantKey === '') {
            return false;
        }

        try {
            $db  = $this->db();
            $now = date('Y-m-d H:i:s');
            $existing = $db->table('tenant_login_index')->where('email', $email)->get()->getRowArray();
            if (is_array($existing)) {
                return (bool) $db->table('tenant_login_index')->where('email', $email)->update([
                    'tenant_key' => $tenantKey,
                    'updated_at' => $now,
                ]);
            }

            return (bool) $db->table('tenant_login_index')->insert([
                'email'      => $email,
                'tenant_key' => $tenantKey,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } catch (Throwable $e) {
            log_message('error', 'MasterTenantRepository::upsertLoginIndex failed: {msg}', ['msg' => $e->getMessage()]);

            return false;
        }
    }

    public function removeLoginIndex(string $email): void
    {
        $email = strtolower(trim($email));
        if ($email === '') {
            return;
        }

        try {
            $this->db()->table('tenant_login_index')->where('email', $email)->delete();
        } catch (Throwable $e) {
            log_message('error', 'MasterTenantRepository::removeLoginIndex failed: {msg}', ['msg' => $e->getMessage()]);
        }
    }

    public function removeLoginIndexForTenantEmail(string $tenantKey, string $email): void
    {
        $email     = strtolower(trim($email));
        $tenantKey = strtolower(trim($tenantKey));
        if ($email === '' || $tenantKey === '') {
            return;
        }

        try {
            $this->db()->table('tenant_login_index')
                ->where('email', $email)
                ->where('tenant_key', $tenantKey)
                ->delete();
        } catch (Throwable $e) {
            log_message('error', 'MasterTenantRepository::removeLoginIndexForTenantEmail failed: {msg}', ['msg' => $e->getMessage()]);
        }
    }

    public function upsertPhoneRoute(
        string $phoneNumberId,
        string $tenantKey,
        ?string $appSecret = null,
        ?string $verifyToken = null
    ): bool {
        $phoneNumberId = trim($phoneNumberId);
        $tenantKey     = strtolower(trim($tenantKey));
        if ($phoneNumberId === '' || $tenantKey === '') {
            return false;
        }

        try {
            $db  = $this->db();
            $now = date('Y-m-d H:i:s');
            $data = [
                'tenant_key' => $tenantKey,
                'updated_at' => $now,
            ];
            if ($appSecret !== null && $appSecret !== '' && ! str_contains($appSecret, '•')) {
                $data['app_secret'] = (new EncryptionService())->encryptIfNeeded($appSecret);
            }
            if ($verifyToken !== null) {
                $data['verify_token'] = $verifyToken;
            }

            $existing = $db->table('tenant_phone_routes')
                ->where('phone_number_id', $phoneNumberId)
                ->get()
                ->getRowArray();

            if (is_array($existing)) {
                return (bool) $db->table('tenant_phone_routes')
                    ->where('phone_number_id', $phoneNumberId)
                    ->update($data);
            }

            $data['phone_number_id'] = $phoneNumberId;
            $data['created_at']      = $now;

            return (bool) $db->table('tenant_phone_routes')->insert($data);
        } catch (Throwable $e) {
            log_message('error', 'MasterTenantRepository::upsertPhoneRoute failed: {msg}', ['msg' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    public function upsertTenant(array $data): bool
    {
        $key = strtolower(trim((string) ($data['key'] ?? '')));
        if ($key === '') {
            return false;
        }

        try {
            $db  = $this->db();
            $now = date('Y-m-d H:i:s');
            $password = (string) ($data['db_password'] ?? '');
            if ($password !== '' && ! str_starts_with($password, 'enc:')) {
                $password = (new EncryptionService())->encryptIfNeeded($password);
            }

            $row = [
                'key'         => $key,
                'name'        => (string) ($data['name'] ?? $key),
                'db_hostname' => (string) ($data['db_hostname'] ?? 'localhost'),
                'db_username' => (string) ($data['db_username'] ?? 'root'),
                'db_password' => $password,
                'db_database' => (string) ($data['db_database'] ?? ''),
                'db_port'     => (int) ($data['db_port'] ?? 3306),
                'status'      => (string) ($data['status'] ?? 'active'),
                'updated_at'  => $now,
            ];

            $existing = $db->table('tenants')->where('key', $key)->get()->getRowArray();
            if (is_array($existing)) {
                if ($password === '') {
                    unset($row['db_password']);
                }

                return (bool) $db->table('tenants')->where('key', $key)->update($row);
            }

            $row['created_at'] = $now;

            return (bool) $db->table('tenants')->insert($row);
        } catch (Throwable $e) {
            log_message('error', 'MasterTenantRepository::upsertTenant failed: {msg}', ['msg' => $e->getMessage()]);

            return false;
        }
    }

    public function decryptTenantPassword(string $stored): string
    {
        return $this->decryptSecret($stored);
    }

    protected function decryptSecret(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        try {
            return (new EncryptionService())->decryptIfNeeded($value);
        } catch (Throwable) {
            return $value;
        }
    }

    protected function encryptSecret(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        try {
            return (new EncryptionService())->encrypt($value);
        } catch (Throwable) {
            return $value;
        }
    }

    public function getPlatformSetting(string $key, string $default = ''): string
    {
        $key = trim($key);
        if ($key === '' || ! self::masterConfigured()) {
            return $default;
        }

        try {
            $row = $this->db()->table('platform_settings')
                ->where('key', $key)
                ->get()
                ->getRowArray();
            if (! is_array($row)) {
                return $default;
            }

            return (string) ($row['value'] ?? $default);
        } catch (Throwable $e) {
            log_message('debug', 'getPlatformSetting failed: {msg}', ['msg' => $e->getMessage()]);

            return $default;
        }
    }

    public function setPlatformSetting(string $key, string $value, bool $encrypt = false): void
    {
        $key = trim($key);
        if ($key === '' || ! self::masterConfigured()) {
            return;
        }

        $store = $encrypt ? $this->encryptSecret($value) : $value;
        $now   = date('Y-m-d H:i:s');
        $db    = $this->db();
        $existing = $db->table('platform_settings')->where('key', $key)->get()->getRowArray();
        if (is_array($existing)) {
            $db->table('platform_settings')->where('key', $key)->update([
                'value'      => $store,
                'updated_at' => $now,
            ]);
        } else {
            $db->table('platform_settings')->insert([
                'key'        => $key,
                'value'      => $store,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * Platform Super Admin branding (site name, tagline, logo, favicon, powered by).
     *
     * @return array{site_name: string, site_tagline: string, site_logo: string, site_favicon: string, logo_url: string, favicon_url: string, powered_by_enabled: bool, powered_by_name: string, powered_by_url: string, powered_by_logo: string, powered_by_logo_url: string}
     */
    public function getPlatformBranding(): array
    {
        $siteName         = $this->getPlatformSetting('platform_site_name', 'Sateri Platform');
        $siteTagline      = $this->getPlatformSetting('platform_site_tagline', 'Super Admin Console');
        $logo             = $this->getPlatformSetting('platform_logo', '');
        $favicon          = $this->getPlatformSetting('platform_favicon', '');
        $poweredByEnabled = $this->getPlatformSetting('platform_powered_by_enabled', '1');
        $poweredByName    = $this->getPlatformSetting('platform_powered_by_name', 'Sateri Technologies');
        $poweredByUrl     = $this->getPlatformSetting('platform_powered_by_url', 'https://sateritechnologies.com');
        $poweredByLogo    = $this->getPlatformSetting('platform_powered_by_logo', '');

        $logoUrl = '';
        if ($logo !== '') {
            $logoUrl = base_url(ltrim($logo, '/'));
        }
        $faviconUrl = '';
        if ($favicon !== '') {
            $faviconUrl = base_url(ltrim($favicon, '/'));
        }
        $poweredByLogoUrl = '';
        if ($poweredByLogo !== '') {
            $poweredByLogoUrl = base_url(ltrim($poweredByLogo, '/'));
        }

        return [
            'site_name'           => $siteName !== '' ? $siteName : 'Sateri Platform',
            'site_tagline'        => $siteTagline !== '' ? $siteTagline : 'Super Admin Console',
            'site_logo'           => $logo,
            'site_favicon'        => $favicon,
            'logo_url'            => $logoUrl,
            'favicon_url'         => $faviconUrl,
            'powered_by_enabled'  => $poweredByEnabled === '1',
            'powered_by_name'     => $poweredByName !== '' ? $poweredByName : 'Sateri Technologies',
            'powered_by_url'      => $poweredByUrl !== '' ? $poweredByUrl : 'https://sateritechnologies.com',
            'powered_by_logo'     => $poweredByLogo,
            'powered_by_logo_url' => $poweredByLogoUrl,
        ];
    }

    /**
     * Shared Meta Tech Provider app used for Embedded Signup across all tenants.
     *
     * @return array{app_id: string, config_id: string, app_secret: string, api_version: string, ready: bool, source: string}
     */
    public function getPlatformMetaTechProvider(): array
    {
        $appId      = '';
        $configId   = '';
        $appSecret  = '';
        $apiVersion = 'v25.0';
        $source     = 'none';

        $webhookVerifyToken = '';
        if (self::masterConfigured()) {
            $appId              = trim($this->getPlatformSetting('meta_tech_app_id'));
            $configId           = trim($this->getPlatformSetting('meta_tech_config_id'));
            $appSecret          = trim($this->decryptSecret($this->getPlatformSetting('meta_tech_app_secret')));
            $apiVersion         = trim($this->getPlatformSetting('meta_tech_api_version', 'v25.0')) ?: 'v25.0';
            $webhookVerifyToken = trim($this->getPlatformSetting('webhook_verify_token'));
            if ($appId !== '' || $configId !== '') {
                $source = 'platform';
            }
        }

        if ($appId === '') {
            $appId = trim((string) env('meta.techAppId', env('META_TECH_APP_ID', '')));
            if ($appId !== '') {
                $source = 'env';
            }
        }
        if ($configId === '') {
            $configId = trim((string) env('meta.techConfigId', env('META_TECH_CONFIG_ID', '')));
            if ($configId !== '') {
                $source = $source === 'none' ? 'env' : $source;
            }
        }
        if ($appSecret === '') {
            $appSecret = trim((string) env('meta.techAppSecret', env('META_TECH_APP_SECRET', '')));
        }
        $envVer = trim((string) env('meta.techApiVersion', env('META_TECH_API_VERSION', '')));
        if ($envVer !== '') {
            $apiVersion = $envVer;
        }
        if ($webhookVerifyToken === '') {
            $webhookVerifyToken = trim((string) env('meta.webhookVerifyToken', env('META_WEBHOOK_VERIFY_TOKEN', '')));
        }

        return [
            'app_id'               => $appId,
            'config_id'            => $configId,
            'app_secret'           => $appSecret,
            'api_version'          => $apiVersion,
            'webhook_verify_token' => $webhookVerifyToken,
            'ready'                => $appId !== '' && $configId !== '' && $appSecret !== '',
            'source'               => $source,
        ];
    }

    /**
     * @param array{app_id?: string, config_id?: string, app_secret?: string, api_version?: string, webhook_verify_token?: string} $data
     */
    public function setPlatformMetaTechProvider(array $data): void
    {
        if (! self::masterConfigured()) {
            throw new \RuntimeException('Master database is not configured.');
        }

        if (array_key_exists('app_id', $data)) {
            $this->setPlatformSetting('meta_tech_app_id', trim((string) $data['app_id']));
        }
        if (array_key_exists('config_id', $data)) {
            $this->setPlatformSetting('meta_tech_config_id', trim((string) $data['config_id']));
        }
        if (array_key_exists('api_version', $data)) {
            $ver = trim((string) $data['api_version']);
            $this->setPlatformSetting('meta_tech_api_version', $ver !== '' ? $ver : 'v25.0');
        }
        if (array_key_exists('webhook_verify_token', $data)) {
            $this->setPlatformSetting('webhook_verify_token', trim((string) $data['webhook_verify_token']));
        }
        if (array_key_exists('app_secret', $data)) {
            $secret = trim((string) $data['app_secret']);
            if ($secret !== '' && ! str_contains($secret, '•')) {
                $this->setPlatformSetting('meta_tech_app_secret', $secret, true);
            }
        }
    }

    /**
     * @return \CodeIgniter\Database\BaseConnection
     */
    protected function db()
    {
        return self::masterConnection();
    }

    /**
     * Connect to sateri_master without config(Database)::connect('master'),
     * which re-enters Config\Database construction during boot.
     *
     * @return \CodeIgniter\Database\BaseConnection
     */
    public static function masterConnection()
    {
        static $conn = null;
        if ($conn !== null) {
            return $conn;
        }

        $tenancy = config(Tenancy::class);
        $cfg     = SubdomainDatabase::defaultConnection();
        $cfg['hostname'] = $tenancy->masterHostname;
        $cfg['username'] = $tenancy->masterUsername;
        $cfg['password'] = $tenancy->masterPassword;
        $cfg['database'] = $tenancy->masterDatabase;
        $cfg['DBDriver'] = 'MySQLi';
        $cfg['port']     = $tenancy->masterPort;
        $cfg['DBDebug']  = \ENVIRONMENT !== 'production';

        $conn = DatabaseConfig::connect($cfg, false);

        return $conn;
    }

    public static function masterConfigured(): bool
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }

        try {
            $tenancy = config(Tenancy::class);
            if (trim($tenancy->masterDatabase) === '') {
                return $cached = false;
            }
            $db = self::masterConnection();
            $db->query('SELECT 1');

            return $cached = $db->tableExists('tenants');
        } catch (Throwable) {
            return $cached = false;
        }
    }
}
