<?= $this->extend('layouts/main') ?>

<?= $this->section('header_actions') ?>
<a href="<?= site_url('reports') ?>" class="btn btn-outline-secondary btn-sm">
    <i class="fas fa-arrow-left me-1"></i> All reports
</a>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<div class="page-list">
<div class="card mb-2">
    <div class="card-body py-3">
        <form method="get" class="filter-bar">
            <input type="date" name="from" class="form-control form-control-sm" style="max-width:150px" value="<?= esc($from ?? '') ?>" title="From">
            <input type="date" name="to" class="form-control form-control-sm" style="max-width:150px" value="<?= esc($to ?? '') ?>" title="To">
            <button type="submit" class="btn btn-wa btn-sm"><i class="fas fa-filter me-1"></i> Apply</button>
        </form>
    </div>
</div>

<?php $stats = $stats ?? []; ?>
<div class="row g-2 mb-2">
    <?php
    $cards = [
        'sent' => ['Sent', 'kpi-accent-teal'],
        'delivered' => ['Delivered', 'kpi-accent-green'],
        'read' => ['Read', 'kpi-accent-sky'],
        'failed' => ['Failed', 'kpi-accent-danger'],
        'replies' => ['Replies', 'kpi-accent-amber'],
    ];
    foreach ($cards as $key => [$label, $accent]):
    ?>
    <div class="col">
        <div class="kpi-card <?= $accent ?>">
            <span class="kpi-label"><?= esc($label) ?></span>
            <span class="kpi-value"><?= (int) ($stats[$key] ?? 0) ?></span>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<div class="dash-panel">
    <div class="panel-head"><h3>Daily delivery</h3></div>
    <div class="panel-body" style="height:320px">
        <canvas id="deliveryChart"
                data-labels='<?= esc(json_encode(array_column($daily ?? [], 'date')), 'attr') ?>'
                data-sent='<?= esc(json_encode(array_column($daily ?? [], 'sent')), 'attr') ?>'
                data-delivered='<?= esc(json_encode(array_column($daily ?? [], 'delivered')), 'attr') ?>'></canvas>
    </div>
</div>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
$(function () {
    var canvas = document.getElementById('deliveryChart');
    if (!canvas || typeof Chart === 'undefined') return;
    var labels = JSON.parse(canvas.getAttribute('data-labels') || '[]');
    var sent = JSON.parse(canvas.getAttribute('data-sent') || '[]');
    var delivered = JSON.parse(canvas.getAttribute('data-delivered') || '[]');
    new Chart(canvas, {
        type: 'line',
        data: {
            labels: labels,
            datasets: [
                { label: 'Sent', data: sent, borderColor: '#4b3786', tension: 0.3 },
                { label: 'Delivered', data: delivered, borderColor: '#8e53f7', tension: 0.3 }
            ]
        },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom' } } }
    });
});
</script>
<?= $this->endSection() ?>
