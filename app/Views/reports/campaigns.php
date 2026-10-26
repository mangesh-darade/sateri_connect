<?= $this->extend('layouts/main') ?>

<?= $this->section('header_actions') ?>
<?php
$from = (string) ($from ?? '');
$to = (string) ($to ?? '');
$campaignId = ($campaignId ?? null) !== null && $campaignId !== '' ? (int) $campaignId : null;
$exportQs = http_build_query(array_filter(['channel' => 'whatsapp', 'from' => $from, 'to' => $to, 'campaign_id' => $campaignId]));
?>
<?php if (function_exists('can') && can('reports.export')): ?>
    <a href="<?= site_url('reports/export-excel?' . $exportQs) ?>" class="btn btn-sm btn-outline-secondary"><i class="fas fa-file-csv me-1"></i> Excel</a>
    <a href="<?= site_url('reports/export-pdf?' . $exportQs) ?>" class="btn btn-sm btn-outline-secondary"><i class="fas fa-print me-1"></i> Print</a>
<?php endif; ?>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php
$wa      = $wa ?? [];
$sum     = $wa['summary'] ?? [];
$options = $wa['campaign_options'] ?? [];
$daily   = $wa['daily'] ?? [];
$sent      = (int) ($sum['sent'] ?? 0);
$delivered = (int) ($sum['delivered'] ?? 0);
$read      = (int) ($sum['read'] ?? 0);
$failed    = (int) ($sum['failed'] ?? 0);
$replies   = (int) ($sum['replies'] ?? 0);
$pct = static fn (int $part, int $whole): float => $whole > 0 ? round(($part / $whole) * 100, 1) : 0.0;

$today = function_exists('app_today_ymd') ? app_today_ymd() : date('Y-m-d');
$ago   = static fn (int $d): string => date('Y-m-d', strtotime($today . ' -' . $d . ' days'));
$ranges = [
    'Today'      => [$today, $today],
    '7 days'     => [$ago(6), $today],
    '30 days'    => [$ago(29), $today],
    'This month' => [date('Y-m-01', strtotime($today)), $today],
    'Last month' => [date('Y-m-01', strtotime($today . ' first day of last month')), date('Y-m-t', strtotime($today . ' first day of last month'))],
];
$dtQs = http_build_query(array_filter(['from' => $from, 'to' => $to, 'campaign_id' => $campaignId]));
$rangeUrl = static fn (string $f, string $t): string => site_url('reports/campaigns?' . http_build_query(array_filter(['from' => $f, 'to' => $t, 'campaign_id' => $campaignId])));
$filterUrl = static fn (int $id): string => site_url('reports/campaigns?' . http_build_query(['from' => $from, 'to' => $to, 'campaign_id' => $id]));

$kpis = [
    ['Sent', number_format($sent), 'Campaign messages', '#0d9488'],
    ['Delivered', number_format($delivered), $pct($delivered, $sent) . '% of sent', '#16a34a'],
    ['Read', number_format($read), $pct($read, $delivered) . '% of delivered', '#0284c7'],
    ['Failed', number_format($failed), $pct($failed, $sent + $failed) . '% of attempts', '#dc2626'],
    ['Pending', number_format((int) ($sum['pending'] ?? 0)), 'Still in queue', '#f59e0b'],
    ['Replies', number_format($replies), 'After the campaign send', '#7c3aed'],
];
$funnel = [
    ['Sent', $sent, 100.0, '#0d9488'],
    ['Delivered', $delivered, $pct($delivered, $sent), '#16a34a'],
    ['Read', $read, $pct($read, $sent), '#0284c7'],
    ['Replied', $replies, $pct($replies, $sent), '#7c3aed'],
];
?>
<div class="page-list">

