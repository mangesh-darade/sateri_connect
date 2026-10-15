<?= $this->extend('layouts/main') ?>

<?= $this->section('header_actions') ?>
<?php
$activeTab = $activeTab ?? 'whatsapp';
$from = (string) ($from ?? '');
$to = (string) ($to ?? '');
$campaignId = $campaignId ?? null;
$exportQs = http_build_query(array_filter([
    'channel'     => $activeTab,
    'from'        => $from,
    'to'          => $to,
    'campaign_id' => ($campaignId !== null && $campaignId !== '') ? $campaignId : null,
]));
?>
<?php if (function_exists('can') && can('reports.export')): ?>
    <a href="<?= site_url('reports/export-excel?' . $exportQs) ?>" class="btn btn-sm btn-outline-secondary" title="Excel"><i class="fas fa-file-csv me-1"></i> Excel</a>
    <a href="<?= site_url('reports/export-pdf?' . $exportQs) ?>" class="btn btn-sm btn-outline-secondary" title="Print"><i class="fas fa-print me-1"></i> Print</a>
<?php endif; ?>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php
$activeTab = $activeTab ?? 'whatsapp';
$from = (string) ($from ?? '');
$to = (string) ($to ?? '');
$campaignId = ($campaignId !== null && $campaignId !== '') ? (int) $campaignId : null;
$wa = $wa ?? [];
$email = $email ?? [];
$waSum = $wa['summary'] ?? [];
$emailSum = $email['summary'] ?? [];
$options = $activeTab === 'email' ? ($email['campaign_options'] ?? []) : ($wa['campaign_options'] ?? []);

