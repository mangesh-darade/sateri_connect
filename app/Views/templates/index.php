<?= $this->extend('layouts/main') ?>

<?= $this->section('header_actions') ?>
<?php if (function_exists('can') && can('templates.create')): ?>
    <a href="<?= site_url('templates/create') ?>" class="btn btn-wa btn-sm">
        <i class="fas fa-plus me-1"></i> Create template
    </a>
<?php endif; ?>
<?php if (function_exists('can') && can('templates.sync')): ?>
    <form action="<?= site_url('templates/sync') ?>" method="post" class="d-inline">
        <?= csrf_field() ?>
        <button type="submit" class="btn btn-outline-secondary btn-sm" id="btnSyncTemplates"><i class="fas fa-sync me-1"></i> <?= esc(function_exists('whatsapp_sync_label') ? whatsapp_sync_label() : 'Sync templates') ?></button>
    </form>
<?php endif; ?>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php
$templateStatuses = [];
$templateCategories = [];
$templateLanguages = [];
$statusCounts = $statusCounts ?? ['APPROVED' => 0, 'PENDING' => 0, 'REJECTED' => 0, 'DISABLED' => 0, 'OTHER' => 0];
$variableCount = static function (array $tpl): int {
    $defs = \App\Libraries\WhatsAppTemplateVariables::definitionsForTemplate(
        $tpl['variables'] ?? null,
        (string) ($tpl['body'] ?? ''),
        $tpl['raw_payload'] ?? null
    );

    return count($defs);
};
$normalizeStatus = static function (?string $value): string {
    $normalized = strtolower(trim((string) $value));
    $normalized = str_replace([' ', '-'], '_', $normalized);
    $normalized = preg_replace('/_+/', '_', $normalized);

    return $normalized ?: '';
};
$statusLabel = static function (?string $value): string {
    $normalized = strtolower(trim((string) $value));
    if ($normalized === 'inprogress') {
        $normalized = 'in progress';
    }
    $normalized = str_replace(['-', '_'], ' ', $normalized);
    $normalized = preg_replace('/\s+/', ' ', $normalized);

    return ucwords($normalized ?: '');
};

foreach ($templates ?? [] as $tpl) {
    $status = $normalizeStatus((string) ($tpl['status'] ?? ''));
    $category = trim((string) ($tpl['category'] ?? ''));
    $language = trim((string) ($tpl['language'] ?? ''));

    if ($status !== '' && ! in_array($status, $templateStatuses, true)) {
        $templateStatuses[] = $status;
    }
    if ($category !== '' && ! in_array($category, $templateCategories, true)) {
        $templateCategories[] = $category;
    }
    if ($language !== '' && ! in_array($language, $templateLanguages, true)) {
        $templateLanguages[] = $language;
    }
}

sort($templateStatuses);
sort($templateCategories);
sort($templateLanguages);
?>
<div class="page-list" id="templatesPageRoot"
     data-auto-sync="<?= (function_exists('can') && can('templates.sync')) ? '1' : '0' ?>"
     data-last-synced="<?= esc((string) ($lastSyncedAt ?? ''), 'attr') ?>">

<div class="card mb-2">
    <div class="card-body py-3">
        <div class="d-flex flex-wrap gap-3 align-items-center justify-content-between">
            <div>
                <div class="fw-semibold">WhatsApp Template Management</div>
                <div class="small text-muted">
                    WABA <?= esc($wabaId ?: 'not configured') ?>
                    <?php if (! empty($phoneNumberId)): ?>
                        · Phone ID <?= esc($phoneNumberId) ?>
                    <?php endif; ?>
                    <?php if (! empty($lastSyncedAt)): ?>
                        · Last synced <?= esc(format_app_datetime($lastSyncedAt)) ?>
                    <?php endif; ?>
                </div>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <span class="badge text-bg-success">Approved <?= (int) ($statusCounts['APPROVED'] ?? 0) ?></span>
                <span class="badge text-bg-warning text-dark">Pending <?= (int) ($statusCounts['PENDING'] ?? 0) ?></span>
                <span class="badge text-bg-danger">Rejected <?= (int) ($statusCounts['REJECTED'] ?? 0) ?></span>
                <span class="badge text-bg-secondary">Disabled <?= (int) ($statusCounts['DISABLED'] ?? 0) ?></span>
            </div>
        </div>
        <div class="small text-muted mt-2">
            Only <strong>APPROVED</strong> templates can be selected for sending.
            Meta API Setup may show <code>hello_world</code> as a sample — that does not guarantee it exists on this WABA.
        </div>
    </div>
