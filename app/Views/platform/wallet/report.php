<?= $this->extend('layouts/platform') ?>

<?= $this->section('header_actions') ?>
<a class="btn-pf btn-pf-primary" href="<?= site_url('platform/wallet-report/csv' . (($exportQuery ?? '') !== '' ? '?' . $exportQuery : '')) ?>"><i class="fas fa-download" aria-hidden="true"></i> Export CSV</a>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php
$f       = $filters ?? [];
$t       = $report['totals'] ?? [];
$money   = static fn (float $n, int $dp = 2): string => '₹' . number_format($n, $dp);
$native  = static fn (float $n, string $c, int $dp = 2): string => ($c === 'INR' ? '₹' : $c . ' ') . number_format($n, $dp);
$typeLabel = ['credit' => 'Recharge', 'debit' => 'Debit', 'refund' => 'Refund'];
$typeClass = ['credit' => 'is-credit', 'debit' => 'is-debit', 'refund' => 'is-refund'];
$catColor  = ['marketing' => '#e11d48', 'utility' => '#0e7490', 'authentication' => '#7c3aed', 'service' => '#16a34a'];

// Quick date ranges keep the other filters.
$keep = array_filter(['tenant' => $f['tenant'], 'type' => $f['type'], 'category' => $f['category'], 'country' => $f['country']], static fn (string $v): bool => $v !== '');
$rangeUrl = static fn (string $from, string $to): string => site_url('platform/wallet-report?' . http_build_query($keep + ['from' => $from, 'to' => $to]));
$today = date('Y-m-d');
$ranges = [
    'Today'      => [$today, $today],
    '7 days'     => [date('Y-m-d', strtotime('-6 days')), $today],
    '30 days'    => [date('Y-m-d', strtotime('-29 days')), $today],
    'This month' => [date('Y-m-01'), $today],
    'Last month' => [date('Y-m-01', strtotime('first day of last month')), date('Y-m-t', strtotime('first day of last month'))],
];

$catTotal = 0.0;
foreach (($report['categories'] ?? []) as $r) {
    $catTotal += (float) $r['amount'];
}
$clientMax = 0.0;
foreach (($report['clients'] ?? []) as $c) {
    $clientMax = max($clientMax, (float) $c['net_spent']);
}
$countryMax = 0.0;
foreach (($report['countries'] ?? []) as $r) {
    $countryMax = max($countryMax, (float) $r['amount']);
}

// Sortable headers and paging keep every active filter.
$baseQs  = array_filter($f, static fn (string $v): bool => $v !== '');
$pageUrl = static fn (array $over): string => site_url('platform/wallet-report?' . http_build_query(array_filter(array_merge($baseQs, $over), static fn ($v): bool => $v !== '' && $v !== null)));
$curSort = $f['sort'] !== '' ? $f['sort'] : 'date';
$curDir  = $f['dir'] !== '' ? $f['dir'] : (in_array($curSort, ['date', 'amount', 'balance'], true) ? 'desc' : 'asc');
$th = static function (string $col, string $label, string $cls = '') use ($curSort, $curDir, $pageUrl): string {
    $on   = $curSort === $col;
    $next = $on && $curDir === 'asc' ? 'desc' : 'asc';
    $icon = $on ? ($curDir === 'asc' ? 'fa-arrow-up' : 'fa-arrow-down') : 'fa-sort';

    return '<th class="' . $cls . ($on ? ' is-sorted' : '') . '"><a class="pfw-sort" href="' . esc($pageUrl(['sort' => $col, 'dir' => $next, 'page' => '']), 'attr') . '">'
        . esc($label) . ' <i class="fas ' . $icon . '" aria-hidden="true"></i></a></th>';
};
$cCur = $f['csort'] !== '' ? $f['csort'] : 'net_spent';
$cDir = $f['cdir'] !== '' ? $f['cdir'] : ($cCur === 'name' ? 'asc' : 'desc');
$cth = static function (string $col, string $label, string $cls = '') use ($cCur, $cDir, $pageUrl): string {
    $on   = $cCur === $col;
    $next = $on && $cDir === 'asc' ? 'desc' : 'asc';
    $icon = $on ? ($cDir === 'asc' ? 'fa-arrow-up' : 'fa-arrow-down') : 'fa-sort';

    return '<th class="' . $cls . ($on ? ' is-sorted' : '') . '"><a class="pfw-sort" href="' . esc($pageUrl(['csort' => $col, 'cdir' => $next]), 'attr') . '">'
        . esc($label) . ' <i class="fas ' . $icon . '" aria-hidden="true"></i></a></th>';
};
$page  = (int) ($report['page'] ?? 1);
$pages = (int) ($report['pages'] ?? 1);
$per   = (int) ($report['per'] ?? 25);
$total = (int) ($report['total_rows'] ?? 0);
$first = $total === 0 ? 0 : ($page - 1) * $per + 1;
$last  = min($total, $page * $per);
$window = array_values(array_unique(array_filter([1, $page - 2, $page - 1, $page, $page + 1, $page + 2, $pages], static fn (int $n): bool => $n >= 1 && $n <= $pages)));
sort($window);
?>

