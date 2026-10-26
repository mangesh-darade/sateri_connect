<?= $this->extend('layouts/main') ?>

<?= $this->section('header_actions') ?>
<div class="d-flex align-items-center gap-2">
    <?php
    $exportQs = http_build_query(array_filter([
        'module'    => $filters['module'] ?? null,
        'action'    => $filters['action'] ?? null,
        'user_id'   => $filters['user_id'] ?? null,
        'date_from' => $filters['date_from'] ?? null,
        'date_to'   => $filters['date_to'] ?? null,
        'search'    => $filters['search'] ?? null,
    ]));
    ?>
    <a href="<?= site_url('activity-logs/export?' . $exportQs) ?>" id="activityExportLink" class="btn btn-sm btn-outline-secondary" title="Export CSV">
        <i class="fas fa-file-csv me-1 text-success"></i> Export CSV
    </a>
</div>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<div class="container-fluid px-0">
    <!-- Filter Card -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body p-3">
            <form id="activityFilters" onsubmit="return false;" class="row g-2 align-items-end">
                <div class="col-md-3">
                    <label class="form-label small text-muted mb-1">Search Keywords</label>
                    <div class="input-group input-group-sm">
                        <span class="input-group-text bg-light border-end-0"><i class="fas fa-search text-muted"></i></span>
                        <input type="text" name="search" id="afSearch" class="form-control border-start-0" placeholder="Description, email, field..." value="<?= esc($filters['search'] ?? '') ?>">
                    </div>
                </div>

                <div class="col-md-2">
                    <label class="form-label small text-muted mb-1">Module</label>
                    <select name="module" id="afModule" class="form-select form-select-sm">
                        <option value="">All Modules</option>
                        <?php foreach ($modules as $mod): ?>
                            <option value="<?= esc($mod) ?>" <?= ($filters['module'] ?? '') === $mod ? 'selected' : '' ?>>
                                <?= esc(ucfirst(str_replace('_', ' ', $mod))) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-2">
                    <label class="form-label small text-muted mb-1">User</label>
                    <select name="user_id" id="afUser" class="form-select form-select-sm">
                        <option value="">All Users</option>
                        <?php foreach ($users as $u): ?>
                            <option value="<?= (int) $u['id'] ?>" <?= ((string) ($filters['user_id'] ?? '') === (string) $u['id']) ? 'selected' : '' ?>>
                                <?= esc($u['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-2">
                    <label class="form-label small text-muted mb-1">From Date</label>
                    <input type="date" name="date_from" id="afFrom" class="form-control form-control-sm" value="<?= esc($filters['date_from'] ?? '') ?>">
                </div>

                <div class="col-md-2">
                    <label class="form-label small text-muted mb-1">To Date</label>
                    <input type="date" name="date_to" id="afTo" class="form-control form-control-sm" value="<?= esc($filters['date_to'] ?? '') ?>">
                </div>

                <div class="col-md-1 d-flex gap-1">
                    <button type="button" class="btn btn-sm btn-outline-secondary w-100" id="afReset" title="Reset Filters">
                        <i class="fas fa-undo"></i>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Logs Table Card -->
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white border-bottom py-3 d-flex justify-content-between align-items-center">
            <h6 class="mb-0 fw-bold">
                <i class="fas fa-history text-primary me-2"></i>Audit Trail &amp; Changes History
            </h6>
        </div>

        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 w-100" id="activityLogsTable">
                    <thead class="table-light text-muted small text-uppercase">
                        <tr>
                            <th style="width: 170px;">Date &amp; Time</th>
                            <th style="width: 170px;">User</th>
                            <th style="width: 120px;">Module</th>
                            <th style="width: 110px;">Action</th>
                            <th>Description &amp; Changes</th>
                            <th style="width: 130px;">IP Address</th>
                            <th style="width: 110px;" class="text-end">Details</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>

    </div>
</div>

<!-- Reusable Clean Single Modal (Placed outside all cards/tables and moved to body via JS to avoid backdrop z-index traps) -->
<style>
#activityDetailModal {
    z-index: 1060 !important;
}
.modal-backdrop {
    z-index: 1050 !important;
}
#activityDetailModal .modal-dialog {
    max-width: 900px;
}
#activityDetailModal .modal-content {
    box-shadow: 0 1rem 3rem rgba(0, 0, 0, 0.28) !important;
}
</style>

