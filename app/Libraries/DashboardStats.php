<?php

declare(strict_types=1);

namespace App\Libraries;

use App\Models\CampaignModel;
use App\Models\EmailHtmlCampaignModel;
use App\Models\EmailLogModel;
use App\Models\EmailRecipientEventModel;

/**
 * Channel-level aggregates (WhatsApp + Email) for the dashboard.
 */
class DashboardStats
{
    /**
     * WhatsApp message counts, optionally limited to a UTC created_at range.
     *
     * @return array{sent:int,delivered:int,read:int,failed:int,replies:int}
     */
    public function whatsapp(?string $from = null, ?string $to = null): array
    {
        $builder = db_connect()->table('messages')->select("
            SUM(CASE WHEN direction = 'outbound' AND status IN ('sent','delivered','read') THEN 1 ELSE 0 END) AS sent,
            SUM(CASE WHEN direction = 'outbound' AND status IN ('delivered','read') THEN 1 ELSE 0 END) AS delivered,
            SUM(CASE WHEN direction = 'outbound' AND status = 'read' THEN 1 ELSE 0 END) AS `read`,
            SUM(CASE WHEN direction = 'outbound' AND status = 'failed' THEN 1 ELSE 0 END) AS failed,
            SUM(CASE WHEN direction = 'inbound' THEN 1 ELSE 0 END) AS replies
        ", false);

        if ($from !== null) {
            $builder->where('created_at >=', $from);
        }
        if ($to !== null) {
            $builder->where('created_at <=', $to);
        }

        $row = $builder->get()->getRowArray() ?? [];

        return [
            'sent'      => (int) ($row['sent'] ?? 0),
            'delivered' => (int) ($row['delivered'] ?? 0),
            'read'      => (int) ($row['read'] ?? 0),
            'failed'    => (int) ($row['failed'] ?? 0),
            'replies'   => (int) ($row['replies'] ?? 0),
        ];
    }

    /**
     * Email send counts plus SES bounces, optionally limited to a UTC created_at range.
     *
     * @return array{sent:int,failed:int,opened:int,clicked:int,bounced:int,open_rate:float}
     */
    public function email(?string $from = null, ?string $to = null): array
    {
        try {
            $summary = model(EmailLogModel::class)->summary($from, $to);

            $bounces = model(EmailRecipientEventModel::class)
                ->where('event_type', EmailRecipientEventModel::TYPE_BOUNCE);
            if ($from !== null) {
                $bounces->where('created_at >=', $from);
            }
            if ($to !== null) {
                $bounces->where('created_at <=', $to);
            }
            $bounced = $bounces->countAllResults();
        } catch (\Throwable $e) {
            log_message('error', 'DashboardStats::email failed: {msg}', ['msg' => $e->getMessage()]);
            $summary = [];
            $bounced = 0;
        }

        return [
            'sent'      => (int) ($summary['sent'] ?? 0),
            'failed'    => (int) ($summary['failed'] ?? 0),
            'opened'    => (int) ($summary['opened'] ?? 0),
            'clicked'   => (int) ($summary['clicked'] ?? 0),
            'bounced'   => $bounced,
            'open_rate' => (float) ($summary['open_rate'] ?? 0.0),
        ];
    }

    /**
     * WhatsApp counts for many UTC ranges in ONE scan of `messages`.
     *
     * @param array<string, array{0:?string,1:?string}> $ranges key => [from, to] (null = open)
     *
     * @return array<string, array{sent:int,delivered:int,read:int,failed:int,replies:int}>
     */
    public function whatsappBuckets(array $ranges): array
    {
        $db     = db_connect();
        $select = [];
        $i      = 0;
        foreach ($ranges as [$from, $to]) {
            $in = $this->rangeSql($db, $from, $to);
            $select[] = "SUM(CASE WHEN {$in} AND direction = 'outbound' AND status IN ('sent','delivered','read') THEN 1 ELSE 0 END) AS s{$i}";
            $select[] = "SUM(CASE WHEN {$in} AND direction = 'outbound' AND status IN ('delivered','read') THEN 1 ELSE 0 END) AS d{$i}";
            $select[] = "SUM(CASE WHEN {$in} AND direction = 'outbound' AND status = 'read' THEN 1 ELSE 0 END) AS r{$i}";
            $select[] = "SUM(CASE WHEN {$in} AND direction = 'outbound' AND status = 'failed' THEN 1 ELSE 0 END) AS f{$i}";
            $select[] = "SUM(CASE WHEN {$in} AND direction = 'inbound' THEN 1 ELSE 0 END) AS i{$i}";
            $i++;
        }

        $row = $db->table('messages')->select(implode(', ', $select), false)->get()->getRowArray() ?? [];

        $out = [];
        $i   = 0;
        foreach (array_keys($ranges) as $key) {
            $out[$key] = [
                'sent'      => (int) ($row["s{$i}"] ?? 0),
                'delivered' => (int) ($row["d{$i}"] ?? 0),
                'read'      => (int) ($row["r{$i}"] ?? 0),
                'failed'    => (int) ($row["f{$i}"] ?? 0),
                'replies'   => (int) ($row["i{$i}"] ?? 0),
            ];
            $i++;
        }

        return $out;
    }

    /**
     * Email counts (+ bounces) for many UTC ranges: one scan of `email_logs`, one of the bounce events.
     *
     * @param array<string, array{0:?string,1:?string}> $ranges key => [from, to] (null = open)
     *
     * @return array<string, array{sent:int,failed:int,opened:int,clicked:int,bounced:int,open_rate:float}>
     */
    public function emailBuckets(array $ranges): array
    {
        $db      = db_connect();
        $logSel  = [];
        $bounceSel = [];
        $i       = 0;
        foreach ($ranges as [$from, $to]) {
            $in = $this->rangeSql($db, $from, $to);
            $logSel[]    = "SUM(CASE WHEN {$in} AND status = 'sent' THEN 1 ELSE 0 END) AS s{$i}";
            $logSel[]    = "SUM(CASE WHEN {$in} AND status = 'failed' THEN 1 ELSE 0 END) AS f{$i}";
            $logSel[]    = "SUM(CASE WHEN {$in} AND open_count > 0 THEN 1 ELSE 0 END) AS o{$i}";
            $logSel[]    = "SUM(CASE WHEN {$in} AND click_count > 0 THEN 1 ELSE 0 END) AS c{$i}";
            $bounceSel[] = "SUM(CASE WHEN {$in} THEN 1 ELSE 0 END) AS b{$i}";
            $i++;
        }

        $log = $bounce = [];
        try {
            $log = $db->table('email_logs')->select(implode(', ', $logSel), false)->get()->getRowArray() ?? [];
            $bounce = $db->table('email_recipient_events')
                ->select(implode(', ', $bounceSel), false)
                ->where('event_type', EmailRecipientEventModel::TYPE_BOUNCE)
                ->get()->getRowArray() ?? [];
        } catch (\Throwable $e) {
            log_message('error', 'DashboardStats::emailBuckets failed: {msg}', ['msg' => $e->getMessage()]);
        }

        $out = [];
        $i   = 0;
        foreach (array_keys($ranges) as $key) {
            $sent   = (int) ($log["s{$i}"] ?? 0);
            $opened = (int) ($log["o{$i}"] ?? 0);
            $out[$key] = [
                'sent'      => $sent,
                'failed'    => (int) ($log["f{$i}"] ?? 0),
                'opened'    => $opened,
                'clicked'   => (int) ($log["c{$i}"] ?? 0),
                'bounced'   => (int) ($bounce["b{$i}"] ?? 0),
                'open_rate' => $sent > 0 ? round(($opened / $sent) * 100, 1) : 0.0,
            ];
            $i++;
        }

        return $out;
    }

    /**
     * Per-day sent/failed for both channels, built from already-fetched `d:YYYY-MM-DD` buckets
     * (see whatsappBuckets / emailBuckets) so no query runs per day.
     *
     * @param list<string>                $dates app-local Y-m-d, oldest first
     * @param array<string, array<string,int>> $wa    whatsappBuckets() result
     * @param array<string, array<string,mixed>> $email emailBuckets() result
     *
     * @return array{labels:list<string>,whatsapp:list<int>,email:list<int>,failed:list<int>,replies:list<int>}
     */
    public function dailyTrend(array $dates, array $wa, array $email): array
    {
        $out = ['labels' => [], 'whatsapp' => [], 'email' => [], 'failed' => [], 'replies' => []];
        foreach ($dates as $date) {
            $w = $wa['d:' . $date] ?? ['sent' => 0, 'failed' => 0, 'replies' => 0];
            $e = $email['d:' . $date] ?? ['sent' => 0, 'failed' => 0];

            $out['labels'][]   = date('M j', strtotime($date));
            $out['whatsapp'][] = (int) $w['sent'];
            $out['email'][]    = (int) $e['sent'];
            $out['failed'][]   = (int) $w['failed'] + (int) $e['failed'];
            $out['replies'][]  = (int) $w['replies'];
        }

        return $out;
    }

    /**
     * SQL predicate limiting `created_at` to [from, to]; '1=1' when both are open.
     */
    protected function rangeSql(\CodeIgniter\Database\BaseConnection $db, ?string $from, ?string $to): string
    {
        $parts = [];
        if ($from !== null) {
            $parts[] = 'created_at >= ' . $db->escape($from);
        }
        if ($to !== null) {
            $parts[] = 'created_at <= ' . $db->escape($to);
        }

        return $parts === [] ? '1=1' : '(' . implode(' AND ', $parts) . ')';
    }

    /**
     * Campaign counts per status across both channels.
     *
     * @return array<string, int>
     */
    public function campaignStatus(): array
    {
        $out = [];
        foreach ([CampaignModel::class, EmailHtmlCampaignModel::class] as $class) {
            try {
                $rows = model($class)->select('status, COUNT(*) AS total')->groupBy('status')->get()->getResultArray();
            } catch (\Throwable $e) {
                continue;
            }
            foreach ($rows as $row) {
                $status       = (string) ($row['status'] ?? 'draft');
                $out[$status] = ($out[$status] ?? 0) + (int) $row['total'];
            }
        }

        return $out;
    }

    /**
     * Latest campaigns from both channels, newest first.
     *
     * @return list<array{channel:string,name:string,status:string,sent:int,url:string}>
     */
    public function recentCampaigns(int $limit): array
    {
        $rows = [];
        foreach (model(CampaignModel::class)->orderBy('created_at', 'DESC')->findAll($limit) as $c) {
            $rows[] = $this->campaignRow('whatsapp', $c, site_url('campaigns/' . (int) $c['id']));
        }

        try {
            foreach (model(EmailHtmlCampaignModel::class)->orderBy('created_at', 'DESC')->findAll($limit) as $c) {
                $rows[] = $this->campaignRow('email', $c, site_url('campaigns/email/' . (int) $c['id']));
            }
        } catch (\Throwable $e) {
            log_message('error', 'DashboardStats::recentCampaigns email failed: {msg}', ['msg' => $e->getMessage()]);
        }

        usort($rows, static fn (array $a, array $b): int => strcmp($b['created_at'], $a['created_at']));

        return array_slice($rows, 0, $limit);
    }

    /**
     * @param array<string, mixed> $c
     *
     * @return array{channel:string,name:string,status:string,sent:int,url:string,created_at:string}
     */
    protected function campaignRow(string $channel, array $c, string $url): array
    {
        return [
            'channel'    => $channel,
            'name'       => (string) ($c['name'] ?? 'Campaign'),
            'status'     => (string) ($c['status'] ?? 'draft'),
            'sent'       => (int) ($c['sent_count'] ?? 0),
            'url'        => $url,
            'created_at' => (string) ($c['created_at'] ?? ''),
        ];
    }
}
