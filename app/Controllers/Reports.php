<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Libraries\DataTablesQuery;
use App\Libraries\ReportExporter;
use App\Libraries\ReportService;
use App\Libraries\WalletReportService;
use App\Libraries\WalletService;
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

        return redirect()->to(site_url('reports/campaigns'));
    }

    /**
     * WhatsApp campaign report: delivery funnel, daily chart, per-campaign results and recipients.
     */
    public function campaigns(): string|ResponseInterface
    {
        if ($denied = $this->requirePermission('reports.view')) {
            return $denied;
        }

        $service = new ReportService();
        $range   = $service->dateRange((string) $this->request->getGet('from'), (string) $this->request->getGet('to'));
        $id      = $this->request->getGet('campaign_id');
        $id      = ($id !== null && $id !== '') ? (int) $id : null;

        return view('reports/campaigns', [
            'pageTitle'  => 'Campaign report',
            'from'       => $range['from'],
            'to'         => $range['to'],
            'campaignId' => $id,
            'wa'         => $service->whatsappReport($range, $id, false),
            'funnel'     => $service->whatsappRecipientFunnel($range['from_utc'], $range['to_utc'], $id),
        ]);
    }

    /**
     * DataTables JSON: WhatsApp campaign breakdown (grouped in SQL, paged in SQL).
     * Replies are attributed per page afterwards, so the Replies column is not sortable.
     */
    public function campaignsData(): ResponseInterface
    {
        if ($denied = $this->requirePermission('reports.view')) {
            return $denied;
        }

        [$service, $range, $id] = $this->dataContext();
        if (! $this->tablesExist(['messages', 'campaigns'])) {
            return $this->response->setJSON($this->emptyTable());
        }

        $out = DataTablesQuery::respond($this->request, [
            'builder'  => static fn () => $service->whatsappCampaignTable($range['from_utc'], $range['to_utc'], $id),
            'columns'  => [0 => 'x.name', 1 => 'x.sent_count', 2 => 'x.delivered_count', 3 => 'x.read_count', 4 => 'x.failed_count', 5 => 'x.pending_count'],
            'search'   => ['x.name'],
            'order'    => [1, 'desc'],
            'tiebreak' => 'x.id',
            'map'      => static fn (array $r): array => [
                'id'              => (int) $r['id'],
                'name'            => (string) $r['name'],
                'sent_count'      => (int) $r['sent_count'],
                'delivered_count' => (int) $r['delivered_count'],
                'read_count'      => (int) $r['read_count'],
                'failed_count'    => (int) $r['failed_count'],
                'pending_count'   => (int) $r['pending_count'],
                'reply_count'     => 0,
                'delivery_rate'   => (int) $r['sent_count'] > 0 ? round(((int) $r['delivered_count'] / (int) $r['sent_count']) * 100, 1) : 0.0,
                'url'             => site_url('campaigns/' . (int) $r['id']),
                'focus_url'       => site_url('reports/campaigns?' . http_build_query(['from' => $range['from'], 'to' => $range['to'], 'campaign_id' => (int) $r['id']])),
            ],
        ]);

        $replies = $service->whatsappRepliesByCampaign($range['from_utc'], $range['to_utc'], $id, array_column($out['data'], 'id'));
        foreach ($out['data'] as &$row) {
            $row['reply_count'] = (int) ($replies[$row['id']] ?? 0);
        }
        unset($row);

        return $this->response->setJSON($out);
    }

    /** DataTables JSON: campaign recipients in the selected period. */
    public function recipientsData(): ResponseInterface
    {
        if ($denied = $this->requirePermission('reports.view')) {
            return $denied;
        }

        [$service, $range, $id] = $this->dataContext();
        if (! $this->tablesExist(['campaign_contacts', 'contacts', 'campaigns'])) {
            return $this->response->setJSON($this->emptyTable());
        }

        return $this->response->setJSON(DataTablesQuery::respond($this->request, [
            'builder'  => static fn () => $service->whatsappRecipientTable($range['from_utc'], $range['to_utc'], $id),
            'columns'  => [
                0 => 'contacts.name', 1 => 'contacts.mobile', 2 => 'campaign_contacts.status', 3 => 'campaigns.name',
                4 => 'campaign_contacts.sent_at', 5 => 'campaign_contacts.delivered_at', 6 => 'campaign_contacts.read_at',
            ],
            'search'   => ['contacts.name', 'contacts.mobile', 'campaigns.name', 'campaign_contacts.status'],
            'order'    => [4, 'desc'],
            'tiebreak' => 'campaign_contacts.id',
            'map'      => static fn (array $r): array => [
                'name'          => (string) ($r['name'] ?? ''),
                'mobile'        => (string) ($r['mobile'] ?? ''),
                'status'        => (string) ($r['status'] ?? ''),
                'campaign_id'   => (int) ($r['campaign_id'] ?? 0),
                'campaign_name' => (string) ($r['campaign_name'] ?? ''),
                'campaign_url'  => site_url('campaigns/' . (int) ($r['campaign_id'] ?? 0)),
                'sent_at'       => format_app_datetime($r['sent_at'] ?? null, 'd-M-Y, g:i A', '—'),
                'delivered_at'  => format_app_datetime($r['delivered_at'] ?? null, 'd-M-Y, g:i A', '—'),
                'read_at'       => format_app_datetime($r['read_at'] ?? null, 'd-M-Y, g:i A', '—'),
                'error_message' => (string) ($r['error_message'] ?? ''),
            ],
        ]));
    }

    /** DataTables JSON: email campaign breakdown. */
    public function emailCampaignsData(): ResponseInterface
    {
        if ($denied = $this->requirePermission('reports.view')) {
            return $denied;
        }

        [$service, $range, $id] = $this->dataContext();
        if (! $this->tablesExist(['email_logs'])) {
            return $this->response->setJSON($this->emptyTable());
        }

        return $this->response->setJSON(DataTablesQuery::respond($this->request, [
            'builder'  => static fn () => $service->emailCampaignTable($range['from_utc'], $range['to_utc'], $id),
            'columns'  => [
                0 => 'x.name', 1 => 'x.sent_count', 2 => 'x.queued_count', 3 => 'x.failed_count', 4 => 'x.opened_count',
                5 => 'x.clicked_count', 6 => '(x.opened_count / NULLIF(x.sent_count, 0))',
            ],
            'search'   => ['x.name'],
            'order'    => [1, 'desc'],
            'tiebreak' => 'x.id',
            'map'      => static fn (array $r): array => [
                'id'            => (int) $r['id'],
                'name'          => (string) $r['name'],
                'sent_count'    => (int) $r['sent_count'],
                'queued_count'  => (int) $r['queued_count'],
                'failed_count'  => (int) $r['failed_count'],
                'opened_count'  => (int) $r['opened_count'],
                'clicked_count' => (int) $r['clicked_count'],
                'open_rate'     => (int) $r['sent_count'] > 0 ? round(((int) $r['opened_count'] / (int) $r['sent_count']) * 100, 1) : 0.0,
                'focus_url'     => (int) $r['id'] > 0
                    ? site_url('reports/email?' . http_build_query(['from' => $range['from'], 'to' => $range['to'], 'campaign_id' => (int) $r['id']]))
                    : '',
            ],
        ]));
    }

    /** DataTables JSON: individual email sends. */
    public function emailLogsData(): ResponseInterface
    {
        if ($denied = $this->requirePermission('reports.view')) {
            return $denied;
        }

        [$service, $range, $id] = $this->dataContext();
        if (! $this->tablesExist(['email_logs'])) {
            return $this->response->setJSON($this->emptyTable());
        }

        return $this->response->setJSON(DataTablesQuery::respond($this->request, [
            'builder'  => static fn () => $service->emailLogTable($range['from_utc'], $range['to_utc'], $id),
            'columns'  => [0 => 'l.to_email', 1 => 'l.subject', 2 => 'l.status', 3 => 'l.open_count', 4 => 'l.click_count', 5 => 'l.created_at'],
            'search'   => ['l.to_email', 'l.subject', 'l.kind', 'l.status'],
            'order'    => [5, 'desc'],
            'tiebreak' => 'l.id',
            'map'      => static fn (array $r): array => [
                'id'          => (int) $r['id'],
                'to_email'    => (string) ($r['to_email'] ?? ''),
                'kind'        => ucfirst((string) ($r['kind'] ?? '')),
                'subject'     => (string) ($r['subject'] ?? ''),
                'status'      => (string) ($r['status'] ?? ''),
                'status_label' => ucfirst(str_replace('_', ' ', strtolower((string) ($r['status'] ?? '')))),
                'open_count'  => (int) ($r['open_count'] ?? 0),
                'click_count' => (int) ($r['click_count'] ?? 0),
                'created_at'  => format_app_datetime($r['created_at'] ?? null),
            ],
        ]));
    }

    /** DataTables JSON: results per WhatsApp template. */
    public function templatesData(): ResponseInterface
    {
        if ($denied = $this->requirePermission('reports.view')) {
            return $denied;
        }

        [$service, $range] = $this->dataContext();
        if (! $this->tablesExist(['messages', 'campaigns', 'templates'])) {
            return $this->response->setJSON($this->emptyTable());
        }

        return $this->response->setJSON(DataTablesQuery::respond($this->request, [
            'builder'  => static fn () => $service->whatsappTemplateTable($range),
            'columns'  => [
                0 => 'x.name', 1 => 'x.category', 2 => 'x.template_status', 3 => 'x.campaigns', 4 => 'x.sent', 5 => 'x.delivered',
                6 => 'x.`read`', 7 => 'x.failed', 8 => '(x.delivered / NULLIF(x.sent, 0))', 9 => '(x.`read` / NULLIF(x.sent, 0))',
            ],
            'search'   => ['x.name', 'x.category', 'x.template_status', 'x.language'],
            'order'    => [4, 'desc'],
            'tiebreak' => 'x.id',
            'map'      => static function (array $r): array {
                $sent = (int) $r['sent'];

                return [
                    'name'          => (string) $r['name'],
                    'language'      => (string) $r['language'],
                    'category'      => ucfirst(strtolower((string) ($r['category'] ?? ''))),
                    'status'        => ucfirst(strtolower((string) ($r['template_status'] ?? ''))),
                    'campaigns'     => (int) $r['campaigns'],
                    'sent'          => $sent,
                    'delivered'     => (int) $r['delivered'],
                    'read'          => (int) $r['read'],
                    'failed'        => (int) $r['failed'],
                    'delivery_rate' => $sent > 0 ? round(((int) $r['delivered'] / $sent) * 100, 1) : 0.0,
                    'read_rate'     => $sent > 0 ? round(((int) $r['read'] / $sent) * 100, 1) : 0.0,
                ];
            },
        ]));
    }

    /** DataTables JSON: wallet deposits in the selected period. */
    public function depositsData(): ResponseInterface
    {
        if ($denied = $this->requirePermission('reports.view')) {
            return $denied;
        }

        $svc     = new WalletReportService();
        $filters = $svc->filters($this->request->getGet());
        if (! $this->tablesExist(['wallet_transactions'])) {
            return $this->response->setJSON($this->emptyTable());
        }

        return $this->response->setJSON(DataTablesQuery::respond($this->request, [
            'builder'  => static fn () => $svc->depositsTable($filters),
            'columns'  => [0 => 't.created_at', 1 => 't.description', 2 => 't.amount', 3 => 't.balance_after'],
            'search'   => ['t.description'],
            'order'    => [0, 'desc'],
            'tiebreak' => 't.id',
            'map'      => static fn (array $r): array => [
                'created_at'    => (string) $r['created_at'],
                'description'   => (string) ($r['description'] ?? ''),
                'amount'        => number_format((float) $r['amount'], 2),
                'balance_after' => number_format((float) $r['balance_after'], 2),
            ],
        ]));
    }

    /** DataTables JSON: wallet transactions (ledger) with the report filters applied. */
    public function transactionsData(): ResponseInterface
    {
        if ($denied = $this->requirePermission('reports.view')) {
            return $denied;
        }

        $svc     = new WalletReportService();
        $filters = $svc->filters($this->request->getGet());
        if (! $this->tablesExist(['wallet_transactions', 'messages', 'contacts', 'campaigns', 'templates'])) {
            return $this->response->setJSON($this->emptyTable());
        }

        $typeLabel = ['credit' => 'Recharge', 'debit' => 'Message / deduction', 'refund' => 'Refund'];

        return $this->response->setJSON(DataTablesQuery::respond($this->request, [
            'builder'  => static fn () => $svc->transactionsTable($filters),
            'columns'  => [
                0 => 't.created_at', 1 => 't.type', 2 => 't.category', 3 => WalletReportService::SOURCE_SQL, 4 => 'c.name',
                5 => 'cp.name', 6 => 'm.status', 7 => 't.description', 8 => 't.amount', 9 => 't.balance_after',
            ],
            'search'   => ['c.name', 'c.mobile', 'cp.name', 'tpl.name', 't.description', 't.reference', 't.category'],
            'order'    => [0, 'desc'],
            'tiebreak' => 't.id',
            'map'      => static fn (array $r): array => [
                'created_at'       => (string) $r['created_at'],
                'type_label'       => $typeLabel[$r['type']] ?? (string) $r['type'],
                'category'         => (string) ($r['category'] ?? ''),
                'source'           => (string) ($r['source'] ?? ''),
                'recipient_name'   => (string) ($r['recipient_name'] ?? ''),
                'recipient_mobile' => (string) ($r['recipient_mobile'] ?? ''),
                'campaign_name'    => (string) ($r['campaign_name'] ?? ''),
                'template_name'    => (string) ($r['template_name'] ?? ''),
                'msg_status'       => ($r['msg_status'] ?? '') !== '' ? ucfirst((string) $r['msg_status']) : '',
                'description'      => (string) ($r['description'] ?? ''),
                'country_code'     => (string) ($r['country_code'] ?? ''),
                'in'               => in_array($r['type'], ['credit', 'refund'], true),
                'amount'           => number_format((float) $r['amount'], 4),
                'balance_after'    => number_format((float) $r['balance_after'], 2),
            ],
        ]));
    }

    /**
     * @return array{0:ReportService,1:array{from:string,to:string,from_utc:string,to_utc:string},2:?int}
     */
    private function dataContext(): array
    {
        $service = new ReportService();
        $from    = $this->request->getGet('from');
        $to      = $this->request->getGet('to');
        $range   = $service->dateRange(is_scalar($from) ? (string) $from : '', is_scalar($to) ? (string) $to : '');
        $id      = $this->request->getGet('campaign_id');
        $id      = (is_scalar($id) && $id !== '' && (int) $id > 0) ? (int) $id : null;

        return [$service, $range, $id];
    }

    /** @param list<string> $tables */
    private function tablesExist(array $tables): bool
    {
        $db = db_connect();
        foreach ($tables as $t) {
            if (! $db->tableExists($t)) {
                return false;
            }
        }

        return true;
    }

    /** @return array{draw:int,recordsTotal:int,recordsFiltered:int,data:list<mixed>} */
    private function emptyTable(): array
    {
        return ['draw' => (int) ($this->request->getGet('draw') ?? 0), 'recordsTotal' => 0, 'recordsFiltered' => 0, 'data' => []];
    }

    /**
     * Email report: engagement funnel, daily sends, campaigns, recent sends and unsubscribes.
     */
    public function email(): string|ResponseInterface
    {
        if ($denied = $this->requirePermission('reports.view')) {
            return $denied;
        }

        $service = new ReportService();
        $range   = $service->dateRange((string) $this->request->getGet('from'), (string) $this->request->getGet('to'));
        $id      = $this->request->getGet('campaign_id');
        $id      = ($id !== null && $id !== '') ? (int) $id : null;

        return view('reports/email', [
            'pageTitle'  => 'Email report',
            'from'       => $range['from'],
            'to'         => $range['to'],
            'campaignId' => $id,
            'email'      => $service->emailReport($range, $id, false),
        ]);
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

    /**
     * WhatsApp wallet transactions: filters, summary by category/country, ledger.
     */
    public function transactions(): string|ResponseInterface
    {
        if ($denied = $this->requirePermission('reports.view')) {
            return $denied;
        }

        $svc     = new WalletReportService();
        $filters = $svc->filters($this->request->getGet());

        return view('reports/transactions', [
            'pageTitle' => 'Transaction report',
            'filters'   => $filters,
            'report'    => $svc->forCurrentTenant($filters, 0, true),
            'wallet'    => (new WalletService())->wallet(),
            'query'     => http_build_query(array_filter($filters, static fn (string $v): bool => $v !== '')),
        ]);
    }

    public function transactionsExport(): ResponseInterface
    {
        if ($denied = $this->requirePermission('reports.export')) {
            return $denied;
        }

        $svc     = new WalletReportService();
        $filters = $svc->filters($this->request->getGet());
        $data    = $svc->forCurrentTenant($filters, WalletReportService::EXPORT_LIMIT, true);

        $rows = [];
        foreach ($data['rows'] as $r) {
            $rows[] = [$r['created_at'], $r['type'], $r['category'], $r['source'] ?? '', $r['recipient_name'] ?? '', $r['recipient_mobile'] ?? '', $r['campaign_name'] ?? '', $r['template_name'] ?? '', $r['msg_status'] ?? '', $r['country_code'], $r['amount'], $r['balance_after'], $r['reference'], $r['description']];
        }

        log_activity('export', 'reports', 'Exported wallet transaction report', ['filters' => $filters]);

        return ReportExporter::respond(
            $this->response,
            (string) $this->request->getGet('format'),
            'Transaction report',
            'transactions-' . $filters['from'] . '-to-' . $filters['to'],
            ['Date', 'Type', 'Category', 'Source', 'Recipient', 'Mobile', 'Campaign', 'Template', 'Msg status', 'Country code', 'Amount', 'Balance after', 'Reference', 'Details'],
            $rows,
            [],
            ['Period' => $filters['from'] . ' to ' . $filters['to']],
        );
    }

    /**
     * Wallet deposits (recharges) with totals.
     */
    public function deposits(): string|ResponseInterface
    {
        if ($denied = $this->requirePermission('reports.view')) {
            return $denied;
        }

        $svc     = new WalletReportService();
        $filters = $svc->filters($this->request->getGet());

        return view('reports/deposits', [
            'pageTitle' => 'Deposit report',
            'filters'   => $filters,
            'deposits'  => $svc->deposits($filters, 0),
            'wallet'    => (new WalletService())->wallet(),
            'query'     => http_build_query(['from' => $filters['from'], 'to' => $filters['to']]),
        ]);
    }

    public function depositsExport(): ResponseInterface
    {
        if ($denied = $this->requirePermission('reports.export')) {
            return $denied;
        }

        $svc     = new WalletReportService();
        $filters = $svc->filters($this->request->getGet());
        $data    = $svc->deposits($filters, WalletReportService::EXPORT_LIMIT);

        $rows = [];
        foreach ($data['rows'] as $r) {
            $rows[] = [$r['created_at'], $r['amount'], $r['balance_after'], $r['description']];
        }

        log_activity('export', 'reports', 'Exported wallet deposit report', ['filters' => $filters]);

        return ReportExporter::respond(
            $this->response,
            (string) $this->request->getGet('format'),
            'Deposit report',
            'deposits-' . $filters['from'] . '-to-' . $filters['to'],
            ['Date', 'Amount', 'Balance after', 'Note'],
            $rows,
            [['Total deposited', $data['total'], '', $data['count'] . ' deposits']],
            ['Period' => $filters['from'] . ' to ' . $filters['to']],
        );
    }

    /**
     * Broadcast results per WhatsApp template.
     */
    public function templates(): string|ResponseInterface
    {
        if ($denied = $this->requirePermission('reports.view')) {
            return $denied;
        }

        $service = new ReportService();
        $range   = $service->dateRange((string) $this->request->getGet('from'), (string) $this->request->getGet('to'));

        return view('reports/templates', [
            'pageTitle' => 'WhatsApp template report',
            'from'      => $range['from'],
            'to'        => $range['to'],
            'totals'    => $service->whatsappTemplateTotals($range),
        ]);
    }

    public function templatesExport(): ResponseInterface
    {
        if ($denied = $this->requirePermission('reports.export')) {
            return $denied;
        }

        $service = new ReportService();
        $range   = $service->dateRange((string) $this->request->getGet('from'), (string) $this->request->getGet('to'));

        $rows = [];
        foreach ($service->whatsappTemplateReport($range) as $r) {
            $rows[] = [
                $r['name'], $r['language'], $r['category'] ?? '', $r['template_status'] ?? '', $r['campaigns'],
                $r['sent'], $r['delivered'], $r['read'], $r['failed'], $r['delivery_rate'] . '%', $r['read_rate'] . '%',
            ];
        }

        log_activity('export', 'reports', 'Exported WhatsApp template report', ['from' => $range['from'], 'to' => $range['to']]);

        return ReportExporter::respond(
            $this->response,
            (string) $this->request->getGet('format'),
            'WhatsApp template report',
            'templates-' . $range['from'] . '-to-' . $range['to'],
            ['Template', 'Language', 'Category', 'Status', 'Campaigns', 'Sent', 'Delivered', 'Read', 'Failed', 'Delivery %', 'Read %'],
            $rows,
            [],
            ['Period' => $range['from'] . ' to ' . $range['to']],
        );
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
     * Retarget a funnel segment: put those contacts in a Customer Group (label) and return the
     * campaign wizard URL with that group preselected.
     */
    public function retarget(): ResponseInterface
    {
        if ($denied = $this->requirePermission('reports.view')) {
            return $denied;
        }
        if (! function_exists('can') || ! can('campaigns.create')) {
            return $this->jsonResponse(false, null, 'You do not have permission to create campaigns.', [], 403);
        }

        $segment  = strtolower(trim((string) $this->request->getPost('segment')));
        $labels   = [
            'sent' => 'Sent', 'unsent' => 'Not sent', 'delivered' => 'Delivered', 'undelivered' => 'Undelivered',
            'read' => 'Opened', 'unread' => 'Not opened',
        ];
        if (! isset($labels[$segment])) {
            return $this->jsonResponse(false, null, 'Choose a valid retarget segment.', [], 422);
        }

        $service = new ReportService();
        $range   = $service->dateRange((string) $this->request->getPost('from'), (string) $this->request->getPost('to'));
        $id      = (int) $this->request->getPost('campaign_id');
        $id      = $id > 0 ? $id : null;

        try {
            $contactIds = $service->whatsappRetargetContactIds($range['from_utc'], $range['to_utc'], $id, $segment);
            if ($contactIds === []) {
                return $this->jsonResponse(false, null, 'No contacts in this segment.', [], 422);
            }

            $campaignName = 'All campaigns';
            if ($id !== null) {
                $row          = model(CampaignModel::class)->find($id);
                $campaignName = trim((string) ($row['name'] ?? '')) ?: ('Campaign #' . $id);
            }

            $tagName = mb_substr('Retarget: ' . $campaignName, 0, 70) . ' - ' . $labels[$segment]
                . ' (' . $range['from'] . ' to ' . $range['to'] . ')';
            $tagName = mb_substr($tagName, 0, 100);

            $tagModel = model(\App\Models\TagModel::class);
            $tag      = $tagModel->where('name', $tagName)->first();
            $tagId    = is_array($tag) ? (int) $tag['id'] : (int) $tagModel->insert(['name' => $tagName, 'color' => '#0284C7']);
            if ($tagId <= 0) {
                return $this->jsonResponse(false, null, 'Could not create the customer group.', [], 500);
            }

            $db = db_connect();
            $db->transStart();
            $db->table('contact_tags')->where('tag_id', $tagId)->delete();
            foreach (array_chunk($contactIds, 500) as $chunk) {
                $db->table('contact_tags')->insertBatch(array_map(
                    static fn (int $cid): array => ['contact_id' => $cid, 'tag_id' => $tagId],
                    $chunk
                ));
            }
            $db->transComplete();
            if (! $db->transStatus()) {
                return $this->jsonResponse(false, null, 'Could not save the contacts to the group.', [], 500);
            }

            log_activity('create', 'campaigns', 'Retarget group created from campaign report', [
                'tag_id' => $tagId, 'segment' => $segment, 'campaign_id' => $id, 'contacts' => count($contactIds),
            ]);

            return $this->jsonResponse(true, [
                'tag_id'   => $tagId,
                'contacts' => count($contactIds),
                'url'      => site_url('campaigns?' . http_build_query(['new' => 'whatsapp', 'label' => $tagId])),
            ], count($contactIds) . ' contacts added to "' . $tagName . '".');
        } catch (\Throwable $e) {
            log_message('error', 'Reports::retarget failed: {msg}', ['msg' => $e->getMessage()]);

            return $this->jsonResponse(false, null, 'Could not build the retarget group.', [], 500);
        }
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
