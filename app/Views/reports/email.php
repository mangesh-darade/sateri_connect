<?= $this->extend('layouts/main') ?>

<?= $this->section('header_actions') ?>
<?php
$from = (string) ($from ?? '');
$to = (string) ($to ?? '');
$campaignId = ($campaignId ?? null) !== null && $campaignId !== '' ? (int) $campaignId : null;
$exportQs = http_build_query(array_filter(['channel' => 'email', 'from' => $from, 'to' => $to, 'campaign_id' => $campaignId]));
?>
<?php if (function_exists('can') && can('reports.export')): ?>
    <a href="<?= site_url('reports/export-excel?' . $exportQs) ?>" class="btn btn-sm btn-outline-secondary"><i class="fas fa-file-csv me-1"></i> Excel</a>
    <a href="<?= site_url('reports/export-pdf?' . $exportQs) ?>" class="btn btn-sm btn-outline-secondary"><i class="fas fa-print me-1"></i> Print</a>
<?php endif; ?>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php
$email   = $email ?? [];
$sum     = $email['summary'] ?? [];
$options = $email['campaign_options'] ?? [];
$daily   = $email['daily'] ?? [];
$sent    = (int) ($sum['sent'] ?? 0);
$opened  = (int) ($sum['opened'] ?? 0);
$clicked = (int) ($sum['clicked'] ?? 0);
$failed  = (int) ($sum['failed'] ?? 0);
$unsubs  = (int) ($sum['unsubscribes'] ?? 0);
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
$rangeUrl  = static fn (string $f, string $t): string => site_url('reports/email?' . http_build_query(array_filter(['from' => $f, 'to' => $t, 'campaign_id' => $campaignId])));
$filterUrl = static fn (int $id): string => site_url('reports/email?' . http_build_query(['from' => $from, 'to' => $to, 'campaign_id' => $id]));

$kpis = [
    ['Sent', number_format($sent), 'Successfully sent', '#16a34a'],
    ['Queued', number_format((int) ($sum['queued'] ?? 0)), 'Waiting to send', '#f59e0b'],
    ['Failed', number_format($failed), ($sum['fail_rate'] ?? 0) . '% of attempts', '#dc2626'],
    ['Opens', number_format($opened), ($sum['open_rate'] ?? 0) . '% open rate', '#0284c7'],
    ['Clicks', number_format($clicked), ($sum['click_rate'] ?? 0) . '% click rate', '#7c3aed'],
    ['Click-to-open', ($sum['ctor'] ?? 0) . '%', 'Clicks divided by opens', '#0d9488'],
    ['Unsubscribed', number_format($unsubs), 'In this date range', '#be123c'],
];
$funnel = [
    ['Sent', $sent, 100.0, '#16a34a'],
    ['Opened', $opened, $pct($opened, $sent), '#0284c7'],
    ['Clicked', $clicked, $pct($clicked, $sent), '#7c3aed'],
    ['Unsubscribed', $unsubs, $pct($unsubs, $sent), '#be123c'],
];
?>
<div class="page-list">

