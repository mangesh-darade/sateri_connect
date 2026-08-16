<?php

declare(strict_types=1);

namespace App\Commands;

use App\Libraries\MasterTenantRepository;
use App\Libraries\TenantConnection;
use App\Libraries\TenantContext;
use App\Models\RoleModel;
use App\Models\UserModel;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Throwable;

/**
 * Provision a new client tenant (separate DB + master routing row).
 *
 *   php spark tenant:provision -key acme -name "Acme Co" -database sateri_acme \
 *     -admin-email admin@acme.test -admin-password ChangeMe123!
 */
class TenantProvision extends BaseCommand
{
    protected $group       = 'Tenancy';
    protected $name        = 'tenant:provision';
    protected $description = 'Create a client tenant DB, migrate/seed, register in sateri_master.';
    protected $usage       = 'tenant:provision -key slug -name "Name" -database db_name [options]';
    protected $options     = [
        '-key'            => 'Tenant slug (required)',
        '-name'           => 'Display name',
        '-database'       => 'MySQL database name (default: sateri_{key})',
        '-hostname'       => 'DB host (default: localhost)',
        '-username'       => 'DB user (default: root)',
        '-password'       => 'DB password (default: empty)',
        '-port'           => 'DB port (default: 3306)',
        '-admin-email'    => 'Initial admin email',
        '-admin-password' => 'Initial admin password',
        '-admin-name'     => 'Initial admin name',
        '-skip-create-db' => 'Do not CREATE DATABASE (assume DBA created it)',
        '-skip-migrate'   => 'Do not run migrations/seeds',
    ];

    public function run(array $params)
    {
        $key = strtolower(trim((string) (CLI::getOption('key') ?? ($params[0] ?? ''))));
        if ($key === '' || ! preg_match('/^[a-z0-9][a-z0-9_-]{1,62}$/', $key)) {
            CLI::error('Provide a valid -key slug (letters, numbers, _-, 2–63 chars).');

            return;
        }

        $name     = trim((string) (CLI::getOption('name') ?? $key));
        $database = trim((string) (CLI::getOption('database') ?? ('sateri_' . $key)));
        $hostname = trim((string) (CLI::getOption('hostname') ?? 'localhost'));
        $username = trim((string) (CLI::getOption('username') ?? 'root'));
        $password = (string) (CLI::getOption('password') ?? '');
        $port     = (int) (CLI::getOption('port') ?? 3306);
        $adminEmail = strtolower(trim((string) (CLI::getOption('admin-email') ?? ('admin@' . $key . '.local'))));
        $adminPass  = (string) (CLI::getOption('admin-password') ?? '');
        $adminName  = trim((string) (CLI::getOption('admin-name') ?? 'Admin'));
        $skipCreate = CLI::getOption('skip-create-db') !== null;
        $skipMigrate = CLI::getOption('skip-migrate') !== null;

        if ($adminPass === '') {
            $adminPass = bin2hex(random_bytes(4)) . 'Aa1!';
            CLI::write('Generated admin password: ' . $adminPass, 'yellow');
        }

        if (! MasterTenantRepository::masterConfigured()) {
            CLI::write('Master DB not ready. Running tenant:ensure-master…', 'yellow');
            command('tenant:ensure-master --create-db');
            if (! MasterTenantRepository::masterConfigured()) {
                CLI::error('Master still not configured. Check database.master.* and MySQL access.');

                return;
            }
        }

        if (! $skipCreate) {
            try {
                $this->createDatabase($hostname, $username, $password, $port, $database);
                CLI::write("Database `{$database}` ensured.", 'green');
            } catch (Throwable $e) {
                CLI::error('CREATE DATABASE failed: ' . $e->getMessage());
                CLI::write('Create it manually, then re-run with --skip-create-db');

                return;
            }
        }

        $master = new MasterTenantRepository();
        if (! $master->upsertTenant([
            'key'         => $key,
            'name'        => $name,
            'db_hostname' => $hostname,
            'db_username' => $username,
            'db_password' => $password,
            'db_database' => $database,
            'db_port'     => $port,
            'status'      => 'active',
        ])) {
            CLI::error('Failed to write tenants row in sateri_master.');

            return;
        }
        CLI::write("Registered tenant `{$key}` in master.", 'green');

        if (! (new TenantConnection())->apply($key, 'provision')) {
            CLI::error('Could not connect to tenant database.');

            return;
        }

        if (! $skipMigrate) {
            try {
                $migrate = service('migrations');
                $migrate->setNamespace('App');
                $migrate->latest();
                $seeder = \Config\Database::seeder();
                $seeder->call('DatabaseSeeder');
                CLI::write('Tenant migrations + seeds complete.', 'green');
            } catch (Throwable $e) {
                CLI::error('Tenant migrate/seed failed: ' . $e->getMessage());

                return;
            }
        }

        TenantContext::set($key, 'provision');
        try {
            $role = model(RoleModel::class)->findBySlug('super-admin')
                ?? model(RoleModel::class)->findBySlug('super_admin')
                ?? model(RoleModel::class)->findBySlug('admin')
                ?? model(RoleModel::class)->first();
            if ($role === null) {
                CLI::error('No roles found after seed.');

                return;
            }

            $users = model(UserModel::class);
            $existing = $users->findByEmail($adminEmail);
            $data = [
                'name'              => $adminName,
                'email'             => $adminEmail,
                'password'          => $adminPass,
                'role_id'           => (int) $role['id'],
                'status'            => 'active',
                'email_verified_at' => date('Y-m-d H:i:s'),
            ];
            if ($existing !== null) {
                $users->update((int) $existing['id'], $data);
            } else {
                $users->insert($data);
            }
            $master->upsertLoginIndex($adminEmail, $key);
            CLI::write("Admin user ready: {$adminEmail}", 'green');
        } catch (Throwable $e) {
            CLI::error('Admin user failed: ' . $e->getMessage());

            return;
        }

        CLI::newLine();
        CLI::write('Done. Next steps:', 'cyan');
        CLI::write("  1) Login on portal host with {$adminEmail}");
        CLI::write('  2) Settings → Meta: save WABA / phone_number_id / app secret');
        CLI::write('  3) Meta webhook URL: https://{your-portal}/webhooks');
        CLI::write("  4) Optional CLI force: database.tenant = {$key}");
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
            throw new \RuntimeException($mysqli->connect_error ?: 'MySQL connect failed');
        }
        $dbName = $mysqli->real_escape_string($database);
        if (! $mysqli->query("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci")) {
            $err = $mysqli->error;
            $mysqli->close();
            throw new \RuntimeException($err ?: 'CREATE DATABASE failed');
        }
        $mysqli->close();
    }
}
