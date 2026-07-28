<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>
<div class="page-list">
<div class="row g-2 mb-2" id="queueStats">
    <?php
    $qs = $stats ?? [];
    $map = [
        'pending' => ['kpi-accent-amber', 'fa-clock'],
        'processing' => ['kpi-accent-sky', 'fa-spinner'],
        'sent' => ['kpi-accent-green', 'fa-check'],
        'failed' => ['kpi-accent-danger', 'fa-times'],
        'cancelled' => ['kpi-accent-ink', 'fa-ban'],
    ];
    foreach ($map as $st => [$accent, $icon]):
    ?>
    <div class="col-6 col-md-4 col-xl">
        <div class="kpi-card <?= $accent ?>">
            <span class="kpi-icon"><i class="fas <?= $icon ?>"></i></span>
            <span class="kpi-label"><?= esc($st) ?></span>
            <span class="kpi-value" data-stat="<?= esc($st) ?>"><?= esc((string) ($qs[$st] ?? 0)) ?></span>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<div class="page-toolbar">
    <div class="toolbar-actions ms-auto">
        <button type="button" class="btn btn-sm btn-outline-secondary" id="btnRefreshQueue"><i class="fas fa-sync me-1"></i> Refresh</button>
    </div>
</div>

<div class="card">
    <div class="card-body py-3">
        <div class="filter-bar">
            <select id="queueStatusFilter" class="form-select form-select-sm" style="max-width:160px">
                <option value="">All statuses</option>
                <?php foreach (['pending','processing','sent','failed','cancelled'] as $st): ?>
                    <option value="<?= $st ?>"><?= ucfirst($st) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <table class="table table-sm table-hover align-middle w-100" id="queueTable">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Contact</th>
                    <th>Type</th>
                    <th>Status</th>
                    <th>Attempts</th>
                    <th>Scheduled</th>
                    <th>Error</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach (($items ?? $queue ?? []) as $item): ?>
                    <tr>
                        <td><?= (int) $item['id'] ?></td>
                        <td><?= esc($item['contact_name'] ?? $item['contact_id'] ?? '') ?></td>
                        <td><?= esc($item['message_type'] ?? '') ?></td>
                        <td><?= view('partials/status_badge', ['status' => $item['status'] ?? '']) ?></td>
                        <td><?= esc(($item['attempts'] ?? 0) . '/' . ($item['max_attempts'] ?? 3)) ?></td>
                        <td class="text-muted small text-nowrap"><?= esc($item['scheduled_at'] ?? '') ?></td>
                        <td class="small text-danger"><?= esc(mb_strimwidth($item['error_message'] ?? '', 0, 60, '…')) ?></td>
                        <td class="text-end">
                            <div class="table-actions justify-content-end">
                            <?php if (function_exists('can') && can('queue.manage')): ?>
                                <?php if (in_array($item['status'] ?? '', ['failed', 'cancelled'], true)): ?>
                                    <form action="<?= site_url('queue/' . (int) $item['id'] . '/retry') ?>" method="post" class="d-inline"><?= csrf_field() ?>
                                        <button class="btn btn-sm btn-outline-secondary" title="Retry"><i class="fas fa-redo"></i></button>
                                    </form>
                                <?php endif; ?>
                                <?php if (in_array($item['status'] ?? '', ['pending', 'processing'], true)): ?>
                                    <form action="<?= site_url('queue/' . (int) $item['id'] . '/cancel') ?>" method="post" class="d-inline"><?= csrf_field() ?>
                                        <button class="btn btn-sm btn-outline-danger" data-confirm="Cancel this queue item?" title="Cancel"><i class="fas fa-ban"></i></button>
                                    </form>
                                <?php endif; ?>
                            <?php endif; ?>
                            </div>
                        </td>
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
    var table = null;
    if ($.fn.DataTable) {
        table = $('#queueTable').DataTable({
            order: [[0, 'desc']],
            pageLength: 25
        });
        $('#queueStatusFilter').on('change', function () {
            table.column(3).search(this.value).draw();
        });
    }
    function refreshStats() {
        APP.get(APP.baseUrl + '/queue/stats').done(function (res) {
            var data = res.data || res.stats || res;
            Object.keys(data || {}).forEach(function (k) {
                $('[data-stat="' + k + '"]').text(data[k]);
            });
        });
    }
    $('#btnRefreshQueue').on('click', function () {
        refreshStats();
        window.location.reload();
    });
    setInterval(refreshStats, 15000);
});
</script>
<?= $this->endSection() ?>
