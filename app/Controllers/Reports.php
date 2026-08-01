<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\CampaignContactModel;
use App\Models\CampaignModel;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Reporting and export (CSV / HTML-PDF style download).
 */
class Reports extends BaseController
{
    public function index(): string|ResponseInterface
    {
        if ($denied = $this->requirePermission('reports.view')) {
            return $denied;
        }

        $from = (string) ($this->request->getGet('from') ?: '');
        $to   = (string) ($this->request->getGet('to') ?: '');
        if ($from === '') {
            $from = (new \DateTimeImmutable('now', \App\Libraries\AppDateTime::appZone()))
                ->modify('first day of this month')->format('Y-m-d');
        }
        if ($to === '') {
            $to = app_today_ymd();
        }
        $campaignId = $this->request->getGet('campaign_id');
        $campaignId = ($campaignId !== null && $campaignId !== '') ? (int) $campaignId : null;
        [$fromUtc, $toUtc] = app_range_bounds_utc($from, $to);

        $summary = $this->deliveryStats($fromUtc, $toUtc, $campaignId);
        $daily   = $this->dailyDelivery($from, $to, $campaignId);

        $trendLabels    = [];
        $trendSent      = [];
        $trendDelivered = [];
        $trendFailed    = [];
        foreach ($daily as $day) {
            $trendLabels[]    = date('M j', strtotime($day['date']));
            $trendSent[]      = $day['sent'];
            $trendDelivered[] = $day['delivered'];
            $trendFailed[]    = $day['failed'];
        }

        $campaignModel = model(CampaignModel::class);
        $campaigns     = $campaignModel->orderBy('created_at', 'DESC')->findAll();

        // Prefer live message aggregates for the selected period when possible;
        // fall back to denormalized campaign counters for the table.
        $campaignStats = $this->campaignBreakdown($fromUtc, $toUtc, $campaignId, $campaigns);

        $filters = [
            'from'        => $from,
            'to'          => $to,
            'campaign_id' => $campaignId,
        ];

        return $this->render('reports/index', [
            'pageTitle'      => 'Reports',
            'from'           => $from,
            'to'             => $to,
            'filters'        => $filters,
            'summary'        => $summary,
            'stats'          => $summary,
            'overview'       => $summary,
            'charts'         => [
                'trends' => [
                    'labels'    => $trendLabels,
                    'sent'      => $trendSent,
                    'delivered' => $trendDelivered,
                    'failed'    => $trendFailed,
                ],
            ],
            'campaigns'      => $campaigns,
            'campaign_stats' => $campaignStats,
        ]);
    }

    public function campaigns(): string|ResponseInterface
    {
        if ($denied = $this->requirePermission('reports.view')) {
            return $denied;
        }

        $campaigns = model(CampaignModel::class)->orderBy('created_at', 'DESC')->findAll();

        if ($this->request->isAJAX()) {
            return $this->jsonResponse(true, $campaigns);
        }

        return $this->render('reports/campaigns', [
            'pageTitle' => 'Campaign Reports',
            'campaigns' => $campaigns,
        ]);
    }

    public function delivery(): string|ResponseInterface
    {
        if ($denied = $this->requirePermission('reports.view')) {
            return $denied;
        }

        $from = (string) ($this->request->getGet('from') ?: '');
        $to   = (string) ($this->request->getGet('to') ?: '');
        if ($from === '') {
            $from = (new \DateTimeImmutable('now', \App\Libraries\AppDateTime::appZone()))
                ->modify('first day of this month')->format('Y-m-d');
        }
        if ($to === '') {
            $to = app_today_ymd();
        }
        $campaignId = $this->request->getGet('campaign_id');
        $campaignId = ($campaignId !== null && $campaignId !== '') ? (int) $campaignId : null;
        [$fromUtc, $toUtc] = app_range_bounds_utc($from, $to);

        $stats = $this->deliveryStats($fromUtc, $toUtc, $campaignId);
        $daily = $this->dailyDelivery($from, $to, $campaignId);

        if ($this->request->isAJAX()) {
            return $this->jsonResponse(true, ['stats' => $stats, 'daily' => $daily]);
        }

        return $this->render('reports/delivery', [
            'pageTitle' => 'Delivery Report',
            'from'      => $from,
            'to'        => $to,
            'stats'     => $stats,
            'daily'     => $daily,
        ]);
    }

