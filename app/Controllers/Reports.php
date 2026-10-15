<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Libraries\ReportService;
use App\Models\CampaignModel;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Older report URLs land on Analytics. Export stays here and uses ReportService.
 */
class Reports extends BaseController
{
    public function index(): ResponseInterface
    {
        if ($denied = $this->requirePermission('reports.view')) {
            return $denied;
        }

        return $this->toAnalytics('whatsapp');
    }

    public function campaigns(): ResponseInterface
    {
        if ($denied = $this->requirePermission('reports.view')) {
            return $denied;
        }

        return $this->toAnalytics('whatsapp');
    }

    public function delivery(): ResponseInterface
    {
        if ($denied = $this->requirePermission('reports.view')) {
            return $denied;
        }

        $channel = strtolower((string) ($this->request->getGet('channel') ?: 'whatsapp'));
        if (! in_array($channel, ['whatsapp', 'email'], true)) {
            $channel = 'whatsapp';
        }

        return $this->toAnalytics($channel);
    }

    public function exportPdf(): ResponseInterface
    {
        if ($denied = $this->requirePermission('reports.export')) {
            return $denied;
        }

        [$channel, $range, $campaignId, $report] = $this->exportContext();
        $app = (string) service('settingsService')->get('app_name', 'Sateri Connect');
        $title = $channel === 'email' ? 'Email report' : 'WhatsApp delivery report';

        $html = '<!DOCTYPE html><html><head><meta charset="utf-8"><title>' . esc($title) . '</title>'
            . '<style>body{font-family:DejaVu Sans,Arial,sans-serif;padding:24px;color:#222}'
            . 'h1{font-size:20px}table{border-collapse:collapse;width:100%;margin-top:16px}'
            . 'th,td{border:1px solid #ccc;padding:8px;text-align:left}th{background:#f5f5f5}</style></head><body>'
            . '<h1>' . esc($app) . ' — ' . esc($title) . '</h1>'
            . '<p>Period: ' . esc($range['from']) . ' to ' . esc($range['to']) . ' (' . esc(settings_timezone()) . ')</p>';

        $html .= '<table><thead><tr><th>Metric</th><th>Value</th></tr></thead><tbody>';
        foreach ($this->summaryRows($channel, $report['summary']) as [$label, $value]) {
            $html .= '<tr><td>' . esc($label) . '</td><td>' . esc($value) . '</td></tr>';
        }
        $html .= '</tbody></table>';

        if ($report['campaigns'] !== []) {
            $html .= '<h2 style="font-size:16px;margin-top:24px">Campaigns in this period</h2><table><thead><tr>';
            if ($channel === 'email') {
                $html .= '<th>Campaign</th><th>Sent</th><th>Queued</th><th>Failed</th><th>Opens</th><th>Clicks</th>';
            } else {
                $html .= '<th>Campaign</th><th>Sent</th><th>Delivered</th><th>Read</th><th>Failed</th><th>Pending</th><th>Replies</th>';
            }
            $html .= '</tr></thead><tbody>';
            foreach ($report['campaigns'] as $row) {
                $html .= '<tr><td>' . esc((string) ($row['name'] ?? '')) . '</td>';
                if ($channel === 'email') {
                    $html .= '<td>' . (int) ($row['sent_count'] ?? 0) . '</td>'
                        . '<td>' . (int) ($row['queued_count'] ?? 0) . '</td>'
                        . '<td>' . (int) ($row['failed_count'] ?? 0) . '</td>'
                        . '<td>' . (int) ($row['opened_count'] ?? 0) . '</td>'
                        . '<td>' . (int) ($row['clicked_count'] ?? 0) . '</td>';
                } else {
                    $html .= '<td>' . (int) ($row['sent_count'] ?? 0) . '</td>'
                        . '<td>' . (int) ($row['delivered_count'] ?? 0) . '</td>'
                        . '<td>' . (int) ($row['read_count'] ?? 0) . '</td>'
                        . '<td>' . (int) ($row['failed_count'] ?? 0) . '</td>'
                        . '<td>' . (int) ($row['pending_count'] ?? 0) . '</td>'
                        . '<td>' . (int) ($row['reply_count'] ?? 0) . '</td>';
                }
                $html .= '</tr>';
            }
            $html .= '</tbody></table>';
        }

        $html .= '<p style="margin-top:24px;font-size:12px;color:#666">Generated '
            . esc(format_app_datetime(app_now_storage())) . '</p></body></html>';

        log_activity('export', 'reports', 'Exported ' . $channel . ' report (printable)', [
            'from' => $range['from'], 'to' => $range['to'], 'campaign_id' => $campaignId, 'channel' => $channel,
        ]);

        return $this->response
            ->setHeader('Content-Type', 'text/html; charset=UTF-8')
            ->setHeader('Content-Disposition', 'attachment; filename="' . $channel . '_report_' . app_today_ymd() . '.html"')
            ->setBody($html);
    }