<section class="platform-card pfw">
    <div class="platform-card-head">
        <div>
            <h2>Wallet report</h2>
            <p><?= esc(date('d M Y', strtotime($f['from']))) ?> – <?= esc(date('d M Y', strtotime($f['to']))) ?></p>
        </div>
        <div class="pfw-ranges" role="group" aria-label="Quick date range">
            <?php foreach ($ranges as $label => [$from, $to]): ?>
                <a class="pfc-chip<?= $f['from'] === $from && $f['to'] === $to ? ' is-on' : '' ?>" href="<?= esc($rangeUrl($from, $to)) ?>"><?= esc($label) ?></a>
            <?php endforeach; ?>
        </div>
    </div>

    <form method="get" action="<?= site_url('platform/wallet-report') ?>" class="pfw-filters">
        <div><label for="fFrom">From</label><input class="platform-input" id="fFrom" type="date" name="from" value="<?= esc($f['from']) ?>"></div>
        <div><label for="fTo">To</label><input class="platform-input" id="fTo" type="date" name="to" value="<?= esc($f['to']) ?>"></div>
        <div>
            <label for="fClient">Client</label>
            <select class="platform-input" id="fClient" name="tenant">
                <option value="">All clients</option>
                <?php foreach (($tenants ?? []) as $tn): ?>
                    <option value="<?= esc((string) $tn['key']) ?>" <?= $f['tenant'] === $tn['key'] ? 'selected' : '' ?>><?= esc((string) ($tn['name'] ?? $tn['key'])) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label for="fType">Type</label>
            <select class="platform-input" id="fType" name="type">
                <option value="">All types</option>
                <?php foreach ($typeLabel as $k => $lbl): ?><option value="<?= $k ?>" <?= $f['type'] === $k ? 'selected' : '' ?>><?= $lbl ?></option><?php endforeach; ?>
            </select>
        </div>
        <div>
            <label for="fCat">Category</label>
            <select class="platform-input" id="fCat" name="category">
                <option value="">All categories</option>
                <?php foreach (['marketing', 'utility', 'authentication', 'service', 'manual'] as $k): ?><option value="<?= $k ?>" <?= $f['category'] === $k ? 'selected' : '' ?>><?= ucfirst($k) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div>
            <label for="fCountry">Country</label>
            <select class="platform-input" id="fCountry" name="country">
                <option value="">All countries</option>
                <?php foreach (\App\Libraries\WhatsAppCountries::all() as $code => $cname): ?>
                    <option value="<?= esc((string) $code) ?>" <?= $f['country'] === (string) $code ? 'selected' : '' ?>><?= esc($cname) ?> (+<?= esc((string) $code) ?>)</option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="pfw-filters-actions">
            <button type="submit" class="btn-pf btn-pf-primary"><i class="fas fa-filter" aria-hidden="true"></i> Apply</button>
            <a class="btn-pf" href="<?= site_url('platform/wallet-report') ?>">Reset</a>
        </div>
    </form>

    <?php if (! empty($report['mixed'])): ?>
        <div class="pfw-note">
            <i class="fas fa-circle-info" aria-hidden="true"></i>
            <span>Clients have different wallet currencies, so totals are converted to INR at the current exchange rates.
                <?php if (! empty($report['unconverted'])): ?><strong> No rate for <?= esc(implode(', ', $report['unconverted'])) ?>; those clients are added as they are.</strong> <a href="<?= site_url('platform/wa-rates') ?>">Set exchange rates</a><?php endif; ?>
            </span>
        </div>
    <?php endif; ?>

    <div class="pfw-hero">
        <div class="pfw-stat is-main">
            <span><i class="fas fa-receipt" aria-hidden="true"></i> Net spent</span>
            <strong><?= $money((float) $t['net_spent']) ?></strong>
            <em>Spent <?= $money((float) $t['spent']) ?> − refunded <?= $money((float) $t['refunded']) ?></em>
        </div>
        <div class="pfw-stat">
            <span><i class="fas fa-circle-arrow-down" aria-hidden="true"></i> Recharged</span>
            <strong class="is-pos"><?= $money((float) $t['recharged']) ?></strong>
            <em>Money added to wallets</em>
        </div>
        <div class="pfw-stat">
            <span><i class="fas fa-paper-plane" aria-hidden="true"></i> Paid messages</span>
            <strong><?= number_format((int) $t['messages']) ?></strong>
            <em><?= number_format((int) $t['free']) ?> free service · <?= number_format((int) $t['sent']) ?> sent · <?= number_format((int) $t['failed']) ?> failed</em>
        </div>
        <div class="pfw-stat">
            <span><i class="fas fa-wallet" aria-hidden="true"></i> Balance now</span>
            <strong><?= $money((float) $t['balance']) ?></strong>
            <em>Across <?= number_format(count($report['clients'] ?? [])) ?> client<?= count($report['clients'] ?? []) === 1 ? '' : 's' ?></em>
        </div>
    </div>

    <div class="pfw-sub pfw-margin">
        <div><span>Billed to clients (ex-GST)</span><strong><?= $money((float) $t['billed']) ?></strong></div>
        <div><span>Meta cost</span><strong><?= $money((float) $t['cost']) ?></strong></div>
        <div><span>Platform margin</span><strong class="<?= (float) $t['margin'] >= 0 ? 'is-pos' : 'pfw-bad' ?>"><?= $money((float) $t['margin']) ?><?= (float) $t['billed'] > 0 ? ' <small>' . number_format(((float) $t['margin'] / (float) $t['billed']) * 100, 1) . '%</small>' : '' ?></strong></div>
        <div><span>GST collected</span><strong><?= $money((float) $t['tax']) ?></strong></div>
    </div>

    <div class="pfw-sub">
        <div><span>Spent on messages</span><strong><?= $money((float) $t['spent']) ?></strong></div>
        <div><span>Refunded</span><strong><?= $money((float) $t['refunded']) ?></strong></div>
        <div><span>Manual deductions</span><strong><?= $money((float) $t['deducted']) ?></strong></div>
    </div>