    public function exportPdf(): ResponseInterface
    {
        if ($denied = $this->requirePermission('reports.export')) {
            return $denied;
        }

        $from = (string) ($this->request->getGet('from') ?: '');
        $to   = (string) ($this->request->getGet('to') ?: '');
        if ($from === '') {
            $from = (new \DateTimeImmutable('now', \App\Libraries\AppDateTime::appZone()))
                ->modify('first day of this month')->format('Y-m-d');
        }
        if ($to === '') {
            $to = app_today_ymd();
        }
        $campaignId = $this->request->getGet('campaign_id');
        $campaignId = ($campaignId !== null && $campaignId !== '') ? (int) $campaignId : null;
        [$fromUtc, $toUtc] = app_range_bounds_utc($from, $to);
        $stats      = $this->deliveryStats($fromUtc, $toUtc, $campaignId);
        $app        = (string) service('settingsService')->get('app_name', 'WhatsApp Automation');

        $html = '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Delivery Report</title>'
            . '<style>body{font-family:DejaVu Sans,Arial,sans-serif;padding:24px;color:#222}'
            . 'h1{font-size:20px}table{border-collapse:collapse;width:100%;margin-top:16px}'
            . 'th,td{border:1px solid #ccc;padding:8px;text-align:left}th{background:#f5f5f5}</style></head><body>'
            . '<h1>' . esc($app) . ' — Delivery Report</h1>'
            . '<p>Period: ' . esc($from) . ' to ' . esc($to) . ' (' . esc(settings_timezone()) . ')</p>'
            . '<table><thead><tr><th>Metric</th><th>Count</th></tr></thead><tbody>';

        foreach ($stats as $key => $value) {
            $html .= '<tr><td>' . esc(ucfirst(str_replace('_', ' ', $key))) . '</td><td>' . (int) $value . '</td></tr>';
        }

        $html .= '</tbody></table><p style="margin-top:24px;font-size:12px;color:#666">Generated '
            . esc(format_app_datetime(app_now_storage())) . '</p></body></html>';

        // HTML download labeled as PDF report (printable). True PDF libs optional later.
        return $this->response
            ->setHeader('Content-Type', 'text/html; charset=UTF-8')
            ->setHeader('Content-Disposition', 'attachment; filename="delivery_report_' . app_today_ymd() . '.html"')
            ->setBody($html);
    }

