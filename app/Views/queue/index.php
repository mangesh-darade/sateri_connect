<?= $this->extend('layouts/main') ?>

<?= $this->section('header_actions') ?>
<div class="d-flex align-items-center gap-2">
    <?php if (function_exists('can') && can('queue.manage')): ?>
        <button type="button" class="btn btn-sm btn-wa" id="btnProcessQueue" title="Process pending queue items now">
            <i class="fas fa-play me-1"></i> Process Queue
        </button>
        <?php if (!empty($stats['failed'])): ?>
            <button type="button" class="btn btn-sm btn-outline-warning" id="btnRetryAllFailed" title="Retry all failed messages">
                <i class="fas fa-redo me-1"></i> Retry Failed (<?= (int)$stats['failed'] ?>)
            </button>
        <?php endif; ?>
    <?php endif; ?>
    <button type="button" class="btn btn-sm btn-outline-secondary" id="btnRefreshQueue"><i class="fas fa-sync me-1"></i> Refresh</button>
</div>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<div class="page-list">
<div class="page-section">
    <div class="page-section-head">
        <h2 class="page-section-title">Queue health</h2>
        <p class="page-section-sub">Live counts by status · Click card to filter</p>
    </div>
    <div class="row g-2" id="queueStats">
    <?php
    $qs = $stats ?? [];
    $map = [
        'pending'    => ['kpi-accent-amber', 'fa-clock'],
        'processing' => ['kpi-accent-sky', 'fa-spinner'],
        'sent'       => ['kpi-accent-green', 'fa-check'],
        'failed'     => ['kpi-accent-danger', 'fa-times'],
        'cancelled'  => ['kpi-accent-ink', 'fa-ban'],
    ];
    foreach ($map as $st => [$accent, $icon]):
    ?>
    <div class="col-6 col-md-4 col-xl">
        <div class="kpi-card <?= $accent ?> js-kpi-filter" role="button" data-filter="<?= esc($st) ?>" style="cursor:pointer;" title="Filter by <?= esc($st) ?>">
            <span class="kpi-icon"><i class="fas <?= $icon ?>"></i></span>
            <span class="kpi-label"><?= esc($st) ?></span>
            <span class="kpi-value" data-stat="<?= esc($st) ?>"><?= esc((string) ($qs[$st] ?? 0)) ?></span>
        </div>
    </div>
    <?php endforeach; ?>
