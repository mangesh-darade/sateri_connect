<?php

namespace App\Libraries;

use Config\Database;

/**
 * CI4 database defaults + subdomain detect/boot.
 * Tenant credentials: ONLY Config\Database::applyBySubdomain() — never .env database.default.*.
 */
class SubdomainDatabase
{
    /**
     * @return array<string, mixed>
     */
    public static function defaultConnection(): array
    {
        return [
            'DSN'          => '',
            'hostname'     => 'localhost',
            'username'     => '',
            'password'     => '',
            'database'     => '',
            'DBDriver'     => 'MySQLi',
            'DBPrefix'     => '',
            'pConnect'     => false,
            'DBDebug'      => false,
            'charset'      => 'utf8mb4',
            'DBCollat'     => 'utf8mb4_general_ci',
            'swapPre'      => '',
            'encrypt'      => false,
            'compress'     => false,
            'strictOn'     => false,
            'failover'     => [],
            'port'         => 3306,
            'numberNative' => false,
            'foundRows'    => false,
            'dateFormat'   => [
                'date'     => 'Y-m-d',
                'datetime' => 'Y-m-d H:i:s',
                'time'     => 'H:i:s',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function testsConnection(): array
    {
        return [
            'DSN'         => '',
            'hostname'    => '127.0.0.1',
            'username'    => '',
            'password'    => '',
            'database'    => ':memory:',
            'DBDriver'    => 'SQLite3',
            'DBPrefix'    => 'db_',
            'pConnect'    => false,
            'DBDebug'     => true,
            'charset'     => 'utf8',
            'DBCollat'    => '',
            'swapPre'     => '',
            'encrypt'     => false,
            'compress'    => false,
            'strictOn'    => true,
            'failover'    => [],
            'port'        => 3306,
            'foreignKeys' => true,
            'busyTimeout' => 1000,
            'synchronous' => null,
            'dateFormat'  => [
                'date'     => 'Y-m-d',
                'datetime' => 'Y-m-d H:i:s',
                'time'     => 'H:i:s',
            ],
        ];
    }

    /**
     * Called from Config\Database::__construct after parent::__construct().
     * Wipes any .env database.default.* merge, then applies subdomain switch only.
     */
    public static function boot(Database $db): void
    {
        if (\ENVIRONMENT === 'testing') {
            $db->defaultGroup = 'tests';
            $db->tests        = self::testsConnection();
            $db->tests['DBDebug'] = true;

            return;
        }

        // Ignore .env database.default.* — switch in Config\Database is the only source.
        $db->default            = self::defaultConnection();
        $db->default['DBDebug'] = \ENVIRONMENT !== 'production';

        $db->applyBySubdomain(self::resolve());
    }

    public static function resolve(): string
    {
        // Optional CLI/dev force of which switch case to use (not connection credentials).
        $forced = env('database.tenant', env('TENANT_KEY', ''));
        if (is_string($forced) && $forced !== '') {
            return strtolower(trim($forced));
        }

        return self::detectSubdomain();
    }

    public static function detectSubdomain(): string
    {
        $host = '';
        if (! empty($_SERVER['HTTP_HOST'])) {
            $host = (string) $_SERVER['HTTP_HOST'];
        } elseif (! empty($_SERVER['SERVER_NAME'])) {
            $host = (string) $_SERVER['SERVER_NAME'];
        }

        $host = strtolower(trim($host));
        if ($host === '') {
            return 'localhost';
        }

        if (str_contains($host, ':')) {
            $host = explode(':', $host, 2)[0];
        }

        if ($host === 'localhost' || filter_var($host, FILTER_VALIDATE_IP)) {
            return 'localhost';
        }

        // Local webhook tunnels (cloudflared / ngrok / similar): Host is public, DB is still local.
        if (self::isLocalTunnelHost($host)) {
            return 'localhost';
        }

        $parts = explode('.', $host);
        $count = count($parts);

        if ($count < 3) {
            return $parts[0] !== '' ? $parts[0] : 'localhost';
        }

        if ($parts[0] === 'www' && $count >= 4) {
            return $parts[1];
        }

        if ($parts[0] === 'www') {
            return 'localhost';
        }

        return $parts[0];
    }

    /**
     * Public reverse-tunnel hostnames used for local Meta/Cheerio webhook delivery.
     */
    public static function isLocalTunnelHost(string $host): bool
    {
        $host = strtolower(trim($host));
        $suffixes = [
            '.trycloudflare.com',
            '.cfargotunnel.com',
            '.ngrok-free.app',
            '.ngrok-free.dev',
            '.ngrok.io',
            '.ngrok.app',
            '.loca.lt',
        ];

        foreach ($suffixes as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return true;
            }
        }

        return false;
    }
}
