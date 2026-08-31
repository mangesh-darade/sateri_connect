<?php

namespace Config;

use App\Libraries\SubdomainDatabase;
use App\Libraries\TenantResolver;
use CodeIgniter\Database\Config;

/**
 * Multi-tenant DBs:
 * - Legacy: applyBySubdomain() switch for known Host slugs
 * - Portal: master DB (sateri_master) + session/JWT/webhook routing
 * Connection credentials are NOT read from .env for default (except optional master.* / tenancy.*).
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
                $this->default['database'] = 'sateri_connect';
                $this->default['DBDriver'] = 'MySQLi';
                $this->default['port']     = 3306;
                break;

            case 'androidtestings':
                $this->default['hostname'] = 'localhost';
                $this->default['username'] = 'stadmin_android';
                $this->default['password'] = '1ub~UI7Yvgg~2txx';
                $this->default['database'] = 'stadmin_android';
                $this->default['DBDriver'] = 'MySQLi';
                $this->default['port']     = 3306;
                break;

            case 'demoelintommetaapi':
                $this->default['hostname'] = 'localhost';
                $this->default['username'] = 'stadmin_demometaapi';
                $this->default['password'] = 'sG96cd07$';
                $this->default['database'] = 'stadmin_demometaapi';
                $this->default['DBDriver'] = 'MySQLi';
                $this->default['port']     = 3306;
                break;

            default:
                break;
        }
    }
}
