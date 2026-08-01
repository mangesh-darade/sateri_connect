<?= $this->extend('layouts/main') ?>

<?= $this->section('header_actions') ?>
<?php
$filters = $filters ?? [];
$fromVal = (string) ($filters['from'] ?? $from ?? date('Y-m-01'));
$toVal   = (string) ($filters['to'] ?? $to ?? date('Y-m-d'));
$campaignFilter = $filters['campaign_id'] ?? '';
$exportQs = http_build_query(array_filter([
    'from'        => $fromVal,
    'to'          => $toVal,
    'campaign_id' => $campaignFilter !== null && $campaignFilter !== '' ? $campaignFilter : null,
]));
?>
<?php if (function_exists('can') && can('reports.export')): ?>
    <a href="<?= site_url('reports/export-excel?' . $exportQs) ?>" class="btn btn-sm btn-outline-secondary" title="Excel"><i class="fas fa-file-csv me-1"></i> Excel</a>
    <a href="<?= site_url('reports/export-pdf?' . $exportQs) ?>" class="btn btn-sm btn-outline-secondary" title="Print / HTML report"><i class="fas fa-print me-1"></i> Print</a>
<?php endif; ?>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php
$filters = $filters ?? [];
$fromVal = (string) ($filters['from'] ?? $from ?? date('Y-m-01'));
$toVal   = (string) ($filters['to'] ?? $to ?? date('Y-m-d'));
$from    = esc($fromVal);
$to      = esc($toVal);
$campaignFilter = $filters['campaign_id'] ?? '';
$summary = $summary ?? $stats ?? $overview ?? [];
?>
<div class="page-list">
<div class="card">
    <div class="card-body py-3">
        <form method="get" action="<?= site_url('reports') ?>" class="filter-bar mb-0">
            <input type="date" name="from" class="form-control form-control-sm" style="max-width:150px" value="<?= $from ?>" title="From">
            <input type="date" name="to" class="form-control form-control-sm" style="max-width:150px" value="<?= $to ?>" title="To">
            <select name="campaign_id" class="form-select form-select-sm" style="max-width:180px">
                <option value="">All campaigns</option>
                <?php foreach (($campaigns ?? []) as $c): ?>
                    <option value="<?= (int) $c['id'] ?>" <?= (string) $campaignFilter === (string) $c['id'] ? 'selected' : '' ?>><?= esc($c['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <div class="filter-bar-actions">
                <button type="submit" class="btn btn-wa btn-sm"><i class="fas fa-filter me-1"></i> Apply</button>
            </div>
        </form>
    </div>
</div>

<div class="page-section">
    <div class="page-section-head">
        <h2 class="page-section-title">Summary</h2>
    </div>
    <div class="row g-2">
    <?php
    $cards = [
        ['Sent', $summary['sent'] ?? 0, 'kpi-accent-teal'],
        ['Delivered', $summary['delivered'] ?? 0, 'kpi-accent-green'],
        ['Read', $summary['read'] ?? 0, 'kpi-accent-sky'],
        ['Failed', $summary['failed'] ?? 0, 'kpi-accent-danger'],
        ['Replies', $summary['replies'] ?? 0, 'kpi-accent-amber'],
    ];
    foreach ($cards as [$label, $num, $accent]):
    ?>
    <div class="col-6 col-md">
        <div class="kpi-card <?= $accent ?>">
            <span class="kpi-label"><?= esc($label) ?></span>
            <span class="kpi-value"><?= esc(number_format((int) $num)) ?></span>
        </div>
    </div>
    <?php endforeach; ?>
    </div>
</div>

<div class="row g-2">
    <div class="col-lg-8">
        <div class="dash-panel">
            <div class="panel-head"><h3>Delivery over time</h3></div>
            <div class="panel-body" style="height:320px">
                <?php $trend = $charts['trends'] ?? $trend ?? []; ?>
                <canvas id="reportTrendChart"
                    data-labels='<?= json_encode($trend['labels'] ?? []) ?>'
                    data-sent='<?= json_encode($trend['sent'] ?? []) ?>'
                    data-delivered='<?= json_encode($trend['delivered'] ?? []) ?>'
                    data-failed='<?= json_encode($trend['failed'] ?? []) ?>'></canvas>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="dash-panel">
            <div class="panel-head"><h3>Status mix</h3></div>
            <div class="panel-body" style="height:320px">
                <canvas id="reportMixChart"
                    data-labels='<?= json_encode(['Delivered','Read','Failed','Replies']) ?>'
                    data-values='<?= json_encode([
                        (int) ($summary['delivered'] ?? 0),
                        (int) ($summary['read'] ?? 0),
                        (int) ($summary['failed'] ?? 0),
                        (int) ($summary['replies'] ?? 0),
                    ]) ?>'></canvas>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-body py-3">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
            <h2 class="h6 mb-0">Campaign breakdown</h2>
            <div class="d-flex gap-2">
                <?php
                $contactsExportQs = http_build_query(array_filter([
                    'type'        => 'contacts',
                    'campaign_id' => $campaignFilter !== null && $campaignFilter !== '' ? $campaignFilter : null,
                ]));
                ?>
                <?php if ($campaignFilter !== null && $campaignFilter !== ''): ?>
                    <a href="<?= site_url('reports/export-excel?' . $contactsExportQs) ?>" class="btn btn-sm btn-outline-secondary" id="reportContactsExcelBtn">
                        <i class="fas fa-file-csv me-1"></i> Contacts Excel
                    </a>
                <?php else: ?>
                    <button type="button" class="btn btn-sm btn-outline-secondary" disabled title="Select a campaign in the filter first">
                        <i class="fas fa-file-csv me-1"></i> Contacts Excel
                    </button>
                <?php endif; ?>
                <a href="<?= site_url('reports/campaigns') ?>" class="btn btn-sm btn-outline-secondary">Campaigns</a>
                <a href="<?= site_url('reports/delivery') ?>" class="btn btn-sm btn-outline-secondary">Delivery</a>
            </div>
        </div>
        <table class="table table-sm table-hover align-middle w-100" id="reportCampaignsTable">
            <thead>
                <tr><th>Campaign</th><th>Sent</th><th>Delivered</th><th>Read</th><th>Failed</th><th>Replies</th></tr>
            </thead>
            <tbody>
                <?php foreach (($campaign_stats ?? []) as $row): ?>
                    <tr<?= ! empty($row['id']) ? ' data-campaign-id="' . (int) $row['id'] . '"' : '' ?>>
                        <td class="fw-semibold"><?= esc($row['name'] ?? '') ?></td>
                        <td><?= esc((string) ($row['sent_count'] ?? 0)) ?></td>
                        <td><?= esc((string) ($row['delivered_count'] ?? 0)) ?></td>
                        <td><?= esc((string) ($row['read_count'] ?? 0)) ?></td>
                        <td><?= esc((string) ($row['failed_count'] ?? 0)) ?></td>
                        <td><?= esc((string) ($row['reply_count'] ?? 0)) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($campaignFilter !== null && $campaignFilter !== ''): ?>
