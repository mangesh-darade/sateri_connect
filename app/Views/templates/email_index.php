<?= $this->extend('layouts/main') ?>

<?= $this->section('header_actions') ?>
<?php if (! empty($canCreate)): ?>
    <button type="button" class="btn btn-wa btn-sm" id="btnCreateEmailTemplate">
        <i class="fas fa-plus me-1"></i> Create Email Template
    </button>
<?php endif; ?>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php
$templates    = $templates ?? [];
$filterSearch = (string) ($filterSearch ?? '');
$filterStatus = (string) ($filterStatus ?? '');
$statusCounts = $statusCounts ?? ['total' => 0, 'active' => 0, 'draft' => 0, 'archived' => 0];
?>
<div class="page-list" id="emailTemplatesPageRoot">

    <!-- Channel Tabs: WhatsApp & Email Templates -->
    <ul class="nav nav-pills mb-3 bg-white p-1 rounded-3 border shadow-sm d-flex flex-nowrap overflow-x-auto" role="tablist">
        <li class="nav-item">
            <a class="nav-link py-2 px-3 text-nowrap fw-medium text-secondary" href="<?= site_url('templates') ?>">
                <i class="fab fa-whatsapp me-1 text-success"></i> WhatsApp Templates
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link py-2 px-3 text-nowrap fw-medium active" href="<?= site_url('templates?channel=email') ?>">
                <i class="fas fa-envelope me-1 text-primary"></i> Email Templates
            </a>
        </li>
    </ul>

    <!-- KPI Summary Cards -->
    <div class="row g-2 mb-3">
        <div class="col-6 col-md-3">
            <div class="kpi-card kpi-accent-sky" role="button" onclick="location.href='<?= site_url('templates?channel=email') ?>'" style="cursor:pointer;">
                <span class="kpi-icon"><i class="fas fa-envelope-open-text"></i></span>
                <span class="kpi-label">Total Templates</span>
                <span class="kpi-value"><?= (int) ($statusCounts['total'] ?? 0) ?></span>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="kpi-card kpi-accent-green" role="button" onclick="location.href='<?= site_url('templates?channel=email&status=active') ?>'" style="cursor:pointer;">
                <span class="kpi-icon"><i class="fas fa-check-circle"></i></span>
                <span class="kpi-label">Active (Ready)</span>
                <span class="kpi-value"><?= (int) ($statusCounts['active'] ?? 0) ?></span>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="kpi-card kpi-accent-amber" role="button" onclick="location.href='<?= site_url('templates?channel=email&status=draft') ?>'" style="cursor:pointer;">
                <span class="kpi-icon"><i class="fas fa-pencil-ruler"></i></span>
                <span class="kpi-label">Drafts</span>
                <span class="kpi-value"><?= (int) ($statusCounts['draft'] ?? 0) ?></span>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="kpi-card kpi-accent-ink" role="button" onclick="location.href='<?= site_url('templates?channel=email&status=archived') ?>'" style="cursor:pointer;">
                <span class="kpi-icon"><i class="fas fa-archive"></i></span>
                <span class="kpi-label">Archived</span>
                <span class="kpi-value"><?= (int) ($statusCounts['archived'] ?? 0) ?></span>
            </div>
        </div>
    </div>

    <!-- Search & Filter Bar -->
    <div class="card mb-3">
        <div class="card-body py-2">
            <form method="get" action="<?= site_url('templates') ?>" class="filter-bar mb-0">
                <input type="hidden" name="channel" value="email">
                <input type="search" name="q" value="<?= esc($filterSearch) ?>" class="form-control form-control-sm" placeholder="Search templates by name or subject..." style="min-width:240px;">
                <select name="status" class="form-select form-select-sm" style="max-width:180px;">
                    <option value="">All Statuses</option>
                    <option value="active" <?= $filterStatus === 'active' ? 'selected' : '' ?>>Active</option>
                    <option value="draft" <?= $filterStatus === 'draft' ? 'selected' : '' ?>>Draft</option>
                    <option value="archived" <?= $filterStatus === 'archived' ? 'selected' : '' ?>>Archived</option>
                </select>
                <div class="filter-bar-actions">
                    <button type="submit" class="btn btn-wa btn-sm"><i class="fas fa-filter me-1"></i> Filter</button>
                    <?php if ($filterSearch !== '' || $filterStatus !== ''): ?>
                        <a href="<?= site_url('templates?channel=email') ?>" class="btn btn-link btn-sm px-1">Reset</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <!-- Templates Table / Grid -->
    <div class="card border-0 shadow-sm rounded-3">
        <div class="card-header bg-white py-3 border-bottom d-flex align-items-center justify-content-between">
            <div>
                <h6 class="mb-0 fw-bold text-dark"><i class="fas fa-layer-group text-primary me-2"></i>Email Template Library</h6>
                <small class="text-muted">Reusable HTML &amp; Rich-Text layouts for broadcast campaigns and auto-drip workflows.</small>
            </div>
            <span class="badge bg-secondary-subtle text-secondary"><?= count($templates) ?> templates</span>
        </div>
        <div class="card-body p-0">
            <?php if (empty($templates)): ?>
                <div class="text-center py-5 text-muted">
                    <div class="mb-3 text-secondary opacity-50">
                        <i class="fas fa-envelope-open-text fa-3x"></i>
                    </div>
                    <h6 class="fw-semibold text-dark mb-1">No email templates found</h6>
                    <p class="small text-muted mb-3">Create your first reusable HTML layout for campaigns and drips.</p>
                    <?php if (! empty($canCreate)): ?>
                        <button type="button" class="btn btn-wa btn-sm" id="btnEmptyCreateTemplate">
                            <i class="fas fa-plus me-1"></i> Create First Template
                        </button>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0" id="emailTemplatesTable">
                        <thead class="table-light">
                            <tr>
                                <th style="width: 32%;">Template Name</th>
                                <th style="width: 30%;">Default Subject Line</th>
                                <th style="width: 14%;">Status</th>
                                <th style="width: 12%;">Updated</th>
                                <th style="width: 12%;" class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($templates as $t): ?>
                                <?php
                                $tId      = (int) ($t['id'] ?? 0);
                                $tName    = (string) ($t['name'] ?? 'Untitled');
                                $tSubject = (string) ($t['subject'] ?? '—');
                                $tStatus  = strtolower((string) ($t['status'] ?? 'draft'));
                                $tHtml    = (string) ($t['html_content'] ?? '');
                                $tCheerio = (string) ($t['cheerio_builder_id'] ?? '');
                                $tUpdated = (string) ($t['updated_at'] ?? $t['created_at'] ?? '');
                                ?>
                                <tr data-id="<?= $tId ?>">
                                    <td>
                                        <div class="d-flex align-items-center gap-2">
                                            <div class="rounded-2 bg-primary-subtle text-primary p-2 d-flex align-items-center justify-content-center" style="width:36px; height:36px;">
                                                <i class="fas fa-file-code"></i>
                                            </div>
                                            <div>
                                                <div class="fw-semibold text-dark"><?= esc($tName) ?></div>
                                                <div class="small text-muted">ID: #<?= $tId ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="text-truncate" style="max-width: 280px;" title="<?= esc($tSubject) ?>">
                                            <?= esc($tSubject ?: '—') ?>
                                        </div>
                                    </td>
                                    <td>
                                        <?= view('partials/status_badge', [
                                            'status' => $tStatus,
                                            'map'    => [
                                                'active'   => 'success',
                                                'draft'    => 'warning',
                                                'archived' => 'secondary',
                                            ],
                                        ]) ?>
                                    </td>
                                    <td class="small text-muted text-nowrap">
                                        <?= esc(format_app_datetime($tUpdated ?: null, 'M j, Y', '—')) ?>
                                    </td>
                                    <td class="text-end">
                                        <div class="table-actions justify-content-end gap-1">
                                            <button type="button" class="btn btn-sm btn-icon-action js-preview-email-template"
                                                    data-id="<?= $tId ?>"
                                                    data-name="<?= esc($tName) ?>"
                                                    data-subject="<?= esc($tSubject) ?>"
                                                    data-html="<?= esc($tHtml) ?>"
                                                    title="Preview Email">
                                                <i class="fas fa-eye text-info"></i>
                                            </button>
                                            <?php if (! empty($canCreate)): ?>
                                                <button type="button" class="btn btn-sm btn-icon-action js-edit-email-template"
                                                        data-id="<?= $tId ?>"
                                                        data-name="<?= esc($tName) ?>"
                                                        data-subject="<?= esc($tSubject) ?>"
                                                        data-status="<?= esc($tStatus) ?>"
                                                        data-cheerio="<?= esc($tCheerio) ?>"
                                                        data-html="<?= esc($tHtml) ?>"
                                                        title="Edit Template">
                                                    <i class="fas fa-pen text-primary"></i>
                                                </button>
                                                <button type="button" class="btn btn-sm btn-icon-action js-clone-email-template"
                                                        data-name="<?= esc($tName) ?> (Copy)"
                                                        data-subject="<?= esc($tSubject) ?>"
                                                        data-html="<?= esc($tHtml) ?>"
                                                        title="Duplicate Template">
                                                    <i class="fas fa-copy text-secondary"></i>
                                                </button>
                                                <button type="button" class="btn btn-sm btn-icon-action js-delete-email-template"
                                                        data-id="<?= $tId ?>"
                                                        data-name="<?= esc($tName) ?>"
                                                        title="Delete Template">
                                                    <i class="fas fa-trash text-danger"></i>
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Preview Modal -->
<div class="modal fade" id="previewEmailTemplateModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header border-bottom py-2 bg-light">
                <div>
                    <h6 class="modal-title fw-bold" id="previewModalTitle">Template Preview</h6>
                    <small class="text-muted" id="previewModalSubject"></small>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-0">
                <iframe id="previewEmailIframe" sandbox="" referrerpolicy="no-referrer" style="width:100%; height:450px; border:0; background:#fff;"></iframe>
            </div>
            <div class="modal-footer py-2">
                <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Create / Edit Email Template Modal -->