</section>

<section class="pfw-grid">
    <div class="platform-card">
        <h3 class="platform-section-title">Spend by category</h3>
        <?php if ($catTotal > 0): ?>
            <div class="pfw-stack" role="img" aria-label="Spend split by category">
                <?php foreach (($report['categories'] ?? []) as $r): ?>
                    <?php $pct = $catTotal > 0 ? ((float) $r['amount'] / $catTotal) * 100 : 0; ?>
                    <span style="width:<?= number_format($pct, 2, '.', '') ?>%;background:<?= $catColor[$r['category']] ?? '#64748b' ?>" title="<?= esc(ucfirst((string) $r['category'])) ?> <?= number_format($pct, 1) ?>%"></span>
                <?php endforeach; ?>
            </div>
            <ul class="pfw-legend">
                <?php foreach (($report['categories'] ?? []) as $r): ?>
                    <?php $pct = $catTotal > 0 ? ((float) $r['amount'] / $catTotal) * 100 : 0; ?>
                    <li>
                        <i style="background:<?= $catColor[$r['category']] ?? '#64748b' ?>"></i>
                        <span class="pfw-legend-name"><?= esc(ucfirst((string) $r['category'])) ?></span>
                        <span class="pfw-legend-n"><?= number_format($r['messages']) ?> msgs</span>
                        <strong><?= $money($r['amount']) ?></strong>
                        <small><?= number_format($pct, 1) ?>%</small>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php else: ?>
            <div class="pfw-empty"><i class="fas fa-chart-pie" aria-hidden="true"></i><p>No paid messages in this period.</p></div>
        <?php endif; ?>
    </div>

    <div class="platform-card">
        <h3 class="platform-section-title">Spend by country</h3>
        <?php if (($report['countries'] ?? []) !== []): ?>
            <ul class="pfw-bars">
                <?php foreach (array_slice($report['countries'], 0, 8) as $r): ?>
                    <?php
                    $cn  = \App\Libraries\WhatsAppCountries::nameOf((string) $r['country_code']);
                    $pct = $countryMax > 0 ? ((float) $r['amount'] / $countryMax) * 100 : 0;
                    ?>
                    <li>
                        <div class="pfw-bars-row"><span><?= $r['country_code'] === '' ? 'Unknown' : esc($cn !== '' ? $cn : '+' . $r['country_code']) ?><?= $r['country_code'] !== '' ? ' <small>+' . esc((string) $r['country_code']) . '</small>' : '' ?></span><strong><?= $money($r['amount']) ?></strong></div>
                        <div class="pfw-bar"><i style="width:<?= number_format($pct, 2, '.', '') ?>%"></i></div>
                        <small><?= number_format($r['messages']) ?> messages</small>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php else: ?>
            <div class="pfw-empty"><i class="fas fa-earth-asia" aria-hidden="true"></i><p>No paid messages in this period.</p></div>
        <?php endif; ?>
    </div>