<div class="card mt-3">
    <div class="card-body py-3">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
            <h2 class="h6 mb-0">Contacts in campaign</h2>
            <span class="small text-muted" id="reportContactsMeta">Loading…</span>
        </div>
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle w-100" id="reportCampaignContactsTable">
                <thead>
                    <tr>
                        <th>Contact</th>
                        <th>Mobile</th>
                        <th>Status</th>
                        <th>Sent</th>
                        <th>Delivered</th>
                        <th>Read (Opened)</th>
                        <th>Error</th>
                    </tr>
                </thead>
                <tbody>
                    <tr><td colspan="7" class="text-muted">Loading contacts…</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
$(function () {
    var campaignFilter = <?= json_encode($campaignFilter !== null && $campaignFilter !== '' ? (int) $campaignFilter : null) ?>;
    if (campaignFilter) {
        $.getJSON(<?= json_encode(site_url('reports/campaign-contacts')) ?>, { campaign_id: campaignFilter, per_page: 500 })
            .done(function (res) {
                var data = (res && res.data) ? res.data : res;
                var rows = (data && data.contacts) ? data.contacts : [];
                var $tb = $('#reportCampaignContactsTable tbody').empty();
                $('#reportContactsMeta').text((data.campaign_name || 'Campaign') + ' — ' + (data.total || rows.length) + ' contact(s)');
                if (!rows.length) {
                    $tb.append('<tr><td colspan="7" class="text-muted">No contacts in this campaign.</td></tr>');
                    return;
                }
                rows.forEach(function (r) {
                    $tb.append(
                        '<tr>'
                        + '<td>' + $('<div>').text(r.name || '').html() + '</td>'
                        + '<td>' + $('<div>').text(r.mobile || '').html() + '</td>'
                        + '<td>' + $('<div>').text(r.status || '').html() + '</td>'
                        + '<td class="small text-muted">' + $('<div>').text(r.sent_at || '—').html() + '</td>'
                        + '<td class="small text-muted">' + $('<div>').text(r.delivered_at || '—').html() + '</td>'
                        + '<td class="small text-muted">' + $('<div>').text(r.read_at || '—').html() + '</td>'
                        + '<td class="small text-danger">' + $('<div>').text(r.error_message || '').html() + '</td>'
                        + '</tr>'
                    );
                });
            })
            .fail(function () {
                $('#reportCampaignContactsTable tbody').html('<tr><td colspan="7" class="text-danger">Failed to load contacts.</td></tr>');
                $('#reportContactsMeta').text('Failed to load');
            });
    }

    var t = document.getElementById('reportTrendChart');
    if (t && window.Chart) {
        new Chart(t.getContext('2d'), {
            type: 'line',
            data: {
                labels: JSON.parse(t.getAttribute('data-labels') || '[]'),
                datasets: [
                    { label: 'Sent', data: JSON.parse(t.getAttribute('data-sent') || '[]'), borderColor: '#4b3786', tension: .3 },
                    { label: 'Delivered', data: JSON.parse(t.getAttribute('data-delivered') || '[]'), borderColor: '#8e53f7', tension: .3 },
                    { label: 'Failed', data: JSON.parse(t.getAttribute('data-failed') || '[]'), borderColor: '#e25555', tension: .3 }
                ]
            },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom' } } }
        });
    }
    var m = document.getElementById('reportMixChart');
    if (m && window.Chart) {
        new Chart(m.getContext('2d'), {
            type: 'doughnut',
            data: {
                labels: JSON.parse(m.getAttribute('data-labels') || '[]'),
                datasets: [{ data: JSON.parse(m.getAttribute('data-values') || '[]'), backgroundColor: ['#8e53f7','#34B7F1','#e25555','#f0a202'], borderWidth: 0 }]
            },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom' } } }
        });
    }
    if ($.fn.DataTable) { $('#reportCampaignsTable').DataTable(); }
});
</script>
<?= $this->endSection() ?>