$tabUrl = static function (string $tab) use ($from, $to): string {
    return site_url('analytics?' . http_build_query(['tab' => $tab, 'from' => $from, 'to' => $to]));
};
$filterUrl = static function (?int $id) use ($activeTab, $from, $to): string {
    $params = ['tab' => $activeTab, 'from' => $from, 'to' => $to];
    if ($id !== null && $id > 0) {
        $params['campaign_id'] = $id;
    }

    return site_url('analytics?' . http_build_query($params));
};
?>
<div class="page-list analytics-page page-stack" id="analyticsPage">
    <div class="analytics-bar">
        <div class="analytics-switch" role="tablist">
            <a class="<?= $activeTab === 'whatsapp' ? 'active' : '' ?>" href="<?= $tabUrl('whatsapp') ?>"><i class="fab fa-whatsapp"></i> WhatsApp</a>
            <a class="<?= $activeTab === 'email' ? 'active' : '' ?>" href="<?= $tabUrl('email') ?>"><i class="fas fa-envelope"></i> Email</a>
        </div>
        <form method="get" action="<?= site_url('analytics') ?>" class="analytics-filters">
            <input type="hidden" name="tab" value="<?= esc($activeTab) ?>">
            <input type="date" name="from" class="form-control form-control-sm" value="<?= esc($from) ?>" title="From" aria-label="From">
            <input type="date" name="to" class="form-control form-control-sm" value="<?= esc($to) ?>" title="To" aria-label="To">
            <select name="campaign_id" class="form-select form-select-sm" title="Campaign" aria-label="Campaign">
                <option value="">All campaigns</option>
                <?php foreach ($options as $option): ?>
                    <option value="<?= (int) $option['id'] ?>" <?= $campaignId === (int) $option['id'] ? 'selected' : '' ?>><?= esc($option['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-wa btn-sm">Apply</button>
            <a href="<?= $tabUrl($activeTab) ?>" class="btn btn-outline-secondary btn-sm" title="Reset filters">Reset</a>
        </form>
    </div>

    <?php if ($activeTab === 'whatsapp'): ?>
        <p class="analytics-note">Campaign sends in the selected dates. Inbox chat is not included. A reply counts only after that campaign was sent.</p>

        <div class="page-section">
            <div class="page-section-head"><h2 class="page-section-title">Delivery</h2></div>
            <div class="row g-2">
                <?php
                $cards = [
                    ['Sent', (int) ($waSum['sent'] ?? 0), 'Campaign messages', 'kpi-accent-teal'],
                    ['Delivered', (int) ($waSum['delivered'] ?? 0), ($waSum['delivery_rate'] ?? 0) . '% of sent', 'kpi-accent-green'],
                    ['Read', (int) ($waSum['read'] ?? 0), ($waSum['read_rate'] ?? 0) . '% of delivered', 'kpi-accent-sky'],
                    ['Failed', (int) ($waSum['failed'] ?? 0), ($waSum['fail_rate'] ?? 0) . '% of attempts', 'kpi-accent-danger'],
                    ['Pending', (int) ($waSum['pending'] ?? 0), 'Still in queue', 'kpi-accent-amber'],
                    ['Replies', (int) ($waSum['replies'] ?? 0), 'After the campaign send', 'kpi-accent-amber'],
                ];
                foreach ($cards as [$label, $num, $meta, $accent]):
                ?>
                <div class="col-6 col-md-4 col-xl-2">
                    <div class="kpi-card kpi-compact <?= $accent ?>">
                        <span class="kpi-label"><?= esc($label) ?></span>
                        <span class="kpi-value"><?= esc(number_format($num)) ?></span>
                        <span class="kpi-meta"><?= esc($meta) ?></span>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="row g-2">
            <div class="col-lg-8">
                <div class="dash-panel">
                    <div class="panel-head"><h3>Daily delivery</h3></div>
                    <div class="panel-body analytics-chart">
                        <?php
                        $daily = $wa['daily'] ?? [];
                        $labels = array_column($daily, 'label');
                        ?>
                        <canvas id="waTrendChart"
                            data-labels='<?= esc(json_encode($labels), 'attr') ?>'
                            data-sent='<?= esc(json_encode(array_column($daily, 'sent')), 'attr') ?>'
                            data-delivered='<?= esc(json_encode(array_column($daily, 'delivered')), 'attr') ?>'
                            data-failed='<?= esc(json_encode(array_column($daily, 'failed')), 'attr') ?>'></canvas>
                    </div>
                </div>
            </div>
            <div class="col-lg-4">
                <div class="dash-panel">
                    <div class="panel-head"><h3>Status mix</h3></div>
                    <div class="panel-body analytics-chart">
                        <canvas id="waMixChart"
                            data-labels='<?= esc(json_encode(['Pending', 'Sent', 'Delivered', 'Read', 'Failed']), 'attr') ?>'
                            data-values='<?= esc(json_encode([
                                (int) ($waSum['pending'] ?? 0),
                                (int) ($waSum['sent_only'] ?? 0),
                                (int) ($waSum['delivered_only'] ?? 0),
                                (int) ($waSum['read'] ?? 0),
                                (int) ($waSum['failed'] ?? 0),
                            ]), 'attr') ?>'></canvas>
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-body py-3">
                <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
                    <h2 class="h6 mb-0">Campaigns in this period</h2>
                    <?php
                    $contactsExportQs = http_build_query(array_filter([
                        'type'        => 'contacts',
                        'channel'     => 'whatsapp',
                        'from'        => $from,
                        'to'          => $to,
                        'campaign_id' => $campaignId,
                    ]));
                    ?>
                    <?php if ($campaignId): ?>
                        <a href="<?= site_url('reports/export-excel?' . $contactsExportQs) ?>" class="btn btn-sm btn-outline-secondary" id="reportContactsExcelBtn">
                            <i class="fas fa-file-csv me-1"></i> Contacts Excel
                        </a>
                    <?php else: ?>
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="reportContactsExcelBtn" disabled title="Select a campaign in the filter first">
                            <i class="fas fa-file-csv me-1"></i> Contacts Excel
                        </button>
                    <?php endif; ?>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Campaign</th><th>Sent</th><th>Delivered</th><th>Read</th><th>Failed</th><th>Pending</th><th>Replies</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($wa['campaigns'])): ?>
                            <tr><td colspan="7" class="text-muted text-center py-3">No campaign sends in this date range.</td></tr>
                        <?php else: ?>
                            <?php foreach ($wa['campaigns'] as $row): ?>
                            <tr>
                                <td class="fw-semibold">
                                    <a href="<?= site_url('campaigns/' . (int) $row['id']) ?>"><?= esc($row['name']) ?></a>
                                    <a href="<?= $filterUrl((int) $row['id']) ?>" class="small text-muted ms-1">Filter</a>
                                </td>
                                <td><?= number_format((int) $row['sent_count']) ?></td>
                                <td><?= number_format((int) $row['delivered_count']) ?> <span class="text-muted small"><?= esc((string) $row['delivery_rate']) ?>%</span></td>
                                <td><?= number_format((int) $row['read_count']) ?></td>
                                <td><?= number_format((int) $row['failed_count']) ?></td>
                                <td><?= number_format((int) $row['pending_count']) ?></td>
                                <td><?= number_format((int) $row['reply_count']) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-body py-3">
                <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
                    <h2 class="h6 mb-0">Recipients in this period</h2>
                    <span class="small text-muted">
                        <?php
                        $shown = count($wa['recipients'] ?? []);
                        $totalRecipients = (int) ($wa['recipient_total'] ?? 0);
                        echo $totalRecipients > $shown
                            ? 'Showing latest ' . number_format($shown) . ' of ' . number_format($totalRecipients)
                            : number_format($totalRecipients) . ' recipient' . ($totalRecipients === 1 ? '' : 's');
                        ?>
                    </span>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle mb-0" id="reportCampaignContactsTable">
                        <thead>
                            <tr>
                                <th>Contact</th><th>Mobile</th><th>Status</th><th>Campaign</th><th>Sent</th><th>Delivered</th><th>Read</th><th>Error</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($wa['recipients'])): ?>
                            <tr><td colspan="8" class="text-muted text-center py-3">No recipients in this date range.</td></tr>
                        <?php else: ?>
                            <?php foreach ($wa['recipients'] as $recipient): ?>
                            <tr>
                                <td class="fw-semibold"><?= esc($recipient['name'] !== '' ? $recipient['name'] : 'Unknown') ?></td>
                                <td><?= esc($recipient['mobile'] !== '' ? $recipient['mobile'] : '—') ?></td>
                                <td><?= view('partials/status_badge', ['status' => $recipient['status'] ?? '']) ?></td>
                                <td>
                                    <?php if ((int) ($recipient['campaign_id'] ?? 0) > 0): ?>
                                        <a href="<?= site_url('campaigns/' . (int) $recipient['campaign_id']) ?>"><?= esc($recipient['campaign_name'] !== '' ? $recipient['campaign_name'] : ('Campaign #' . (int) $recipient['campaign_id'])) ?></a>
                                    <?php else: ?>
                                        —
                                    <?php endif; ?>
                                </td>
                                <td class="small text-muted"><?= esc(format_app_datetime($recipient['sent_at'] ?? null, 'd-M-Y, g:i A', '—')) ?></td>
                                <td class="small text-muted"><?= esc(format_app_datetime($recipient['delivered_at'] ?? null, 'd-M-Y, g:i A', '—')) ?></td>
                                <td class="small text-muted"><?= esc(format_app_datetime($recipient['read_at'] ?? null, 'd-M-Y, g:i A', '—')) ?></td>
                                <td class="small text-danger"><?= esc($recipient['error_message'] !== '' ? $recipient['error_message'] : '') ?></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    <?php else: ?>
        <p class="analytics-note">Opens, clicks, and unsubscribes are for emails sent in these dates. Cards include campaigns plus single, bulk, and drip sends.</p>

        <div class="page-section">
            <div class="page-section-head"><h2 class="page-section-title">Email delivery and engagement</h2></div>
            <div class="row g-2">
                <?php
                $emailCards = [
                    ['Sent', (int) ($emailSum['sent'] ?? 0), 'Successfully sent', 'kpi-accent-teal'],
                    ['Queued', (int) ($emailSum['queued'] ?? 0), 'Waiting to send', 'kpi-accent-amber'],
                    ['Failed', (int) ($emailSum['failed'] ?? 0), ($emailSum['fail_rate'] ?? 0) . '% of attempts', 'kpi-accent-danger'],
                    ['Opens', (int) ($emailSum['opened'] ?? 0), ($emailSum['open_rate'] ?? 0) . '% open rate', 'kpi-accent-green'],
                    ['Clicks', (int) ($emailSum['clicked'] ?? 0), ($emailSum['click_rate'] ?? 0) . '% click rate', 'kpi-accent-sky'],
                    ['CTOR', (string) ($emailSum['ctor'] ?? 0) . '%', 'Clicks divided by opens', 'kpi-accent-sky'],
                    ['Unsubscribed', (int) ($emailSum['unsubscribes'] ?? 0), 'In this date range', 'kpi-accent-danger'],
                ];
                foreach ($emailCards as [$label, $num, $meta, $accent]):
                ?>
                <div class="col-6 col-md-4 col-xl">
                    <div class="kpi-card kpi-compact <?= $accent ?>">
                        <span class="kpi-label"><?= esc($label) ?></span>
                        <span class="kpi-value"><?= esc(is_int($num) ? number_format($num) : (string) $num) ?></span>
                        <span class="kpi-meta"><?= esc($meta) ?></span>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="row g-2">
            <div class="col-lg-8">
                <div class="dash-panel">
                    <div class="panel-head"><h3>Daily sends</h3></div>
                    <div class="panel-body analytics-chart">
                        <?php $daily = $email['daily'] ?? []; ?>
                        <canvas id="emailTrendChart"
                            data-labels='<?= esc(json_encode(array_column($daily, 'label')), 'attr') ?>'
                            data-sent='<?= esc(json_encode(array_column($daily, 'sent')), 'attr') ?>'
                            data-failed='<?= esc(json_encode(array_column($daily, 'failed')), 'attr') ?>'
                            data-queued='<?= esc(json_encode(array_column($daily, 'queued')), 'attr') ?>'></canvas>
                    </div>
                </div>
            </div>
            <div class="col-lg-4">
                <div class="dash-panel">
                    <div class="panel-head"><h3>Sent, queued, failed</h3></div>
                    <div class="panel-body analytics-chart">
                        <canvas id="emailMixChart"
                            data-labels='<?= esc(json_encode(['Sent', 'Queued', 'Failed']), 'attr') ?>'
                            data-values='<?= esc(json_encode([
                                (int) ($emailSum['sent'] ?? 0),
                                (int) ($emailSum['queued'] ?? 0),
                                (int) ($emailSum['failed'] ?? 0),
                            ]), 'attr') ?>'></canvas>
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-body py-3">
                <h2 class="h6 mb-2">Campaigns in this period</h2>
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle mb-0">
                        <thead>
                            <tr><th>Campaign</th><th>Sent</th><th>Queued</th><th>Failed</th><th>Opens</th><th>Clicks</th><th>Open rate</th></tr>
                        </thead>
                        <tbody>
                        <?php if (empty($email['campaigns'])): ?>
                            <tr><td colspan="7" class="text-muted text-center py-3">No emails in this date range.</td></tr>
                        <?php else: ?>
                            <?php foreach ($email['campaigns'] as $row): ?>
                            <tr>
                                <td class="fw-semibold">
                                    <?= esc($row['name']) ?>
                                    <?php if ((int) $row['id'] > 0): ?>
                                        <a href="<?= $filterUrl((int) $row['id']) ?>" class="small text-muted ms-1">Filter</a>
                                    <?php endif; ?>
                                </td>
                                <td><?= number_format((int) $row['sent_count']) ?></td>
                                <td><?= number_format((int) $row['queued_count']) ?></td>
                                <td><?= number_format((int) $row['failed_count']) ?></td>
                                <td><?= number_format((int) $row['opened_count']) ?></td>
                                <td><?= number_format((int) $row['clicked_count']) ?></td>
                                <td><?= esc((string) $row['open_rate']) ?>%</td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="row g-2">
            <div class="col-lg-8">
                <div class="card">
                    <div class="card-body py-3">
                        <h2 class="h6 mb-2">Recent sends in this period</h2>
                        <div class="table-responsive">
                            <table class="table table-sm table-hover align-middle mb-0">
                                <thead>
                                    <tr><th>Recipient</th><th>Subject</th><th>Status</th><th>Opens</th><th>Clicks</th><th>When</th></tr>
                                </thead>
                                <tbody>
                                <?php if (empty($email['logs'])): ?>
                                    <tr><td colspan="6" class="text-muted text-center py-3">No email sends in this date range.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($email['logs'] as $log): ?>
                                    <tr>
                                        <td>
                                            <span class="fw-semibold"><?= esc($log['to_email'] !== '' ? $log['to_email'] : '—') ?></span>
                                            <?php if (($log['kind'] ?? '') !== ''): ?>
                                                <div class="small text-muted"><?= esc(ucfirst((string) $log['kind'])) ?></div>
                                            <?php endif; ?>
                                            <?php if ((int) ($log['id'] ?? 0) > 0): ?>
                                                <a href="#" class="js-email-recipients small text-decoration-none" data-log-id="<?= (int) $log['id'] ?>"><i class="fas fa-users me-1"></i>Recipients</a>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-truncate small" style="max-width:180px" title="<?= esc($log['subject'] ?? '') ?>"><?= esc(($log['subject'] ?? '') !== '' ? $log['subject'] : '—') ?></td>
                                        <td><?= view('partials/status_badge', ['status' => $log['status'] ?? '']) ?></td>
                                        <td><?= (int) ($log['open_count'] ?? 0) > 0 ? (int) $log['open_count'] : '—' ?></td>
                                        <td><?= (int) ($log['click_count'] ?? 0) > 0 ? (int) $log['click_count'] : '—' ?></td>
                                        <td class="small text-muted text-nowrap"><?= esc(format_app_datetime($log['created_at'] ?? null)) ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-lg-4">
                <div class="dash-panel">
                    <div class="panel-head d-flex justify-content-between align-items-center">
                        <h3 class="mb-0">Unsubscribed</h3>
                        <span class="badge bg-danger rounded-pill"><?= number_format((int) ($emailSum['unsubscribes'] ?? 0)) ?></span>
                    </div>
                    <div class="panel-body p-0">
                        <?php if (empty($email['unsubscribes'])): ?>
                            <div class="text-center py-4 text-muted small">No unsubscribes in this date range.</div>
                        <?php else: ?>
                            <ul class="list-group list-group-flush small">
                                <?php foreach ($email['unsubscribes'] as $unsub): ?>
                                <li class="list-group-item d-flex justify-content-between align-items-center py-2 px-3">
                                    <div class="text-truncate me-2">
                                        <div class="fw-semibold text-truncate"><?= esc($unsub['email'] ?? '') ?></div>
                                        <div class="text-muted" style="font-size:0.7rem"><?= esc(($unsub['reason'] ?? '') !== '' ? $unsub['reason'] : 'User requested') ?></div>
                                    </div>
                                    <span class="text-muted" style="font-size:0.7rem"><?= esc(format_app_datetime($unsub['created_at'] ?? null, 'd M', '—')) ?></span>
                                </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>
