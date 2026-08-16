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
                static fn (string $h): string => strtolower(trim($h)),
                explode(',', $hosts)
            )));
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