<div class="rpt-toolbar">
    <div class="rpt-ranges" role="group" aria-label="Quick date range">
        <?php foreach ($ranges as $label => [$f, $t]): ?>
            <a class="rpt-chip<?= $from === $f && $to === $t ? ' is-on' : '' ?>" href="<?= esc($rangeUrl($f, $t)) ?>"><?= esc($label) ?></a>
        <?php endforeach; ?>
    </div>
    <form method="get" action="<?= site_url('reports/email') ?>" class="rpt-filters">
        <input type="date" name="from" class="form-control form-control-sm" value="<?= esc($from) ?>" aria-label="From">
        <input type="date" name="to" class="form-control form-control-sm" value="<?= esc($to) ?>" aria-label="To">
        <select name="campaign_id" class="form-select form-select-sm" aria-label="Campaign">
            <option value="">All campaigns</option>
            <?php foreach ($options as $o): ?>
                <option value="<?= (int) $o['id'] ?>" <?= $campaignId === (int) $o['id'] ? 'selected' : '' ?>><?= esc($o['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-wa btn-sm">Apply</button>
        <a href="<?= site_url('reports/email') ?>" class="btn btn-outline-secondary btn-sm">Reset</a>
    </form>
</div>

<p class="rpt-note">Opens, clicks and unsubscribes are for emails sent in these dates. Numbers include campaigns plus single, bulk and drip sends.</p>

<div class="rpt-kpis">
    <?php foreach ($kpis as [$label, $value, $meta, $color]): ?>
        <div class="rpt-kpi" style="--c:<?= $color ?>"><span><?= esc($label) ?></span><strong><?= esc($value) ?></strong><em><?= esc($meta) ?></em></div>
    <?php endforeach; ?>
</div>

<div class="row g-3 mb-3">
    <div class="col-lg-5">
        <div class="rpt-panel">
            <h2>Engagement funnel</h2>
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
                <p class="rpt-note mt-3 mb-0">Each bar is shown as a share of emails sent.</p>
            <?php else: ?>
                <div class="rpt-empty"><i class="fas fa-filter"></i>No emails sent in this period.</div>
            <?php endif; ?>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="rpt-panel">
            <h2>Daily sends</h2>
            <div class="rpt-chart">
                <canvas id="emailTrendChart"
                    data-labels='<?= esc(json_encode(array_column($daily, 'label')), 'attr') ?>'
                    data-sent='<?= esc(json_encode(array_column($daily, 'sent')), 'attr') ?>'
                    data-failed='<?= esc(json_encode(array_column($daily, 'failed')), 'attr') ?>'
                    data-queued='<?= esc(json_encode(array_column($daily, 'queued')), 'attr') ?>'></canvas>
            </div>
        </div>
    </div>
</div>

<div class="rpt-panel mb-3">
    <div class="rpt-panel-head">
        <h2>Campaign performance</h2>
    </div>
    <div class="table-responsive">
        <table class="table table-sm table-hover align-middle mb-0 rpt-table" id="rptCampTable" data-dt-report="email-campaigns" data-url="<?= esc(site_url('reports/email/campaigns-data?' . $dtQs), 'attr') ?>">
            <thead>
                <tr><th>Campaign</th><th class="num">Sent</th><th class="num">Queued</th><th class="num">Failed</th><th class="num">Opens</th><th class="num">Clicks</th><th class="num">Open rate</th><th></th></tr>
            </thead>
        </table>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-8">
        <div class="rpt-panel">
            <div class="rpt-panel-head">
                <h2>Recent sends</h2>
            </div>
            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0 rpt-table" id="rptLogTable" data-dt-report="email-logs" data-url="<?= esc(site_url('reports/email/logs-data?' . $dtQs), 'attr') ?>">
                    <thead><tr><th>Recipient</th><th>Subject</th><th>Status</th><th class="num">Opens</th><th class="num">Clicks</th><th>When</th></tr></thead>
                </table>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="rpt-panel">
            <div class="rpt-panel-head">
                <h2>Unsubscribed</h2>
                <span class="badge bg-danger rounded-pill"><?= number_format($unsubs) ?></span>
            </div>
            <?php if (empty($email['unsubscribes'])): ?>
                <div class="rpt-empty"><i class="fas fa-user-check"></i>No unsubscribes in this date range.</div>
            <?php else: ?>
                <ul class="list-group list-group-flush small">
                    <?php foreach ($email['unsubscribes'] as $u): ?>
                        <li class="list-group-item d-flex justify-content-between align-items-center px-0">
                            <div class="text-truncate me-2">
                                <div class="fw-semibold text-truncate"><?= esc($u['email'] ?? '') ?></div>
                                <div class="text-muted" style="font-size:0.7rem"><?= esc(($u['reason'] ?? '') !== '' ? $u['reason'] : 'User requested') ?></div>
                            </div>
                            <span class="text-muted" style="font-size:0.7rem"><?= esc(format_app_datetime($u['created_at'] ?? null, 'd M', '—')) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>
</div>

</div>
<?= view('partials/email_recipients_modal') ?>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= asset_url('assets/js/analytics.js') ?>"></script>
<script src="<?= asset_url('assets/js/email-recipients.js') ?>"></script>
<script src="<?= asset_url('assets/js/reports-tables.js') ?>"></script>
<?= $this->endSection() ?>