<?= view('partials/email_recipients_modal') ?>
<?= $this->endSection() ?>

<?= $this->section('styles') ?>
<style>
.analytics-page.page-stack { gap: 0.7rem; }
.analytics-bar {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 0.6rem 0.85rem;
    padding: 0.4rem 0.5rem 0.4rem 0.4rem;
    background: #fff;
    border: 1px solid var(--border);
    border-radius: 12px;
    box-shadow: var(--shadow-sm);
}
.analytics-switch {
    display: inline-flex;
    align-items: center;
    gap: 2px;
    padding: 3px;
    background: #f3f5f4;
    border-radius: 10px;
}
.analytics-switch a {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    padding: 0.32rem 0.75rem;
    border-radius: 8px;
    font-size: 0.82rem;
    font-weight: 600;
    color: #64748b;
    text-decoration: none;
    line-height: 1.2;
}
.analytics-switch a.active {
    background: #fff;
    color: #0f172a;
    box-shadow: 0 1px 2px rgba(15, 23, 42, 0.08);
}
.analytics-filters {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.4rem;
    margin: 0;
}
.analytics-filters .form-control,
.analytics-filters .form-select {
    width: auto;
    min-height: 32px;
    font-size: 0.8rem;
}
.analytics-filters input[type="date"] { width: 9.2rem; }
.analytics-filters select { max-width: 13.5rem; }
.analytics-note {
    margin: 0;
    padding: 0 0.15rem;
    font-size: 0.75rem;
    line-height: 1.35;
    color: var(--text-muted);
}
.analytics-page .page-section { gap: 0.4rem; }
.analytics-page .page-section-title { font-size: 0.78rem; letter-spacing: 0.04em; text-transform: uppercase; color: var(--text-muted); }
.analytics-page .kpi-card.kpi-compact {
    min-height: 0;
    padding: 0.55rem 0.7rem 0.5rem 0.8rem;
    gap: 0.05rem;
    border-radius: 12px;
}
.analytics-page .kpi-card .kpi-value { font-size: 1.28rem; }
.analytics-page .kpi-card .kpi-meta { font-size: 0.68rem; padding-top: 0; }
.analytics-page .dash-panel { border-radius: 12px; }
.analytics-page .dash-panel .panel-head { padding: 0.55rem 0.85rem 0.15rem; }
.analytics-page .dash-panel .panel-head h3 { font-size: 0.9rem; }
.analytics-chart { height: 220px; padding: 0.15rem 0.65rem 0.45rem; }
.analytics-page > .card { border-radius: 12px; }
.analytics-page > .card > .card-body { padding: 0.75rem 0.9rem; }
@media (max-width: 767.98px) {
    .analytics-filters,
    .analytics-filters .form-control,
    .analytics-filters .form-select,
    .analytics-filters input[type="date"] { width: 100%; max-width: none; }
}
</style>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= asset_url('assets/js/analytics.js') ?>"></script>
<script src="<?= asset_url('assets/js/email-recipients.js') ?>"></script>
<?= $this->endSection() ?>
