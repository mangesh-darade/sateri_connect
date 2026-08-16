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
    protected $usage       = 'tenant:ensure-master [--create-db]';
    protected $options     = [
        '--create-db' => 'CREATE DATABASE sateri_master if missing',
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
                $this->createPlatformSettings($forge);
                $this->createPlatformAdmins($forge);
                CLI::write('Master tenancy tables created.', 'green');
            } else {
                CLI::write('Master tenancy tables already present.', 'green');
            }

            $repo = new \App\Libraries\MasterTenantRepository();
            if ($repo->findPlatformAdminByEmail('platform@sateri.local') === null) {
                $repo->ensurePlatformAdmin('platform@sateri.local', 'Platform@123', 'Platform Super Admin');
                CLI::write('Platform admin: platform@sateri.local / Platform@123', 'yellow');
            } else {
                CLI::write('Platform admin ready: platform@sateri.local', 'green');
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
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $forge->addKey('id', true);
        $forge->addUniqueKey('key');
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
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $forge->addKey('id', true);
        $forge->addUniqueKey('email');
        $forge->createTable('platform_admins', true);
    }
}
