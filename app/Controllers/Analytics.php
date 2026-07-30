<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\CampaignModel;
use App\Models\EmailHtmlCampaignModel;
use App\Models\EmailLogModel;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Global Analytics — WhatsApp + Email tabs.
 */
class Analytics extends BaseController
{
    public function index(): string|ResponseInterface
    {
        if ($denied = $this->requirePermission('reports.view')) {
            return $denied;
        }

        $tab  = strtolower(trim((string) ($this->request->getGet('tab') ?: 'whatsapp')));
        if (! in_array($tab, ['whatsapp', 'email'], true)) {
            $tab = 'whatsapp';
        }

        $from = (string) ($this->request->getGet('from') ?: '');
        $to   = (string) ($this->request->getGet('to') ?: '');
        if ($from === '') {
            $from = (new \DateTimeImmutable('now', \App\Libraries\AppDateTime::appZone()))
                ->modify('first day of this month')
                ->format('Y-m-d');
        }
        if ($to === '') {
            $to = app_today_ymd();
        }
        [$fromUtc, $toUtc] = app_range_bounds_utc($from, $to);

        $waSummary = $this->whatsappStats($fromUtc, $toUtc);
        $waDaily   = $this->whatsappDaily($from, $to);

        $emailModel   = model(EmailLogModel::class);
        $emailSummary = $emailModel->summary($fromUtc, $toUtc);
        $emailDaily   = $emailModel->daily($from, $to);

        $emailCampaignRows = model(EmailHtmlCampaignModel::class)
            ->orderBy('id', 'DESC')
            ->findAll(20);
        $emailCampaigns = [];
        foreach ($emailCampaignRows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $campaignId = (int) ($row['id'] ?? 0);
            $subject    = trim((string) ($row['subject'] ?? ''));
            $emailCampaigns[] = [
                'id'         => $campaignId,
                'name'       => (string) ($row['name'] ?? ($subject !== '' ? $subject : ('Campaign #' . $campaignId))),
                'status'     => (string) ($row['status'] ?? 'draft'),
                'sent_count' => (int) ($row['sent_count'] ?? 0),
            ];
        }

        $waCampaignRows = model(CampaignModel::class)
            ->orderBy('created_at', 'DESC')
            ->findAll(20);
        $waCampaigns = [];
        foreach ($waCampaignRows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $waCampaignId = (int) ($row['id'] ?? 0);
            $waCampaigns[] = [
                'id'              => $waCampaignId,
                'name'            => (string) ($row['name'] ?? ('Campaign #' . $waCampaignId)),
                'status'          => (string) ($row['status'] ?? 'unknown'),
                'sent_count'      => (int) ($row['sent_count'] ?? 0),
                'delivered_count' => (int) ($row['delivered_count'] ?? 0),
                'failed_count'    => (int) ($row['failed_count'] ?? 0),
            ];
        }

        $waTrendLabels = [];
        $waTrendSent = [];
        $waTrendDelivered = [];
        $waTrendFailed = [];
        foreach ($waDaily as $day) {
            $waTrendLabels[]    = date('M j', strtotime($day['date']));
            $waTrendSent[]      = $day['sent'];
            $waTrendDelivered[] = $day['delivered'];
            $waTrendFailed[]    = $day['failed'];
        }

        $emailTrendLabels = [];
        $emailTrendSent = [];
        $emailTrendFailed = [];
        foreach ($emailDaily as $day) {
            $emailTrendLabels[] = date('M j', strtotime($day['date']));
            $emailTrendSent[]   = $day['sent'];
            $emailTrendFailed[] = $day['failed'];
        }

        $recentEmailLogRows = model(EmailLogModel::class)
            ->orderBy('id', 'DESC')
            ->findAll(25);
        $recentEmailLogs = [];
        foreach ($recentEmailLogRows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $recentEmailLogs[] = [
                'created_at' => (string) ($row['created_at'] ?? ''),
                'kind'       => (string) ($row['kind'] ?? ''),
                'to_email'   => (string) ($row['to_email'] ?? ''),
                'status'     => (string) ($row['status'] ?? 'unknown'),
            ];
        }

        return $this->render('analytics/index', [
            'pageTitle'  => 'Global Analytics',
            'activeTab'  => $tab,
            'from'       => $from,
            'to'         => $to,
            'wa'         => [
                'summary'   => $waSummary,
                'campaigns' => $waCampaigns,
                'charts'    => [
                    'labels'    => $waTrendLabels,
                    'sent'      => $waTrendSent,
                    'delivered' => $waTrendDelivered,
                    'failed'    => $waTrendFailed,
                ],
            ],
            'email' => [
                'summary'   => $emailSummary,
                'campaigns' => $emailCampaigns,
                'logs'      => $recentEmailLogs,
                'charts'    => [
                    'labels' => $emailTrendLabels,
                    'sent'   => $emailTrendSent,
                    'failed' => $emailTrendFailed,
                ],
            ],
        ]);
    }