    public function exportExcel(): ResponseInterface
    {
        if ($denied = $this->requirePermission('reports.export')) {
            return $denied;
        }

        $type = (string) ($this->request->getGet('type') ?: 'delivery');
        [$channel, $range, $campaignId, $report] = $this->exportContext();
        $service = new ReportService();

        if ($type === 'contacts') {
            if ($channel !== 'whatsapp' || $campaignId === null || $campaignId <= 0) {
                return $this->response->setStatusCode(422)->setBody('Select a WhatsApp campaign to export contacts.');
            }

            $rows = $service->whatsappRecipients($range['from_utc'], $range['to_utc'], $campaignId, null)['rows'];
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
        } elseif ($channel === 'email') {
            $csv = "section,name,sent,queued,failed,opened,clicked\n";
            foreach ($report['daily'] as $row) {
                $csv .= implode(',', [
                    'daily',
                    $this->csvCell($row['date'] ?? ''),
                    (int) ($row['sent'] ?? 0),
                    (int) ($row['queued'] ?? 0),
                    (int) ($row['failed'] ?? 0),
                    0,
                    0,
                ]) . "\n";
            }
            foreach ($report['campaigns'] as $r) {
                $csv .= implode(',', [
                    'campaign_breakdown',
                    $this->csvCell($r['name'] ?? ''),
                    (int) ($r['sent_count'] ?? 0),
                    (int) ($r['queued_count'] ?? 0),
                    (int) ($r['failed_count'] ?? 0),
                    (int) ($r['opened_count'] ?? 0),
                    (int) ($r['clicked_count'] ?? 0),
                ]) . "\n";
            }
            $filename = 'email_report_' . str_replace('-', '', app_today_ymd()) . '.csv';
        } else {
            $csv = "section,date_or_campaign,sent,delivered,read,failed,pending,replies\n";
            foreach ($report['daily'] as $row) {
                $csv .= implode(',', [
                    'daily_delivery',
                    $this->csvCell($row['date'] ?? ''),
                    (int) ($row['sent'] ?? 0),
                    (int) ($row['delivered'] ?? 0),
                    0,
                    (int) ($row['failed'] ?? 0),
                    (int) ($row['pending'] ?? 0),
                    0,
                ]) . "\n";
            }
            foreach ($report['campaigns'] as $r) {
                $csv .= implode(',', [
                    'campaign_breakdown',
                    $this->csvCell($r['name'] ?? ''),
                    (int) ($r['sent_count'] ?? 0),
                    (int) ($r['delivered_count'] ?? 0),
                    (int) ($r['read_count'] ?? 0),
                    (int) ($r['failed_count'] ?? 0),
                    (int) ($r['pending_count'] ?? 0),
                    (int) ($r['reply_count'] ?? 0),
                ]) . "\n";
            }
            $filename = 'delivery_report_' . str_replace('-', '', app_today_ymd()) . '.csv';
        }

        log_activity('export', 'reports', 'Exported report CSV: ' . $filename, [
            'type' => $type, 'campaign_id' => $campaignId, 'channel' => $channel,
        ]);

        return $this->response
            ->setHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->setHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->setBody($csv);
    }

