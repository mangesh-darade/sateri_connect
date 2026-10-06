<?php

namespace Config;

use App\Libraries\SubdomainDatabase;
use App\Libraries\TenantResolver;
use CodeIgniter\Database\Config;

/**
 * Multi-tenant DBs:
 * - Single domain / Subdomain: localhost or dynamic .env default fallback
 * - Portal: master DB (sateri_master) + session/JWT/webhook routing
 */
class Database extends Config
{
    public string $filesPath = APPPATH . 'Database' . DIRECTORY_SEPARATOR;

    public string $defaultGroup = 'default';

    /** @var array<string, mixed> */
    public array $default;

    /** @var array<string, mixed> */
    public array $tests;

    /** Master routing DB (tenants, login index, phone routes). @var array<string, mixed> */
    public array $master = [];

    public function __construct()
    {
        $this->default = SubdomainDatabase::defaultConnection();
        $this->tests   = SubdomainDatabase::testsConnection();
        $this->master  = SubdomainDatabase::defaultConnection();

        parent::__construct();
        TenantResolver::boot($this);
    }

    /** Subdomain → DB. Keep for legacy Host-based tenants. */
    public function applyBySubdomain(string $subdomain): void
    {
        switch ($subdomain) {
            case 'localhost':
                $this->default['hostname'] = 'localhost';
                $this->default['username'] = 'root';
                $this->default['password'] = '';
                $this->default['database'] = 'elintom_reach_platfrom';
                $this->default['DBDriver'] = 'MySQLi';
                $this->default['port']     = 3306;
                break;

            default:
                // Single domain / subdomain default fallback from .env
                $defaultDb = (string) env('database.default.database', env('DB_DATABASE', ''));
                if ($defaultDb !== '') {
                    $this->default['hostname'] = (string) env('database.default.hostname', env('DB_HOST', 'localhost'));
                    $this->default['username'] = (string) env('database.default.username', env('DB_USER', 'root'));
                    $this->default['password'] = (string) env('database.default.password', env('DB_PASS', ''));
                    $this->default['database'] = $defaultDb;
                    $this->default['DBDriver'] = (string) env('database.default.DBDriver', 'MySQLi');
                    $this->default['port']     = (int) env('database.default.port', env('DB_PORT', 3306));
                }
                break;
        }
    }
}