<div class="rpt-toolbar">
    <div class="rpt-ranges" role="group" aria-label="Quick date range">
        <?php foreach ($ranges as $label => [$f, $t]): ?>
            <a class="rpt-chip<?= $from === $f && $to === $t ? ' is-on' : '' ?>" href="<?= esc($rangeUrl($f, $t)) ?>"><?= esc($label) ?></a>
        <?php endforeach; ?>
    </div>
    <form method="get" action="<?= site_url('reports/campaigns') ?>" class="rpt-filters">
        <input type="date" name="from" class="form-control form-control-sm" value="<?= esc($from) ?>" aria-label="From">
        <input type="date" name="to" class="form-control form-control-sm" value="<?= esc($to) ?>" aria-label="To">
        <select name="campaign_id" class="form-select form-select-sm" aria-label="Campaign">
            <option value="">All campaigns</option>
            <?php foreach ($options as $o): ?>
                <option value="<?= (int) $o['id'] ?>" <?= $campaignId === (int) $o['id'] ? 'selected' : '' ?>><?= esc($o['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-wa btn-sm">Apply</button>
        <a href="<?= site_url('reports/campaigns') ?>" class="btn btn-outline-secondary btn-sm">Reset</a>
    </form>
</div>

<p class="rpt-note">WhatsApp campaign sends in the selected dates. Inbox chat is not included. A reply counts only after that campaign was sent.</p>

<div class="rpt-kpis">
    <?php foreach ($kpis as [$label, $value, $meta, $color]): ?>
        <div class="rpt-kpi" style="--c:<?= $color ?>"><span><?= esc($label) ?></span><strong><?= esc($value) ?></strong><em><?= esc($meta) ?></em></div>
    <?php endforeach; ?>
</div>

<div class="row g-3 mb-3">
    <div class="col-lg-5">
        <div class="rpt-panel">
            <h2>Delivery funnel</h2>
            <?php if ($sent > 0): ?>
                <div class="rpt-funnel">
                    <?php foreach ($funnel as [$label, $count, $share, $color]): ?>
                        <div class="rpt-funnel-row">
                            <span class="name"><?= esc($label) ?></span>
                            <div class="rpt-funnel-track"><div class="rpt-funnel-fill" style="width:<?= number_format(min(100, $share), 2, '.', '') ?>%;--c:<?= $color ?>"></div></div>
                            <span class="rpt-funnel-val"><strong><?= number_format($count) ?></strong><small><?= esc((string) $share) ?>%</small></span>
                        </div>
                    <?php endforeach; ?>
                </div>
                <p class="rpt-note mt-3 mb-0">Each bar is shown as a share of messages sent.</p>
            <?php else: ?>
                <div class="rpt-empty"><i class="fas fa-filter"></i>No campaign messages sent in this period.</div>
            <?php endif; ?>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="rpt-panel">
            <h2>Daily delivery</h2>
            <div class="rpt-chart">
                <canvas id="waTrendChart"
                    data-labels='<?= esc(json_encode(array_column($daily, 'label')), 'attr') ?>'
                    data-sent='<?= esc(json_encode(array_column($daily, 'sent')), 'attr') ?>'
                    data-delivered='<?= esc(json_encode(array_column($daily, 'delivered')), 'attr') ?>'
                    data-failed='<?= esc(json_encode(array_column($daily, 'failed')), 'attr') ?>'></canvas>
            </div>
        </div>
    </div>
</div>

<div class="rpt-panel mb-3">
    <div class="rpt-panel-head">
        <h2>Campaign performance</h2>
        <div class="d-flex align-items-center gap-2 flex-wrap">
            <?php if ($campaignId && function_exists('can') && can('reports.export')): ?>
                <a href="<?= site_url('reports/export-excel?' . http_build_query(['type' => 'contacts', 'channel' => 'whatsapp', 'from' => $from, 'to' => $to, 'campaign_id' => $campaignId])) ?>" class="btn btn-sm btn-outline-secondary"><i class="fas fa-file-csv me-1"></i> Contacts Excel</a>
            <?php endif; ?>
        </div>
    </div>
    <div class="table-responsive">
        <table class="table table-sm table-hover align-middle mb-0 rpt-table" id="rptCampTable" data-dt-report="wa-campaigns" data-url="<?= esc(site_url('reports/campaigns/data?' . $dtQs), 'attr') ?>">
            <thead>
                <tr><th>Campaign</th><th class="num">Sent</th><th class="num">Delivered</th><th class="num">Read</th><th class="num">Failed</th><th class="num">Pending</th><th class="num">Replies</th><th></th></tr>
            </thead>
        </table>
    </div>
</div>

<div class="rpt-panel">
    <div class="rpt-panel-head">
        <h2>Recipients</h2>
        <div class="d-flex align-items-center gap-3 flex-wrap">
        </div>
    </div>
    <div class="table-responsive">
        <table class="table table-sm table-hover align-middle mb-0 rpt-table" id="rptRecipTable" data-dt-report="wa-recipients" data-url="<?= esc(site_url('reports/campaigns/recipients-data?' . $dtQs), 'attr') ?>">
            <thead>
                <tr><th>Contact</th><th>Mobile</th><th>Status</th><th>Campaign</th><th>Sent</th><th>Delivered</th><th>Read</th><th>Error</th></tr>
            </thead>
        </table>
    </div>
</div>

</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= asset_url('assets/js/analytics.js') ?>"></script>
<script src="<?= asset_url('assets/js/reports-tables.js') ?>"></script>
<?= $this->endSection() ?>
