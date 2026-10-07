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
     * Per-day sent/failed for both channels over the last N days (app timezone).
     *
     * @return array{labels:list<string>,whatsapp:list<int>,email:list<int>,failed:list<int>,replies:list<int>}
     */
    public function dailyTrend(int $days): array
    {
        $dates = AppDateTime::recentDaysYmd($days);
        $email = [];
        try {
            foreach (model(EmailLogModel::class)->daily($dates[0], end($dates)) as $row) {
                $email[$row['date']] = $row;
            }
        } catch (\Throwable $e) {
            log_message('error', 'DashboardStats::dailyTrend email failed: {msg}', ['msg' => $e->getMessage()]);
        }

        $out = ['labels' => [], 'whatsapp' => [], 'email' => [], 'failed' => [], 'replies' => []];
        foreach ($dates as $date) {
            [$from, $to] = app_day_bounds_utc($date);
            $wa = $this->whatsapp($from, $to);
            $em = $email[$date] ?? ['sent' => 0, 'failed' => 0];

            $out['labels'][]   = date('M j', strtotime($date));
            $out['whatsapp'][] = $wa['sent'];
            $out['email'][]    = (int) $em['sent'];
            $out['failed'][]   = $wa['failed'] + (int) $em['failed'];
            $out['replies'][]  = $wa['replies'];
        }

        return $out;
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