</section>

<section class="platform-card">
    <div class="platform-card-head">
        <div><h3 class="platform-section-title" style="margin:0">By client</h3><p>Search, sort and page through every client.</p></div>
    </div>
    <div class="platform-table-wrap">
        <table class="platform-table pfw-table" id="pfwClients" style="width:100%">
            <thead><tr>
                <th>Client</th><th class="num">Balance</th><th class="num">Recharged</th><th class="num">Net spent</th><th class="num">Margin</th>
                <th class="pfw-share-col">Share of spend</th>
                <th class="num">Paid msgs</th><th class="num">Sent</th><th class="num">Delivered</th><th class="num">Failed</th><th class="num">Replies</th>
            </tr></thead>
            <tbody></tbody>
        </table>
    </div>
</section>

<section class="platform-card">
    <div class="platform-card-head">
        <div>
            <h3 class="platform-section-title" style="margin:0">Transactions</h3>
            <p>Search, sort and page through every transaction in the selected period.</p>
        </div>
    </div>
    <div class="platform-table-wrap">
        <table class="platform-table pfw-table pfw-grid-table" id="pfwTx" style="width:100%">
            <thead><tr>
                <th>Date</th><th>Client</th><th>Type</th><th>Category</th><th>Country</th><th class="num">Amount</th><th class="num">Balance after</th><th>Details</th>
            </tr></thead>
            <tbody></tbody>
        </table>
    </div>

    <?php if ($total > \App\Libraries\WalletReportService::EXPORT_LIMIT): ?>
        <div class="platform-help" style="margin-top:.6rem">Export covers the latest <?= number_format(\App\Libraries\WalletReportService::EXPORT_LIMIT) ?> rows.</div>
    <?php endif; ?>
</section>
<?= $this->endSection() ?>


