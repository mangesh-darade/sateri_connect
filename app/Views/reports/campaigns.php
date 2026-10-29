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
$fn     = $funnel ?? ['total' => 0, 'sent' => 0, 'delivered' => 0, 'read' => 0];
$fTotal = (int) $fn['total'];
$fPct   = static fn (int $n): float => $fTotal > 0 ? round(($n / $fTotal) * 100, 1) : 0.0;
$canRetarget = function_exists('can') && can('campaigns.create');
$fStages = [
    ['label' => 'Total', 'count' => $fTotal, 'pct' => $fPct($fTotal), 'segments' => []],
    ['label' => 'Sent', 'count' => (int) $fn['sent'], 'pct' => $fPct((int) $fn['sent']),
        'segments' => ['sent' => 'WhatsApp contacts sent', 'unsent' => 'WhatsApp contacts not sent']],
    ['label' => 'Delivered', 'count' => (int) $fn['delivered'], 'pct' => $fPct((int) $fn['delivered']),
        'segments' => ['delivered' => 'WhatsApp contacts delivered', 'undelivered' => 'WhatsApp contacts undelivered']],
    ['label' => 'Opened', 'count' => (int) $fn['read'], 'pct' => $fPct((int) $fn['read']),
        'segments' => ['read' => 'WhatsApp contacts who opened', 'unread' => 'WhatsApp contacts who did not open']],
    ['label' => 'Clicks', 'count' => null, 'pct' => null, 'segments' => []],
];
// Funnel shape: stage i to stage i+1 is a polygon whose height follows the share of total; Clicks is a thin line.
$fW = 1000; $fH = 240; $fMid = $fH / 2;
$fHeights = array_map(static fn (array $st): float => max(1.0, ($st['pct'] ?? 0.0) / 100 * $fH), $fStages);
$fColors  = ['var(--brand-800)', 'var(--brand-600)', 'var(--brand-500)', 'var(--brand-400)', 'var(--brand-400)'];
$fPolys   = [];
$colW     = $fW / count($fStages);
foreach ($fStages as $i => $st) {
    $x0 = $i * $colW;
    $x1 = ($i + 1) * $colW;
    $h0 = $fHeights[$i];
    $h1 = $fHeights[$i + 1] ?? $h0;
    $pt = static fn (float $x, float $y): string => round($x, 1) . ',' . round($y, 1);
    $fPolys[] = [implode(' ', [$pt($x0, $fMid - $h0 / 2), $pt($x1, $fMid - $h1 / 2), $pt($x1, $fMid + $h1 / 2), $pt($x0, $fMid + $h0 / 2)]), $fColors[$i]];
}
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
    <div class="col-12">
        <div class="rpt-panel">
            <h2>Campaign performance funnel</h2>
            <?php if ($fTotal > 0): ?>
                <div class="rpt-fn" id="rptFunnel"
                     data-url="<?= esc(site_url('reports/campaigns/retarget'), 'attr') ?>"
                     data-from="<?= esc($from, 'attr') ?>" data-to="<?= esc($to, 'attr') ?>"
                     data-campaign="<?= (int) ($campaignId ?? 0) ?>">
                    <div class="rpt-fn-stats">
                        <?php foreach ($fStages as $st): ?>
                            <div class="rpt-fn-stat">
                                <div class="rpt-fn-head">
                                    <span class="rpt-fn-label"><?= esc($st['label']) ?></span>
                                    <span class="rpt-fn-count"><?= $st['count'] === null ? '—' : number_format($st['count']) ?></span>
                                    <?php if ($canRetarget): ?>
                                        <div class="dropdown">
                                            <button type="button" class="rpt-fn-dots" data-bs-toggle="dropdown" data-bs-boundary="viewport" aria-label="Retarget <?= esc($st['label'], 'attr') ?>"><i class="fas fa-ellipsis-v"></i></button>
                                            <div class="dropdown-menu dropdown-menu-end rpt-fn-menu">
                                                <div class="rpt-fn-menu-title"><i class="fas fa-bullhorn me-1"></i>Choose who to retarget</div>
                                                <?php if ($st['segments'] === []): ?>
                                                    <span class="dropdown-item disabled">Not tracked for WhatsApp</span>
                                                <?php endif; ?>
                                                <?php foreach ($st['segments'] as $seg => $text): ?>
                                                    <button type="button" class="dropdown-item js-retarget" data-segment="<?= esc($seg, 'attr') ?>"><?= esc($text) ?></button>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <strong class="rpt-fn-pct"><?= $st['pct'] === null ? '—' : esc((string) $st['pct']) . '%' ?></strong>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <svg class="rpt-fn-svg" viewBox="0 0 <?= $fW ?> <?= $fH ?>" preserveAspectRatio="none" role="img" aria-label="Campaign performance funnel">
                        <?php foreach ($fPolys as [$points, $fill]): ?>
                            <polygon points="<?= esc($points, 'attr') ?>" style="fill:<?= $fill ?>"/>
                        <?php endforeach; ?>
                    </svg>
                </div>
                <p class="rpt-note mt-3 mb-0">Each stage is shown as a share of total recipients. Click tracking is not available for WhatsApp.</p>
            <?php else: ?>
                <div class="rpt-empty"><i class="fas fa-filter"></i>No campaign messages sent in this period.</div>
            <?php endif; ?>
        </div>
    </div>
    <div class="col-12">
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
<script src="<?= asset_url('assets/js/campaign-retarget.js') ?>"></script>
<?= $this->endSection() ?>
