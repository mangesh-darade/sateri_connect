<?php

declare(strict_types=1);

namespace App\Libraries;

use App\Models\RoleModel;
use App\Models\UserModel;
use RuntimeException;
use Throwable;

/**
 * Create / bootstrap a tenant DB + master routing (shared by CLI + Platform UI).
 */
class TenantProvisionService
{
    public function __construct(protected ?MasterTenantRepository $master = null)
    {
        $this->master ??= new MasterTenantRepository();
    }

    /**
     * @param array{
     *   key: string,
     *   name?: string,
     *   database?: string,
     *   hostname?: string,
     *   username?: string,
     *   password?: string,
     *   port?: int,
     *   admin_email?: string,
     *   admin_password?: string,
     *   admin_name?: string,
     *   skip_create_db?: bool,
     *   skip_migrate?: bool
     * } $input
     * @return array{ok: bool, message: string, key?: string, admin_email?: string, admin_password?: string}
     */
    public function provision(array $input): array
    {
        $key = strtolower(trim((string) ($input['key'] ?? '')));
        if ($key === '' || ! preg_match('/^[a-z0-9][a-z0-9_-]{1,62}$/', $key)) {
            return ['ok' => false, 'message' => 'Invalid client key (letters, numbers, _-, 2–63 chars).'];
        }

        if (! MasterTenantRepository::masterConfigured()) {
            return ['ok' => false, 'message' => 'Master DB not ready. Run: php spark tenant:ensure-master --create-db'];
        }

        if ($this->master->findTenant($key) !== null) {
            return ['ok' => false, 'message' => 'Client key already exists.'];
        }

        $name        = trim((string) ($input['name'] ?? $key));
        $database    = trim((string) ($input['database'] ?? ('sateri_' . $key)));
        $hostname    = trim((string) ($input['hostname'] ?? 'localhost'));
        $username    = trim((string) ($input['username'] ?? 'root'));
        $password    = (string) ($input['password'] ?? '');
        $port        = (int) ($input['port'] ?? 3306);
        $adminEmail  = strtolower(trim((string) ($input['admin_email'] ?? ('admin@' . $key . '.local'))));
        $adminPass   = (string) ($input['admin_password'] ?? '');
        $adminName   = trim((string) ($input['admin_name'] ?? 'Admin'));
        $skipCreate  = ! empty($input['skip_create_db']);
        $skipMigrate = ! empty($input['skip_migrate']);

        if ($adminPass === '') {
            $adminPass = bin2hex(random_bytes(4)) . 'Aa1!';
        }

        if ($this->master->findTenantKeyByEmail($adminEmail) !== null) {
            return ['ok' => false, 'message' => 'Admin email is already used by another client.'];
        }

        if (! $skipCreate) {
            try {
                $this->createDatabase($hostname, $username, $password, $port, $database);
            } catch (Throwable $e) {
                return ['ok' => false, 'message' => 'CREATE DATABASE failed: ' . $e->getMessage()];
            }
        }

        if (! $this->master->upsertTenant([
            'key'         => $key,
            'name'        => $name !== '' ? $name : $key,
            'db_hostname' => $hostname,
            'db_username' => $username,
            'db_password' => $password,
            'db_database' => $database,
            'db_port'     => $port,
            'status'      => 'active',
        ])) {
            return ['ok' => false, 'message' => 'Failed to register client in master.'];
        }

        if (! (new TenantConnection())->apply($key, 'provision')) {
            return ['ok' => false, 'message' => 'Could not connect to new client database.'];
        }

        if (! $skipMigrate) {
            try {
                $migrate = service('migrations');
                $migrate->setNamespace('App');
                $migrate->latest();
                \Config\Database::seeder()->call('DatabaseSeeder');
            } catch (Throwable $e) {
                return ['ok' => false, 'message' => 'Migrate/seed failed: ' . $e->getMessage()];
            }
        }

        TenantContext::set($key, 'provision');

        try {
            $this->ensureAdminUser($adminEmail, $adminPass, $adminName);
            $this->master->upsertLoginIndex($adminEmail, $key);

            // Brand app_name for clearer UI.
            service('settingsService')->set('app_name', $name !== '' ? $name : $key, 'general', false);
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => 'Admin user failed: ' . $e->getMessage()];
        }

