<?= $this->extend('layouts/main') ?>

<?= $this->section('header_actions') ?>
<?= view('reports/_export_menu', ['exportUrl' => site_url('reports/templates/export'), 'exportQuery' => http_build_query(['from' => $from, 'to' => $to])]) ?>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php
$sum  = $totals ?? ['templates' => 0, 'campaigns' => 0, 'sent' => 0, 'delivered' => 0, 'read' => 0, 'failed' => 0];
$dtQs = http_build_query(['from' => (string) $from, 'to' => (string) $to]);
?>
<div class="page-list">

<div class="card mb-3">
    <div class="card-body py-3">
        <form method="get" action="<?= site_url('reports/templates') ?>" class="row g-2 align-items-end">
            <div class="col-6 col-md-3"><label class="form-label small mb-1">From</label><input class="form-control form-control-sm" type="date" name="from" value="<?= esc((string) $from) ?>"></div>
            <div class="col-6 col-md-3"><label class="form-label small mb-1">To</label><input class="form-control form-control-sm" type="date" name="to" value="<?= esc((string) $to) ?>"></div>
            <div class="col-12 col-md-3 d-flex gap-2">
                <button class="btn btn-wa btn-sm" type="submit">Apply</button>
                <a class="btn btn-outline-secondary btn-sm" href="<?= site_url('reports/templates') ?>">This month</a>
            </div>
        </form>
    </div>
</div>

<div class="row g-2 mb-3 text-center">
    <?php foreach ([
        'Templates used' => $sum['templates'],
        'Campaigns'      => $sum['campaigns'],
        'Sent'           => $sum['sent'],
        'Delivered'      => $sum['delivered'],
        'Read'           => $sum['read'],
        'Failed'         => $sum['failed'],
    ] as $label => $value): ?>
        <div class="col-6 col-md-2"><div class="card"><div class="card-body p-2"><div class="small text-muted"><?= esc($label) ?></div><strong><?= number_format((int) $value) ?></strong></div></div></div>
    <?php endforeach; ?>
</div>

<div class="card">
    <div class="card-header"><h2 class="card-title mb-0">Results by WhatsApp template</h2></div>
    <div class="card-body py-3">
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0" id="rptTemplateTable" data-dt-report="templates" data-url="<?= esc(site_url('reports/templates/data?' . $dtQs), 'attr') ?>">
                <thead>
                    <tr>
                        <th>Template</th><th>Category</th><th>Status</th>
                        <th class="text-end">Campaigns</th><th class="text-end">Sent</th><th class="text-end">Delivered</th>
                        <th class="text-end">Read</th><th class="text-end">Failed</th>
                        <th class="text-end">Delivery %</th><th class="text-end">Read %</th>
                    </tr>
                </thead>
            </table>
        </div>
        <div class="small text-muted mt-2">Counts template messages sent through broadcasts. Template messages sent by workflows or one by one are not included.</div>
    </div>
</div>

</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= asset_url('assets/js/reports-tables.js') ?>"></script>
<?= $this->endSection() ?>
