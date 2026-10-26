<?php

declare(strict_types=1);

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Multi-client tenancy: shared portal host + master routing DB.
 */
class Tenancy extends BaseConfig
{
    /**
     * Hostnames where tenant is resolved from session/JWT/webhook map
     * (not from subdomain). Comma-separated via env tenancy.portalHosts.
     *
     * @var list<string>
     */
    public array $portalHosts = ['localhost', '127.0.0.1'];

    /**
     * Optional local-only host → tenant_key overrides (empty in production).
     * Real routing: master `tenant_host_routes` + Settings webhook URL sync / auto-discover.
     * Env (dev only): tenancy.hostAliases=myhost:tenant_key
     *
     * @var array<string, string>
     */
    public array $hostAliases = [];

    /**
     * Master DB connection (routing only). Override via env database.master.*.
     */
    public string $masterHostname = 'localhost';
    public string $masterUsername = 'root';
    public string $masterPassword = '';
    public string $masterDatabase = 'sateri_master';

    public int $masterPort = 3306;

    public function __construct()
    {
        parent::__construct();

        $hosts = env('tenancy.portalHosts', '');
        if (is_string($hosts) && trim($hosts) !== '') {
            $this->portalHosts = array_values(array_filter(array_map(
                static fn(string $h): string => strtolower(trim($h)),
                explode(',', $hosts)
            )));
        }

        $aliasEnv = env('tenancy.hostAliases', null);
        if (is_string($aliasEnv) && trim($aliasEnv) !== '') {
            $parsed = [];
            foreach (explode(',', $aliasEnv) as $pair) {
                $pair = trim($pair);
                if ($pair === '' || ! str_contains($pair, ':')) {
                    continue;
                }
                [$host, $tenantKey] = array_map('trim', explode(':', $pair, 2));
                $host      = strtolower($host);
                $tenantKey = strtolower($tenantKey);
                if ($host !== '' && $tenantKey !== '' && preg_match('/^[a-z0-9_\-]{1,64}$/', $tenantKey) === 1) {
                    $parsed[$host] = $tenantKey;
                }
            }
            if ($parsed !== []) {
                $this->hostAliases = $parsed;
            }
        }

        $h = env('database.master.hostname', null);
        if (is_string($h) && $h !== '') {
            $this->masterHostname = $h;
        }
        $u = env('database.master.username', null);
        if (is_string($u) && $u !== '') {
            $this->masterUsername = $u;
        }
        $p = env('database.master.password', null);
        if (is_string($p)) {
            $this->masterPassword = $p;
        }
        $d = env('database.master.database', null);
        if (is_string($d) && $d !== '') {
            $this->masterDatabase = $d;
        }
        $port = env('database.master.port', null);
        if ($port !== null && $port !== '') {
            $this->masterPort = (int) $port;
        }

        $this->warnOnWeakMasterCredentials();
    }

    /**
     * The master DB holds every tenant's (encrypted) DB credentials and all routing, so in production
     * it must not run as root or with an empty password.
     */
    protected function warnOnWeakMasterCredentials(): void
    {
        if (! defined('ENVIRONMENT') || \ENVIRONMENT !== 'production') {
            return;
        }

        if (strtolower($this->masterUsername) === 'root' || $this->masterPassword === '') {
            try {
                log_message('critical', 'Tenancy: master DB uses root and/or an empty password. Set database.master.username/password in .env to a dedicated least-privilege user.');
            } catch (\Throwable) {
                // logger not ready this early; never break boot over a warning
            }
        }
    }

    public function isPortalHost(string $host): bool
    {
        $host = strtolower(trim($host));
        if (str_contains($host, ':')) {
            $host = explode(':', $host, 2)[0];
        }

        return in_array($host, $this->portalHosts, true);
    }
}
