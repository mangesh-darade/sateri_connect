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
        $database    = trim((string) ($input['database'] ?? ''));
        if ($database === '') {
            $database = 'sateri_' . str_replace('-', '_', $key);
        }
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
            $adminPass = bin2hex(random_bytes(9)) . 'Aa1!';
        }

        $dbError = $this->validateDatabaseTarget($database, $hostname, $port, ! empty($input['allow_existing_db']));
        if ($dbError !== null) {
            return ['ok' => false, 'message' => $dbError];
        }

        if ($adminEmail === '' || ! filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'message' => 'A valid admin email is required.'];
        }

        if ($this->master->findTenantKeyByEmail($adminEmail) !== null) {
            return ['ok' => false, 'message' => 'Admin email is already used by another client.'];
        }

        if (! $skipCreate) {
            try {
                $this->createDatabase($hostname, $username, $password, $port, $database);
            } catch (Throwable $e) {
                return ['ok' => false, 'message' => $this->describeDbError($e, $hostname, $port)];
            }
        }

        if (! $this->master->upsertTenant([
            'key'                => $key,
            'name'               => $name !== '' ? $name : $key,
            'db_hostname'        => $hostname,
            'db_username'        => $username,
            'db_password'        => $password,
            'db_database'        => $database,
            'db_port'            => $port,
            'status'             => 'active',
            'platform_admin_id'  => (int) ($input['platform_admin_id'] ?? 0),
        ])) {
            return ['ok' => false, 'message' => 'Failed to register client in master.'];
        }

        // From here on a failure must not leave a half-registered client behind (a retry would be
        // blocked by "Client key already exists").
        if (! (new TenantConnection())->apply($key, 'provision')) {
            $this->master->deleteTenant($key);

            return ['ok' => false, 'message' => 'The database was created but the app could not connect to it with the given details. Nothing was saved; check the credentials and try again.'];
        }

        if (! $skipMigrate) {
            try {
                $migrate = service('migrations');
                $migrate->setNamespace('App');
                $migrate->latest();
                \Config\Database::seeder()->call('DatabaseSeeder');
            } catch (Throwable $e) {
                log_message('error', '[TenantProvisionService::provision] migrate/seed failed for {key}: {msg}', ['key' => $key, 'msg' => $e->getMessage()]);
                $this->master->deleteTenant($key);

                return ['ok' => false, 'message' => 'Could not set up the client database tables. Nothing was saved; see the application log for details.'];
            }
        }

        TenantContext::set($key, 'provision');

        try {
            $this->ensureAdminUser($adminEmail, $adminPass, $adminName);
            if (! $this->master->upsertLoginIndex($adminEmail, $key)) {
                throw new RuntimeException('Login index could not be saved.');
            }

            // Brand app_name for clearer UI.
            service('settingsService')->set('app_name', $name !== '' ? $name : $key, 'general', false);
        } catch (Throwable $e) {
            log_message('error', '[TenantProvisionService::provision] admin user failed for {key}: {msg}', ['key' => $key, 'msg' => $e->getMessage()]);
            $this->master->deleteTenant($key);
            $this->master->removeLoginIndexForTenantEmail($key, $adminEmail);

            return ['ok' => false, 'message' => 'Could not create the client admin login. Nothing was saved; check the admin email and password and try again.'];
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
            if (! $this->master->upsertLoginIndex($email, $tenantKey)) {
                return ['ok' => false, 'message' => 'That email is already used by another client or is not a valid email address.'];
            }
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => $this->safeMessage($e, 'Could not save the client login. See the application log for details.')];
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
            return ['ok' => false, 'message' => $this->safeMessage($e, 'Could not save the Meta connection. See the application log for details.')];
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

    /**
     * A tenant database must be a plain identifier, never the master or a MySQL system schema, and
     * (unless explicitly allowed) never one that another tenant on the same server already uses.
     */
    protected function validateDatabaseTarget(string $database, string $hostname, int $port, bool $allowExisting): ?string
    {
        if (preg_match('/^[A-Za-z0-9_]{1,64}$/', $database) !== 1) {
            return 'Database name may only contain letters, numbers and underscores (max 64).';
        }
        if ($port < 1 || $port > 65535) {
            return 'Invalid database port. MySQL normally uses 3306.';
        }
        if (preg_match('/^[A-Za-z0-9._\-]{1,255}$/', $hostname) !== 1) {
            return 'Invalid database host.';
        }

        $lower = strtolower($database);
        $masterDb = strtolower(trim((string) config(\Config\Tenancy::class)->masterDatabase));
        if ($lower === $masterDb || in_array($lower, ['mysql', 'information_schema', 'performance_schema', 'sys'], true)) {
            return 'That database name is reserved.';
        }

        if (! $allowExisting && $this->master->databaseInUse($hostname, $port, $database)) {
            return 'That database is already used by another client.';
        }

        return null;
    }

    /**
     * Messages we raised ourselves (validation) are fine to show; database/driver errors are not.
     */
    protected function safeMessage(Throwable $e, string $fallback): string
    {
        log_message('error', '[TenantProvisionService] {class}: {msg}', ['class' => $e::class, 'msg' => $e->getMessage()]);

        if ($e instanceof \CodeIgniter\Database\Exceptions\DatabaseException || $e instanceof \mysqli_sql_exception) {
            return $fallback;
        }

        $msg = trim($e->getMessage());

        return ($msg !== '' && mb_strlen($msg) <= 200 && ! preg_match('/SQLSTATE|mysql|`|\bSELECT\b|\bINSERT\b|\bUPDATE\b/i', $msg)) ? $msg : $fallback;
    }

    /**
     * Turn a MySQL failure into a short, actionable message. The raw driver text (host names, account
     * names, SQL) goes to the log only.
     */
    protected function describeDbError(Throwable $e, string $hostname, int $port): string
    {
        log_message('error', '[TenantProvisionService] database error {code}: {msg}', [
            'code' => (string) $e->getCode(),
            'msg'  => $e->getMessage(),
        ]);

        return match ((int) $e->getCode()) {
            2002, 2003, 2006 => "Cannot reach the MySQL server at {$hostname}:{$port}. Check that MySQL is running and that the host and port are correct.",
            1045             => 'MySQL rejected the database username or password.',
            1044, 1142, 1227 => 'That MySQL user is not allowed to create or use this database. Use an account with CREATE privileges.',
            default          => 'Could not set up the client database. Check the connection details; see the application log for details.',
        };
    }

    protected function createDatabase(
        string $hostname,
        string $username,
        string $password,
        int $port,
        string $database
    ): void {
        if (preg_match('/^[A-Za-z0-9_]{1,64}$/', $database) !== 1) {
            throw new RuntimeException('Invalid database name.');
        }

        $mysqli = @new \mysqli($hostname, $username, $password, '', $port);
        if ($mysqli->connect_errno) {
            throw new RuntimeException($mysqli->connect_error ?: 'MySQL connect failed', (int) $mysqli->connect_errno);
        }
        $dbName = $mysqli->real_escape_string($database);
        if (! $mysqli->query("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci")) {
            $err   = $mysqli->error;
            $errno = (int) $mysqli->errno;
            $mysqli->close();
            throw new RuntimeException($err ?: 'CREATE DATABASE failed', $errno);
        }
        $mysqli->close();
    }
}