    public function exportExcel(): ResponseInterface
    {
        if ($denied = $this->requirePermission('reports.export')) {
            return $denied;
        }

        $type = (string) ($this->request->getGet('type') ?: 'delivery');
        $from = (string) ($this->request->getGet('from') ?: '');
        $to   = (string) ($this->request->getGet('to') ?: '');
        if ($from === '') {
            $from = (new \DateTimeImmutable('now', \App\Libraries\AppDateTime::appZone()))
                ->modify('first day of this month')->format('Y-m-d');
        }
        if ($to === '') {
            $to = app_today_ymd();
        }
        $campaignId = $this->request->getGet('campaign_id');
        $campaignId = ($campaignId !== null && $campaignId !== '') ? (int) $campaignId : null;
        [$fromUtc, $toUtc] = app_range_bounds_utc($from, $to);
        $campaigns = model(CampaignModel::class)->orderBy('created_at', 'DESC')->findAll();

        if ($type === 'contacts') {
            if ($campaignId === null || $campaignId <= 0) {
                return $this->response->setStatusCode(422)->setBody('Select a campaign to export contacts.');
            }

            $rows = $this->campaignContactRows($campaignId);
            $csv  = "name,mobile,status,sent_at,delivered_at,read_at,error_message\n";
            foreach ($rows as $r) {
                $csv .= implode(',', [
                    $this->csvCell($r['name'] ?? ''),
                    $this->csvCell($r['mobile'] ?? ''),
                    $this->csvCell($r['status'] ?? ''),
                    $this->csvCell(format_app_datetime($r['sent_at'] ?? null, 'Y-m-d H:i:s', '')),
                    $this->csvCell(format_app_datetime($r['delivered_at'] ?? null, 'Y-m-d H:i:s', '')),
                    $this->csvCell(format_app_datetime($r['read_at'] ?? null, 'Y-m-d H:i:s', '')),
                    $this->csvCell($r['error_message'] ?? ''),
                ]) . "\n";
            }
            $filename = 'campaign_' . $campaignId . '_contacts_' . str_replace('-', '', app_today_ymd()) . '.csv';
        } elseif ($type === 'campaigns') {
            $breakdown = $this->campaignBreakdown($fromUtc, $toUtc, $campaignId, $campaigns);
            $csv       = "section,campaign,sent,delivered,read,failed,replies\n";
            foreach ($breakdown as $r) {
                $csv .= implode(',', [
                    'campaign_breakdown',
                    $this->csvCell($r['name'] ?? ''),
                    (int) ($r['sent_count'] ?? 0),
                    (int) ($r['delivered_count'] ?? 0),
                    (int) ($r['read_count'] ?? 0),
                    (int) ($r['failed_count'] ?? 0),
                    (int) ($r['reply_count'] ?? 0),
                ]) . "\n";
            }
            $filename = 'campaigns_report_' . str_replace('-', '', app_today_ymd()) . '.csv';
        } else {
            // Default: daily delivery + campaign breakdown, both filter-aware.
            $daily     = $this->dailyDelivery($from, $to, $campaignId);
            $breakdown = $this->campaignBreakdown($fromUtc, $toUtc, $campaignId, $campaigns);
            $csv       = "section,date_or_campaign,sent,delivered,read,failed,replies\n";
            foreach ($daily as $row) {
                $csv .= implode(',', [
                    'daily_delivery',
                    $this->csvCell($row['date'] ?? ''),
                    (int) ($row['sent'] ?? 0),
                    (int) ($row['delivered'] ?? 0),
                    (int) ($row['read'] ?? 0),
                    (int) ($row['failed'] ?? 0),
                    (int) ($row['replies'] ?? 0),
                ]) . "\n";
            }
            foreach ($breakdown as $r) {
                $csv .= implode(',', [
                    'campaign_breakdown',
                    $this->csvCell($r['name'] ?? ''),
                    (int) ($r['sent_count'] ?? 0),
                    (int) ($r['delivered_count'] ?? 0),
                    (int) ($r['read_count'] ?? 0),
                    (int) ($r['failed_count'] ?? 0),
                    (int) ($r['reply_count'] ?? 0),
                ]) . "\n";
            }
            $filename = 'delivery_report_' . str_replace('-', '', app_today_ymd()) . '.csv';
        }

        return $this->response
            ->setHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->setHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->setBody($csv);
    }