</div>

<?php if (! empty($templates)): ?>
<div class="card">
    <div class="card-body py-3">
        <div class="section-toolbar">
            <div class="btn-group btn-group-sm template-tab-group" role="tablist" aria-label="Template view switcher">
                <button type="button" class="btn btn-wa template-view-toggle active" data-view="card">
                    <i class="fas fa-id-card me-1"></i> Card
                </button>
                <button type="button" class="btn btn-outline-secondary template-view-toggle" data-view="grid">
                    <i class="fas fa-table-cells-large me-1"></i> Grid
                </button>
            </div>
            <div class="section-meta">
                Showing <span class="fw-semibold text-dark" id="templateVisibleCount"><?= count($templates) ?></span> of
                <span class="fw-semibold text-dark"><?= count($templates) ?></span> templates
            </div>
        </div>

        <div class="filter-bar mb-0">
            <input type="search" id="templateSearch" class="form-control form-control-sm" placeholder="Search name, body, footer">
            <select id="templateStatusFilter" class="form-select form-select-sm" title="Status">
                <option value="">Status</option>
                <?php foreach ($templateStatuses as $status): ?>
                    <option value="<?= esc($normalizeStatus($status), 'attr') ?>"><?= esc($statusLabel($status)) ?></option>
                <?php endforeach; ?>
            </select>
            <select id="templateCategoryFilter" class="form-select form-select-sm" title="Category">
                <option value="">Category</option>
                <?php foreach ($templateCategories as $category): ?>
                    <option value="<?= esc(strtolower($category), 'attr') ?>"><?= esc($category) ?></option>
                <?php endforeach; ?>
            </select>
            <select id="templateLanguageFilter" class="form-select form-select-sm" title="Language">
                <option value="">Language</option>
                <?php foreach ($templateLanguages as $language): ?>
                    <option value="<?= esc(strtolower($language), 'attr') ?>"><?= esc($language) ?></option>
                <?php endforeach; ?>
            </select>
            <div class="filter-bar-actions">
                <button type="button" class="btn btn-outline-secondary btn-sm" id="templateResetFilters" title="Reset filters">
                    <i class="fas fa-rotate-left me-1"></i> Reset
                </button>
            </div>
        </div>
        <div id="templateActiveFilters" class="d-flex flex-wrap gap-2 mt-2"></div>
    </div>
</div>

