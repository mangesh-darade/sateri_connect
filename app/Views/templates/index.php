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
<div class="page-list">

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
                        <?php if (! empty($tpl['synced_at'])): ?>
                            <div class="small text-muted">Synced <?= esc(format_app_datetime($tpl['synced_at'] ?? null)) ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="panel-body border-top pt-2 d-flex gap-2" style="border-color:var(--border)!important">
                        <button type="button" class="btn btn-sm btn-outline-secondary btn-preview-tpl"
                            data-id="<?= (int) $tpl['id'] ?>"
                            data-name="<?= esc($tpl['name'], 'attr') ?>"
                            data-body="<?= esc($tpl['body'] ?? '', 'attr') ?>"
                            data-footer="<?= esc($tpl['footer'] ?? '', 'attr') ?>"
                            data-header="<?= esc($tpl['header_content'] ?? '', 'attr') ?>">
                            <i class="fas fa-eye"></i> Preview
                        </button>
                        <a href="<?= site_url('templates/' . (int) $tpl['id']) ?>" class="btn btn-sm btn-wa">Details</a>
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
                        <tr><th>Name</th><th>Language</th><th>Category</th><th>Status</th><th>Synced</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($templates as $tpl): ?>
                            <?php
                            $status = $normalizeStatus((string) ($tpl['status'] ?? ''));
                            $category = strtolower(trim((string) ($tpl['category'] ?? '')));
                            $language = strtolower(trim((string) ($tpl['language'] ?? '')));
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
                                <td class="text-muted small text-nowrap"><?= esc(format_app_datetime($tpl['synced_at'] ?? null, 'd-M-Y, g:i A', '')) ?></td>
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
        <div class="dash-panel">
            <div class="activity-empty">
                <i class="fas fa-file-alt"></i>
                No templates yet — create one or sync from the active provider.
                <div class="mt-3 d-flex flex-wrap gap-2 justify-content-center">
                    <?php if (function_exists('can') && can('templates.create')): ?>
                        <a href="<?= site_url('templates/create') ?>" class="btn btn-wa btn-sm">Create template</a>
                    <?php endif; ?>
                    <?php if (function_exists('can') && can('templates.sync')): ?>
                        <form action="<?= site_url('templates/sync') ?>" method="post" class="d-inline"><?= csrf_field() ?>
                            <button type="submit" class="btn btn-outline-secondary btn-sm">Sync templates</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
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
                    <div class="fw-semibold" id="tplPreviewHeader"></div>
                    <div id="tplPreviewBody" style="white-space:pre-wrap"></div>
                    <div class="small text-muted" id="tplPreviewFooter"></div>
                </div>
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

    $('.btn-preview-tpl').on('click', function () {
        var $b = $(this);
        $('#tplPreviewTitle').text($b.data('name'));
        $('#tplPreviewHeader').text($b.data('header') || '');
        $('#tplPreviewBody').text($b.data('body') || '');
        $('#tplPreviewFooter').text($b.data('footer') || '');
        if (window.APP && typeof APP.showModal === 'function') {
            APP.showModal('#tplPreviewModal');
        } else if (window.bootstrap) {
            bootstrap.Modal.getOrCreateInstance(document.getElementById('tplPreviewModal')).show();
        }
    });
});
</script>
<?= $this->endSection() ?>