    public function data(): ResponseInterface
    {
        if ($denied = $this->requirePermission('reports.view')) {
            return $denied;
        }

        $channel = strtolower(trim((string) ($this->request->getGet('channel') ?: 'whatsapp')));
        $from    = (string) ($this->request->getGet('from') ?: (new \DateTimeImmutable('now', \App\Libraries\AppDateTime::appZone()))->modify('first day of this month')->format('Y-m-d'));
        $to      = (string) ($this->request->getGet('to') ?: app_today_ymd());
        [$fromUtc, $toUtc] = app_range_bounds_utc($from, $to);

        if ($channel === 'email') {
            $model = model(EmailLogModel::class);

            return $this->jsonResponse(true, [
                'summary' => $model->summary($fromUtc, $toUtc),
                'daily'   => $model->daily($from, $to),
            ]);
        }

        return $this->jsonResponse(true, [
            'summary' => $this->whatsappStats($fromUtc, $toUtc),
            'daily'   => $this->whatsappDaily($from, $to),
        ]);
    }

    /**
     * @return array{sent:int,delivered:int,read:int,failed:int,replies:int}
     */
    protected function whatsappStats(string $from, string $to): array
    {
        $row = db_connect()->table('messages')
            ->select("
                SUM(CASE WHEN direction = 'outbound' AND status IN ('sent','delivered','read') THEN 1 ELSE 0 END) AS sent,
                SUM(CASE WHEN direction = 'outbound' AND status IN ('delivered','read') THEN 1 ELSE 0 END) AS delivered,
                SUM(CASE WHEN direction = 'outbound' AND status = 'read' THEN 1 ELSE 0 END) AS `read`,
                SUM(CASE WHEN direction = 'outbound' AND status = 'failed' THEN 1 ELSE 0 END) AS failed,
                SUM(CASE WHEN direction = 'inbound' THEN 1 ELSE 0 END) AS replies
            ", false)
            ->where('created_at >=', $from)
            ->where('created_at <=', $to)
            ->get()
            ->getRowArray();

        return [
            'sent'      => (int) ($row['sent'] ?? 0),
            'delivered' => (int) ($row['delivered'] ?? 0),
            'read'      => (int) ($row['read'] ?? 0),
            'failed'    => (int) ($row['failed'] ?? 0),
            'replies'   => (int) ($row['replies'] ?? 0),
        ];
    }

    /**
     * @return list<array{date:string,sent:int,delivered:int,read:int,failed:int,replies:int}>
     */
    protected function whatsappDaily(string $from, string $to): array
    {
        try {
            $start = new \DateTimeImmutable($from, \App\Libraries\AppDateTime::appZone());
            $end   = new \DateTimeImmutable($to, \App\Libraries\AppDateTime::appZone());
        } catch (\Throwable $e) {
            return [];
        }

        if ($end < $start) {
            return [];
        }

        $out = [];
        for ($d = $start; $d <= $end; $d = $d->modify('+1 day')) {
            $day = $d->format('Y-m-d');
            [$dayStart, $dayEnd] = app_day_bounds_utc($day);
            $out[] = array_merge(['date' => $day], $this->whatsappStats($dayStart, $dayEnd));
        }

        return $out;
    }
}