<div class="modal fade" id="activityDetailModal" tabindex="-1" aria-labelledby="activityDetailModalLabel" aria-hidden="true" style="z-index: 1060;">
    <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
        <div class="modal-content text-start border-0">
            <div class="modal-header py-3 bg-light border-bottom">
                <div>
                    <h6 class="modal-title fw-bold mb-0" id="activityDetailModalLabel">
                        <i class="fas fa-history text-primary me-2"></i><span id="modalEventTitle">Activity Event</span>
                    </h6>
                    <div class="small text-muted mt-1" id="modalEventSub">
                        <!-- Event sub metadata injected here -->
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body p-4">
                <!-- Modal Navigation Tabs -->
                <ul class="nav nav-pills nav-fill mb-3 bg-light p-1 rounded" id="modalNavTabs" role="tablist">
                    <li class="nav-item" role="presentation" id="modalTabDiffLi">
                        <button class="nav-link active py-1 small fw-medium" id="modalTabDiffBtn" data-bs-toggle="tab" data-bs-target="#modalPaneDiff" type="button" role="tab">
                            <i class="fas fa-exchange-alt me-1 text-primary"></i> Old vs New Changes
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link py-1 small fw-medium" id="modalTabContextBtn" data-bs-toggle="tab" data-bs-target="#modalPaneContext" type="button" role="tab">
                            <i class="fas fa-list-ul me-1 text-info"></i> Event Context
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link py-1 small fw-medium" id="modalTabRawBtn" data-bs-toggle="tab" data-bs-target="#modalPaneRaw" type="button" role="tab">
                            <i class="fas fa-code me-1 text-secondary"></i> Raw Payload
                        </button>
                    </li>
                </ul>

                <div class="tab-content" id="modalNavTabContent">
                    <!-- Diff Comparison Tab -->
                    <div class="tab-pane fade show active" id="modalPaneDiff" role="tabpanel">
                        <div id="modalDiffContainer"></div>
                    </div>

                    <!-- Event Context Tab -->
                    <div class="tab-pane fade" id="modalPaneContext" role="tabpanel">
                        <div class="card bg-light border-0">
                            <div class="card-body p-3">
                                <div class="row g-2 mb-3">
                                    <div class="col-sm-6">
                                        <div class="text-muted small">Description:</div>
                                        <div class="fw-semibold small" id="modalContextDesc">—</div>
                                    </div>
                                    <div class="col-sm-6">
                                        <div class="text-muted small">IP Address:</div>
                                        <div class="font-monospace small" id="modalContextIp">—</div>
                                    </div>
                                    <div class="col-12 mt-2" id="modalContextUaWrap">
                                        <div class="text-muted small">User Agent:</div>
                                        <div class="small text-muted font-monospace text-break" id="modalContextUa">—</div>
                                    </div>
                                </div>
                                <hr class="my-2">
                                <div class="text-muted small fw-bold mb-2">Context Parameters:</div>
                                <div class="d-flex flex-wrap gap-2" id="modalContextParams">
                                    <!-- Context parameters chips injected here -->
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Raw JSON Tab -->
                    <div class="tab-pane fade" id="modalPaneRaw" role="tabpanel">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="small text-muted">JSON Structure:</span>
                            <button type="button" class="btn btn-xs btn-outline-secondary py-0 px-2" id="btnCopyJson">
                                <i class="fas fa-copy me-1"></i> Copy
                            </button>
                        </div>
                        <pre class="bg-dark text-light p-3 rounded small mb-0 font-monospace text-wrap" style="max-height: 350px; overflow-y: auto;"><code id="modalRawCode"></code></pre>
                    </div>
                </div>
            </div>

            <div class="modal-footer py-2 bg-light border-top">
                <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    var modalEl = document.getElementById('activityDetailModal');
    if (!modalEl) return;

    // CRUCIAL FIX: Move modal directly into document.body to break out of AdminLTE .content-wrapper stacking context (z-index 820)
    // This prevents Bootstrap's backdrop (z-index 1050 on body) from appearing over the modal!
    if (modalEl.parentNode !== document.body) {
        document.body.appendChild(modalEl);
    }

    var bsModal = new bootstrap.Modal(modalEl, {
        backdrop: true,
        keyboard: true
    });

    var exportBase = <?= json_encode(site_url('activity-logs/export')) ?>;

    function badgeClass(action) {
        action = String(action || '').toLowerCase();
        var has = function () {
            for (var i = 0; i < arguments.length; i++) { if (action.indexOf(arguments[i]) !== -1) return true; }
            return false;
        };
        if (has('create', 'add', 'import')) return 'bg-success-subtle text-success border border-success-subtle';
        if (has('delete', 'erase', 'remove')) return 'bg-danger-subtle text-danger border border-danger-subtle';
        if (has('send', 'run', 'dispatch')) return 'bg-primary-subtle text-primary border border-primary-subtle';
        if (has('update', 'edit', 'save')) return 'bg-info-subtle text-info border border-info-subtle';
        if (has('pause', 'stop')) return 'bg-warning-subtle text-warning border border-warning-subtle';
        return 'bg-secondary-subtle text-secondary border border-secondary-subtle';
    }

    function prettyModule(m) {
        m = String(m || 'general').replace(/_/g, ' ');
        return m.charAt(0).toUpperCase() + m.slice(1);
    }

    function syncExportLink() {
        var params = {
            module: $('#afModule').val() || '',
            user_id: $('#afUser').val() || '',
            date_from: $('#afFrom').val() || '',
            date_to: $('#afTo').val() || '',
            search: $('#afSearch').val() || ''
        };
        var parts = [];
        $.each(params, function (k, v) { if (v !== '') parts.push(encodeURIComponent(k) + '=' + encodeURIComponent(v)); });
        $('#activityExportLink').attr('href', exportBase + (parts.length ? '?' + parts.join('&') : ''));
    }

    var logsDt = AppDT.table('#activityLogsTable', {
        url: <?= json_encode(site_url('activity-logs/data')) ?>,
        order: [[0, 'desc']],
        emptyText: 'No activity logs found matching the selected filters.',
        filters: { q: '#afSearch', module: '#afModule', user_id: '#afUser', date_from: '#afFrom', date_to: '#afTo' },
        columns: [
            { data: null, className: 'small text-muted text-nowrap', render: function (r) { return AppDT.esc(r.created_at); } },
            { data: null, render: function (r) {
                var name = r.user_name || 'System';
                var h = '<div class="d-flex align-items-center">' +
                    '<div class="avatar avatar-xs rounded-circle bg-light text-primary fw-bold me-2 d-flex align-items-center justify-content-center" style="width: 28px; height: 28px; font-size: 0.75rem;">' +
                    AppDT.esc(name.charAt(0).toUpperCase()) + '</div><div><div class="fw-medium small">' + AppDT.esc(name) + '</div>';
                if (r.user_email) h += '<div class="text-muted" style="font-size: 0.72rem;">' + AppDT.esc(r.user_email) + '</div>';
                return h + '</div></div>';
            } },
            { data: null, render: function (r) { return '<span class="badge bg-light text-dark border">' + AppDT.esc(prettyModule(r.module)) + '</span>'; } },
            { data: null, render: function (r) {
                return '<span class="badge ' + badgeClass(r.action) + ' px-2 py-1">' + AppDT.esc(String(r.action || 'EVENT').toUpperCase()) + '</span>';
            } },
            { data: null, orderable: false, className: 'small text-break', render: function (r) {
                var h = '<div>' + AppDT.esc(r.description || '\u2014') + '</div>';
                if (r.change_count > 0) {
                    h += '<div class="mt-1"><button type="button" class="btn btn-xs btn-outline-info py-0 px-2 fw-medium rounded-pill btn-open-log" style="font-size: 0.73rem;">' +
                        '<i class="fas fa-exchange-alt me-1"></i>' + AppDT.esc(r.change_count) + ' field(s) modified</button></div>';
                }
                return h;
            } },
            { data: null, className: 'small text-muted font-monospace', render: function (r) { return AppDT.esc(r.ip_address || '\u2014'); } },
            { data: null, orderable: false, className: 'text-end', render: function (r) {
                var has = r.change_count > 0;
                return '<button type="button" class="btn btn-xs ' + (has ? 'btn-primary' : 'btn-outline-secondary') + ' py-1 px-2 btn-open-log" title="View change details">' +
                    (has ? '<i class="fas fa-sliders-h me-1"></i> Changes' : '<i class="fas fa-eye me-1"></i> View') + '</button>';
            } }
        ],
        drawCallback: syncExportLink
    });

    $('#afReset').on('click', function () {
        $('#afSearch, #afModule, #afUser, #afFrom, #afTo').val('');
        logsDt.ajax.reload();
    });

    // Escape helper
    function escapeHtml(str) {
        if (str === null || str === undefined) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    $(document).on('click', '.btn-open-log', function(e) {
        e.preventDefault();
        var row = logsDt.row($(this).closest('tr')).data();
        if (!row) return;

        var data = $.extend({}, row, {
            action: row.action || 'EVENT',
            module: row.module || 'general',
            user_name: row.user_name || 'System',
            ip_address: row.ip_address || '\u2014',
            has_changes: row.change_count > 0,
            badge_class: badgeClass(row.action)
        });

        // Re-verify modal is attached to document.body
        if (modalEl.parentNode !== document.body) {
            document.body.appendChild(modalEl);
        }

        // 1. Header info
        $('#modalEventTitle').text('Activity Event #' + data.id);
        var subHtml = '<span class="badge ' + (data.badge_class || 'bg-secondary') + ' me-1">' + escapeHtml(data.action.toUpperCase()) + '</span> ' +
            escapeHtml(data.module.replace(/_/g, ' ').toUpperCase()) + ' &bull; ' +
            'By <strong>' + escapeHtml(data.user_name || 'System') + '</strong> &bull; ' +
            escapeHtml(data.created_at);
        $('#modalEventSub').html(subHtml);

        // 2. Diff pane
        var hasChanges = data.has_changes && Object.keys(data.changes || {}).length > 0;
        if (hasChanges) {
            $('#modalTabDiffLi').show();
            var diffHtml = '<div class="table-responsive border rounded">' +
                '<table class="table table-sm align-middle mb-0">' +
                '<thead class="table-light small">' +
                '<tr>' +
                '<th style="width: 25%;">Field</th>' +
                '<th style="width: 37%;" class="text-danger"><i class="fas fa-minus-circle me-1"></i>Previous / Old Value</th>' +
                '<th style="width: 38%;" class="text-success"><i class="fas fa-plus-circle me-1"></i>Updated / New Value</th>' +
                '</tr>' +
                '</thead>' +
                '<tbody>';

            $.each(data.changes, function(field, item) {
                var oldV = item ? (item.old !== undefined ? item.old : item) : '(empty)';
                var newV = item ? (item.new !== undefined ? item.new : item) : '(empty)';
                if (typeof oldV === 'object') oldV = JSON.stringify(oldV);
                if (typeof newV === 'object') newV = JSON.stringify(newV);

                diffHtml += '<tr>' +
                    '<td class="fw-semibold text-secondary small font-monospace">' + escapeHtml(field) + '</td>' +
                    '<td><div class="p-2 rounded bg-danger-subtle text-danger small font-monospace text-break text-decoration-line-through">' + escapeHtml(oldV || '(empty)') + '</div></td>' +
                    '<td><div class="p-2 rounded bg-success-subtle text-success small font-monospace text-break fw-semibold">' + escapeHtml(newV || '(empty)') + '</div></td>' +
                    '</tr>';
            });

            diffHtml += '</tbody></table></div>';
            $('#modalDiffContainer').html(diffHtml);

            // Activate Diff tab
            bootstrap.Tab.getOrCreateInstance(document.getElementById('modalTabDiffBtn')).show();
        } else {
            $('#modalTabDiffLi').hide();
            $('#modalDiffContainer').html('');
            // Activate Context tab
            bootstrap.Tab.getOrCreateInstance(document.getElementById('modalTabContextBtn')).show();
        }

        // 3. Context Pane
        $('#modalContextDesc').text(data.description || '—');
        $('#modalContextIp').text(data.ip_address || '—');
        if (data.user_agent) {
            $('#modalContextUaWrap').show();
            $('#modalContextUa').text(data.user_agent);
        } else {
            $('#modalContextUaWrap').hide();
        }

        var paramsHtml = '';
        var metaObj = data.metadata || {};
        var countParams = 0;
        $.each(metaObj, function(k, v) {
            if (k === 'changes' || k === 'before' || k === 'after') return;
            countParams++;
            var valStr = (typeof v === 'object' && v !== null) ? JSON.stringify(v) : String(v);
            paramsHtml += '<div class="badge bg-white text-dark border p-2 small text-start">' +
                '<span class="text-muted font-monospace">' + escapeHtml(k) + ':</span> ' +
                '<span class="fw-semibold ms-1 font-monospace">' + escapeHtml(valStr) + '</span>' +
                '</div>';
        });

        if (countParams > 0) {
            $('#modalContextParams').html(paramsHtml).parent().show();
        } else {
            $('#modalContextParams').html('<span class="text-muted small">No extra context recorded.</span>');
        }

        // 4. Raw JSON pane
        var formattedJson = JSON.stringify(metaObj, null, 2);
        $('#modalRawCode').text(formattedJson);

        // Show Modal on top of everything
        bsModal.show();
    });

    // Copy JSON button
    $('#btnCopyJson').on('click', function() {
        var text = $('#modalRawCode').text();
        navigator.clipboard.writeText(text).then(function() {
            var $btn = $('#btnCopyJson');
            var original = $btn.html();
            $btn.html('<i class="fas fa-check text-success me-1"></i> Copied!');
            setTimeout(function() { $btn.html(original); }, 2000);
        });
    });
});
</script>
<?= $this->endSection() ?>
