<?php

declare(strict_types=1);

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;
use Config\Tenancy;
use Throwable;

/**
 * Ensure sateri_master schema exists (safe to re-run).
 *
 *   php spark tenant:ensure-master --create-db
 */
class TenantEnsureMaster extends BaseCommand
{
    protected $group       = 'Tenancy';
    protected $name        = 'tenant:ensure-master';
    protected $description = 'Create sateri_master database (optional) and tenancy tables.';
    protected $usage       = 'tenant:ensure-master [--create-db] [--admin-email=...] [--admin-password=...]';
    protected $options     = [
        '--create-db'       => 'CREATE DATABASE sateri_master if missing',
        '--admin-email'     => 'Platform super admin email (default platform@sateri.local)',
        '--admin-password'  => 'Platform super admin password, min 12 chars (default: random, printed once)',
    ];

    public function run(array $params)
    {
        $tenancy = config(Tenancy::class);

        if (CLI::getOption('create-db') !== null) {
            try {
                $mysqli = @new \mysqli(
                    $tenancy->masterHostname,
                    $tenancy->masterUsername,
                    $tenancy->masterPassword,
                    '',
                    $tenancy->masterPort
                );
                if ($mysqli->connect_errno) {
                    throw new \RuntimeException($mysqli->connect_error ?: 'connect failed');
                }
                $dbName = $mysqli->real_escape_string($tenancy->masterDatabase);
                $mysqli->query("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
                $mysqli->close();
                CLI::write("Database `{$tenancy->masterDatabase}` ensured.", 'green');
            } catch (Throwable $e) {
                CLI::error('CREATE DATABASE failed: ' . $e->getMessage());

                return;
            }
        }

        try {
            $db    = \App\Libraries\MasterTenantRepository::masterConnection();
            $forge = Database::forge($db);

            if (! $db->tableExists('tenants')) {
                $this->createTenants($forge);
                $this->createLoginIndex($forge);
                $this->createPhoneRoutes($forge);
                $this->createHostRoutes($forge);
                $this->createPlatformSettings($forge);
                $this->createPlatformAdmins($forge);
                $this->createPlatformAdminMeta($forge);
                $this->createLoginAttempts($forge);
                CLI::write('Master tenancy tables created.', 'green');
            } else {
                CLI::write('Master tenancy tables already present.', 'green');
            }

            (new \App\Libraries\MetaDataDeletionService())->ensureTable();
            (new \App\Libraries\ClientStatsCache())->ensureTable();
            (new \App\Libraries\MswipeService())->ensureRouteTable();
            (new \App\Libraries\WalletRateCard())->ensureTable();   // wa_rates + wa_fx_rates

            $repo = new \App\Libraries\MasterTenantRepository();
            $repo->ensurePlatformSettingsTable();
            $repo->ensureTenantOwnerColumn();
            $repo->ensurePrimaryAdminColumn();
            $repo->ensureAdminMetaTable();
            $repo->ensureHostRoutesTable();
            CLI::write('Master columns synced: tenants.platform_admin_id, tenants.country_code, platform_admins.is_primary, platform_admin_meta, tenant_host_routes.', 'green');
            $adminEmail = strtolower(trim((string) (CLI::getOption('admin-email') ?? 'platform@sateri.local')));
            if (! filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
                CLI::error('Invalid --admin-email.');

                return;
            }

            if ($repo->findPlatformAdminByEmail($adminEmail) === null) {
                // Never ship a known default password: use the supplied one or generate a random one.
                $supplied = (string) (CLI::getOption('admin-password') ?? env('PLATFORM_ADMIN_PASSWORD', ''));
                $password = $supplied !== '' ? $supplied : bin2hex(random_bytes(10));
                if (strlen($password) < 12) {
                    CLI::error('Platform admin password must be at least 12 characters.');

                    return;
                }

                $repo->ensurePlatformAdmin($adminEmail, $password, 'Platform Super Admin');
                CLI::write("Platform admin created: {$adminEmail}", 'green');
                if ($supplied === '') {
                    CLI::write("Generated password (shown once, store it safely): {$password}", 'yellow');
                }
            } else {
                CLI::write("Platform admin ready: {$adminEmail}", 'green');
            }
        } catch (Throwable $e) {
            CLI::error('Master schema failed: ' . $e->getMessage());
            CLI::write('Hint: php spark tenant:ensure-master --create-db');
        }
    }

    protected function createTenants($forge): void
    {
        $forge->addField([
            'id' => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'key' => ['type' => 'VARCHAR', 'constraint' => 64],
            'name' => ['type' => 'VARCHAR', 'constraint' => 150],
            'db_hostname' => ['type' => 'VARCHAR', 'constraint' => 191, 'default' => 'localhost'],
            'db_username' => ['type' => 'VARCHAR', 'constraint' => 191],
            'db_password' => ['type' => 'TEXT', 'null' => true],
            'db_database' => ['type' => 'VARCHAR', 'constraint' => 191],
            'db_port' => ['type' => 'INT', 'unsigned' => true, 'default' => 3306],
            'status' => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'active'],
            'max_users' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'max_roles' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'platform_admin_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'country_code' => ['type' => 'VARCHAR', 'constraint' => 8, 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $forge->addKey('id', true);
        $forge->addUniqueKey('key');
        $forge->addKey('platform_admin_id');
        $forge->createTable('tenants', true);
    }

    protected function createLoginIndex($forge): void
    {
        $forge->addField([
            'id' => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'email' => ['type' => 'VARCHAR', 'constraint' => 191],
            'tenant_key' => ['type' => 'VARCHAR', 'constraint' => 64],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $forge->addKey('id', true);
        $forge->addUniqueKey('email');
        $forge->addKey('tenant_key');
        $forge->createTable('tenant_login_index', true);
    }

    protected function createPhoneRoutes($forge): void
    {
        $forge->addField([
            'id' => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'phone_number_id' => ['type' => 'VARCHAR', 'constraint' => 64],
            'tenant_key' => ['type' => 'VARCHAR', 'constraint' => 64],
            'app_secret' => ['type' => 'TEXT', 'null' => true],
            'verify_token' => ['type' => 'VARCHAR', 'constraint' => 191, 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $forge->addKey('id', true);
        $forge->addUniqueKey('phone_number_id');
        $forge->addKey('tenant_key');
        $forge->createTable('tenant_phone_routes', true);
    }

    protected function createHostRoutes($forge): void
    {
        $forge->addField([
            'id' => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'host' => ['type' => 'VARCHAR', 'constraint' => 191],
            'tenant_key' => ['type' => 'VARCHAR', 'constraint' => 64],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $forge->addKey('id', true);
        $forge->addUniqueKey('host');
        $forge->addKey('tenant_key');
        $forge->createTable('tenant_host_routes', true);
    }

    protected function createPlatformSettings($forge): void
    {
        $forge->addField([
            'id' => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'key' => ['type' => 'VARCHAR', 'constraint' => 100],
            'value' => ['type' => 'TEXT', 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $forge->addKey('id', true);
        $forge->addUniqueKey('key');
        $forge->createTable('platform_settings', true);
    }

    protected function createPlatformAdmins($forge): void
    {
        $forge->addField([
            'id' => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'email' => ['type' => 'VARCHAR', 'constraint' => 191],
            'password' => ['type' => 'VARCHAR', 'constraint' => 255],
            'name' => ['type' => 'VARCHAR', 'constraint' => 150, 'null' => true],
            'status' => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'active'],
            'is_primary' => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 0],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $forge->addKey('id', true);
        $forge->addUniqueKey('email');
        $forge->createTable('platform_admins', true);
    }

    protected function createLoginAttempts($forge): void
    {
        $forge->addField([
            'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'email' => ['type' => 'VARCHAR', 'constraint' => 191],
            'ip_address' => ['type' => 'VARCHAR', 'constraint' => 45],
            'channel' => ['type' => 'VARCHAR', 'constraint' => 10, 'default' => 'web'],
            'user_agent' => ['type' => 'VARCHAR', 'constraint' => 255, 'default' => ''],
            'created_at' => ['type' => 'DATETIME'],
        ]);
        $forge->addKey('id', true);
        $forge->addKey(['email', 'created_at']);
        $forge->addKey(['ip_address', 'created_at']);
        $forge->createTable('login_attempts', true);
    }

    protected function createPlatformAdminMeta($forge): void
    {
        $forge->addField([
            'platform_admin_id' => ['type' => 'INT', 'unsigned' => true],
            'app_id' => ['type' => 'VARCHAR', 'constraint' => 64, 'default' => ''],
            'config_id' => ['type' => 'VARCHAR', 'constraint' => 64, 'default' => ''],
            'lead_config_id' => ['type' => 'VARCHAR', 'constraint' => 64, 'default' => ''],
            'app_secret' => ['type' => 'TEXT', 'null' => true],
            'api_version' => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'v25.0'],
            'webhook_verify_token' => ['type' => 'VARCHAR', 'constraint' => 191, 'default' => ''],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $forge->addKey('platform_admin_id', true);
        $forge->createTable('platform_admin_meta', true);
    }
}