<?= $this->section('scripts') ?>
<?= view('partials/platform_dt_assets') ?>
<script>
(function () {
    var qs = <?= json_encode((string) ($query ?? '')) ?>;
    var base = <?= json_encode(site_url('platform/wallet-report')) ?>;
    var typeLabel = { credit: 'Recharge', debit: 'Debit', refund: 'Refund' };
    var typeClass = { credit: 'is-credit', debit: 'is-debit', refund: 'is-refund' };
    var catColor = { marketing: '#e11d48', utility: '#0e7490', authentication: '#7c3aed', service: '#16a34a' };
    var muted = '<span class="pfw-muted">&mdash;</span>';
    function url(path) {
        var keep = qs.split('&').filter(function (p) { return p && !/^(page|per|sort|dir|csort|cdir)=/.test(p); }).join('&');
        return base + '/' + path + (keep ? '?' + keep : '');
    }

    AppDT.table('#pfwClients', {
        url: url('clients'),
        order: [[3, 'desc']],
        emptyText: 'No wallet data yet. A client wallet is created the first time it is opened.',
        columns: [
            { data: null, render: function (r) {
                return '<a class="pfw-link" href="' + AppDT.esc(r.url) + '">' + AppDT.esc(r.name) + '</a>' +
                    (r.enabled ? '' : ' <span class="platform-badge platform-badge-warn">Wallet off</span>') +
                    '<div class="platform-client-meta">' + AppDT.esc(r.currency) + '</div>';
            } },
            { data: null, className: 'num', render: function (r) { return AppDT.esc(r.balance); } },
            { data: null, className: 'num', render: function (r) { return AppDT.esc(r.recharged); } },
            { data: null, className: 'num', render: function (r) { return '<strong>' + AppDT.esc(r.net_spent) + '</strong>'; } },
            { data: null, className: 'num', render: function (r) { return '<span class="' + (r.margin_pos ? 'is-pos' : 'pfw-muted') + '">' + AppDT.esc(r.margin) + '</span>'; } },
            { data: null, orderable: false, className: 'pfw-share-col', render: function (r) {
                return '<div class="pfw-bar"><i style="width:' + (parseFloat(r.share) || 0) + '%"></i></div>';
            } },
            { data: null, className: 'num', render: function (r) { return AppDT.esc(r.messages); } },
            { data: null, className: 'num', render: function (r) { return AppDT.esc(r.sent); } },
            { data: null, className: 'num', render: function (r) {
                return AppDT.esc(r.delivered) + (r.delivered_pct ? '<div class="platform-client-meta">' + AppDT.esc(r.delivered_pct) + '</div>' : '');
            } },
            { data: null, className: 'num', render: function (r) { return '<span class="' + (r.failed_bad ? 'pfw-bad' : 'pfw-muted') + '">' + AppDT.esc(r.failed) + '</span>'; } },
            { data: null, className: 'num', render: function (r) { return AppDT.esc(r.replies); } }
        ]
    });

    AppDT.table('#pfwTx', {
        url: url('transactions'),
        order: [[0, 'desc']],
        emptyText: 'No transactions match these filters.',
        columns: [
            { data: null, className: 'pfw-nowrap', render: function (r) { return AppDT.esc(r.date) + '<div class="platform-client-meta">' + AppDT.esc(r.time) + '</div>'; } },
            { data: null, render: function (r) { return AppDT.esc(r.client); } },
            { data: null, render: function (r) {
                return '<span class="pfw-type ' + AppDT.esc(typeClass[r.type] || '') + '">' + AppDT.esc(typeLabel[r.type] || r.type) + '</span>';
            } },
            { data: null, render: function (r) {
                if (!r.category) return muted;
                var c = catColor[r.category] || '#94a3b8';
                return '<span class="pfw-cat"><i style="background:' + AppDT.esc(c) + '"></i>' + AppDT.esc(r.category.charAt(0).toUpperCase() + r.category.slice(1)) + '</span>';
            } },
            { data: null, render: function (r) { return r.country ? AppDT.esc(r.country) : muted; } },
            { data: null, className: 'num', render: function (r) {
                return '<span class="pfw-amt ' + (r.incoming ? 'is-in' : 'is-out') + '">' + (r.incoming ? '+' : '−') + AppDT.esc(r.amount) + '</span>';
            } },
            { data: null, className: 'num', render: function (r) { return AppDT.esc(r.balance); } },
            { data: null, orderable: false, className: 'pfw-details', render: function (r) { return AppDT.esc(r.details); } }
        ]
    });
})();
</script>
<?= $this->endSection() ?>
