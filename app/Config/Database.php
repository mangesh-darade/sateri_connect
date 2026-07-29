<?php

namespace Config;

use App\Libraries\SubdomainDatabase;
use CodeIgniter\Database\Config;

/**
 * Edit ONLY applyBySubdomain() switch for multi-tenant DBs.
 * Connection credentials are NOT read from .env (database.default.* ignored).
 * Defaults + subdomain detect: App\Libraries\SubdomainDatabase.
 */
class Database extends Config
{
    public string $filesPath = APPPATH . 'Database' . DIRECTORY_SEPARATOR;

    public string $defaultGroup = 'default';

    /** @var array<string, mixed> */
    public array $default;

    /** @var array<string, mixed> */
    public array $tests;

    public function __construct()
    {
        $this->default = SubdomainDatabase::defaultConnection();
        $this->tests   = SubdomainDatabase::testsConnection();

        parent::__construct();
        SubdomainDatabase::boot($this);
    }

    /** Subdomain → DB. Add new clients here only. */
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

            // case 'herbinn':
            //     $this->default['hostname'] = 'localhost';
            //     $this->default['username'] = 'root';
            //     $this->default['password'] = '';
            //     $this->default['database'] = 'herbinn';
            //     $this->default['port']     = 3306;
            //     break;

            // case 'client1':
            //     $this->default['hostname'] = 'localhost';
            //     $this->default['username'] = 'root';
            //     $this->default['password'] = '';
            //     $this->default['database'] = 'client1_db';
            //     $this->default['port']     = 3306;
            //     break;

            default:
                break;
        }
    }
}
