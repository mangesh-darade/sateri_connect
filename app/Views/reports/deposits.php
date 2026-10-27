<?= $this->extend('layouts/main') ?>

<?= $this->section('header_actions') ?>
<?= view('reports/_export_menu', ['exportUrl' => site_url('reports/deposits/export'), 'exportQuery' => ($query ?? '')]) ?>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php
$f        = $filters ?? [];
$d        = $deposits ?? [];
$wallet   = $wallet ?? [];
$currency = (string) ($wallet['currency'] ?? \App\Libraries\Currency::base());
$symbol   = \App\Libraries\WhatsAppCountries::symbolOf((string) $currency);
$money    = static fn (float $n, int $dp = 2): string => number_format($n, $dp);
?>
<div class="page-list">

<div class="card mb-3">
    <div class="card-body py-3">
        <form method="get" action="<?= site_url('reports/deposits') ?>" class="row g-2 align-items-end">
            <div class="col-6 col-md-3"><label class="form-label small mb-1">From</label><input class="form-control form-control-sm" type="date" name="from" value="<?= esc($f['from']) ?>"></div>
            <div class="col-6 col-md-3"><label class="form-label small mb-1">To</label><input class="form-control form-control-sm" type="date" name="to" value="<?= esc($f['to']) ?>"></div>
            <div class="col-12 col-md-3 d-flex gap-2">
                <button class="btn btn-wa btn-sm" type="submit">Apply</button>
                <a class="btn btn-outline-secondary btn-sm" href="<?= site_url('reports/deposits') ?>">Reset (last 30 days)</a>
            </div>
        </form>
    </div>
</div>

<div class="row g-2 mb-3 text-center">
    <?php foreach ([
        'Total deposited'  => $symbol . $money((float) ($d['total'] ?? 0)),
        'Deposits'         => number_format((int) ($d['count'] ?? 0)),
        'Average deposit'  => $symbol . $money((float) ($d['average'] ?? 0)),
        'Largest deposit'  => $symbol . $money((float) ($d['largest'] ?? 0)),
        'Last deposit'     => ($d['last_at'] ?? '') !== '' ? (string) $d['last_at'] : '—',
        'Balance now'      => $symbol . $money((float) ($wallet['balance'] ?? 0)),
    ] as $label => $value): ?>
        <div class="col-6 col-md-2"><div class="card"><div class="card-body p-2"><div class="small text-muted"><?= esc($label) ?></div><strong><?= esc($value) ?></strong></div></div></div>
    <?php endforeach; ?>
</div>

<div class="card">
    <div class="card-header"><h2 class="card-title mb-0">Deposits</h2></div>
    <div class="card-body py-3">
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0" id="rptDepositTable" data-dt-report="deposits" data-url="<?= esc(site_url('reports/deposits/data?' . http_build_query(['from' => $f['from'], 'to' => $f['to']])), 'attr') ?>">
                <thead>
                    <tr><th>Date</th><th>Note</th><th class="text-end">Amount</th><th class="text-end">Balance after</th></tr>
                </thead>
            </table>
        </div>
        <div class="small text-muted mt-2">Deposits are added by your platform admin. Refunds for failed messages are shown in the Transaction report.</div>
    </div>
</div>

</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= asset_url('assets/js/reports-tables.js') ?>"></script>
<?= $this->endSection() ?>