<div class="modal fade" id="editEmailTemplateModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <form id="emailTemplateModalForm">
                <?= csrf_field() ?>
                <input type="hidden" name="id" id="tpl_id" value="">
                <div class="modal-header border-bottom py-2 bg-light">
                    <h6 class="modal-title fw-bold" id="tplModalTitle"><i class="fas fa-paint-brush text-primary me-2"></i>Email Template Designer</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-3">
                    <div class="row g-2 mb-2">
                        <div class="col-md-7">
                            <label class="form-label small fw-semibold mb-1">Template Name <span class="text-danger">*</span></label>
                            <input type="text" name="name" id="tpl_name" class="form-control form-control-sm" placeholder="e.g. Welcome Onboarding Email" required maxlength="191">
                        </div>
                        <div class="col-md-5">
                            <label class="form-label small fw-semibold mb-1">Status</label>
                            <select name="status" id="tpl_status" class="form-select form-select-sm">
                                <option value="active">Active (Ready for Campaigns)</option>
                                <option value="draft">Draft (Work in progress)</option>
                                <option value="archived">Archived</option>
                            </select>
                        </div>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small fw-semibold mb-1">Default Subject Line</label>
                        <input type="text" name="subject" id="tpl_subject" class="form-control form-control-sm" placeholder="e.g. Welcome to Our Family! {{name}}" maxlength="255">
                    </div>
                    <div class="mb-2">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <label class="form-label small fw-semibold mb-0">Email Content <span class="text-danger">*</span></label>
                            <div class="small">
                                <span class="text-muted me-1">Insert tags:</span>
                                <button type="button" class="badge bg-light text-primary border js-insert-tag" data-tag="{{name}}">{{name}}</button>
                                <button type="button" class="badge bg-light text-primary border js-insert-tag" data-tag="{{email}}">{{email}}</button>
                                <button type="button" class="badge bg-light text-primary border js-insert-tag" data-tag="{{mobile}}">{{mobile}}</button>
                            </div>
                        </div>
                        <textarea name="html_content" id="tpl_html" class="form-control form-control-sm font-monospace" rows="12" placeholder="<div style='font-family:sans-serif;'>&#10;  <h2>Hello {{name}},</h2>&#10;  <p>Thank you for choosing us.</p>&#10;</div>" required data-email-editor data-editor-height="300"></textarea>
                    </div>
                    <div class="alert alert-danger py-2 small d-none mb-0" id="tplModalError"></div>
                </div>
                <div class="modal-footer py-2">
                    <button type="button" class="btn btn-sm btn-light border" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-wa btn-sm px-3" id="btnSaveEmailTpl">
                        <i class="fas fa-save me-1"></i> Save Template
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<?= $this->include('partials/email_editor_assets') ?>
<script>
$(function () {
    var editModal = new bootstrap.Modal(document.getElementById('editEmailTemplateModal'));
    var prevModal = new bootstrap.Modal(document.getElementById('previewEmailTemplateModal'));

    // Open Create Modal
    $('#btnCreateEmailTemplate, #btnEmptyCreateTemplate').on('click', function () {
        $('#tplModalTitle').html('<i class="fas fa-plus-circle text-primary me-2"></i>Create Email Template');
        $('#tpl_id').val('');
        $('#tpl_name').val('');
        $('#tpl_subject').val('');
        $('#tpl_status').val('active');
        $('#tpl_html').val("<div style=\"font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #e2e8f0; border-radius: 8px;\">\n  <h2 style=\"color: #1e293b;\">Hello {{name}},</h2>\n  <p style=\"color: #475569; font-size: 15px; line-height: 1.6;\">Thank you for being with us. We are excited to have you on board!</p>\n  <hr style=\"border: none; border-top: 1px solid #e2e8f0; margin: 20px 0;\">\n  <p style=\"color: #94a3b8; font-size: 12px;\">If you have any questions, reply to this email.</p>\n</div>");
        $('#tplModalError').addClass('d-none').text('');
        editModal.show();
    });

    // Open Edit Modal
    $(document).on('click', '.js-edit-email-template', function () {
        var $btn = $(this);
        $('#tplModalTitle').html('<i class="fas fa-pen text-primary me-2"></i>Edit Email Template #' + $btn.data('id'));
        $('#tpl_id').val($btn.data('id'));
        $('#tpl_name').val($btn.data('name'));
        $('#tpl_subject').val($btn.data('subject'));
        $('#tpl_status').val($btn.data('status') || 'active');
        $('#tpl_html').val($btn.data('html') || '');
        $('#tplModalError').addClass('d-none').text('');
        editModal.show();
    });

    // Duplicate Template
    $(document).on('click', '.js-clone-email-template', function () {
        var $btn = $(this);
        $('#tplModalTitle').html('<i class="fas fa-copy text-primary me-2"></i>Duplicate Template');
        $('#tpl_id').val('');
        $('#tpl_name').val($btn.data('name'));
        $('#tpl_subject').val($btn.data('subject'));
        $('#tpl_status').val('draft');
        $('#tpl_html').val($btn.data('html') || '');
        $('#tplModalError').addClass('d-none').text('');
        editModal.show();
    });

    // Tag Insertion
    $(document).on('click', '.js-insert-tag', function () {
        APP.emailEditor.insertText(document.getElementById('tpl_html'), $(this).data('tag'));
    });

    // Submit Form
    $('#emailTemplateModalForm').on('submit', function (e) {
        e.preventDefault();
        var $btn = $('#btnSaveEmailTpl');
        var origHtml = $btn.html();
        $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin me-1"></i> Saving...');
        $('#tplModalError').addClass('d-none').text('');

        var payload = {
            id: $('#tpl_id').val(),
            name: $('#tpl_name').val(),
            subject: $('#tpl_subject').val(),
            status: $('#tpl_status').val(),
            html_content: $('#tpl_html').val()
        };

        APP.post(APP.baseUrl + '/email-manager/builders', payload).done(function (res) {
            APP.toast(res.message || 'Template saved successfully', 'success');
            editModal.hide();
            setTimeout(function () { window.location.reload(); }, 600);
        }).fail(function (xhr) {
            var msg = (xhr.responseJSON && xhr.responseJSON.message) || 'Failed to save template';
            $('#tplModalError').removeClass('d-none').text(msg);
            $btn.prop('disabled', false).html(origHtml);
        });
    });

    // Preview
    $(document).on('click', '.js-preview-email-template', function () {
        var name = $(this).data('name');
        var subj = $(this).data('subject');
        var html = $(this).data('html') || '<p class="text-muted p-4">No HTML content in this template.</p>';

        $('#previewModalTitle').text(name);
        $('#previewModalSubject').text('Subject: ' + (subj || '(No Subject)'));

        document.getElementById('previewEmailIframe').srcdoc = String(html);

        prevModal.show();
    });

    // Delete
    $(document).on('click', '.js-delete-email-template', function () {
        var id = $(this).data('id');
        var name = $(this).data('name');
        APP.confirm({
            title: 'Delete Template?',
            text: 'Are you sure you want to delete "' + name + '"? This action cannot be undone.',
            confirmText: 'Yes, Delete',
            confirmColor: '#dc2626'
        }).then(function (result) {
            if (!result.isConfirmed) return;
            APP.post(APP.baseUrl + '/email-manager/builders/' + id + '/delete', {}).done(function (res) {
                APP.toast(res.message || 'Template deleted', 'success');
                setTimeout(function () { window.location.reload(); }, 600);
            }).fail(function (xhr) {
                APP.toast((xhr.responseJSON && xhr.responseJSON.message) || 'Delete failed', 'error');
            });
        });
    });
});
</script>
<?= $this->endSection() ?>
