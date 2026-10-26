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
     * Master DB connection (routing only), filled in the constructor from the set that applies to this
     * request. See isLocalRequest() for how local vs production is decided.
     */
    public string $masterHostname = 'localhost';
    public string $masterUsername = '';
    public string $masterPassword = '';
    public string $masterDatabase = '';

    public int $masterPort = 3306;

    /** True when this request was treated as local; see isLocalRequest(). */
    public bool $isLocal = false;

    /**
     * Master DB credentials per environment. Edit here: no .env needed.
     *
     * WARNING: the production password is stored in this file. Do not push this file to a shared or
     * public git remote with real credentials in it.
     *
     * @var array{local: array<string, mixed>, production: array<string, mixed>}
     */
    protected array $masterSets = [
        'local' => [
            'hostname' => 'localhost',
            'username' => 'root',
            'password' => '',
            'database' => 'sateri_master',
            'port'     => 3306,
        ],
        'production' => [
            'hostname' => 'localhost',
            'username' => 'stadmin_sateri_connect',
            'password' => 'Darade@554',
            'database' => 'stadmin_sateri_connect',
            'port'     => 3306,
        ],
    ];

    public function __construct()
    {
        parent::__construct();

        $this->isLocal = $this->isLocalRequest();

        $set                  = $this->masterSets[$this->isLocal ? 'local' : 'production'];
        $this->masterHostname = (string) $set['hostname'];
        $this->masterUsername = (string) $set['username'];
        $this->masterPassword = (string) $set['password'];
        $this->masterDatabase = (string) $set['database'];
        $this->masterPort     = (int) $set['port'];

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

        $this->warnOnWeakMasterCredentials();
    }

    /**
     * Local (developer machine) or production?
     *
     * - CI_ENVIRONMENT=production is NEVER local, whatever the Host header says (it is attacker-controlled).
     * - Otherwise a web request is local when its host is localhost / 127.0.0.1 / ::1 / *.localhost or a
     *   dev tunnel (ngrok, cloudflared, ...); any other host uses the production set.
     * - CLI has no host, so a non-production CLI run counts as local.
     */
    protected function isLocalRequest(): bool
    {
        if (defined('ENVIRONMENT') && \ENVIRONMENT === 'production') {
            return false;
        }

        $host = (string) ($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '');
        if ($host === '') {
            return PHP_SAPI === 'cli';
        }

        $host = strtolower(trim($host));
        if (str_starts_with($host, '[')) {
            $host = explode(']', $host, 2)[0] . ']';
        } elseif (str_contains($host, ':')) {
            $host = explode(':', $host, 2)[0];
        }

        return in_array($host, ['localhost', '127.0.0.1', '::1', '[::1]'], true)
            || str_ends_with($host, '.localhost')
            || \App\Libraries\SubdomainDatabase::isLocalTunnelHost($host);
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

        $message = null;
        if ($this->masterDatabase === '' || $this->masterUsername === '') {
            $message = 'Tenancy: production master DB is not configured. Fill the production set in app/Config/Tenancy.php (multi-client routing is off until then).';
        } elseif (strtolower($this->masterUsername) === 'root' || $this->masterPassword === '') {
            $message = 'Tenancy: master DB uses root and/or an empty password. Use a dedicated least-privilege user in the production set of app/Config/Tenancy.php.';
        }

        if ($message !== null) {
            try {
                log_message('critical', $message);
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