<div id="templateCardView" class="template-view-panel page-section">
    <div class="row g-2">
        <?php foreach ($templates as $tpl): ?>
            <?php
            $status = $normalizeStatus((string) ($tpl['status'] ?? ''));
            $category = strtolower(trim((string) ($tpl['category'] ?? '')));
            $language = strtolower(trim((string) ($tpl['language'] ?? '')));
            $varsCount = $variableCount($tpl);
            $isApproved = strtoupper((string) ($tpl['status'] ?? '')) === 'APPROVED';
            $searchText = strtolower(trim(implode(' ', [
                (string) ($tpl['name'] ?? ''),
                (string) ($tpl['body'] ?? ''),
                (string) ($tpl['footer'] ?? ''),
                (string) ($tpl['header_content'] ?? ''),
                (string) ($tpl['category'] ?? ''),
                (string) ($tpl['language'] ?? ''),
                (string) ($tpl['status'] ?? ''),
            ])));
            ?>
            <div class="col-md-6 col-xl-4 template-card-item"
                data-status="<?= esc($status, 'attr') ?>"
                data-category="<?= esc($category, 'attr') ?>"
                data-language="<?= esc($language, 'attr') ?>"
                data-search="<?= esc($searchText, 'attr') ?>">
                <div class="dash-panel h-100 d-flex flex-column">
                    <div class="panel-head align-items-start">
                        <div>
                            <h3 class="mb-1"><?= esc($tpl['name']) ?></h3>
                            <div class="small text-muted"><?= esc($tpl['language'] ?? '') ?> · <?= esc($tpl['category'] ?? '') ?></div>
                        </div>
                        <?= view('partials/status_badge', ['status' => strtolower($tpl['status'] ?? 'unknown')]) ?>
                    </div>
                    <div class="panel-body flex-grow-1">
                        <p class="small mb-2" style="white-space:pre-wrap;color:var(--text-muted)"><?= esc(mb_strimwidth($tpl['body'] ?? '', 0, 160, '…')) ?></p>
                        <div class="small text-muted mb-1"><?= (int) $varsCount ?> variable<?= $varsCount === 1 ? '' : 's' ?></div>
                        <?php if (! empty($tpl['synced_at'])): ?>
                            <div class="small text-muted">Last synced <?= esc(format_app_datetime($tpl['synced_at'] ?? null)) ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="panel-body border-top pt-2 d-flex flex-wrap gap-2" style="border-color:var(--border)!important">
                        <button type="button" class="btn btn-sm btn-outline-secondary btn-preview-tpl"
                            data-id="<?= (int) $tpl['id'] ?>"
                            data-name="<?= esc($tpl['name'], 'attr') ?>"
                            data-body="<?= esc($tpl['body'] ?? '', 'attr') ?>"
                            data-footer="<?= esc($tpl['footer'] ?? '', 'attr') ?>"
                            data-header="<?= esc($tpl['header_content'] ?? '', 'attr') ?>"
                            data-header-type="<?= esc($tpl['header_type'] ?? '', 'attr') ?>">
                            <i class="fas fa-eye"></i> View
                        </button>
                        <a href="<?= site_url('templates/' . (int) $tpl['id']) ?>" class="btn btn-sm btn-outline-secondary">Details</a>
                        <?php if ($isApproved && function_exists('can') && (can('chat.send') || can('templates.sync') || can('templates.create'))): ?>
                            <button type="button"
                                class="btn btn-sm btn-wa btn-send-test-tpl"
                                data-id="<?= (int) $tpl['id'] ?>"
                                data-name="<?= esc($tpl['name'], 'attr') ?>"
                                data-language="<?= esc($tpl['language'] ?? '', 'attr') ?>"
                                data-status="<?= esc($tpl['status'] ?? '', 'attr') ?>">
                                <i class="fas fa-paper-plane"></i> Send Test
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<div id="templateGridView" class="template-view-panel d-none">
    <div class="card">
        <div class="card-header">
            <h2 class="card-title mb-0">All templates</h2>
        </div>
        <div class="card-body py-3">
            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle w-100" id="templatesTable">
                    <thead>
                        <tr><th>Name</th><th>Language</th><th>Category</th><th>Status</th><th>Variables</th><th>Last Synced</th><th></th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($templates as $tpl): ?>
                            <?php
                            $status = $normalizeStatus((string) ($tpl['status'] ?? ''));
                            $category = strtolower(trim((string) ($tpl['category'] ?? '')));
                            $language = strtolower(trim((string) ($tpl['language'] ?? '')));
                            $varsCount = $variableCount($tpl);
                            $isApproved = strtoupper((string) ($tpl['status'] ?? '')) === 'APPROVED';
                            $searchText = strtolower(trim(implode(' ', [
                                (string) ($tpl['name'] ?? ''),
                                (string) ($tpl['body'] ?? ''),
                                (string) ($tpl['footer'] ?? ''),
                                (string) ($tpl['header_content'] ?? ''),
                                (string) ($tpl['category'] ?? ''),
                                (string) ($tpl['language'] ?? ''),
                                (string) ($tpl['status'] ?? ''),
                            ])));
                            ?>
                            <tr
                                data-status="<?= esc($status, 'attr') ?>"
                                data-category="<?= esc($category, 'attr') ?>"
                                data-language="<?= esc($language, 'attr') ?>"
                                data-search="<?= esc($searchText, 'attr') ?>">
                                <td class="fw-semibold"><a href="<?= site_url('templates/' . (int) $tpl['id']) ?>" class="text-decoration-none"><?= esc($tpl['name']) ?></a></td>
                                <td><?= esc($tpl['language'] ?? '') ?></td>
                                <td><?= esc($tpl['category'] ?? '') ?></td>
                                <td><?= view('partials/status_badge', ['status' => strtolower($tpl['status'] ?? '')]) ?></td>
                                <td><?= (int) $varsCount ?></td>
                                <td class="text-muted small text-nowrap"><?= esc(format_app_datetime($tpl['synced_at'] ?? null, 'd-M-Y, g:i A', '')) ?></td>
                                <td class="text-nowrap">
                                    <a href="<?= site_url('templates/' . (int) $tpl['id']) ?>" class="btn btn-sm btn-outline-secondary">View</a>
                                    <?php if ($isApproved && function_exists('can') && (can('chat.send') || can('templates.sync') || can('templates.create'))): ?>
                                        <button type="button" class="btn btn-sm btn-wa btn-send-test-tpl"
                                            data-id="<?= (int) $tpl['id'] ?>"
                                            data-name="<?= esc($tpl['name'], 'attr') ?>"
                                            data-language="<?= esc($tpl['language'] ?? '', 'attr') ?>"
                                            data-status="<?= esc($tpl['status'] ?? '', 'attr') ?>">Send Test</button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div id="templateEmptyState" class="dash-panel d-none mt-2">
    <div class="activity-empty">
        <i class="fas fa-filter-circle-xmark"></i>
        No templates match the current filters.
        <div class="mt-3">
            <button type="button" class="btn btn-outline-secondary btn-sm" id="templateResetEmptyState">Clear filters</button>
        </div>
    </div>
