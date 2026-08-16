<?php

declare(strict_types=1);

namespace App\Libraries;

use Config\Database;
use Throwable;

/**
 * Aggregate health + performance stats across tenant DBs for platform console.
 */
class PlatformStatsService
{
    public function __construct(
        protected MasterTenantRepository $master = new MasterTenantRepository(),
        protected TenantConnection $connection = new TenantConnection(),
    ) {
    }

    /**
     * @return array{
     *   totals: array<string, int|float>,
     *   clients: list<array<string, mixed>>
     * }
     */
    public function dashboard(): array
    {
        $tenants = $this->master->listActiveTenants();
        $clients = [];
        $totals  = [
            'clients'      => 0,
            'online'       => 0,
            'warn'         => 0,
            'down'         => 0,
            'users'        => 0,
            'contacts'     => 0,
            'campaigns'    => 0,
            'sent'         => 0,
            'delivered'    => 0,
            'failed'       => 0,
            'replies'      => 0,
            'open_chats'   => 0,
            'queue'        => 0,
            'meta_ready'   => 0,
        ];

        foreach ($tenants as $tenant) {
            $row = $this->clientSnapshot($tenant);
            $clients[] = $row;
            $totals['clients']++;

            $health = (string) ($row['health'] ?? 'down');
            if ($health === 'ok') {
                $totals['online']++;
            } elseif ($health === 'warn') {
                $totals['warn']++;
            } else {
                $totals['down']++;
            }

            foreach (['users', 'contacts', 'campaigns', 'sent', 'delivered', 'failed', 'replies', 'open_chats', 'queue'] as $k) {
                $totals[$k] += (int) ($row[$k] ?? 0);
            }
            if (! empty($row['meta_ready'])) {
                $totals['meta_ready']++;
            }
        }

        $totals['delivery_rate'] = $totals['sent'] > 0
            ? round(($totals['delivered'] / $totals['sent']) * 100, 1)
            : 0.0;
        $totals['fail_rate'] = $totals['sent'] > 0
            ? round(($totals['failed'] / max(1, $totals['sent'] + $totals['failed'])) * 100, 1)
            : 0.0;

        $trend = $this->aggregateTrend($tenants, 14);
        $charts = $this->buildCharts($clients, $totals, $trend);

        return [
            'totals'  => $totals,
            'clients' => $clients,
            'trend'   => $trend,
            'charts'  => $charts,
        ];
    }

    /**
     * @param list<array<string, mixed>> $tenants
     * @return list<array{date:string,sent:int,delivered:int,failed:int,replies:int}>
     */
    protected function aggregateTrend(array $tenants, int $days): array
    {
        $byDate = [];
        foreach (AppDateTime::recentDaysYmd($days) as $date) {
            $byDate[$date] = [
                'date'      => $date,
                'sent'      => 0,
                'delivered' => 0,
                'failed'    => 0,
                'replies'   => 0,
            ];
        }

        foreach ($tenants as $tenant) {
            $key = strtolower(trim((string) ($tenant['key'] ?? '')));
            if ($key === '') {
                continue;
            }
            try {
                if (! $this->connection->apply($key, 'platform-stats')) {
                    continue;
                }
                $db = Database::connect();
                foreach ($this->messagesByDay($db, $days) as $day) {
                    $d = (string) ($day['date'] ?? '');
                    if (! isset($byDate[$d])) {
                        continue;
                    }
                    $byDate[$d]['sent']      += (int) ($day['sent'] ?? 0);
                    $byDate[$d]['delivered'] += (int) ($day['delivered'] ?? 0);
                    $byDate[$d]['failed']    += (int) ($day['failed'] ?? 0);
                    $byDate[$d]['replies']   += (int) ($day['replies'] ?? 0);
                }
            } catch (Throwable $e) {
                log_message('error', 'PlatformStatsService::aggregateTrend [{key}]: {msg}', [
                    'key' => $key,
                    'msg' => $e->getMessage(),
                ]);
            }
        }

        return array_values($byDate);
    }