        return [
            'ok'             => true,
            'message'        => 'Client created.',
            'key'            => $key,
            'admin_email'    => $adminEmail,
            'admin_password' => $adminPass,
        ];
    }

    /**
     * Update (or create) client workspace admin login.
     *
     * @return array{ok: bool, message: string}
     */
    public function setClientLogin(string $tenantKey, string $email, string $password, string $name = 'Admin'): array
    {
        $tenantKey = strtolower(trim($tenantKey));
        $email     = strtolower(trim($email));
        $password  = trim($password);
        if ($tenantKey === '' || $email === '') {
            return ['ok' => false, 'message' => 'Tenant and email are required.'];
        }

        $other = $this->master->findTenantKeyByEmail($email);
        if ($other !== null && $other !== $tenantKey) {
            return ['ok' => false, 'message' => 'Email already belongs to another client.'];
        }

        if (! (new TenantConnection())->apply($tenantKey, 'platform')) {
            return ['ok' => false, 'message' => 'Unable to connect to client database.'];
        }
        TenantContext::set($tenantKey, 'platform');

        try {
            $users   = model(UserModel::class);
            $current = $this->currentAdminUser($users, $tenantKey);
            if ($current === null) {
                $current = $users->findByEmail($email);
            }

            if ($current === null && $password === '') {
                return ['ok' => false, 'message' => 'Password is required when creating a new admin user.'];
            }

            $taken = $users->findByEmail($email);
            if ($taken !== null && ($current === null || (int) $taken['id'] !== (int) $current['id'])) {
                return ['ok' => false, 'message' => 'The email field must contain a unique value.'];
            }

            $this->ensureAdminUser($email, $password, $name !== '' ? $name : 'Admin', $password !== '', $current);
            $this->master->upsertLoginIndex($email, $tenantKey);
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }

        return ['ok' => true, 'message' => 'Client login saved.'];
    }

    /**
     * Save Meta connection fields into the tenant settings + phone route.
     *
     * @param array<string, mixed> $meta
     * @return array{ok: bool, message: string}
     */
    public function saveClientMeta(string $tenantKey, array $meta): array
    {
        $tenantKey = strtolower(trim($tenantKey));
        if ($tenantKey === '') {
            return ['ok' => false, 'message' => 'Tenant key required.'];
        }

        if (! (new TenantConnection())->apply($tenantKey, 'platform')) {
            return ['ok' => false, 'message' => 'Unable to connect to client database.'];
        }
        TenantContext::set($tenantKey, 'platform');

        try {
            $settings = service('settingsService');
            $payload  = [];
            foreach ([
                'access_token', 'phone_number_id', 'waba_id', 'verify_token',
                'app_secret', 'app_id', 'business_id', 'api_version',
            ] as $k) {
                if (array_key_exists($k, $meta)) {
                    $payload[$k] = $meta[$k];
                }
            }
            if ($payload !== []) {
                $settings->setMetaConfig($payload);
            }
            if (array_key_exists('app_name', $meta) && trim((string) $meta['app_name']) !== '') {
                $settings->set('app_name', trim((string) $meta['app_name']), 'general', false);
                $this->master->updateTenantName($tenantKey, trim((string) $meta['app_name']));
            }
            $settings->setWhatsAppProvider('meta');
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }

        return ['ok' => true, 'message' => 'Meta settings saved for client.'];
    }

    /**
     * @param array<string, mixed>|null $existing Current admin row to update. Null inserts a new user.
     */
    protected function ensureAdminUser(string $email, string $password, string $name, bool $updatePassword = true, ?array $existing = null): void
    {
        $role = model(RoleModel::class)->findBySlug('super-admin')
            ?? model(RoleModel::class)->findBySlug('super_admin')
            ?? model(RoleModel::class)->findBySlug('admin')
            ?? model(RoleModel::class)->first();
        if ($role === null) {
            throw new RuntimeException('No roles found. Migrate/seed the client DB first.');
        }

        $users = model(UserModel::class);
        if ($existing === null) {
            $existing = $users->findByEmail($email);
        }
        $data = [
            'name'              => $name,
            'email'             => $email,
            'role_id'           => (int) $role['id'],
            'status'            => 'active',
            'email_verified_at' => date('Y-m-d H:i:s'),
        ];
        if ($updatePassword || $existing === null) {
            if ($password === '') {
                throw new RuntimeException('Password is required for a new admin user.');
            }
            $data['password'] = $password;
        }
        if ($existing !== null) {
            $id = (int) $existing['id'];
            // {id} is not in the update payload, so the model rule treats this user's own email as a duplicate.
            $users->setValidationRule('email', 'required|valid_email|max_length[191]|is_unique[users.email,id,' . $id . ']');
            try {
                if (! $users->update($id, $data)) {
                    throw new RuntimeException(implode(' ', $users->errors() ?: ['Update failed']));
                }
            } finally {
                $users->setValidationRule('email', 'required|valid_email|max_length[191]|is_unique[users.email,id,{id}]');
            }
        } elseif (! $users->insert($data)) {
            throw new RuntimeException(implode(' ', $users->errors() ?: ['Insert failed']));
        }
    }

    /**
     * Admin shown on the client screen: first login-index email for this tenant.
     *
     * @return array<string, mixed>|null
     */
    protected function currentAdminUser(UserModel $users, string $tenantKey): ?array
    {
        $email = '';
        try {
            $idx = MasterTenantRepository::masterConnection()
                ->table('tenant_login_index')
                ->where('tenant_key', $tenantKey)
                ->orderBy('id', 'ASC')
                ->get()
                ->getRowArray();
            $email = is_array($idx) ? strtolower(trim((string) ($idx['email'] ?? ''))) : '';
        } catch (Throwable) {
            $email = '';
        }

        if ($email === '') {
            return null;
        }

        return $users->findByEmail($email);
    }

    protected function createDatabase(
        string $hostname,
        string $username,
        string $password,
        int $port,
        string $database
    ): void {
        $mysqli = @new \mysqli($hostname, $username, $password, '', $port);
        if ($mysqli->connect_errno) {
            throw new RuntimeException($mysqli->connect_error ?: 'MySQL connect failed');
        }
        $dbName = $mysqli->real_escape_string($database);
        if (! $mysqli->query("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci")) {
            $err = $mysqli->error;
            $mysqli->close();
            throw new RuntimeException($err ?: 'CREATE DATABASE failed');
        }
        $mysqli->close();
    }
}