</div>
<?php else: ?>
<div class="row g-2">
    <div class="col-12">
        <?= view('partials/empty_state', [
            'icon'        => 'file-alt',
            'title'       => 'No templates yet',
            'text'        => 'Create one or sync from the active WhatsApp provider.',
            'actionUrl'   => (function_exists('can') && can('templates.create')) ? site_url('templates/create') : null,
            'actionLabel' => (function_exists('can') && can('templates.create')) ? 'Create template' : null,
        ]) ?>
        <?php if (function_exists('can') && can('templates.sync')): ?>
            <div class="text-center mt-n2 mb-3">
                <form action="<?= site_url('templates/sync') ?>" method="post" class="d-inline"><?= csrf_field() ?>
                    <button type="submit" class="btn btn-outline-secondary btn-sm">Sync templates</button>
                </form>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>
</div>

<div class="modal fade" id="tplPreviewModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="tplPreviewTitle">Template</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" style="background:var(--wa-chat-bg)">
                <div class="msg-bubble" style="background:var(--wa-bubble-out);display:inline-block;max-width:100%">
                    <div id="tplPreviewHeader"></div>
                    <div id="tplPreviewBody" style="white-space:pre-wrap"></div>
                    <div class="small text-muted" id="tplPreviewFooter"></div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="tplSendTestModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Send Test — <span id="tplSendTestName"></span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="tplSendTestId" value="">
                <div class="mb-3">
                    <label class="form-label">Recipient phone (E.164 / digits)</label>
                    <input type="text" class="form-control" id="tplSendTestTo" placeholder="9198XXXXXXXX">
                </div>
                <div id="tplSendTestVars" class="mb-2"></div>
                <div class="small text-muted">Only APPROVED templates for this WABA can be sent. Credentials are taken from Settings — never enter another customer's WABA or token.</div>
                <div id="tplSendTestError" class="alert alert-danger d-none mt-3 mb-0"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-wa" id="tplSendTestSubmit"><i class="fas fa-paper-plane me-1"></i> Send Test</button>
            </div>
        </div>
    </div>
</div>
<?= $this->endSection() ?>

