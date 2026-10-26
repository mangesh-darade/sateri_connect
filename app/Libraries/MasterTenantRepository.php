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
    protected static bool $tenantOwnerReady = false;

    protected static bool $primaryAdminReady = false;

    protected static bool $adminMetaReady = false;
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

    /**
     * @return list<array<string, mixed>>
     */
    public function listPlatformAdmins(): array
    {
        try {
            $this->ensurePrimaryAdminColumn();
            $rows = $this->db()->table('platform_admins')
                ->select('id, email, name, status, is_primary, created_at')
                ->orderBy('is_primary', 'DESC')
                ->orderBy('name', 'ASC')
                ->get()
                ->getResultArray();
        } catch (Throwable $e) {
            log_message('error', 'MasterTenantRepository::listPlatformAdmins failed: {msg}', ['msg' => $e->getMessage()]);

            return [];
        }

        return is_array($rows) ? $rows : [];
    }

    public function createPlatformAdmin(string $email, string $password, string $name): bool
    {
        $email = strtolower(trim($email));
        $name  = trim($name);
        if ($email === '' || $password === '' || $name === '') {
            return false;
        }

        try {
            $db = $this->db();
            $existing = $db->table('platform_admins')->where('email', $email)->get()->getRowArray();
            if (is_array($existing)) {
                return false;
            }

            $now = date('Y-m-d H:i:s');

            $this->ensurePrimaryAdminColumn();

            return (bool) $db->table('platform_admins')->insert([
                'email'      => $email,
                'password'   => password_hash($password, PASSWORD_DEFAULT),
                'name'       => $name,
                'status'     => 'active',
                'is_primary' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } catch (Throwable $e) {
            log_message('error', 'MasterTenantRepository::createPlatformAdmin failed: {msg}', ['msg' => $e->getMessage()]);

            return false;
        }
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

            $this->ensurePrimaryAdminColumn();

            return (bool) $db->table('platform_admins')->insert([
                'email'      => $email,
                'password'   => $hash,
                'name'       => $name,
                'status'     => 'active',
                'is_primary' => 0,
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
    public function listActiveTenants(?int $platformAdminId = null): array
    {
        try {
            $this->ensureTenantOwnerColumn();
            $builder = $this->db()->table('tenants')
                ->where('status', 'active');
            if ($platformAdminId !== null && $platformAdminId > 0 && ! $this->isPrimaryPlatformAdmin($platformAdminId)) {
                $builder->where('platform_admin_id', $platformAdminId);
            }

            $rows = $builder->orderBy('name', 'ASC')->get()->getResultArray();

            return is_array($rows) ? $rows : [];
        } catch (Throwable $e) {
            log_message('error', 'MasterTenantRepository::listActiveTenants failed: {msg}', ['msg' => $e->getMessage()]);

            return [];
        }
    }

    /** Calling code of the client's country, '' when it was never set (older clients). */
    public function tenantCountry(string $key): string
    {
        $this->ensureTenantOwnerColumn();

        return strtolower(trim((string) ($this->findTenant($key)['country_code'] ?? '')));
    }

    public function setTenantCountry(string $key, string $countryCode): bool
    {
        $key = strtolower(trim($key));
        $countryCode = strtolower(trim($countryCode));
        if ($key === '' || $countryCode === '') {
            return false;
        }
        $this->ensureTenantOwnerColumn();

        try {
            return (bool) $this->db()->table('tenants')->where('key', $key)->update([
                'country_code' => $countryCode,
                'updated_at'   => date('Y-m-d H:i:s'),
            ]);
        } catch (Throwable $e) {
            log_message('error', 'MasterTenantRepository::setTenantCountry failed: {msg}', ['msg' => $e->getMessage()]);

            return false;
        }
    }

    public function assignTenantOwner(string $key, int $platformAdminId): bool
    {
        $key = strtolower(trim($key));
        $owner = $this->findPlatformAdminById($platformAdminId);
        if ($key === '' || $owner === null || strtolower((string) ($owner['status'] ?? '')) !== 'active') {
            return false;
        }

        $this->ensureTenantOwnerColumn();

        try {
            return $this->db()->table('tenants')->where('key', $key)->update([
                'platform_admin_id' => $platformAdminId,
                'updated_at'        => date('Y-m-d H:i:s'),
            ]) !== false;
        } catch (Throwable $e) {
            log_message('error', 'MasterTenantRepository::assignTenantOwner failed: {msg}', ['msg' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findPlatformAdminById(int $id): ?array
    {
        if ($id < 1) {
            return null;
        }

        $this->ensurePrimaryAdminColumn();

        try {
            $row = $this->db()->table('platform_admins')
                ->select('id, email, name, status, is_primary')
                ->where('id', $id)
                ->get()
                ->getRowArray();
        } catch (Throwable $e) {
            log_message('error', 'MasterTenantRepository::findPlatformAdminById failed: {msg}', ['msg' => $e->getMessage()]);

            return null;
        }

        return is_array($row) ? $row : null;
    }

    public function platformAdminOwnsTenant(int $platformAdminId, string $key): bool
    {
        if ($platformAdminId < 1) {
            return false;
        }

        $this->ensureTenantOwnerColumn();
        $row = $this->findTenant($key);
        if (! is_array($row)) {
            return false;
        }
        if ($this->isPrimaryPlatformAdmin($platformAdminId)) {
            return true;
        }

        return (int) ($row['platform_admin_id'] ?? 0) === $platformAdminId;
    }

    public function isPrimaryPlatformAdmin(int $platformAdminId): bool
    {
        if ($platformAdminId < 1) {
            return false;
        }

        $this->ensurePrimaryAdminColumn();

        try {
            $row = $this->db()->table('platform_admins')
                ->select('is_primary')
                ->where('id', $platformAdminId)
                ->get()
                ->getRowArray();
        } catch (Throwable $e) {
            log_message('error', 'MasterTenantRepository::isPrimaryPlatformAdmin failed: {msg}', ['msg' => $e->getMessage()]);

            return false;
        }

        return (int) ($row['is_primary'] ?? 0) === 1;
    }

    public function ensurePrimaryAdminColumn(): void
    {
        if (self::$primaryAdminReady) {
            return;
        }

        try {
            $db = $this->db();
            if (! $db->tableExists('platform_admins')) {
                return;
            }
            if (! $db->fieldExists('is_primary', 'platform_admins')) {
                $db->query('ALTER TABLE `platform_admins` ADD `is_primary` TINYINT UNSIGNED NOT NULL DEFAULT 0');
            }
            $this->keepSinglePrimaryAdmin();
            self::$primaryAdminReady = true;
        } catch (Throwable $e) {
            log_message('error', 'MasterTenantRepository::ensurePrimaryAdminColumn failed: {msg}', ['msg' => $e->getMessage()]);
        }
    }

    protected function keepSinglePrimaryAdmin(): void
    {
        $db = $this->db();
        $primaries = $db->table('platform_admins')
            ->select('id')
            ->where('is_primary', 1)
            ->orderBy('id', 'ASC')
            ->get()
            ->getResultArray();

        if ($primaries === []) {
            $first = $db->table('platform_admins')->select('id')->orderBy('id', 'ASC')->get(1)->getRowArray();
            $adminId = (int) ($first['id'] ?? 0);
            if ($adminId > 0) {
                $db->table('platform_admins')->where('id', $adminId)->update([
                    'is_primary' => 1,
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
            }

            return;
        }

        $keepId = (int) ($primaries[0]['id'] ?? 0);
        if (count($primaries) > 1 && $keepId > 0) {
            $db->table('platform_admins')->where('id !=', $keepId)->update([
                'is_primary' => 0,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        }
    }

    public function ensureTenantOwnerColumn(): void
    {
        if (self::$tenantOwnerReady) {
            return;
        }

        try {
            $db = $this->db();
            if (! $db->tableExists('tenants')) {
                return;
            }
            if (! $db->fieldExists('platform_admin_id', 'tenants')) {
                $db->query('ALTER TABLE `tenants` ADD `platform_admin_id` INT UNSIGNED NULL DEFAULT NULL');
                $db->query('ALTER TABLE `tenants` ADD KEY `platform_admin_id` (`platform_admin_id`)');
                $this->assignUnownedTenantsToFirstAdmin();
            }
            if (! $db->fieldExists('country_code', 'tenants')) {
                // Calling code of the client's country (see WhatsAppCountries). Drives wallet currency and timezone.
                $db->query('ALTER TABLE `tenants` ADD `country_code` VARCHAR(8) NULL DEFAULT NULL');
            }
            self::$tenantOwnerReady = true;
        } catch (Throwable $e) {
            log_message('error', 'MasterTenantRepository::ensureTenantOwnerColumn failed: {msg}', ['msg' => $e->getMessage()]);
        }
    }

    protected function assignUnownedTenantsToFirstAdmin(): void
    {
        $first = $this->db()->table('platform_admins')->orderBy('id', 'ASC')->get(1)->getRowArray();
        $adminId = (int) ($first['id'] ?? 0);
        if ($adminId < 1) {
            return;
        }

        $this->db()->table('tenants')
            ->where('platform_admin_id IS NULL', null, false)
            ->update([
                'platform_admin_id' => $adminId,
                'updated_at'        => date('Y-m-d H:i:s'),
            ]);
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

            if ($this->db()->tableExists('platform_admin_meta')) {
                $adminTokens = $this->db()->table('platform_admin_meta')
                    ->select('webhook_verify_token')
                    ->get()
                    ->getResultArray();
                foreach ($adminTokens as $adminToken) {
                    $t = trim((string) ($adminToken['webhook_verify_token'] ?? ''));
                    if ($t !== '') {
                        $tokens[] = $t;
                    }
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

            if ($this->db()->tableExists('platform_admin_meta')) {
                $adminSecrets = $this->db()->table('platform_admin_meta')
                    ->select('app_secret')
                    ->get()
                    ->getResultArray();
                foreach ($adminSecrets as $adminSecret) {
                    $s = $this->decryptSecret((string) ($adminSecret['app_secret'] ?? ''));
                    if ($s !== '') {
                        $secrets[] = $s;
                    }
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

    /**
     * True when a different, still-active tenant already owns a routing row.
     * Routing rows (login email, phone id, host) must never be silently re-pointed by a tenant,
     * otherwise one client could capture another client's logins or webhooks.
     */
    protected function claimedByAnotherTenant(string $ownerKey, string $newKey): bool
    {
        $ownerKey = strtolower(trim($ownerKey));

        return $ownerKey !== '' && $ownerKey !== $newKey && $this->findActiveTenant($ownerKey) !== null;
    }

    public function upsertLoginIndex(string $email, string $tenantKey, bool $allowReassign = false): bool
    {
        $email     = strtolower(trim($email));
        $tenantKey = strtolower(trim($tenantKey));
        if ($email === '' || $tenantKey === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        try {
            $db  = $this->db();
            $now = date('Y-m-d H:i:s');
            $existing = $db->table('tenant_login_index')->where('email', $email)->get()->getRowArray();
            if (is_array($existing)) {
                $owner = (string) ($existing['tenant_key'] ?? '');
                if (! $allowReassign && $this->claimedByAnotherTenant($owner, $tenantKey)) {
                    log_message('warning', 'Login index: email already belongs to another tenant; refusing to reassign to {key}.', ['key' => $tenantKey]);

                    return false;
                }

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
        ?string $verifyToken = null,
        bool $allowReassign = false
    ): bool {
        $phoneNumberId = trim($phoneNumberId);
        $tenantKey     = strtolower(trim($tenantKey));
        if (
            preg_match('/^[A-Za-z0-9_\-]{1,64}$/', $phoneNumberId) !== 1
            || preg_match('/^[a-z0-9_\-]{1,64}$/', $tenantKey) !== 1
        ) {
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
                if (! $allowReassign && $this->claimedByAnotherTenant((string) ($existing['tenant_key'] ?? ''), $tenantKey)) {
                    log_message('warning', 'Phone route {pnid} already belongs to another tenant; refusing to reassign to {key}.', [
                        'pnid' => $phoneNumberId,
                        'key'  => $tenantKey,
                    ]);

                    return false;
                }

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
     * Public hostname or subdomain label → tenant key (webhook / shared vanity hosts).
     *
     * @return array<string, mixed>|null
     */
    public function findHostRoute(string $host): ?array
    {
        $host = strtolower(trim($host));
        if (str_contains($host, ':')) {
            $host = explode(':', $host, 2)[0];
        }
        if ($host === '') {
            return null;
        }

        try {
            $this->ensureHostRoutesTable();
            $row = $this->db()->table('tenant_host_routes')
                ->where('host', $host)
                ->get()
                ->getRowArray();
        } catch (Throwable $e) {
            log_message('error', 'MasterTenantRepository::findHostRoute failed: {msg}', ['msg' => $e->getMessage()]);

            return null;
        }

        return is_array($row) ? $row : null;
    }

    /**
     * Hosts/labels a tenant must never be able to claim: the portal itself, IPs, and generic labels
     * that would capture unrelated traffic.
     */
    protected function isClaimableHost(string $host): bool
    {
        if (preg_match('/^[a-z0-9]([a-z0-9\-\.]{0,189}[a-z0-9])?$/', $host) !== 1 || filter_var($host, FILTER_VALIDATE_IP)) {
            return false;
        }

        $tenancy = config(Tenancy::class);
        if ($tenancy->isPortalHost($host)) {
            return false;
        }

        $reserved = ['www', 'app', 'api', 'admin', 'portal', 'connect', 'mail', 'webhook', 'webhooks', 'localhost'];
        foreach ($tenancy->portalHosts as $portalHost) {
            $reserved[] = explode('.', (string) $portalHost)[0];
        }

        return ! in_array($host, $reserved, true);
    }

    public function upsertHostRoute(string $host, string $tenantKey, bool $allowReassign = false): bool
    {
        $host      = strtolower(trim($host));
        $tenantKey = strtolower(trim($tenantKey));
        if (str_contains($host, ':')) {
            $host = explode(':', $host, 2)[0];
        }
        if (
            $host === ''
            || preg_match('/^[a-z0-9_\-]{1,64}$/', $tenantKey) !== 1
            || ! $this->isClaimableHost($host)
        ) {
            return false;
        }

        try {
            $this->ensureHostRoutesTable();
            $db  = $this->db();
            $now = date('Y-m-d H:i:s');
            $existing = $db->table('tenant_host_routes')
                ->where('host', $host)
                ->get()
                ->getRowArray();

            if (is_array($existing)) {
                if (! $allowReassign && $this->claimedByAnotherTenant((string) ($existing['tenant_key'] ?? ''), $tenantKey)) {
                    log_message('warning', 'Host route {host} already belongs to another tenant; refusing to reassign to {key}.', [
                        'host' => $host,
                        'key'  => $tenantKey,
                    ]);

                    return false;
                }

                return (bool) $db->table('tenant_host_routes')
                    ->where('host', $host)
                    ->update([
                        'tenant_key' => $tenantKey,
                        'updated_at' => $now,
                    ]);
            }

            return (bool) $db->table('tenant_host_routes')->insert([
                'host'       => $host,
                'tenant_key' => $tenantKey,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } catch (Throwable $e) {
            log_message('error', 'MasterTenantRepository::upsertHostRoute failed: {msg}', ['msg' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * Register full host + first subdomain label for a tenant's public webhook base.
     */
    public function syncHostRoutesFromPublicBase(string $publicBase, string $tenantKey): void
    {
        $tenantKey = strtolower(trim($tenantKey));
        if ($tenantKey === '') {
            return;
        }

        $publicBase = trim($publicBase);
        if ($publicBase !== '' && ! str_contains($publicBase, '://')) {
            $publicBase = 'https://' . $publicBase;
        }
        $host = strtolower(trim((string) (parse_url($publicBase, PHP_URL_HOST) ?: '')));
        if ($host === '') {
            return;
        }

        $this->upsertHostRoute($host, $tenantKey);
        $parts = explode('.', $host);
        if (count($parts) >= 3 && $parts[0] !== 'www') {
            $this->upsertHostRoute($parts[0], $tenantKey);
        }
    }

    public function ensureHostRoutesTable(): void
    {
        static $ready = false;
        if ($ready) {
            return;
        }

        try {
            $db = $this->db();
            if (! $db->tableExists('tenant_host_routes')) {
                $db->query('CREATE TABLE `tenant_host_routes` (
                    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `host` VARCHAR(191) NOT NULL,
                    `tenant_key` VARCHAR(64) NOT NULL,
                    `created_at` DATETIME NULL,
                    `updated_at` DATETIME NULL,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `tenant_host_routes_host` (`host`),
                    KEY `tenant_host_routes_tenant` (`tenant_key`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci');
            }
            $ready = true;
        } catch (Throwable $e) {
            log_message('error', 'MasterTenantRepository::ensureHostRoutesTable failed: {msg}', ['msg' => $e->getMessage()]);
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

            $this->ensureTenantOwnerColumn();
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

            $country = strtolower(trim((string) ($data['country_code'] ?? '')));
            if ($country !== '') {
                $row['country_code'] = $country;
            }

            $existing = $db->table('tenants')->where('key', $key)->get()->getRowArray();
            if (is_array($existing)) {
                if ($password === '') {
                    unset($row['db_password']);
                }

                return (bool) $db->table('tenants')->where('key', $key)->update($row);
            }

            $ownerId = (int) ($data['platform_admin_id'] ?? 0);
            if ($ownerId < 1) {
                $first = $db->table('platform_admins')->orderBy('id', 'ASC')->get(1)->getRowArray();
                $ownerId = (int) ($first['id'] ?? 0);
            }
            if ($ownerId > 0) {
                $row['platform_admin_id'] = $ownerId;
            }
            $row['created_at'] = $now;

            return (bool) $db->table('tenants')->insert($row);
        } catch (Throwable $e) {
            log_message('error', 'MasterTenantRepository::upsertTenant failed: {msg}', ['msg' => $e->getMessage()]);

            return false;
        }
    }

    /** Remove a master tenant row (used to undo a provisioning that failed half-way). */
    public function deleteTenant(string $key): bool
    {
        $key = strtolower(trim($key));
        if ($key === '') {
            return false;
        }

        try {
            return (bool) $this->db()->table('tenants')->where('key', $key)->delete();
        } catch (Throwable $e) {
            log_message('error', 'MasterTenantRepository::deleteTenant failed: {msg}', ['msg' => $e->getMessage()]);

            return false;
        }
    }

    /** True when a tenant row already points at this database on this server. */
    public function databaseInUse(string $hostname, int $port, string $database): bool
    {
        try {
            $hostname = strtolower(trim($hostname));
            $rows     = $this->db()->table('tenants')
                ->select('db_hostname, db_port')
                ->where('db_database', $database)
                ->get()
                ->getResultArray();
            foreach ($rows as $row) {
                $sameHost = strtolower(trim((string) ($row['db_hostname'] ?? ''))) === $hostname;
                $samePort = (int) ($row['db_port'] ?? 3306) === $port;
                if ($sameHost && $samePort) {
                    return true;
                }
            }
        } catch (Throwable $e) {
            log_message('error', 'MasterTenantRepository::databaseInUse failed: {msg}', ['msg' => $e->getMessage()]);

            return true; // fail closed: refuse to provision if we cannot prove the DB is free
        }

        return false;
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
     * Meta Tech Provider app for one platform super admin.
     * Each admin has their own App ID, Config ID, and secret.
     *
     * @return array{app_id: string, config_id: string, lead_config_id: string, app_secret: string, api_version: string, webhook_verify_token: string, ready: bool, source: string}
     */
    public function getPlatformMetaTechProvider(?int $platformAdminId = null): array
    {
        $appId      = '';
        $configId   = '';
        $leadConfigId = '';
        $appSecret  = '';
        $apiVersion = 'v25.0';
        $source     = 'none';
        $webhookVerifyToken = '';

        if (self::masterConfigured()) {
            $this->ensureAdminMetaTable();
            $adminId = $platformAdminId ?? $this->resolveMetaAdminId();
            if ($adminId > 0) {
                $own = $this->adminMetaRow($adminId);
                if ($own !== null) {
                    $appId              = trim((string) ($own['app_id'] ?? ''));
                    $configId           = trim((string) ($own['config_id'] ?? ''));
                    $leadConfigId       = trim((string) ($own['lead_config_id'] ?? ''));
                    $appSecret          = trim($this->decryptSecret((string) ($own['app_secret'] ?? '')));
                    $apiVersion         = trim((string) ($own['api_version'] ?? '')) ?: 'v25.0';
                    $webhookVerifyToken = trim((string) ($own['webhook_verify_token'] ?? ''));
                    if ($appId !== '' || $configId !== '') {
                        $source = 'platform-admin';
                    }
                }
            } else {
                $appId              = trim($this->getPlatformSetting('meta_tech_app_id'));
                $configId           = trim($this->getPlatformSetting('meta_tech_config_id'));
                $leadConfigId       = trim($this->getPlatformSetting('meta_tech_lead_config_id'));
                $appSecret          = trim($this->decryptSecret($this->getPlatformSetting('meta_tech_app_secret')));
                $apiVersion         = trim($this->getPlatformSetting('meta_tech_api_version', 'v25.0')) ?: 'v25.0';
                $webhookVerifyToken = trim($this->getPlatformSetting('webhook_verify_token'));
                if ($appId !== '' || $configId !== '') {
                    $source = 'platform';
                }
            }
        }

        $adminId = $platformAdminId ?? $this->resolveMetaAdminId();
        if ($adminId < 1) {
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
        }

        return [
            'app_id'               => $appId,
            'config_id'            => $configId,
            'lead_config_id'       => $leadConfigId,
            'app_secret'           => $appSecret,
            'api_version'          => $apiVersion,
            'webhook_verify_token' => $webhookVerifyToken,
            'ready'                => $appId !== '' && $configId !== '' && $appSecret !== '',
            'source'               => $source,
        ];
    }

    /**
     * @param array{app_id?: string, config_id?: string, lead_config_id?: string, app_secret?: string, api_version?: string, webhook_verify_token?: string} $data
     */
    public function setPlatformMetaTechProvider(array $data, ?int $platformAdminId = null): void
    {
        if (! self::masterConfigured()) {
            throw new \RuntimeException('Master database is not configured.');
        }

        $this->ensureAdminMetaTable();
        $adminId = $platformAdminId ?? $this->resolveMetaAdminId();
        if ($adminId < 1) {
            throw new \RuntimeException('Sign in as a platform super admin before saving Meta credentials.');
        }

        $current = $this->adminMetaRow($adminId) ?? [];
        $secret = array_key_exists('app_secret', $data) ? trim((string) $data['app_secret']) : '';
        if ($secret === '' || str_contains($secret, '•')) {
            $secret = (string) ($current['app_secret'] ?? '');
        } else {
            $secret = $this->encryptSecret($secret);
        }

        $now = date('Y-m-d H:i:s');
        $row = [
            'platform_admin_id'    => $adminId,
            'app_id'               => array_key_exists('app_id', $data) ? trim((string) $data['app_id']) : (string) ($current['app_id'] ?? ''),
            'config_id'            => array_key_exists('config_id', $data) ? trim((string) $data['config_id']) : (string) ($current['config_id'] ?? ''),
            'lead_config_id'       => array_key_exists('lead_config_id', $data) ? trim((string) $data['lead_config_id']) : (string) ($current['lead_config_id'] ?? ''),
            'app_secret'           => $secret,
            'api_version'          => array_key_exists('api_version', $data) ? (trim((string) $data['api_version']) ?: 'v25.0') : ((string) ($current['api_version'] ?? '') ?: 'v25.0'),
            'webhook_verify_token' => array_key_exists('webhook_verify_token', $data) ? trim((string) $data['webhook_verify_token']) : (string) ($current['webhook_verify_token'] ?? ''),
            'updated_at'           => $now,
        ];

        $db = $this->db();
        if ($current === []) {
            $row['created_at'] = $now;
            $db->table('platform_admin_meta')->insert($row);
        } else {
            $db->table('platform_admin_meta')->where('platform_admin_id', $adminId)->update($row);
        }

        if ($this->isPrimaryPlatformAdmin($adminId)) {
            $this->setPlatformSetting('meta_tech_app_id', $row['app_id']);
            $this->setPlatformSetting('meta_tech_config_id', $row['config_id']);
            $this->setPlatformSetting('meta_tech_lead_config_id', $row['lead_config_id']);
            $this->setPlatformSetting('meta_tech_api_version', $row['api_version']);
            $this->setPlatformSetting('webhook_verify_token', $row['webhook_verify_token']);
            if ($row['app_secret'] !== '') {
                $this->setPlatformSetting('meta_tech_app_secret', $this->decryptSecret($row['app_secret']), true);
            }
        }
    }

    public function ensureAdminMetaTable(): void
    {
        if (self::$adminMetaReady || ! self::masterConfigured()) {
            return;
        }

        try {
            $db = $this->db();
            if (! $db->tableExists('platform_admin_meta')) {
                $db->query(
                    'CREATE TABLE `platform_admin_meta` (
                        `platform_admin_id` INT UNSIGNED NOT NULL,
                        `app_id` VARCHAR(64) NOT NULL DEFAULT \'\',
                        `config_id` VARCHAR(64) NOT NULL DEFAULT \'\',
                        `lead_config_id` VARCHAR(64) NOT NULL DEFAULT \'\',
                        `app_secret` TEXT NULL,
                        `api_version` VARCHAR(20) NOT NULL DEFAULT \'v25.0\',
                        `webhook_verify_token` VARCHAR(191) NOT NULL DEFAULT \'\',
                        `created_at` DATETIME NULL,
                        `updated_at` DATETIME NULL,
                        PRIMARY KEY (`platform_admin_id`)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
                );
            }
            if ($db->tableExists('platform_admin_meta') && ! $db->fieldExists('lead_config_id', 'platform_admin_meta')) {
                $db->query('ALTER TABLE `platform_admin_meta` ADD `lead_config_id` VARCHAR(64) NOT NULL DEFAULT \'\' AFTER `config_id`');
            }
            $this->seedPrimaryAdminMeta();
            self::$adminMetaReady = true;
        } catch (Throwable $e) {
            log_message('error', 'MasterTenantRepository::ensureAdminMetaTable failed: {msg}', ['msg' => $e->getMessage()]);
        }
    }

    /** Creates master.login_attempts on first use, so no manual migration is needed. */
    public function ensureLoginAttemptsTable(): void
    {
        if (! self::masterConfigured()) {
            return;
        }

        $this->db()->query(
            'CREATE TABLE IF NOT EXISTS `login_attempts` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `email` VARCHAR(191) NOT NULL,
                `ip_address` VARCHAR(45) NOT NULL,
                `channel` VARCHAR(10) NOT NULL DEFAULT \'web\',
                `user_agent` VARCHAR(255) NOT NULL DEFAULT \'\',
                `created_at` DATETIME NOT NULL,
                PRIMARY KEY (`id`),
                KEY `idx_login_attempts_email` (`email`, `created_at`),
                KEY `idx_login_attempts_ip` (`ip_address`, `created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    }

    /**
     * Failed logins grouped by email and IP, most attempts first.
     *
     * @return list<array<string, mixed>>
     */
    public function failedLoginSummary(int $limit = 100): array
    {
        if (! self::masterConfigured()) {
            return [];
        }

        try {
            $this->ensureLoginAttemptsTable();

            return $this->db()->table('login_attempts')
                ->select('email, ip_address, COUNT(*) AS attempts, MAX(created_at) AS last_try')
                ->groupBy('email, ip_address')
                ->orderBy('attempts', 'DESC')
                ->limit(max(1, $limit))
                ->get()->getResultArray();
        } catch (Throwable $e) {
            log_message('error', 'MasterTenantRepository::failedLoginSummary failed: {msg}', ['msg' => $e->getMessage()]);

            return [];
        }
    }

    /** Audit row for a failed login, kept in the master DB because the tenant is not known yet at login. */
    public function recordFailedLogin(string $email, string $ip, string $channel, string $userAgent): void
    {
        if (! self::masterConfigured()) {
            return;
        }

        try {
            $this->ensureLoginAttemptsTable();
            $db = $this->db();
            $db->table('login_attempts')->insert([
                'email'      => mb_substr(strtolower(trim($email)), 0, 191),
                'ip_address' => mb_substr($ip, 0, 45),
                'channel'    => $channel,
                'user_agent' => mb_substr($userAgent, 0, 255),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (Throwable $e) {
            log_message('error', 'MasterTenantRepository::recordFailedLogin failed: {msg}', ['msg' => $e->getMessage()]);
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function adminMetaRow(int $platformAdminId): ?array
    {
        if ($platformAdminId < 1) {
            return null;
        }

        try {
            $row = $this->db()->table('platform_admin_meta')
                ->where('platform_admin_id', $platformAdminId)
                ->get()
                ->getRowArray();
        } catch (Throwable $e) {
            log_message('error', 'MasterTenantRepository::adminMetaRow failed: {msg}', ['msg' => $e->getMessage()]);

            return null;
        }

        return is_array($row) ? $row : null;
    }

    protected function seedPrimaryAdminMeta(): void
    {
        $primaryId = 0;
        try {
            $primary = $this->db()->table('platform_admins')
                ->select('id')
                ->where('is_primary', 1)
                ->orderBy('id', 'ASC')
                ->get(1)
                ->getRowArray();
            $primaryId = (int) ($primary['id'] ?? 0);
        } catch (Throwable) {
            $primaryId = 0;
        }
        if ($primaryId < 1 || $this->adminMetaRow($primaryId) !== null) {
            return;
        }

        $appId = trim($this->getPlatformSetting('meta_tech_app_id'));
        $configId = trim($this->getPlatformSetting('meta_tech_config_id'));
        $secret = trim($this->getPlatformSetting('meta_tech_app_secret'));
        if ($appId === '' && $configId === '' && $secret === '') {
            return;
        }

        $now = date('Y-m-d H:i:s');
        $this->db()->table('platform_admin_meta')->insert([
            'platform_admin_id'    => $primaryId,
            'app_id'               => $appId,
            'config_id'            => $configId,
            'lead_config_id'       => trim($this->getPlatformSetting('meta_tech_lead_config_id')),
            'app_secret'           => $secret,
            'api_version'          => trim($this->getPlatformSetting('meta_tech_api_version', 'v25.0')) ?: 'v25.0',
            'webhook_verify_token' => trim($this->getPlatformSetting('webhook_verify_token')),
            'created_at'           => $now,
            'updated_at'           => $now,
        ]);
    }

    protected function resolveMetaAdminId(): int
    {
        $sessionId = (int) (session('platform_admin_id') ?: 0);
        if ($sessionId > 0) {
            return $sessionId;
        }

        $key = (string) (TenantContext::get() ?: '');
        if ($key !== '') {
            $tenant = $this->findTenant($key);
            $ownerId = (int) ($tenant['platform_admin_id'] ?? 0);
            if ($ownerId > 0) {
                return $ownerId;
            }
        }

        return 0;
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
