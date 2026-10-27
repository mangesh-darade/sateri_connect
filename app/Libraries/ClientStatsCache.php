<?php

declare(strict_types=1);

namespace App\Libraries;

use Throwable;

/**
 * A copy of each client's headline numbers in the master DB (`tenant_stats_cache`), so the All clients page can
 * search, filter, sort and page with plain SQL instead of opening every client database on each view.
 *
 * Rows are refreshed when they are older than TTL (on page load, a few at a time) and by `php spark stats:refresh`
 * (cron), so a large fleet stays fresh without slowing the page.
 */
class ClientStatsCache
{
    public const TTL = 300;
    public const PAGE_REFRESH_LIMIT = 20;

    /** Dropdown filters => SQL condition per option. */
    public const FILTERS = [
        'health'   => ['ok' => "health = 'ok'", 'warn' => "health = 'warn'", 'down' => "health = 'down'"],
        'wallet'   => ['on' => 'wallet_enabled = 1', 'off' => 'wallet_enabled = 0', 'funded' => "wallet_state = 'ok'", 'low' => "wallet_state = 'low'", 'empty' => "wallet_state = 'empty'"],
        'wa'       => ['ready' => 'meta_ready = 1', 'setup' => 'meta_ready = 0'],
        'email'    => ['ready' => 'email_ready = 1', 'setup' => 'email_ready = 0'],
        'activity' => ['sending' => 'sent > 0', 'idle' => 'sent = 0 AND email_sent = 0', 'failures' => 'failed > 0', 'chats' => 'open_chats > 0'],
    ];

    /** Sort key => column. */
    public const SORTS = [
        'name' => 'name', 'health' => 'health_rank', 'users' => 'users', 'contacts' => 'contacts', 'sent' => 'sent',
        'failed' => 'failed', 'open_chats' => 'open_chats', 'email_sent' => 'email_sent', 'wallet' => 'wallet_inr',
    ];

    private PlatformStatsService $stats;
    private MasterTenantRepository $master;

    public function __construct(?PlatformStatsService $stats = null, ?MasterTenantRepository $master = null)
    {
        $this->stats  = $stats ?? new PlatformStatsService();
        $this->master = $master ?? new MasterTenantRepository();
    }

    protected function db()
    {
        return MasterTenantRepository::masterConnection();
    }