<?= $this->section('styles') ?>
<style>
.template-filter-chip {
    display: inline-flex;
    align-items: center;
    gap: .35rem;
    padding: .35rem .65rem;
    border-radius: 999px;
    background: rgba(37, 211, 102, .12);
    color: var(--text);
    font-size: .8rem;
    font-weight: 600;
}
</style>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
$(function () {
    var templateTable = null;
    var $cardItems = $('.template-card-item');
    var $cardView = $('#templateCardView');
    var $gridView = $('#templateGridView');
    var $emptyState = $('#templateEmptyState');
    var $visibleCount = $('#templateVisibleCount');
    var $activeFilters = $('#templateActiveFilters');
    var activeView = localStorage.getItem('templatesViewMode') || 'card';

    if ($.fn.DataTable && $('#templatesTable').length) {
        templateTable = $('#templatesTable').DataTable({
            pageLength: 10,
            order: [],
            dom: 'rt<"d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2 mt-3"lip>'
        });
    }

    function getFilters() {
        return {
            search: ($('#templateSearch').val() || '').toString().trim().toLowerCase(),
            status: ($('#templateStatusFilter').val() || '').toString().trim().toLowerCase(),
            category: ($('#templateCategoryFilter').val() || '').toString().trim().toLowerCase(),
            language: ($('#templateLanguageFilter').val() || '').toString().trim().toLowerCase()
        };
    }

    function renderFilterChips(filters) {
        var chips = [];

        if (filters.search) {
            chips.push('<span class="template-filter-chip">Search: ' + $('<div>').text(filters.search).html() + '</span>');
        }
        if (filters.status) {
            chips.push('<span class="template-filter-chip">Status: ' + $('<div>').text(filters.status).html() + '</span>');
        }
        if (filters.category) {
            chips.push('<span class="template-filter-chip">Category: ' + $('<div>').text(filters.category).html() + '</span>');
        }
        if (filters.language) {
            chips.push('<span class="template-filter-chip">Language: ' + $('<div>').text(filters.language).html() + '</span>');
        }

        $activeFilters.html(chips.join(''));
    }

    function matchesFilters(filters, rowData) {
        if (filters.search && rowData.search.indexOf(filters.search) === -1) {
            return false;
        }
        if (filters.status && rowData.status !== filters.status) {
            return false;
        }
        if (filters.category && rowData.category !== filters.category) {
            return false;
        }
        if (filters.language && rowData.language !== filters.language) {
            return false;
        }

        return true;
    }

    function updateVisibleState(count) {
        $visibleCount.text(count);
        $emptyState.toggleClass('d-none', count > 0);
    }

    function applyCardFilters(filters) {
        var visibleCount = 0;

        $cardItems.each(function () {
            var $item = $(this);
            var isMatch = matchesFilters(filters, {
                search: ($item.data('search') || '').toString(),
                status: ($item.data('status') || '').toString(),
                category: ($item.data('category') || '').toString(),
                language: ($item.data('language') || '').toString()
            });

            $item.toggleClass('d-none', !isMatch);
            if (isMatch) {
                visibleCount++;
            }
        });

        return visibleCount;
    }

    function applyGridFilters(filters) {
        if (!templateTable) {
            return applyCardFilters(filters);
        }

        $.fn.dataTable.ext.search = $.fn.dataTable.ext.search || [];
        $.fn.dataTable.ext.search = $.grep($.fn.dataTable.ext.search, function (fn) {
            return !fn._templatesFilter;
        });

        var tableFilter = function (settings, data, dataIndex) {
            if (settings.nTable !== $('#templatesTable').get(0)) {
                return true;
            }

            var rowNode = templateTable.row(dataIndex).node();
            var $row = $(rowNode);

            return matchesFilters(filters, {
                search: ($row.data('search') || '').toString(),
                status: ($row.data('status') || '').toString(),
                category: ($row.data('category') || '').toString(),
                language: ($row.data('language') || '').toString()
            });
        };
        tableFilter._templatesFilter = true;

        $.fn.dataTable.ext.search.push(tableFilter);
        templateTable.draw();

        return templateTable.rows({ filter: 'applied' }).count();
    }

    function setView(view) {
        activeView = view === 'grid' ? 'grid' : 'card';
        localStorage.setItem('templatesViewMode', activeView);

        $('.template-view-toggle').each(function () {
            var $btn = $(this);
            var isActive = $btn.data('view') === activeView;
            $btn.toggleClass('active btn-wa', isActive);
            $btn.toggleClass('btn-outline-secondary', !isActive);
        });

        $cardView.toggleClass('d-none', activeView !== 'card');
        $gridView.toggleClass('d-none', activeView !== 'grid');
    }

    function applyFilters() {
        var filters = getFilters();
        var visibleCount = applyCardFilters(filters);

        if (templateTable) {
            applyGridFilters(filters);
            if (activeView === 'grid') {
                visibleCount = templateTable.rows({ filter: 'applied' }).count();
            }
        }

        renderFilterChips(filters);
        updateVisibleState(visibleCount);
    }

    function resetFilters() {
        $('#templateSearch').val('');
        $('#templateStatusFilter').val('');
        $('#templateCategoryFilter').val('');
        $('#templateLanguageFilter').val('');
        applyFilters();
    }

    $('.template-view-toggle').on('click', function () {
        setView($(this).data('view'));
        applyFilters();
    });

    $('#templateSearch').on('input', applyFilters);
    $('#templateStatusFilter, #templateCategoryFilter, #templateLanguageFilter').on('change', applyFilters);
    $('#templateResetFilters, #templateResetEmptyState').on('click', resetFilters);

    setView(activeView);
    applyFilters();

    // Auto-sync from Meta/Cheerio every time this screen opens.
    (function autoSyncOnOpen() {
        var $root = $('#templatesPageRoot');
        if ($root.data('auto-sync') != 1 && $root.data('auto-sync') !== '1') {
            return;
        }

        // After a reload triggered by sync, skip one pass to avoid a loop.
        var skipKey = 'templates_skip_auto_sync_once';
        if (sessionStorage.getItem(skipKey) === '1') {
            sessionStorage.removeItem(skipKey);
            return;
        }

        var $btn = $('#btnSyncTemplates');
        var originalHtml = $btn.length ? $btn.html() : '';
        if ($btn.length) {
            $btn.prop('disabled', true)
                .html('<i class="fas fa-sync fa-spin me-1"></i> Syncing…');
        }

        var csrf = $('meta[name="csrf-token"]').attr('content') || '';
        $.ajax({
            url: APP.baseUrl + '/templates/sync',
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest' },
            dataType: 'json',
            timeout: 60000
        }).done(function (res) {
            if (!(res && res.success)) {
                if (window.APP && APP.toast) {
                    APP.toast((res && res.message) || 'Template sync failed', 'error');
                }
                return;
            }

            var d = res.data || {};
            var changed = (parseInt(d.inserted || 0, 10) > 0)
                || (parseInt(d.updated || 0, 10) > 0)
                || (parseInt(d.disabled || 0, 10) > 0)
                || (parseInt(d.synced || 0, 10) > 0);

            if (changed) {
                sessionStorage.setItem(skipKey, '1');
                if (window.APP && APP.toast) {
                    APP.toast(res.message || 'Templates synced', 'success');
                }
                location.reload();
                return;
            }

            if (window.APP && APP.toast) {
                APP.toast(res.message || 'Templates already up to date', 'success');
            }
        }).fail(function (xhr) {
            var msg = (xhr.responseJSON && xhr.responseJSON.message)
                || 'Could not auto-sync templates';
            if (window.APP && APP.toast) {
                APP.toast(msg, 'error');
            }
        }).always(function () {
            if ($btn.length && !sessionStorage.getItem(skipKey)) {
                $btn.prop('disabled', false).html(originalHtml);
            }
        });
    })();

    $('.btn-preview-tpl').on('click', function () {
        var $b = $(this);
        $('#tplPreviewTitle').text($b.data('name'));
        $('#tplPreviewHeader').html(
            APP.templateHeaderPreviewHtml($b.data('header-type'), $b.data('header'))
        );
        $('#tplPreviewBody').text($b.data('body') || '');
        $('#tplPreviewFooter').text($b.data('footer') || '');
        if (window.APP && typeof APP.showModal === 'function') {
            APP.showModal('#tplPreviewModal');
        } else if (window.bootstrap) {
            bootstrap.Modal.getOrCreateInstance(document.getElementById('tplPreviewModal')).show();
        }
    });

    function showSendTestModal(defs, meta) {
        $('#tplSendTestId').val(meta.id);
        $('#tplSendTestName').text(meta.name + ' (' + (meta.language || '') + ')');
        $('#tplSendTestError').addClass('d-none').text('');
        var $vars = $('#tplSendTestVars').empty();
        (defs || []).forEach(function (def) {
            var key = def.key || '';
            var label = def.label || ('Variable {{' + key + '}}');
            var example = def.example || '';
            $vars.append(
                '<div class="mb-2">' +
                '<label class="form-label">' + $('<div>').text(label).html() + '</label>' +
                '<input type="text" class="form-control tpl-send-var" data-key="' + $('<div>').text(key).html() + '" placeholder="' + $('<div>').text(example || ('{{' + key + '}}')).html() + '">' +
                '</div>'
            );
        });
        if (window.APP && typeof APP.showModal === 'function') {
            APP.showModal('#tplSendTestModal');
        } else if (window.bootstrap) {
            bootstrap.Modal.getOrCreateInstance(document.getElementById('tplSendTestModal')).show();
        }
    }

    $(document).on('click', '.btn-send-test-tpl', function () {
        var $b = $(this);
        var status = ($b.data('status') || '').toString().toUpperCase();
        if (status && status !== 'APPROVED') {
            if (window.APP && APP.toast) {
                APP.toast('Only APPROVED templates can be sent. Current status: ' + status, 'error');
            } else {
                alert('Only APPROVED templates can be sent.');
            }
            return;
        }
        var id = $b.data('id');
        $.getJSON(APP.baseUrl + '/templates/' + id + '/preview')
            .done(function (res) {
                var defs = (res && res.data && res.data.variable_definitions) ? res.data.variable_definitions : [];
                defs = defs.map(function (d) {
                    if (!d.label) {
                        d.label = 'Variable {{' + (d.key || '') + '}}';
                    }
                    return d;
                });
                showSendTestModal(defs, {
                    id: id,
                    name: $b.data('name'),
                    language: $b.data('language')
                });
            })
            .fail(function () {
                showSendTestModal([], {
                    id: id,
                    name: $b.data('name'),
                    language: $b.data('language')
                });
            });
    });

    $('#tplSendTestSubmit').on('click', function () {
        var id = $('#tplSendTestId').val();
        var to = ($('#tplSendTestTo').val() || '').toString().trim();
        var vars = {};
        $('.tpl-send-var').each(function () {
            vars[$(this).data('key')] = ($(this).val() || '').toString().trim();
        });
        var $err = $('#tplSendTestError').addClass('d-none');
        if (!to) {
            $err.removeClass('d-none').text('Recipient phone number is required.');
            return;
        }
        var csrf = $('meta[name="csrf-token"]').attr('content') || '';
        var $btn = $(this).prop('disabled', true);
        $.ajax({
            url: APP.baseUrl + '/templates/' + id + '/send-test',
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest' },
            contentType: 'application/json',
            dataType: 'json',
            data: JSON.stringify({ to: to, variables: vars })
        }).done(function (res) {
            if (res && res.success) {
                if (window.APP && APP.toast) {
                    APP.toast(res.message || 'Test sent', 'success');
                }
                if (window.bootstrap) {
                    bootstrap.Modal.getOrCreateInstance(document.getElementById('tplSendTestModal')).hide();
                }
            } else {
                $err.removeClass('d-none').text((res && res.message) || 'Send failed');
            }
        }).fail(function (xhr) {
            var msg = (xhr.responseJSON && xhr.responseJSON.message) || 'Send failed';
            $err.removeClass('d-none').text(msg);
        }).always(function () {
            $btn.prop('disabled', false);
        });
    });
});
</script>
<?= $this->endSection() ?>
