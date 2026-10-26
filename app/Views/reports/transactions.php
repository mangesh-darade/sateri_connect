<?= $this->extend('layouts/main') ?>

<?= $this->section('header_actions') ?>
<?= view('reports/_export_menu', ['exportUrl' => site_url('reports/transactions/export'), 'exportQuery' => ($query ?? '')]) ?>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php
$wallet   = $wallet ?? [];
$f        = $filters ?? [];
$t        = $report['totals'] ?? [];
$currency = (string) ($wallet['currency'] ?? 'INR');
$symbol   = $currency === 'INR' ? '₹' : $currency . ' ';
$money    = static fn (float $n, int $dp = 2): string => number_format($n, $dp);
$typeLabel = ['credit' => 'Recharge', 'debit' => 'Message / deduction', 'refund' => 'Refund'];
?>
<div class="page-list">

<div class="card mb-3">
    <div class="card-body py-3">
        <form method="get" action="<?= site_url('reports/transactions') ?>" class="row g-2 align-items-end">
            <div class="col-6 col-md-2"><label class="form-label small mb-1">From</label><input class="form-control form-control-sm" type="date" name="from" value="<?= esc($f['from']) ?>"></div>
            <div class="col-6 col-md-2"><label class="form-label small mb-1">To</label><input class="form-control form-control-sm" type="date" name="to" value="<?= esc($f['to']) ?>"></div>
            <div class="col-6 col-md-2">
                <label class="form-label small mb-1">Type</label>
                <select class="form-select form-select-sm" name="type">
                    <option value="">All</option>
                    <?php foreach ($typeLabel as $k => $lbl): ?><option value="<?= $k ?>" <?= $f['type'] === $k ? 'selected' : '' ?>><?= esc($lbl) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small mb-1">Category</label>
                <select class="form-select form-select-sm" name="category">
                    <option value="">All</option>
                    <?php foreach (['marketing', 'utility', 'authentication', 'service', 'manual'] as $k): ?><option value="<?= $k ?>" <?= $f['category'] === $k ? 'selected' : '' ?>><?= ucfirst($k) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small mb-1">Country</label>
                <select class="form-select form-select-sm" name="country">
                    <option value="">All countries</option>
                    <?php foreach (\App\Libraries\WhatsAppCountries::all() as $code => $cname): ?>
                        <option value="<?= esc((string) $code) ?>" <?= $f['country'] === (string) $code ? 'selected' : '' ?>><?= esc($cname) ?> (+<?= esc((string) $code) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2 d-flex gap-2">
                <button class="btn btn-wa btn-sm" type="submit">Apply</button>
                <a class="btn btn-outline-secondary btn-sm" href="<?= site_url('reports/transactions') ?>">Reset</a>
            </div>
        </form>
    </div>
</div>

<div class="row g-2 mb-3 text-center">
    <?php foreach ([
        'Balance now'       => $symbol . $money((float) ($t['balance'] ?? 0)),
        'Recharged'         => $symbol . $money((float) ($t['recharged'] ?? 0)),
        'Spent'             => $symbol . $money((float) ($t['spent'] ?? 0)),
        'Refunded'          => $symbol . $money((float) ($t['refunded'] ?? 0)),
        'Net spent'         => $symbol . $money((float) ($t['net_spent'] ?? 0)),
        'Paid messages'     => number_format((int) ($t['messages'] ?? 0)),
        'Free service msgs' => number_format((int) ($t['free'] ?? 0)),
    ] as $label => $value): ?>
        <div class="col-6 col-md"><div class="card"><div class="card-body p-2"><div class="small text-muted"><?= esc($label) ?></div><strong><?= esc($value) ?></strong></div></div></div>
    <?php endforeach; ?>
</div>

<div class="row g-3 mb-3">
    <div class="col-md-6">
        <div class="card h-100">
            <div class="card-header"><h2 class="card-title mb-0">By category</h2></div>
            <div class="card-body py-3">
                <table class="table table-sm mb-0">
                    <thead><tr><th>Category</th><th class="text-end">Messages</th><th class="text-end">Amount</th></tr></thead>
                    <tbody>
                    <?php foreach (($report['categories'] ?? []) as $r): ?>
                        <tr><td><?= esc(ucfirst((string) $r['category'])) ?></td><td class="text-end"><?= number_format($r['messages']) ?></td><td class="text-end"><?= $money($r['amount'], 4) ?></td></tr>
                    <?php endforeach; ?>
                    <?php if (($report['categories'] ?? []) === []): ?><tr><td colspan="3" class="text-muted">No paid messages in this range.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card h-100">
            <div class="card-header"><h2 class="card-title mb-0">By country</h2></div>
            <div class="card-body py-3">
                <table class="table table-sm mb-0">
                    <thead><tr><th>Country</th><th class="text-end">Messages</th><th class="text-end">Amount</th></tr></thead>
                    <tbody>
                    <?php foreach (($report['countries'] ?? []) as $r): ?>
                        <?php $cn = \App\Libraries\WhatsAppCountries::nameOf((string) $r['country_code']); ?>
                        <tr><td><?= $r['country_code'] === '' ? '—' : esc($cn !== '' ? $cn . ' (+' . $r['country_code'] . ')' : '+' . $r['country_code']) ?></td><td class="text-end"><?= number_format($r['messages']) ?></td><td class="text-end"><?= $money($r['amount'], 4) ?></td></tr>
                    <?php endforeach; ?>
                    <?php if (($report['countries'] ?? []) === []): ?><tr><td colspan="3" class="text-muted">No paid messages in this range.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php
$cats = ['marketing', 'utility', 'authentication', 'service'];
$matrix = [];
foreach (($report['sources'] ?? []) as $r) {
    $matrix[$r['source']][$r['category']] = ($matrix[$r['source']][$r['category']] ?? 0.0) + $r['amount'];
    $matrix[$r['source']]['_n'] = ($matrix[$r['source']]['_n'] ?? 0) + $r['messages'];
}
?>
<div class="card mb-3">
    <div class="card-header"><h2 class="card-title mb-0">Spend by source and category</h2></div>
    <div class="card-body py-3">
        <div class="table-responsive">
        <table class="table table-sm mb-0">
            <thead><tr><th>Source</th><th class="text-end">Messages</th><?php foreach ($cats as $c): ?><th class="text-end"><?= ucfirst($c) ?></th><?php endforeach; ?><th class="text-end">Total</th></tr></thead>
            <tbody>
            <?php foreach ($matrix as $src => $m): $tot = 0.0; ?>
                <tr>
                    <td><?= esc($src) ?></td><td class="text-end"><?= number_format((int) $m['_n']) ?></td>
                    <?php foreach ($cats as $c): $v = (float) ($m[$c] ?? 0); $tot += $v; ?><td class="text-end"><?= $v > 0 ? esc($symbol) . $money($v, 4) : '—' ?></td><?php endforeach; ?>
                    <td class="text-end"><strong><?= esc($symbol) ?><?= $money($tot, 4) ?></strong></td>
                </tr>
            <?php endforeach; ?>
            <?php if ($matrix === []): ?><tr><td colspan="7" class="text-muted">No paid messages in this range.</td></tr><?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>

<div class="card mb-3">
    <div class="card-header"><h2 class="card-title mb-0">Spend by campaign</h2></div>
    <div class="card-body py-3">
        <table class="table table-sm mb-0">
            <thead><tr><th>Campaign</th><th class="text-end">Messages</th><th class="text-end">Amount spent</th></tr></thead>
            <tbody>
            <?php foreach (($report['campaigns'] ?? []) as $r): ?>
                <tr><td><?= esc($r['campaign']) ?></td><td class="text-end"><?= number_format($r['messages']) ?></td><td class="text-end"><?= esc($symbol) ?><?= $money($r['amount'], 4) ?></td></tr>
            <?php endforeach; ?>
            <?php if (($report['campaigns'] ?? []) === []): ?><tr><td colspan="3" class="text-muted">No paid messages in this range.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card">
    <div class="card-header"><h2 class="card-title mb-0">Transactions</h2></div>
    <div class="card-body py-3">
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0" id="rptTxTable" data-dt-report="transactions" data-url="<?= esc(site_url('reports/transactions/data?' . ($query ?? '')), 'attr') ?>">
                <thead>
                    <tr><th>Date</th><th>Type</th><th>Category</th><th>Source</th><th>Recipient</th><th>Campaign / Template</th><th>Msg status</th><th>Details</th><th class="text-end">Amount</th><th class="text-end">Balance</th></tr>
                </thead>
            </table>
        </div>
    </div>
</div>

</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= asset_url('assets/js/reports-tables.js') ?>"></script>
<?= $this->endSection() ?>
