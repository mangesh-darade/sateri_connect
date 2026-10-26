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

    /**
     * Tenant key the live default connection is actually bound to. TenantContext only says which
     * tenant was *requested* (e.g. from the session); it can differ from the connection at boot.
     */
    protected static ?string $boundKey = null;

    public function __construct(?MasterTenantRepository $master = null)
    {
        $this->master = $master ?? new MasterTenantRepository();
    }

    /** Record that the default connection was already pointed at this tenant (boot-time legacy switch). */
    public static function markBound(?string $key): void
    {
        $key             = $key !== null ? strtolower(trim($key)) : '';
        self::$boundKey = $key !== '' ? $key : null;
    }

    public function apply(string $key, string $source = 'manual'): bool
    {
        $key = strtolower(trim($key));
        if ($key === '' || preg_match('/^[a-z0-9_\-]{1,64}$/', $key) !== 1) {
            return false;
        }

        if (self::$boundKey === $key && $this->currentDatabaseName() !== '') {
            TenantContext::set($key, $source);

            return true;
        }

        $row = $this->master->findActiveTenant($key);
        if ($row !== null) {
            return $this->applyFromMasterRow($row, $source);
        }

        return $this->applyLegacySwitch($key, $source);
    }

    /**
     * Remember the current tenant connection so a page-id search can switch back.
     *
     * @return array{config: array<string, mixed>, key: ?string, source: ?string}
     */
    public function capture(): array
    {
        $config = config(DatabaseConfig::class);

        return [
            'config' => $config->default,
            'key'    => TenantContext::get(),
            'source' => TenantContext::source(),
            'bound'  => self::$boundKey,
        ];
    }

    /**
     * @param array{config?: array<string, mixed>, key?: ?string, source?: ?string, bound?: ?string} $captured
     */
    public function restore(array $captured): void
    {
        $saved = $captured['config'] ?? null;
        if (! is_array($saved) || $saved === []) {
            return;
        }

        $config = config(DatabaseConfig::class);
        $config->default = $saved;
        $this->reconnectDefault();
        self::markBound($captured['bound'] ?? null);

        $key = trim((string) ($captured['key'] ?? ''));
        if ($key !== '') {
            TenantContext::set($key, (string) ($captured['source'] ?? 'webhook'));
        } else {
            TenantContext::clear();
        }
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
     * Resolve vanity / webhook host → tenant when subdomain ≠ tenant key.
     * Order: master tenant_host_routes → discover via tenants' webhook_public_base
     * → optional env hostAliases → tenant key = subdomain (if such tenant exists).
     */
    public function applyFromRequestHost(?string $host = null, bool $allowDiscovery = true): bool
    {
        $host = strtolower(trim((string) ($host ?? TenantResolver::requestHost())));
        if (str_contains($host, ':')) {
            $host = explode(':', $host, 2)[0];
        }
        if ($host === '' || $host === 'localhost' || filter_var($host, FILTER_VALIDATE_IP)) {
            return false;
        }

        $candidates = [$host];
        $parts      = explode('.', $host);
        if (count($parts) >= 3 && $parts[0] !== 'www') {
            $candidates[] = $parts[0];
        } elseif (count($parts) >= 1) {
            $candidates[] = $parts[0];
        }
        $candidates = array_values(array_unique(array_filter($candidates)));

        if (MasterTenantRepository::masterConfigured()) {
            foreach ($candidates as $candidate) {
                $route = $this->master->findHostRoute($candidate);
                $key   = strtolower(trim((string) ($route['tenant_key'] ?? '')));
                if ($key !== '' && $this->apply($key, 'host_route')) {
                    return true;
                }
            }

            $discovered = $allowDiscovery ? $this->discoverTenantKeyByWebhookHost($candidates) : null;
            if ($discovered !== null && $this->apply($discovered, 'host_discover')) {
                return true;
            }
        }

        $aliases = config(\Config\Tenancy::class)->hostAliases ?? [];
        if (is_array($aliases) && $aliases !== []) {
            foreach ($candidates as $candidate) {
                $key = strtolower(trim((string) ($aliases[$candidate] ?? '')));
                if ($key !== '' && $this->apply($key, 'host_alias')) {
                    return true;
                }
            }
        }

        if (MasterTenantRepository::masterConfigured()) {
            foreach ($candidates as $candidate) {
                if ($this->master->findActiveTenant($candidate) !== null && $this->apply($candidate, 'host_key')) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Find which tenant saved this public webhook host, then pin master host routes.
     *
     * @param list<string> $candidates
     */
    protected function discoverTenantKeyByWebhookHost(array $candidates): ?string
    {
        if ($candidates === [] || ! MasterTenantRepository::masterConfigured()) {
            return null;
        }

        // Discovery opens a connection per tenant. Remember misses and cap scans per client IP so
        // anonymous requests with random Host headers cannot fan out across every tenant database.
        $missKey = 'tenant_discover_miss_' . md5(implode('|', $candidates));
        try {
            if (cache($missKey)) {
                return null;
            }
            $ip = (string) service('request')->getIPAddress();
            if (service('throttler')->check('tenant_discover_' . md5($ip), 10, MINUTE) === false) {
                return null;
            }
        } catch (Throwable) {
            // cache/throttler unavailable: fall through to a normal scan
        }

        $snapshot = $this->capture();
        // Only the full host counts: a tenant-editable URL must not claim another host's first label.
        $wanted = array_fill_keys(array_filter($candidates, static fn (string $c): bool => str_contains($c, '.')), true);
        if ($wanted === []) {
            return null;
        }

        try {
            foreach ($this->master->listActiveTenants(null) as $row) {
                $key = strtolower(trim((string) ($row['key'] ?? '')));
                if ($key === '') {
                    continue;
                }
                if (! $this->apply($key, 'host_discover_scan')) {
                    continue;
                }

                $base = rtrim((string) ((new SettingsService())->get('webhook_public_base', '')), '/');
                if ($base === '') {
                    continue;
                }
                if (! str_contains($base, '://')) {
                    $base = 'https://' . $base;
                }
                $savedHost = strtolower(trim((string) (parse_url($base, PHP_URL_HOST) ?: '')));
                if ($savedHost === '') {
                    continue;
                }

                if (isset($wanted[$savedHost])) {
                    $this->master->syncHostRoutesFromPublicBase($base, $key);

                    return $key;
                }
            }
        } catch (Throwable $e) {
            log_message('error', 'TenantConnection::discoverTenantKeyByWebhookHost failed: {msg}', [
                'msg' => $e->getMessage(),
            ]);
        } finally {
            $this->restore($snapshot);
        }

        try {
            cache()->save($missKey, 1, 60);
        } catch (Throwable) {
            // ignore
        }

        return null;
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
        self::markBound($key);
        TenantContext::set($key, $source);

        return true;
    }

    /**
     * Legacy (Host-slug) tenants. The `.env` default-DB fallback inside applyBySubdomain() answers
     * for ANY key, so only allow keys that genuinely are this request's own tenant — otherwise a
     * made-up key from a URL/JWT would silently land in the default database.
     */
    protected function applyLegacySwitch(string $key, string $source): bool
    {
        $forced  = strtolower(trim((string) env('database.tenant', env('TENANT_KEY', ''))));
        $allowed = $key === SubdomainDatabase::detectSubdomain() || ($forced !== '' && $key === $forced);
        if (! $allowed) {
            log_message('warning', 'Tenant "{key}" is not in master and is not a legacy host tenant (source: {src}).', [
                'key' => $key,
                'src' => $source,
            ]);

            return false;
        }

        $config = config(DatabaseConfig::class);
        $config->default            = SubdomainDatabase::defaultConnection();
        $config->default['DBDebug'] = \ENVIRONMENT !== 'production';
        $config->applyBySubdomain($key);

        if (trim((string) ($config->default['database'] ?? '')) === '') {
            return false;
        }

        $this->reconnectDefault();
        self::markBound($key);
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

        self::resetTenantBoundState();
    }

    /**
     * Shared models and services keep the DB connection (and memoized settings) of the tenant they were
     * first built for. Drop them whenever the default connection changes so nothing leaks across tenants.
     */
    protected static function resetTenantBoundState(): void
    {
        try {
            \CodeIgniter\Config\Factories::reset('models');
        } catch (Throwable) {
            // best effort
        }

        foreach ([
            'settingsService', 'whatsApp', 'pageMessaging', 'queueService', 'automationEngine', 'keywordBot',
            'campaignService', 'contactAttributes', 'quickReplies', 'whatsAppConsent', 'emailProvider', 'aiService',
        ] as $name) {
            try {
                \Config\Services::resetSingle($name);
            } catch (Throwable) {
                // service may not be instantiated yet
            }
        }
    }

    /**
     * Restore the captured tenant only if the live connection actually moved away from it.
     *
     * @param array{config?: array<string, mixed>, key?: ?string, source?: ?string, bound?: ?string} $captured
     */
    public function restoreIfChanged(array $captured): void
    {
        $saved   = $captured['config'] ?? [];
        $current = config(DatabaseConfig::class)->default;
        $same    = ($saved['database'] ?? '') === ($current['database'] ?? '')
            && ($saved['hostname'] ?? '') === ($current['hostname'] ?? '')
            && ($saved['username'] ?? '') === ($current['username'] ?? '')
            && ($captured['key'] ?? null) === TenantContext::get();

        if (! $same) {
            $this->restore($captured);
        }
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