    /**
     * JSON list of contacts sent in the selected period for one campaign.
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

        $service = new ReportService();
        $range   = $service->dateRange(
            (string) $this->request->getGet('from'),
            (string) $this->request->getGet('to')
        );
        $page    = max(1, (int) ($this->request->getGet('page') ?? 1));
        $perPage = max(1, min(500, (int) ($this->request->getGet('per_page') ?? 100)));

        return $this->jsonResponse(true, $service->campaignContactsPage(
            $campaignId,
            $range['from_utc'],
            $range['to_utc'],
            $page,
            $perPage
        ));
    }

    private function toAnalytics(string $tab): ResponseInterface
    {
        $service = new ReportService();
        $range   = $service->dateRange(
            (string) $this->request->getGet('from'),
            (string) $this->request->getGet('to')
        );
        $campaignId = $this->request->getGet('campaign_id');
        $params = [
            'tab'  => $tab,
            'from' => $range['from'],
            'to'   => $range['to'],
        ];
        if ($campaignId !== null && $campaignId !== '') {
            $params['campaign_id'] = (int) $campaignId;
        }

        return redirect()->to(site_url('analytics?' . http_build_query($params)));
    }

    /**
     * @return array{0:string,1:array{from:string,to:string,from_utc:string,to_utc:string},2:?int,3:array<string,mixed>}
     */
    private function exportContext(): array
    {
        $channel = strtolower(trim((string) ($this->request->getGet('channel') ?: 'whatsapp')));
        if (! in_array($channel, ['whatsapp', 'email'], true)) {
            $channel = 'whatsapp';
        }

        $service = new ReportService();
        $range   = $service->dateRange(
            (string) $this->request->getGet('from'),
            (string) $this->request->getGet('to')
        );
        $campaignId = $this->request->getGet('campaign_id');
        $campaignId = ($campaignId !== null && $campaignId !== '') ? (int) $campaignId : null;
        $report = $channel === 'email'
            ? $service->emailReport($range, $campaignId)
            : $service->whatsappReport($range, $campaignId);

        return [$channel, $range, $campaignId, $report];
    }

    /**
     * @param array<string,int|float> $summary
     * @return list<array{0:string,1:string}>
     */
    private function summaryRows(string $channel, array $summary): array
    {
        if ($channel === 'email') {
            return [
                ['Sent', (string) (int) ($summary['sent'] ?? 0)],
                ['Queued', (string) (int) ($summary['queued'] ?? 0)],
                ['Failed', (string) (int) ($summary['failed'] ?? 0)],
                ['Unique opens', (string) (int) ($summary['opened'] ?? 0)],
                ['Open rate', (string) ($summary['open_rate'] ?? 0) . '%'],
                ['Unique clicks', (string) (int) ($summary['clicked'] ?? 0)],
                ['Click rate', (string) ($summary['click_rate'] ?? 0) . '%'],
                ['Click-to-open', (string) ($summary['ctor'] ?? 0) . '%'],
                ['Unsubscribed', (string) (int) ($summary['unsubscribes'] ?? 0)],
            ];
        }

        return [
            ['Sent', (string) (int) ($summary['sent'] ?? 0)],
            ['Delivered', (string) (int) ($summary['delivered'] ?? 0)],
            ['Delivery rate', (string) ($summary['delivery_rate'] ?? 0) . '%'],
            ['Read', (string) (int) ($summary['read'] ?? 0)],
            ['Read rate', (string) ($summary['read_rate'] ?? 0) . '%'],
            ['Failed', (string) (int) ($summary['failed'] ?? 0)],
            ['Pending', (string) (int) ($summary['pending'] ?? 0)],
            ['Replies', (string) (int) ($summary['replies'] ?? 0)],
        ];
    }

    private function csvCell(mixed $value): string
    {
        $text = \App\Libraries\ContactExportService::safeCell($value);

        return '"' . str_replace('"', '""', $text) . '"';
    }
}
