<?php

declare(strict_types=1);

namespace App\Libraries;

use CodeIgniter\Database\Config as DbConfig;
use Config\Database as DatabaseConfig;
use ReflectionClass;
use Throwable;

/**
 * Switch the default CI4 DB connection to a tenant (master row or legacy switch).
 */
class TenantConnection
{
    protected MasterTenantRepository $master;

    public function __construct(?MasterTenantRepository $master = null)
    {
        $this->master = $master ?? new MasterTenantRepository();
    }

    public function apply(string $key, string $source = 'manual'): bool
    {
        $key = strtolower(trim($key));
        if ($key === '') {
            return false;
        }

        if (TenantContext::get() === $key && $this->currentDatabaseName() !== '') {
            TenantContext::set($key, $source);

            return true;
        }

        $row = $this->master->findActiveTenant($key);
        if ($row !== null) {
            return $this->applyFromMasterRow($row, $source);
        }

        return $this->applyLegacySwitch($key, $source);
    }

    public function applyFromPhoneNumberId(string $phoneNumberId): bool
    {
        $route = $this->master->findPhoneRoute($phoneNumberId);
        if ($route === null) {
            return false;
        }

        $key = strtolower(trim((string) ($route['tenant_key'] ?? '')));
        if ($key === '') {
            return false;
        }

        return $this->apply($key, 'webhook');
    }

    /**
     * @param array<string, mixed> $row
     */
    protected function applyFromMasterRow(array $row, string $source): bool
    {
        $key = strtolower(trim((string) ($row['key'] ?? '')));
        $database = trim((string) ($row['db_database'] ?? ''));
        if ($key === '' || $database === '') {
            return false;
        }

        $config = config(DatabaseConfig::class);
        $config->default               = SubdomainDatabase::defaultConnection();
        $config->default['DBDebug']    = \ENVIRONMENT !== 'production';
        $config->default['hostname']   = (string) ($row['db_hostname'] ?? 'localhost');
        $config->default['username']   = (string) ($row['db_username'] ?? '');
        $config->default['password']   = $this->master->decryptTenantPassword((string) ($row['db_password'] ?? ''));
        $config->default['database']   = $database;
        $config->default['DBDriver']   = 'MySQLi';
        $config->default['port']       = (int) ($row['db_port'] ?? 3306);

        $this->reconnectDefault();
        TenantContext::set($key, $source);

        return true;
    }

    protected function applyLegacySwitch(string $key, string $source): bool
    {
        $config = config(DatabaseConfig::class);
        $config->default            = SubdomainDatabase::defaultConnection();
        $config->default['DBDebug'] = \ENVIRONMENT !== 'production';
        $config->applyBySubdomain($key);

        if (trim((string) ($config->default['database'] ?? '')) === '') {
            return false;
        }

        $this->reconnectDefault();
        TenantContext::set($key, $source);

        return true;
    }

    protected function reconnectDefault(): void
    {
        try {
            $ref  = new ReflectionClass(DbConfig::class);
            $prop = $ref->getProperty('instances');
            $prop->setAccessible(true);
            /** @var array<string, mixed> $instances */
            $instances = $prop->getValue() ?? [];
            if (isset($instances['default'])) {
                try {
                    $instances['default']->close();
                } catch (Throwable) {
                    // ignore
                }
                unset($instances['default']);
                $prop->setValue(null, $instances);
            }
        } catch (Throwable $e) {
            log_message('debug', 'TenantConnection::reconnectDefault: {msg}', ['msg' => $e->getMessage()]);
        }

        // Pass connection array to avoid group lookup edge cases after config mutation.
        $config = config(DatabaseConfig::class);
        DatabaseConfig::connect($config->default, false);
    }

    protected function currentDatabaseName(): string
    {
        try {
            $config = config(DatabaseConfig::class);

            return trim((string) ($config->default['database'] ?? ''));
        } catch (Throwable) {
            return '';
        }
    }
}