</div>
</div>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h2 class="card-title mb-0">Queue jobs</h2>
        <div class="filter-bar m-0">
            <select id="queueStatusFilter" class="form-select form-select-sm" style="max-width:160px">
                <option value="">All statuses</option>
                <?php foreach (['pending','processing','sent','failed','cancelled'] as $st): ?>
                    <option value="<?= $st ?>" <?= ($filterStatus ?? '') === $st ? 'selected' : '' ?>><?= ucfirst($st) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
    <div class="card-body py-3">
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
                    <?php
                        $attempts = (int) ($item['attempts'] ?? 0);
                        $maxAttempts = (int) ($item['max_attempts'] ?? 3);
                        $displayAttempts = min($attempts, $maxAttempts) . '/' . $maxAttempts;
                        $payloadJson = is_string($item['payload'] ?? null) ? $item['payload'] : json_encode($item['payload'] ?? []);
                    ?>
                    <tr data-id="<?= (int) $item['id'] ?>">
                        <td><?= (int) $item['id'] ?></td>
                        <td>
                            <?php if (!empty($item['contact_id'])): ?>
                                <a href="<?= site_url('contacts/' . (int) $item['contact_id']) ?>" class="fw-semibold text-decoration-none">
                                    <?= esc($item['contact_name'] ?: ($item['mobile'] ?: '#' . $item['contact_id'])) ?>
                                </a>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                            <?php if (!empty($item['campaign_name'])): ?>
                                <div class="text-muted small"><i class="fas fa-bullhorn me-1"></i><?= esc($item['campaign_name']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td><span class="badge bg-light text-dark border"><?= esc($item['message_type'] ?? 'text') ?></span></td>
                        <td data-search="<?= esc(strtolower((string)($item['status'] ?? ''))) ?>" data-order="<?= esc(strtolower((string)($item['status'] ?? ''))) ?>">
                            <?= view('partials/status_badge', [
                                'status' => $item['status'] ?? '',
                                'map'    => [
                                    'sent'       => 'success',
                                    'pending'    => 'warning',
                                    'processing' => 'info',
                                    'failed'     => 'danger',
                                    'cancelled'  => 'secondary',
                                ],
                            ]) ?>
                        </td>
                        <td>
                            <?php if ($item['status'] === 'failed' && $attempts >= $maxAttempts): ?>
                                <span class="badge bg-danger-subtle text-danger border border-danger-subtle"><?= esc($displayAttempts) ?></span>
                            <?php else: ?>
                                <?= esc($displayAttempts) ?>
                            <?php endif; ?>
                        </td>
                        <td class="text-muted small text-nowrap"><?= esc(format_app_datetime($item['scheduled_at'] ?? null)) ?></td>
                        <td class="small text-danger" title="<?= esc((string)($item['error_message'] ?? '')) ?>">
                            <?= esc(mb_strimwidth((string)($item['error_message'] ?? ''), 0, 55, '…')) ?>
                        </td>
                        <td class="text-end">
                            <div class="table-actions justify-content-end gap-1">
                                <button type="button" class="btn btn-sm btn-icon-action js-view-payload"
                                        data-id="<?= (int) $item['id'] ?>"
                                        data-type="<?= esc($item['message_type'] ?? 'text') ?>"
                                        data-contact="<?= esc($item['contact_name'] ?: ($item['mobile'] ?? '')) ?>"
                                        data-error="<?= esc((string)($item['error_message'] ?? '')) ?>"
                                        data-payload="<?= esc($payloadJson) ?>"
                                        title="View Payload">
                                    <i class="fas fa-eye"></i>
                                </button>
                                <?php if (function_exists('can') && can('queue.manage')): ?>
                                    <?php if (in_array($item['status'] ?? '', ['failed', 'cancelled'], true)): ?>
                                        <button type="button" class="btn btn-sm btn-icon-action text-primary js-retry-item"
                                                data-url="<?= site_url('queue/' . (int) $item['id'] . '/retry') ?>"
                                                title="Retry message now">
                                            <i class="fas fa-redo"></i>
                                        </button>
                                    <?php endif; ?>
                                    <?php if (in_array($item['status'] ?? '', ['pending', 'processing'], true)): ?>
                                        <button type="button" class="btn btn-sm btn-icon-action text-danger js-cancel-item"
                                                data-url="<?= site_url('queue/' . (int) $item['id'] . '/cancel') ?>"
                                                title="Cancel queue item">
                                            <i class="fas fa-ban"></i>
                                        </button>
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

<!-- Payload Detail Modal -->
<div class="modal fade" id="queuePayloadModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="payloadModalTitle">Queue Job Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="mb-2">
                    <span class="text-muted small">Contact:</span> <strong id="payloadContact">—</strong> · 
                    <span class="text-muted small">Type:</span> <span class="badge bg-secondary" id="payloadType">text</span>
                </div>
                <div id="payloadErrorAlert" class="alert alert-danger py-2 small mb-2 d-none">
                    <strong>Error:</strong> <span id="payloadErrorText"></span>
                </div>
                <label class="form-label small fw-semibold">Payload Data:</label>
                <pre class="bg-light p-2 rounded small text-dark code-font mb-0" id="payloadJsonPre" style="max-height: 250px; overflow-y: auto;"></pre>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
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
            var val = (this.value || '').trim().toLowerCase();
            if (!val) {
                table.column(3).search('').draw();
            } else {
                table.column(3).search('^' + val + '$', true, false, true).draw();
            }
        });

        // Click on KPI card to quickly filter table or toggle
        $('.js-kpi-filter').on('click', function () {
            var status = ($(this).data('filter') || '').toString().toLowerCase();
            var current = ($('#queueStatusFilter').val() || '').toString().toLowerCase();
            if (current === status) {
                $('#queueStatusFilter').val('').trigger('change');
            } else {
                $('#queueStatusFilter').val(status).trigger('change');
            }
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

    // Process Queue button
    $('#btnProcessQueue').on('click', function () {
        var $btn = $(this);
        var originalHtml = $btn.html();
        $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin me-1"></i> Processing...');

        APP.post(APP.baseUrl + '/queue/process', {}).done(function (res) {
            APP.toast(res.message || 'Queue processed', res.status ? 'success' : 'info');
            setTimeout(function () { window.location.reload(); }, 800);
        }).fail(function (xhr) {
            APP.toast((xhr.responseJSON && xhr.responseJSON.message) || 'Process failed', 'error');
            $btn.prop('disabled', false).html(originalHtml);
        });
    });

    // Retry All Failed button
    $('#btnRetryAllFailed').on('click', function () {
        var $btn = $(this);
        APP.confirm({
            title: 'Retry All Failed?',
            text: 'All failed messages will be queued and processed now.',
            confirmText: 'Yes, Retry All'
        }).then(function (result) {
            if (!result.isConfirmed) return;
            var originalHtml = $btn.html();
            $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin me-1"></i> Retrying...');

            APP.post(APP.baseUrl + '/queue/retry-all', {}).done(function (res) {
                APP.toast(res.message || 'All failed messages retried', 'success');
                setTimeout(function () { window.location.reload(); }, 800);
            }).fail(function (xhr) {
                APP.toast((xhr.responseJSON && xhr.responseJSON.message) || 'Retry all failed', 'error');
                $btn.prop('disabled', false).html(originalHtml);
            });
        });
    });

    // Single item retry via AJAX
    $(document).on('click', '.js-retry-item', function (e) {
        e.preventDefault();
        var $btn = $(this);
        var url = $btn.data('url');
        var originalHtml = $btn.html();
        $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i>');

        APP.post(url, {}).done(function (res) {
            APP.toast(res.message || 'Item retried', 'success');
            setTimeout(function () { window.location.reload(); }, 800);
        }).fail(function (xhr) {
            APP.toast((xhr.responseJSON && xhr.responseJSON.message) || 'Retry failed', 'error');
            $btn.prop('disabled', false).html(originalHtml);
        });
    });

    // Single item cancel via AJAX
    $(document).on('click', '.js-cancel-item', function (e) {
        e.preventDefault();
        var $btn = $(this);
        var url  = $btn.data('url');
        APP.confirm({
            title: 'Cancel Queue Item?',
            text: 'This message will not be sent.',
            confirmText: 'Yes, Cancel'
        }).then(function (result) {
            if (!result.isConfirmed) return;
            APP.post(url, {}).done(function (res) {
                APP.toast(res.message || 'Item cancelled', 'success');
                setTimeout(function () { window.location.reload(); }, 800);
            }).fail(function (xhr) {
                APP.toast((xhr.responseJSON && xhr.responseJSON.message) || 'Cancel failed', 'error');
            });
        });
    });

    // View Payload Modal
    $(document).on('click', '.js-view-payload', function () {
        var $el = $(this);
        var id = $el.data('id');
        var type = $el.data('type');
        var contact = $el.data('contact');
        var error = $el.data('error');
        var rawPayload = $el.data('payload');

        $('#payloadModalTitle').text('Queue Job #' + id);
        $('#payloadContact').text(contact || '—');
        $('#payloadType').text(type);

        if (error) {
            $('#payloadErrorText').text(error);
            $('#payloadErrorAlert').removeClass('d-none');
        } else {
            $('#payloadErrorAlert').addClass('d-none');
        }

        try {
            var parsed = typeof rawPayload === 'object' ? rawPayload : JSON.parse(rawPayload);
            $('#payloadJsonPre').text(JSON.stringify(parsed, null, 2));
        } catch (e) {
            $('#payloadJsonPre').text(rawPayload || '{}');
        }

        var modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('queuePayloadModal'));
        modal.show();
    });

    setInterval(refreshStats, 15000);
});
</script>
<?= $this->endSection() ?>
