<?php

declare(strict_types=1);

namespace App\Libraries;

use Config\Database as DatabaseConfig;
use Config\Tenancy;
use Throwable;

/**
 * Boot-time + request-time tenant resolution (legacy subdomain + portal session).
 *
 * IMPORTANT: boot() must NOT open DB connections (especially master). Doing so
 * re-enters Config\Database construction and causes an infinite loop / hang.
 */
class TenantResolver
{
    /**
     * Called from Config\Database::__construct after defaults are wiped.
     */
    public static function boot(DatabaseConfig $db): void
    {
        if (\ENVIRONMENT === 'testing') {
            $db->defaultGroup = 'tests';
            $db->tests        = SubdomainDatabase::testsConnection();
            $db->tests['DBDebug'] = true;

            return;
        }

        $db->default            = SubdomainDatabase::defaultConnection();
        $db->default['DBDebug'] = \ENVIRONMENT !== 'production';

        self::applyMasterGroup($db);

        $forced = env('database.tenant', env('TENANT_KEY', ''));
        if (is_string($forced) && trim($forced) !== '') {
            $key = strtolower(trim($forced));
            $db->applyBySubdomain($key);
            if (trim((string) ($db->default['database'] ?? '')) !== '') {
                TenantContext::set($key, 'env');

                return;
            }
            // Master-backed tenants are applied later (CLI/AuthFilter), not during boot.
            TenantContext::set($key, 'env');

            return;
        }

        $hostKey = SubdomainDatabase::detectSubdomain();
        $host    = self::currentHost();
        $tenancy = config(Tenancy::class);
        $portal  = $tenancy->isPortalHost($host) || SubdomainDatabase::isLocalTunnelHost($host);

        // Remember session tenant for AuthFilter; do not connect here.
        $sessionKey = self::sessionTenantKey();
        if ($sessionKey !== null && ($portal || $sessionKey !== $hostKey)) {
            TenantContext::set($sessionKey, 'session');
        }

        // Legacy Host slug → switch credentials (no extra DB round-trip).
        $db->applyBySubdomain($hostKey);
        if (trim((string) ($db->default['database'] ?? '')) !== '') {
            if (! TenantContext::has()) {
                TenantContext::set($hostKey, 'subdomain');
            }

            return;
        }

        // Unknown host / empty switch — Auth/webhook/CLI will apply master tenant later.
        if (! TenantContext::has()) {
            TenantContext::clear();
        }
    }

    public static function ensureFromSession(): bool
    {
        $key = self::sessionTenantKey();
        if ($key === null) {
            $key = TenantContext::get();
        }
        if ($key === null || $key === '') {
            return trim((string) (config(DatabaseConfig::class)->default['database'] ?? '')) !== '';
        }

        return (new TenantConnection())->apply($key, 'session');
    }

    public static function ensureFromJwtClaim(?string $tenantKey): bool
    {
        if ($tenantKey === null || trim($tenantKey) === '') {
            return false;
        }

        return (new TenantConnection())->apply($tenantKey, 'jwt');
    }

    protected static function sessionTenantKey(): ?string
    {
        try {
            if (! function_exists('session')) {
                return null;
            }
            $key = session()->get('tenant_key');
            if (! is_string($key) || trim($key) === '') {
                return null;
            }

            return strtolower(trim($key));
        } catch (Throwable) {
            return null;
        }
    }

    protected static function currentHost(): string
    {
        $host = '';
        if (! empty($_SERVER['HTTP_HOST'])) {
            $host = (string) $_SERVER['HTTP_HOST'];
        } elseif (! empty($_SERVER['SERVER_NAME'])) {
            $host = (string) $_SERVER['SERVER_NAME'];
        }
        $host = strtolower(trim($host));
        if (str_contains($host, ':')) {
            $host = explode(':', $host, 2)[0];
        }

        return $host;
    }

    protected static function applyMasterGroup(DatabaseConfig $db): void
    {
        $tenancy = config(Tenancy::class);
        $base    = SubdomainDatabase::defaultConnection();
        $db->master               = $base;
        $db->master['hostname']   = $tenancy->masterHostname;
        $db->master['username']   = $tenancy->masterUsername;
        $db->master['password']   = $tenancy->masterPassword;
        $db->master['database']   = $tenancy->masterDatabase;
        $db->master['DBDriver']   = 'MySQLi';
        $db->master['port']       = $tenancy->masterPort;
        $db->master['DBDebug']    = \ENVIRONMENT !== 'production';
    }
}