    /**
     * JSON list of contacts in a campaign with delivery timestamps.
     */
    public function campaignContacts(): ResponseInterface
    {
        if ($denied = $this->requirePermission('reports.view')) {
            return $denied;
        }

        $campaignId = (int) ($this->request->getGet('campaign_id') ?? 0);
        if ($campaignId <= 0) {
            return $this->jsonResponse(false, null, 'Select a campaign.', [], 422);
        }

        $campaign = model(CampaignModel::class)->find($campaignId);
        if ($campaign === null) {
            return $this->jsonResponse(false, null, 'Campaign not found.', [], 404);
        }

        $page    = max(1, (int) ($this->request->getGet('page') ?? 1));
        $perPage = max(1, min(500, (int) ($this->request->getGet('per_page') ?? 100)));
        $offset  = ($page - 1) * $perPage;

        $model = model(CampaignContactModel::class);
        $total = $model->where('campaign_id', $campaignId)->countAllResults();
        $rows  = $model
            ->select('campaign_contacts.*, contacts.name, contacts.mobile')
            ->join('contacts', 'contacts.id = campaign_contacts.contact_id', 'left')
            ->where('campaign_contacts.campaign_id', $campaignId)
            ->orderBy('campaign_contacts.id', 'DESC')
            ->findAll($perPage, $offset);

        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'contact_id'    => (int) ($r['contact_id'] ?? 0),
                'name'          => (string) ($r['name'] ?? ''),
                'mobile'        => (string) ($r['mobile'] ?? ''),
                'status'        => (string) ($r['status'] ?? ''),
                'sent_at'       => format_app_datetime($r['sent_at'] ?? null),
                'delivered_at'  => format_app_datetime($r['delivered_at'] ?? null),
                'read_at'       => format_app_datetime($r['read_at'] ?? null),
                'error_message' => (string) ($r['error_message'] ?? ''),
            ];
        }

        return $this->jsonResponse(true, [
            'campaign_id'   => $campaignId,
            'campaign_name' => (string) ($campaign['name'] ?? ''),
            'total'         => $total,
            'page'          => $page,
            'per_page'      => $perPage,
            'contacts'      => $out,
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function campaignContactRows(int $campaignId): array
    {
        return model(CampaignContactModel::class)
            ->select('campaign_contacts.*, contacts.name, contacts.mobile')
            ->join('contacts', 'contacts.id = campaign_contacts.contact_id', 'left')
            ->where('campaign_contacts.campaign_id', $campaignId)
            ->orderBy('campaign_contacts.id', 'DESC')
            ->findAll();
    }

    protected function csvCell(mixed $value): string
    {
        $text = (string) $value;

        return '"' . str_replace('"', '""', $text) . '"';
    }

    /**
     * @return array{sent:int,delivered:int,read:int,failed:int,replies:int}
     */
    protected function deliveryStats(string $from, string $to, ?int $campaignId = null): array
    {
        $builder = db_connect()->table('messages')
            ->select("
                SUM(CASE WHEN direction = 'outbound' AND status IN ('sent','delivered','read') THEN 1 ELSE 0 END) AS sent,
                SUM(CASE WHEN direction = 'outbound' AND status IN ('delivered','read') THEN 1 ELSE 0 END) AS delivered,
                SUM(CASE WHEN direction = 'outbound' AND status = 'read' THEN 1 ELSE 0 END) AS `read`,
                SUM(CASE WHEN direction = 'outbound' AND status = 'failed' THEN 1 ELSE 0 END) AS failed,
                SUM(CASE WHEN direction = 'inbound' THEN 1 ELSE 0 END) AS replies
            ", false)
            ->where('created_at >=', $from)
            ->where('created_at <=', $to);

        if ($campaignId !== null && $campaignId > 0) {
            $builder->where('campaign_id', $campaignId);
        }

        $row = $builder->get()->getRowArray();

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
    protected function dailyDelivery(string $from, string $to, ?int $campaignId = null): array
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
            $stats = $this->deliveryStats($dayStart, $dayEnd, $campaignId);
            $out[] = array_merge(['date' => $day], $stats);
        }

        return $out;
    }

    /**
     * Per-campaign message aggregates for the selected period.
     *
     * @param list<array<string,mixed>> $campaigns
     * @return list<array{name:string,sent_count:int,delivered_count:int,read_count:int,failed_count:int,reply_count:int}>
     */
    protected function campaignBreakdown(string $from, string $to, ?int $campaignId, array $campaigns): array
    {
        $builder = db_connect()->table('messages')
            ->select("
                campaign_id,
                SUM(CASE WHEN direction = 'outbound' AND status IN ('sent','delivered','read') THEN 1 ELSE 0 END) AS sent_count,
                SUM(CASE WHEN direction = 'outbound' AND status IN ('delivered','read') THEN 1 ELSE 0 END) AS delivered_count,
                SUM(CASE WHEN direction = 'outbound' AND status = 'read' THEN 1 ELSE 0 END) AS read_count,
                SUM(CASE WHEN direction = 'outbound' AND status = 'failed' THEN 1 ELSE 0 END) AS failed_count,
                SUM(CASE WHEN direction = 'inbound' THEN 1 ELSE 0 END) AS reply_count
            ", false)
            ->where('created_at >=', $from)
            ->where('created_at <=', $to)
            ->where('campaign_id IS NOT NULL')
            ->groupBy('campaign_id');

        if ($campaignId !== null && $campaignId > 0) {
            $builder->where('campaign_id', $campaignId);
        }

        $rows = $builder->get()->getResultArray();
        $byId = [];
        foreach ($rows as $row) {
            $byId[(int) $row['campaign_id']] = $row;
        }

        $out = [];
        foreach ($campaigns as $c) {
            $id = (int) ($c['id'] ?? 0);
            if ($campaignId !== null && $campaignId > 0 && $id !== $campaignId) {
                continue;
            }

            $live = $byId[$id] ?? null;
            $out[] = [
                'id'              => $id,
                'name'            => (string) ($c['name'] ?? ''),
                'sent_count'      => (int) ($live['sent_count'] ?? $c['sent_count'] ?? 0),
                'delivered_count' => (int) ($live['delivered_count'] ?? $c['delivered_count'] ?? 0),
                'read_count'      => (int) ($live['read_count'] ?? $c['read_count'] ?? 0),
                'failed_count'    => (int) ($live['failed_count'] ?? $c['failed_count'] ?? 0),
                'reply_count'     => (int) ($live['reply_count'] ?? $c['reply_count'] ?? 0),
            ];
        }

        return $out;
    }
}
