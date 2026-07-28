<?= $this->extend('layouts/main') ?>

<?= $this->section('header_actions') ?>
<a href="<?= site_url('campaigns') ?>" class="btn btn-outline-secondary btn-sm"><i class="fas fa-arrow-left me-1"></i> Back</a>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php
$campaign = $campaign ?? [];
$id = (int) ($campaign['id'] ?? 0);
$total = max(1, (int) ($campaign['total_contacts'] ?? 1));
$sent = (int) ($campaign['sent_count'] ?? 0);
$pct = min(100, round(($sent / $total) * 100));
?>
<div class="row g-3 mb-3">
    <div class="col-lg-8">
        <div class="dash-panel">
            <div class="panel-head">
                <h3><?= esc($campaign['name'] ?? 'Campaign') ?></h3>
                <?= view('partials/status_badge', ['status' => $campaign['status'] ?? 'draft']) ?>
            </div>
            <div class="panel-body">
                <div class="row g-2 mb-3 small text-muted">
                    <div class="col-md-4"><strong class="text-dark">Template</strong><br><?= esc($template['name'] ?? $campaign['template_name'] ?? $campaign['template_id'] ?? '—') ?></div>
                    <div class="col-md-4"><strong class="text-dark">Scheduled</strong><br><?= esc($campaign['scheduled_at'] ?? '—') ?></div>
                    <div class="col-md-4"><strong class="text-dark">Started / Done</strong><br><?= esc($campaign['started_at'] ?? '—') ?> / <?= esc($campaign['completed_at'] ?? '—') ?></div>
                </div>
                <label class="form-label small text-muted mb-1">Progress · <?= esc((string) $sent) ?> / <?= esc((string) ($campaign['total_contacts'] ?? 0)) ?></label>
                <div class="progress mb-3" style="height:10px;border-radius:999px;background:var(--wa-mist)">
                    <div class="progress-bar" style="width:<?= $pct ?>%;background:linear-gradient(90deg,#128C7E,#25D366);border-radius:999px"></div>
                </div>
                <div class="row g-2 text-center">
                    <?php
                    $metrics = [
                        ['Sent', $campaign['sent_count'] ?? 0, '#128C7E'],
                        ['Delivered', $campaign['delivered_count'] ?? 0, '#25D366'],
                        ['Read', $campaign['read_count'] ?? 0, '#34B7F1'],
                        ['Failed', $campaign['failed_count'] ?? 0, '#e25555'],
                        ['Replies', $campaign['reply_count'] ?? 0, '#f0a202'],
                    ];
                    foreach ($metrics as [$label, $val, $color]):
                    ?>
                    <div class="col">
                        <div class="fw-bold" style="font-family:var(--font-display);color:<?= $color ?>"><?= esc((string) $val) ?></div>
                        <small class="text-muted"><?= esc($label) ?></small>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="panel-body border-top pt-3 d-flex flex-wrap gap-2" style="border-color:var(--border)!important">
                <?php if (function_exists('can') && can('campaigns.start')): ?>
                    <?php if (in_array($campaign['status'] ?? '', ['draft', 'scheduled', 'paused'], true)): ?>
                        <form action="<?= site_url('campaigns/' . $id . '/send-now') ?>" method="post" class="d-inline"><?= csrf_field() ?>
                            <button class="btn btn-wa btn-sm" data-confirm="Start sending now?">Send now</button>
                        </form>
                    <?php endif; ?>
                    <?php if (($campaign['status'] ?? '') === 'running'): ?>
                        <form action="<?= site_url('campaigns/' . $id . '/pause') ?>" method="post" class="d-inline"><?= csrf_field() ?>
                            <button class="btn btn-outline-secondary btn-sm">Pause</button>
                        </form>
                    <?php endif; ?>
                    <?php if (($campaign['status'] ?? '') === 'paused'): ?>
                        <form action="<?= site_url('campaigns/' . $id . '/resume') ?>" method="post" class="d-inline"><?= csrf_field() ?>
                            <button class="btn btn-wa btn-sm">Resume</button>
                        </form>
                    <?php endif; ?>
                    <?php if (in_array($campaign['status'] ?? '', ['running', 'paused', 'scheduled'], true)): ?>
                        <form action="<?= site_url('campaigns/' . $id . '/cancel') ?>" method="post" class="d-inline"><?= csrf_field() ?>
                            <button class="btn btn-outline-danger btn-sm" data-confirm="Cancel this campaign?">Cancel</button>
                        </form>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="dash-panel">
            <div class="panel-head"><h3>Delivery mix</h3></div>
            <div class="panel-body">
                <canvas id="campaignAnalyticsChart" height="220"
                    data-labels='<?= json_encode(['Sent','Delivered','Read','Failed','Replies']) ?>'
                    data-values='<?= json_encode([
                        (int) ($campaign['sent_count'] ?? 0),
                        (int) ($campaign['delivered_count'] ?? 0),
                        (int) ($campaign['read_count'] ?? 0),
                        (int) ($campaign['failed_count'] ?? 0),
                        (int) ($campaign['reply_count'] ?? 0),
                    ]) ?>'></canvas>
            </div>
        </div>
    </div>
</div>

<div class="dash-panel">
    <div class="panel-head"><h3>Recipients</h3></div>
    <div class="panel-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0" id="campaignContactsTable">
                <thead>
                    <tr><th>Contact</th><th>Mobile</th><th>Status</th><th>Sent at</th><th>Error</th></tr>
                </thead>
                <tbody>
                    <?php foreach (($recipients ?? $campaign_contacts ?? []) as $r): ?>
                        <tr>
                            <td><?= esc($r['name'] ?? '') ?></td>
                            <td><?= esc($r['mobile'] ?? '') ?></td>
                            <td><?= view('partials/status_badge', ['status' => $r['status'] ?? '']) ?></td>
                            <td class="text-muted small"><?= esc($r['sent_at'] ?? $r['processed_at'] ?? '—') ?></td>
                            <td class="small text-danger"><?= esc($r['error_message'] ?? '') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
$(function () {
    var canvas = document.getElementById('campaignAnalyticsChart');
    if (canvas && window.Chart) {
        var labels = JSON.parse(canvas.getAttribute('data-labels') || '[]');
        var values = JSON.parse(canvas.getAttribute('data-values') || '[]');
        new Chart(canvas.getContext('2d'), {
            type: 'doughnut',
            data: {
                labels: labels,
                datasets: [{
                    data: values,
                    backgroundColor: ['#128C7E','#25D366','#34B7F1','#e25555','#f0a202'],
                    borderWidth: 0
                }]
            },
            options: { plugins: { legend: { position: 'bottom' } } }
        });
    }
    if ($.fn.DataTable) { $('#campaignContactsTable').DataTable({ pageLength: 25 }); }
});
</script>
<?= $this->endSection() ?>
