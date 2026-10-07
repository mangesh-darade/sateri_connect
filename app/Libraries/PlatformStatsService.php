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
            'email_ready'  => 0,
            'email_sent'   => 0,
            'email_failed' => 0,
            'email_opened' => 0,
            'email_clicked' => 0,
            'email_bounced' => 0,
            'email_unsubscribed' => 0,
        ];
        $emailKeys = ['email_sent', 'email_failed', 'email_opened', 'email_clicked', 'email_bounced', 'email_unsubscribed'];

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
            foreach ($emailKeys as $k) {
                $totals[$k] += (int) ($row[$k] ?? 0);
            }
            if (! empty($row['meta_ready'])) {
                $totals['meta_ready']++;
            }
            if (! empty($row['email_ready'])) {
                $totals['email_ready']++;
            }
        }

        $totals['email_open_rate']   = $this->rate($totals['email_opened'], $totals['email_sent']);
        $totals['email_bounce_rate'] = $this->rate($totals['email_bounced'], $totals['email_sent']);

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
        $clientEmailSent = [];
        foreach ($clients as $c) {
            $clientLabels[]    = (string) ($c['name'] ?? $c['key'] ?? 'Client');
            $clientContacts[]  = (int) ($c['contacts'] ?? 0);
            $clientSent[]      = (int) ($c['sent'] ?? 0);
            $clientEmailSent[] = (int) ($c['email_sent'] ?? 0);
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
                'contacts'   => $clientContacts,
                'sent'       => $clientSent,
                'email_sent' => $clientEmailSent,
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
        $emailTrend = [];
        $recentEmailCampaigns = [];
        $emailSenders = [];
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
                    $usersActive = (int) $db->table('users')->where('status', 'active')->where('deleted_at', null)->countAllResults();
                }
                if ($db->tableExists('messages')) {
                    $last = $db->table('messages')->selectMax('created_at')->get()->getRowArray();
                    $lastMessageAt = $last['created_at'] ?? null;
                }
                $emailTrend = $this->emailByDay($db, 14);
                if ($db->tableExists('email_html_campaigns')) {
                    $recentEmailCampaigns = $db->table('email_html_campaigns')
                        ->select('id, name, subject, status, sent_count, failed_count, sent_at, created_at')
                        ->orderBy('created_at', 'DESC')
                        ->limit(5)
                        ->get()
                        ->getResultArray();
                }
                if ($db->tableExists('email_senders')) {
                    $emailSenders = $db->table('email_senders')
                        ->select('id, type, provider, name, email, domain, status, is_default, last_checked_at')
                        ->orderBy('is_default', 'DESC')
                        ->orderBy('id', 'DESC')
                        ->limit(10)
                        ->get()
                        ->getResultArray();
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
        $snap['email_trend']      = $emailTrend;
        $snap['recent_email_campaigns'] = $recentEmailCampaigns;
        $snap['email_senders']    = $emailSenders;
        $emailSent = (int) $snap['email_sent'];
        $snap['email_open_rate']   = $this->rate((int) $snap['email_opened'], $emailSent);
        $snap['email_click_rate']  = $this->rate((int) $snap['email_clicked'], $emailSent);
        $snap['email_bounce_rate'] = $this->rate((int) $snap['email_bounced'], $emailSent);
        $snap['email_fail_rate']   = $this->rate((int) $snap['email_failed'], $emailSent + (int) $snap['email_failed']);
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
        ] + $this->emptyEmailStats();

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
                ? (int) $db->table('users')->where('deleted_at', null)->countAllResults()
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
                $base['email_provider']       = $settings->getEmailProvider();
                $base['email_provider_label'] = $settings->emailProviderLabel();
                $base['email_configured']     = $settings->isEmailConfigured();
            }
            $base['meta_ready']      = $metaReady;
            $base['phone_number_id'] = $phoneId;
            $base['app_name']        = $appName;

            $base = array_merge($base, $this->emailCounts($db));
            $base['email_ready'] = $base['email_configured']
                && ($base['email_senders_verified'] > 0 || $base['email_provider'] !== SettingsService::EMAIL_PROVIDER_SES);

            if ($metaReady && $base['email_ready']) {
                $base['health']       = 'ok';
                $base['health_label'] = 'Healthy';
            } elseif ($metaReady || $base['email_ready']) {
                $base['health']       = 'ok';
                $base['health_label'] = $metaReady ? 'WhatsApp only' : 'Email only';
            } else {
                $base['health']       = 'warn';
                $base['health_label'] = 'Needs channel setup';
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

    /**
     * @return array<string, mixed>
     */
    protected function emptyEmailStats(): array
    {
        return [
            'email_provider'         => '',
            'email_provider_label'   => '—',
            'email_configured'       => false,
            'email_ready'            => false,
            'email_jobs'             => 0,
            'email_sent'             => 0,
            'email_failed'           => 0,
            'email_delivered'        => 0,
            'email_opened'           => 0,
            'email_clicked'          => 0,
            'email_bounced'          => 0,
            'email_complained'       => 0,
            'email_unsubscribed'     => 0,
            'email_senders_total'    => 0,
            'email_senders_verified' => 0,
        ];
    }

    /**
     * Recipient-level email counts. Uses email_recipient_events when present,
     * falling back to job-level email_logs for workspaces without per-recipient tracking.
     *
     * @return array<string, int>
     */
    protected function emailCounts(object $db): array
    {
        $out = [];

        if ($db->tableExists('email_logs')) {
            $out['email_jobs'] = (int) $db->table('email_logs')->countAllResults();
        }

        $byType = [];
        if ($db->tableExists('email_recipient_events')) {
            $rows = $db->table('email_recipient_events')
                ->select('event_type, COUNT(*) AS c')
                ->groupBy('event_type')
                ->get()
                ->getResultArray();
            foreach ($rows as $r) {
                $byType[(string) $r['event_type']] = (int) $r['c'];
            }
        }

        $out['email_sent']       = $byType['sent'] ?? 0;
        $out['email_failed']     = $byType['failed'] ?? 0;
        $out['email_delivered']  = $byType['delivery'] ?? 0;
        $out['email_opened']     = $byType['open'] ?? 0;
        $out['email_clicked']    = $byType['click'] ?? 0;
        $out['email_bounced']    = $byType['bounce'] ?? 0;
        $out['email_complained'] = $byType['complaint'] ?? 0;

        foreach ($this->untrackedEmailJobs($db) as $job) {
            $out['email_sent']    += $job['sent'];
            $out['email_failed']  += $job['failed'];
            $out['email_opened']  += $job['opened'];
            $out['email_clicked'] += $job['clicked'];
        }

        if ($db->tableExists('email_unsubscribes')) {
            $out['email_unsubscribed'] = (int) $db->table('email_unsubscribes')
                ->where('is_active', 1)
                ->where('is_deleted', 0)
                ->countAllResults();
        }

        if ($db->tableExists('email_senders')) {
            $out['email_senders_total'] = (int) $db->table('email_senders')->countAllResults();
            $out['email_senders_verified'] = (int) $db->table('email_senders')
                ->where('status', 'verified')
                ->countAllResults();
        }

        return $out;
    }

    /**
     * @return list<array{date:string,sent:int,failed:int,opened:int,bounced:int}>
     */
    protected function emailByDay(object $db, int $days): array
    {
        $dates = AppDateTime::recentDaysYmd($days);
        if ($dates === []) {
            return [];
        }

        $byDate = [];
        foreach ($dates as $date) {
            $byDate[$date] = ['date' => $date, 'sent' => 0, 'failed' => 0, 'opened' => 0, 'bounced' => 0];
        }
        [$from, $to] = AppDateTime::rangeBoundsUtc($dates[0], $dates[count($dates) - 1]);

        if ($db->tableExists('email_recipient_events')) {
            $map = ['sent' => 'sent', 'failed' => 'failed', 'open' => 'opened', 'bounce' => 'bounced'];
            $rows = $db->table('email_recipient_events')
                ->select('event_type, first_at')
                ->whereIn('event_type', array_keys($map))
                ->where('first_at >=', $from)
                ->where('first_at <=', $to)
                ->get()
                ->getResultArray();
            foreach ($rows as $r) {
                $day = AppDateTime::format($r['first_at'] ?? null, 'Y-m-d', '');
                if (isset($byDate[$day])) {
                    $byDate[$day][$map[(string) $r['event_type']]]++;
                }
            }
        }

        foreach ($this->untrackedEmailJobs($db, $from, $to) as $job) {
            $day = AppDateTime::format($job['created_at'], 'Y-m-d', '');
            if (isset($byDate[$day])) {
                $byDate[$day]['sent']   += $job['sent'];
                $byDate[$day]['failed'] += $job['failed'];
                $byDate[$day]['opened'] += $job['opened'];
            }
        }

        return array_values($byDate);
    }

    /**
     * Email jobs logged before per-recipient tracking existed (no email_recipient_events rows).
     * Each job is expanded to its recipient count so totals stay comparable with tracked sends.
     *
     * @return list<array{created_at:?string,sent:int,failed:int,opened:int,clicked:int}>
     */
    protected function untrackedEmailJobs(object $db, ?string $from = null, ?string $to = null): array
    {
        if (! $db->tableExists('email_logs')) {
            return [];
        }

        $builder = $db->table('email_logs l')
            ->select('l.status, l.to_email, l.meta_json, l.opened_at, l.clicked_at, l.created_at')
            ->whereIn('l.status', ['sent', 'failed']);
        if ($db->tableExists('email_recipient_events')) {
            $builder->where('NOT EXISTS (SELECT 1 FROM ' . $db->prefixTable('email_recipient_events') . ' e WHERE e.log_id = l.id)', null, false);
        }
        if ($from !== null) {
            $builder->where('l.created_at >=', $from);
        }
        if ($to !== null) {
            $builder->where('l.created_at <=', $to);
        }

        $jobs = [];
        foreach ($builder->get()->getResultArray() as $row) {
            $recipients = $this->jobRecipientCount($row);
            $isSent     = ($row['status'] ?? '') === 'sent';
            $jobs[] = [
                'created_at' => $row['created_at'] ?? null,
                'sent'       => $isSent ? $recipients : 0,
                'failed'     => $isSent ? 0 : $recipients,
                'opened'     => ! empty($row['opened_at']) ? 1 : 0,
                'clicked'    => ! empty($row['clicked_at']) ? 1 : 0,
            ];
        }

        return $jobs;
    }

    /**
     * @param array<string, mixed> $row email_logs row
     */
    protected function jobRecipientCount(array $row): int
    {
        $meta = json_decode((string) ($row['meta_json'] ?? ''), true);
        if (is_array($meta)) {
            foreach (['result', 'raw'] as $k) {
                $res = $meta[$k] ?? null;
                if (! is_array($res)) {
                    continue;
                }
                $n = (int) ($res['sent'] ?? $res['emailCount'] ?? ($res['data']['emailCount'] ?? 0));
                if ($n > 0) {
                    return $n;
                }
            }
        }

        $to = trim((string) ($row['to_email'] ?? ''));
        if ($to === '' || str_starts_with($to, 'label:')) {
            return 1;
        }

        return max(1, count(array_filter(array_map('trim', explode(',', $to)))));
    }

    protected function rate(int $part, int $whole): float
    {
        return $whole > 0 ? round(($part / $whole) * 100, 1) : 0.0;
    }
}