    /**
     * @param list<array<string, mixed>> $clients
     * @param array<string, mixed> $totals
     * @param list<array<string, mixed>> $trend
     * @return array<string, mixed>
     */
    protected function buildCharts(array $clients, array $totals, array $trend): array
    {
        $labels = [];
        $sent = [];
        $delivered = [];
        $failed = [];
        $replies = [];
        foreach ($trend as $day) {
            $labels[]    = date('M j', strtotime((string) $day['date']));
            $sent[]      = (int) ($day['sent'] ?? 0);
            $delivered[] = (int) ($day['delivered'] ?? 0);
            $failed[]    = (int) ($day['failed'] ?? 0);
            $replies[]   = (int) ($day['replies'] ?? 0);
        }

        $clientLabels = [];
        $clientContacts = [];
        $clientSent = [];
        foreach ($clients as $c) {
            $clientLabels[]   = (string) ($c['name'] ?? $c['key'] ?? 'Client');
            $clientContacts[] = (int) ($c['contacts'] ?? 0);
            $clientSent[]     = (int) ($c['sent'] ?? 0);
        }

        return [
            'trend' => [
                'labels'    => $labels,
                'sent'      => $sent,
                'delivered' => $delivered,
                'failed'    => $failed,
                'replies'   => $replies,
            ],
            'clients' => [
                'labels'   => $clientLabels,
                'contacts' => $clientContacts,
                'sent'     => $clientSent,
            ],
            'health' => [
                'labels' => ['Healthy', 'Needs setup', 'Offline'],
                'values' => [
                    (int) ($totals['online'] ?? 0),
                    (int) ($totals['warn'] ?? 0),
                    (int) ($totals['down'] ?? 0),
                ],
            ],
            'delivery' => [
                'labels' => ['Delivered', 'Failed', 'Other sent'],
                'values' => [
                    (int) ($totals['delivered'] ?? 0),
                    (int) ($totals['failed'] ?? 0),
                    max(0, (int) ($totals['sent'] ?? 0) - (int) ($totals['delivered'] ?? 0)),
                ],
            ],
        ];
    }

    /**
     * Deep stats for one tenant (already applied connection optional).
     *
     * @param array<string, mixed> $tenant
     * @return array<string, mixed>
     */
    public function clientDeep(array $tenant, bool $alreadyConnected = false): array
    {
        $snap = $this->clientSnapshot($tenant, $alreadyConnected);
        $key  = (string) ($tenant['key'] ?? '');

        $trend = [];
        $recentCampaigns = [];
        $adminEmail = '';
        $adminName  = '';
        $usersActive = 0;
        $lastMessageAt = null;

        if (($snap['health'] ?? '') !== 'down') {
            try {
                if (! $alreadyConnected) {
                    $this->connection->apply($key, 'platform-stats');
                }
                $db = Database::connect();
                $trend = $this->messagesByDay($db, 14);
                if ($db->tableExists('campaigns')) {
                    $recentCampaigns = $db->table('campaigns')
                        ->select('id, name, status, created_at')
                        ->orderBy('created_at', 'DESC')
                        ->limit(5)
                        ->get()
                        ->getResultArray();
                }
                if ($db->tableExists('users')) {
                    $usersActive = (int) $db->table('users')->where('status', 'active')->countAllResults();
                }
                if ($db->tableExists('messages')) {
                    $last = $db->table('messages')->selectMax('created_at')->get()->getRowArray();
                    $lastMessageAt = $last['created_at'] ?? null;
                }
            } catch (Throwable $e) {
                log_message('error', 'PlatformStatsService::clientDeep failed [{key}]: {msg}', [
                    'key' => $key,
                    'msg' => $e->getMessage(),
                ]);
            }
        }

        try {
            $idx = MasterTenantRepository::masterConnection()
                ->table('tenant_login_index')
                ->where('tenant_key', $key)
                ->orderBy('id', 'ASC')
                ->get()
                ->getRowArray();
            $adminEmail = is_array($idx) ? (string) ($idx['email'] ?? '') : '';
        } catch (Throwable) {
            $adminEmail = '';
        }

        if ($adminEmail !== '' && ($snap['health'] ?? '') !== 'down') {
            try {
                $user = Database::connect()->table('users')->where('email', $adminEmail)->get()->getRowArray();
                $adminName = is_array($user) ? (string) ($user['name'] ?? '') : '';
            } catch (Throwable) {
                $adminName = '';
            }
        }

        $snap['trend']            = $trend;
        $snap['recent_campaigns'] = $recentCampaigns;
        $snap['admin_email']      = $adminEmail;
        $snap['admin_name']       = $adminName;
        $snap['users_active']     = $usersActive;
        $snap['last_message_at']  = $lastMessageAt;
        $snap['delivery_rate']    = ((int) ($snap['sent'] ?? 0)) > 0
            ? round(((int) $snap['delivered'] / (int) $snap['sent']) * 100, 1)
            : 0.0;
        $snap['fail_rate'] = ((int) ($snap['sent'] ?? 0) + (int) ($snap['failed'] ?? 0)) > 0
            ? round(((int) $snap['failed'] / max(1, (int) $snap['sent'] + (int) $snap['failed'])) * 100, 1)
            : 0.0;

        return $snap;
    }