    public function ensureTable(): void
    {
        $this->db()->query(
            'CREATE TABLE IF NOT EXISTS `tenant_stats_cache` (
                `tenant_key` VARCHAR(64) NOT NULL,
                `name` VARCHAR(191) NOT NULL DEFAULT \'\',
                `db_database` VARCHAR(191) NOT NULL DEFAULT \'\',
                `country_code` VARCHAR(8) NOT NULL DEFAULT \'\',
                `health` VARCHAR(8) NOT NULL DEFAULT \'down\',
                `health_rank` TINYINT UNSIGNED NOT NULL DEFAULT 2,
                `meta_ready` TINYINT(1) NOT NULL DEFAULT 0,
                `email_ready` TINYINT(1) NOT NULL DEFAULT 0,
                `users` INT UNSIGNED NOT NULL DEFAULT 0,
                `contacts` INT UNSIGNED NOT NULL DEFAULT 0,
                `sent` INT UNSIGNED NOT NULL DEFAULT 0,
                `failed` INT UNSIGNED NOT NULL DEFAULT 0,
                `open_chats` INT UNSIGNED NOT NULL DEFAULT 0,
                `email_sent` INT UNSIGNED NOT NULL DEFAULT 0,
                `wallet_enabled` TINYINT(1) NOT NULL DEFAULT 0,
                `wallet_state` VARCHAR(8) NOT NULL DEFAULT \'off\',
                `wallet_balance` DECIMAL(16,4) NOT NULL DEFAULT 0,
                `wallet_currency` VARCHAR(3) NOT NULL DEFAULT \'\',
                `wallet_inr` DECIMAL(16,4) NOT NULL DEFAULT 0,
                `wallet_inr_ok` TINYINT(1) NOT NULL DEFAULT 1,
                `payload` LONGTEXT NULL,
                `refreshed_at` DATETIME NOT NULL,
                PRIMARY KEY (`tenant_key`),
                KEY `idx_tsc_health` (`health`),
                KEY `idx_tsc_wallet` (`wallet_state`, `wallet_enabled`),
                KEY `idx_tsc_country` (`country_code`),
                KEY `idx_tsc_name` (`name`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    }

    /**
     * Recompute one client and store it.
     *
     * @param array<string, mixed> $tenant a `tenants` row
     */
    public function refresh(array $tenant): void
    {
        $snap = $this->stats->clientSnapshot($tenant);
        $base = Currency::base();
        $cur  = strtoupper((string) ($snap['wallet_currency'] ?? $base));
        $bal  = (float) ($snap['wallet_balance'] ?? 0);
        // Column names say "inr" for historical reasons; the value is in the platform base currency.
        $inr  = (new WalletRateCard())->convert($bal, $cur, $base);
        $h    = in_array((string) ($snap['health'] ?? 'down'), ['ok', 'warn'], true) ? (string) $snap['health'] : 'down';

        $row = [
            'tenant_key'      => (string) $snap['key'],
            'name'            => mb_substr((string) ($snap['name'] ?? $snap['key']), 0, 191),
            'db_database'     => mb_substr((string) ($snap['db_database'] ?? ''), 0, 191),
            'country_code'    => (string) ($snap['country_code'] ?? ''),
            'health'          => $h,
            'health_rank'     => ['ok' => 0, 'warn' => 1, 'down' => 2][$h],
            'meta_ready'      => ! empty($snap['meta_ready']) ? 1 : 0,
            'email_ready'     => ! empty($snap['email_ready']) ? 1 : 0,
            'users'           => (int) ($snap['users'] ?? 0),
            'contacts'        => (int) ($snap['contacts'] ?? 0),
            'sent'            => (int) ($snap['sent'] ?? 0),
            'failed'          => (int) ($snap['failed'] ?? 0),
            'open_chats'      => (int) ($snap['open_chats'] ?? 0),
            'email_sent'      => (int) ($snap['email_sent'] ?? 0),
            'wallet_enabled'  => ! empty($snap['wallet_enabled']) ? 1 : 0,
            'wallet_state'    => (string) ($snap['wallet_state'] ?? 'off'),
            'wallet_balance'  => $bal,
            'wallet_currency' => $cur,
            'wallet_inr'      => $inr ?? $bal,
            'wallet_inr_ok'   => $inr === null ? 0 : 1,
            'payload'         => json_encode($snap, JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}',
            'refreshed_at'    => date('Y-m-d H:i:s'),
        ];

        $db = $this->db();
        $exists = $db->table('tenant_stats_cache')->where('tenant_key', $row['tenant_key'])->countAllResults() > 0;
        $exists
            ? $db->table('tenant_stats_cache')->where('tenant_key', $row['tenant_key'])->update($row)
            : $db->table('tenant_stats_cache')->insert($row);
    }

    /**
     * Refresh what is missing or stale (or everything with $force) and drop clients that no longer exist.
     *
     * @return array{refreshed: int, total: int, remaining: int}
     */
    public function refreshStale(?int $platformAdminId, bool $force = false, int $limit = self::PAGE_REFRESH_LIMIT): array
    {
        $this->ensureTable();
        $tenants = $this->master->listActiveTenants($platformAdminId);
        $keys    = array_map(static fn (array $t): string => strtolower(trim((string) ($t['key'] ?? ''))), $tenants);

        $db = $this->db();
        $age = [];
        foreach ($db->table('tenant_stats_cache')->select('tenant_key, refreshed_at')->get()->getResultArray() as $r) {
            $age[(string) $r['tenant_key']] = (int) strtotime((string) $r['refreshed_at']);
        }

        $due = [];
        foreach ($tenants as $t) {
            $k = strtolower(trim((string) ($t['key'] ?? '')));
            if ($k !== '' && ($force || ! isset($age[$k]) || time() - $age[$k] > self::TTL)) {
                $due[] = $t;
            }
        }

        $done     = 0;
        $captured = (new TenantConnection())->capture();
        foreach (array_slice($due, 0, $limit > 0 ? $limit : null) as $t) {
            try {
                $this->refresh($t);
                $done++;
            } catch (Throwable $e) {
                log_message('error', 'ClientStatsCache refresh [{k}]: {m}', ['k' => (string) ($t['key'] ?? ''), 'm' => $e->getMessage()]);
            }
        }

        $this->stats->restoreConnection($captured);

        // Clients that were deleted or deactivated must not linger in the list (primary admin sees them all).
        if ($platformAdminId === null || $this->master->isPrimaryPlatformAdmin((int) $platformAdminId)) {
            $stale = array_diff(array_keys($age), $keys);
            if ($stale !== []) {
                $db->table('tenant_stats_cache')->whereIn('tenant_key', array_values($stale))->delete();
            }
        }

        return ['refreshed' => $done, 'total' => count($tenants), 'remaining' => max(0, count($due) - $done)];
    }

    /**
     * One page of clients with search, filters and sorting done in SQL.
     *
     * @param array<string, string> $f   health, wallet, wa, email, activity, country, q
     * @param list<string>          $keys clients this admin may see
     *
     * @return array{rows: list<array<string, mixed>>, total: int, counts: array<string, mixed>, wallet_total: float, wallet_missing: list<string>, refreshed_at: string, countries: array<string, string>}
     */
    public function query(array $f, string $sort, string $dir, int $page, int $per, array $keys): array
    {
        $this->ensureTable();
        $empty = ['rows' => [], 'total' => 0, 'counts' => $this->emptyCounts(), 'wallet_total' => 0.0, 'wallet_missing' => [], 'refreshed_at' => '', 'countries' => []];
        if ($keys === []) {
            return $empty;
        }

        $db   = $this->db();
        $base = static fn () => $db->table('tenant_stats_cache')->whereIn('tenant_key', $keys);

        // Counts and wallet total always describe every client the admin can see.
        $counts = $this->emptyCounts();
        $sums = $base()->select(
            'COUNT(*) AS n,'
            . "SUM(health='ok') AS h_ok, SUM(health='warn') AS h_warn, SUM(health='down') AS h_down,"
            . 'SUM(wallet_enabled=1) AS w_on, SUM(wallet_enabled=0) AS w_off,'
            . "SUM(wallet_state='ok') AS w_funded, SUM(wallet_state='low') AS w_low, SUM(wallet_state='empty') AS w_empty,"
            . 'SUM(meta_ready=1) AS wa_ready, SUM(meta_ready=0) AS wa_setup, SUM(email_ready=1) AS em_ready, SUM(email_ready=0) AS em_setup,'
            . 'SUM(sent>0) AS a_sending, SUM(sent=0 AND email_sent=0) AS a_idle, SUM(failed>0) AS a_failures, SUM(open_chats>0) AS a_chats,'
            . 'COALESCE(SUM(wallet_inr),0) AS wallet_total, MIN(refreshed_at) AS oldest',
            false
        )->get()->getRowArray() ?: [];
        $counts['all'] = (int) ($sums['n'] ?? 0);
        foreach (['health' => ['ok' => 'h_ok', 'warn' => 'h_warn', 'down' => 'h_down'], 'wallet' => ['on' => 'w_on', 'off' => 'w_off', 'funded' => 'w_funded', 'low' => 'w_low', 'empty' => 'w_empty'],
                  'wa' => ['ready' => 'wa_ready', 'setup' => 'wa_setup'], 'email' => ['ready' => 'em_ready', 'setup' => 'em_setup'],
                  'activity' => ['sending' => 'a_sending', 'idle' => 'a_idle', 'failures' => 'a_failures', 'chats' => 'a_chats']] as $name => $map) {
            foreach ($map as $opt => $col) {
                $counts[$name][$opt] = (int) ($sums[$col] ?? 0);
            }
        }
        $counts += ['ok' => $counts['health']['ok'], 'warn' => $counts['health']['warn'], 'down' => $counts['health']['down'],
                    'walleton' => $counts['wallet']['on'], 'walletoff' => $counts['wallet']['off']];

        $missing = array_column($base()->select('DISTINCT wallet_currency AS c', false)->where('wallet_inr_ok', 0)->get()->getResultArray(), 'c');

        $countries = [];
        foreach ($base()->select('DISTINCT country_code AS c', false)->where('country_code !=', '')->get()->getResultArray() as $r) {
            $countries[(string) $r['c']] = WhatsAppCountries::nameOf((string) $r['c']) ?: '+' . $r['c'];
        }
        asort($countries);

        // The filtered, sorted page.
        $b = $base();
        foreach (self::FILTERS as $name => $opts) {
            $v = (string) ($f[$name] ?? '');
            if ($v !== '' && isset($opts[$v])) {
                $b->where($opts[$v], null, false);
            }
        }
        $country = (string) ($f['country'] ?? '');
        if ($country === 'none') {
            $b->where('country_code', '');
        } elseif ($country !== '') {
            $b->where('country_code', $country);
        }
        $q = trim((string) ($f['q'] ?? ''));
        if ($q !== '') {
            $codes = array_keys(array_filter(WhatsAppCountries::all(), static fn (string $n): bool => stripos($n, $q) !== false));
            $b->groupStart()->like('name', $q)->orLike('tenant_key', $q)->orLike('db_database', $q)->orLike('country_code', $q);
            if ($codes !== []) {
                $b->orWhereIn('country_code', array_map('strval', $codes));
            }
            $b->groupEnd();
        }

        $total = (clone $b)->countAllResults(false);
        $col   = self::SORTS[$sort] ?? 'name';
        $rows  = $b->orderBy($col, strtolower($dir) === 'desc' ? 'DESC' : 'ASC')->orderBy('tenant_key', 'ASC')
            ->limit($per, max(0, ($page - 1) * $per))->get()->getResultArray();

        $out = [];
        foreach ($rows as $r) {
            $snap = json_decode((string) ($r['payload'] ?? ''), true);
            $out[] = (is_array($snap) ? $snap : []) + ['key' => $r['tenant_key'], 'name' => $r['name']];
        }

        return [
            'rows' => $out, 'total' => $total, 'counts' => $counts, 'wallet_total' => (float) ($sums['wallet_total'] ?? 0),
            'wallet_missing' => array_values(array_map('strval', $missing)), 'refreshed_at' => (string) ($sums['oldest'] ?? ''), 'countries' => $countries,
        ];
    }

    /** @return array<string, mixed> */
    protected function emptyCounts(): array
    {
        $c = ['all' => 0, 'ok' => 0, 'warn' => 0, 'down' => 0, 'walleton' => 0, 'walletoff' => 0];
        foreach (self::FILTERS as $name => $opts) {
            foreach ($opts as $o => $_) {
                $c[$name][$o] = 0;
            }
        }

        return $c;
    }
}