    /**
     * @param array<string, mixed> $tenant
     * @return array<string, mixed>
     */
    public function clientSnapshot(array $tenant, bool $alreadyConnected = false): array
    {
        $key  = strtolower(trim((string) ($tenant['key'] ?? '')));
        $name = (string) ($tenant['name'] ?? $key);
        $dbName = (string) ($tenant['db_database'] ?? '');

        $base = [
            'key'          => $key,
            'name'         => $name,
            'db_database'  => $dbName,
            'status'       => (string) ($tenant['status'] ?? 'active'),
            'health'       => 'down',
            'health_label' => 'Offline',
            'error'        => '',
            'users'        => 0,
            'contacts'     => 0,
            'campaigns'    => 0,
            'sent'         => 0,
            'delivered'    => 0,
            'read'         => 0,
            'failed'       => 0,
            'replies'      => 0,
            'open_chats'   => 0,
            'queue'        => 0,
            'meta_ready'   => false,
            'phone_number_id' => '',
            'app_name'     => $name,
        ];

        if ($key === '') {
            $base['error'] = 'Missing tenant key';

            return $base;
        }

        try {
            if (! $alreadyConnected && ! $this->connection->apply($key, 'platform-stats')) {
                $base['error'] = 'DB connect failed';

                return $base;
            }

            $db = Database::connect();
            $db->query('SELECT 1');

            $base['users'] = $db->tableExists('users')
                ? (int) $db->table('users')->countAllResults()
                : 0;
            $base['contacts'] = $db->tableExists('contacts')
                ? (int) $db->table('contacts')->where('deleted_at', null)->countAllResults()
                : 0;
            $base['campaigns'] = $db->tableExists('campaigns')
                ? (int) $db->table('campaigns')->countAllResults()
                : 0;
            $base['open_chats'] = $db->tableExists('conversations')
                ? (int) $db->table('conversations')->where('status', 'open')->countAllResults()
                : 0;
            $base['queue'] = $db->tableExists('message_queue')
                ? (int) $db->table('message_queue')->where('status', 'pending')->countAllResults()
                : 0;

            if ($db->tableExists('messages')) {
                $msg = $db->table('messages')
                    ->select("
                        SUM(CASE WHEN direction = 'outbound' AND status IN ('sent','delivered','read') THEN 1 ELSE 0 END) AS sent,
                        SUM(CASE WHEN direction = 'outbound' AND status IN ('delivered','read') THEN 1 ELSE 0 END) AS delivered,
                        SUM(CASE WHEN direction = 'outbound' AND status = 'read' THEN 1 ELSE 0 END) AS `read`,
                        SUM(CASE WHEN direction = 'outbound' AND status = 'failed' THEN 1 ELSE 0 END) AS failed,
                        SUM(CASE WHEN direction = 'inbound' THEN 1 ELSE 0 END) AS replies
                    ", false)
                    ->get()
                    ->getRowArray();
                $base['sent']      = (int) ($msg['sent'] ?? 0);
                $base['delivered'] = (int) ($msg['delivered'] ?? 0);
                $base['read']      = (int) ($msg['read'] ?? 0);
                $base['failed']    = (int) ($msg['failed'] ?? 0);
                $base['replies']   = (int) ($msg['replies'] ?? 0);
            }

            $metaReady = false;
            $phoneId   = '';
            $appName   = $name;
            if ($db->tableExists('settings')) {
                // Fresh instance — shared SettingsService caches values across tenants.
                $settings = new SettingsService();
                $meta = $settings->getMetaConfig();
                $phoneId = trim((string) ($meta['phone_number_id'] ?? ''));
                $token   = trim((string) ($meta['access_token'] ?? ''));
                $metaReady = $phoneId !== '' && $token !== '';
                $appName = (string) $settings->get('app_name', $name);
            }
            $base['meta_ready']      = $metaReady;
            $base['phone_number_id'] = $phoneId;
            $base['app_name']        = $appName;

            if (! $metaReady) {
                $base['health']       = 'warn';
                $base['health_label'] = 'Needs Meta setup';
            } else {
                $base['health']       = 'ok';
                $base['health_label'] = 'Healthy';
            }

            return $base;
        } catch (Throwable $e) {
            $base['error']        = $e->getMessage();
            $base['health']       = 'down';
            $base['health_label'] = 'Offline';

            return $base;
        }
    }

    /**
     * @return list<array{date:string,sent:int,delivered:int,failed:int,replies:int}>
     */
    protected function messagesByDay(object $db, int $days): array
    {
        if (! $db->tableExists('messages')) {
            return [];
        }

        $result = [];
        foreach (AppDateTime::recentDaysYmd($days) as $date) {
            [$from, $to] = app_day_bounds_utc($date);
            $row = $db->table('messages')
                ->select("
                    SUM(CASE WHEN direction = 'outbound' AND status IN ('sent','delivered','read') THEN 1 ELSE 0 END) AS sent,
                    SUM(CASE WHEN direction = 'outbound' AND status IN ('delivered','read') THEN 1 ELSE 0 END) AS delivered,
                    SUM(CASE WHEN direction = 'outbound' AND status = 'failed' THEN 1 ELSE 0 END) AS failed,
                    SUM(CASE WHEN direction = 'inbound' THEN 1 ELSE 0 END) AS replies
                ", false)
                ->where('created_at >=', $from)
                ->where('created_at <=', $to)
                ->get()
                ->getRowArray();

            $result[] = [
                'date'      => $date,
                'sent'      => (int) ($row['sent'] ?? 0),
                'delivered' => (int) ($row['delivered'] ?? 0),
                'failed'    => (int) ($row['failed'] ?? 0),
                'replies'   => (int) ($row['replies'] ?? 0),
            ];
        }

        return $result;
    }
}
