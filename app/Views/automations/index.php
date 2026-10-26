<?= $this->extend('layouts/main') ?>

<?= $this->section('header_actions') ?>
<?php if (function_exists('can') && can('automations.create')): ?>
    <a href="<?= site_url('automations/create') ?>" class="btn btn-wa btn-sm"><i class="fas fa-plus me-1"></i> New workflow</a>
<?php endif; ?>
<?php if (function_exists('can') && (can('automations.create') || can('automations.edit'))): ?>
    <form action="<?= site_url('automations/sync-cheerio') ?>" method="post" class="d-inline" id="formSyncCheerioWorkflows">
        <?= csrf_field() ?>
        <button type="submit" class="btn btn-outline-secondary btn-sm" id="btnSyncCheerioWorkflows"
                title="Sync workflows for the active WhatsApp provider">
            <i class="fas fa-cloud-download-alt me-1"></i> <?= esc(function_exists('whatsapp_sync_label') ? whatsapp_sync_label() : 'Sync workflows') ?>
        </button>
    </form>
<?php endif; ?>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<div class="page-list">
    <!-- Workflow Tabs: Visual Automations & Automated Journeys (Drips) -->
    <ul class="nav nav-pills mb-3 bg-white p-1 rounded-3 border shadow-sm d-flex flex-nowrap overflow-x-auto" role="tablist">
        <li class="nav-item">
            <a class="nav-link py-2 px-3 text-nowrap fw-medium active" href="<?= site_url('automations') ?>">
                <i class="fab fa-whatsapp me-1 text-success"></i> WhatsApp Workflows
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link py-2 px-3 text-nowrap fw-medium text-secondary" href="<?= site_url('automations?channel=email') ?>">
                <i class="fas fa-envelope me-1 text-primary"></i> Email Workflows (Auto Drips)
            </a>
        </li>
        <?php if (function_exists('can') && can('sequences.view')): ?>
        <li class="nav-item">
            <a class="nav-link py-2 px-3 text-nowrap fw-medium text-secondary" href="<?= site_url('sequences') ?>">
                <i class="fas fa-list-ol me-1 text-info"></i> Sequences
            </a>
        </li>
        <?php endif; ?>
    </ul>

<div class="card">
    <div class="card-header d-flex align-items-center justify-content-between gap-2">
        <h2 class="card-title mb-0">Workflows</h2>
        <span class="small text-muted">Trigger → condition → action</span>
    </div>
    <div class="card-body py-3">
        <table class="table table-sm table-hover align-middle w-100" id="automationsTable">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Trigger</th>
                    <th>Priority</th>
                    <th>Active</th>
                    <th>Updated</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody></tbody>
        </table>
    </div>
</div>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
$(function () {
    var perm = {
        edit: <?= (function_exists('can') && can('automations.edit')) ? 'true' : 'false' ?>,
        create: <?= (function_exists('can') && can('automations.create')) ? 'true' : 'false' ?>,
        del: <?= (function_exists('can') && can('automations.delete')) ? 'true' : 'false' ?>
    };
    var csrfName = <?= json_encode(csrf_token()) ?>;
    var csrfHash = <?= json_encode(csrf_hash()) ?>;
    var base = <?= json_encode(rtrim(site_url('automations'), '/')) ?>;

    function csrfField() {
        return '<input type="hidden" name="' + AppDT.esc(csrfName) + '" value="' + AppDT.esc(csrfHash) + '">';
    }

    AppDT.table('#automationsTable', {
        url: <?= json_encode(site_url('automations/data')) ?>,
        order: [[2, 'asc']],
        emptyText: 'No automations yet. Create a workflow to get started.',
        columns: [
            { data: null, render: function (r) {
                var h = '<div class="fw-semibold">' + AppDT.esc(r.name) + '</div>';
                if (r.trigger_type === 'cheerio_workflow') {
                    h += '<small class="text-muted"><i class="fas fa-cloud"></i> Synced from External</small>';
                } else if (r.has_graph) {
                    h += '<small class="text-wa"><i class="fas fa-project-diagram"></i> Visual workflow</small>';
                }
                return h;
            } },
            { data: null, render: function (r) { return '<code>' + AppDT.esc(r.trigger_type) + '</code>'; } },
            { data: null, render: function (r) { return AppDT.esc(r.priority); } },
            { data: null, render: function (r) {
                var on = !!r.is_active;
                if (!perm.edit) return on ? 'On' : 'Off';
                return '<form action="' + AppDT.esc(base + '/' + r.id + '/toggle') + '" method="post" class="d-inline">' + csrfField() +
                    '<button type="submit" class="btn btn-sm ' + (on ? 'btn-wa' : 'btn-outline-secondary') + '">' + (on ? 'On' : 'Off') + '</button></form>';
            } },
            { data: null, className: 'text-muted small text-nowrap', render: function (r) { return AppDT.esc(r.updated_at); } },
            { data: null, orderable: false, className: 'text-end', render: function (r) {
                var h = '<div class="table-actions justify-content-end">';
                if (perm.edit) {
                    h += '<a href="' + AppDT.esc(base + '/' + r.id + '/edit') + '" class="btn btn-sm btn-outline-secondary" title="Edit"><i class="fas fa-edit"></i></a> ' +
                        '<a href="' + AppDT.esc(base + '/' + r.id + '/builder') + '" class="btn btn-sm btn-outline-secondary" title="Builder"><i class="fas fa-project-diagram"></i></a> ';
                }
                if (perm.create) {
                    h += '<form action="' + AppDT.esc(base + '/' + r.id + '/duplicate') + '" method="post" class="d-inline">' + csrfField() +
                        '<button type="submit" class="btn btn-sm btn-outline-secondary" title="Duplicate"><i class="fas fa-copy"></i></button></form> ';
                }
                if (perm.del) {
                    h += '<button type="button" class="btn btn-sm btn-outline-danger" data-confirm-delete data-url="' + AppDT.esc(base + '/' + r.id + '/delete') + '" title="Delete"><i class="fas fa-trash"></i></button>';
                }
                return h + '</div>';
            } }
        ]
    });

    $('#formSyncCheerioWorkflows').on('submit', function () {
        var $btn = $('#btnSyncCheerioWorkflows').prop('disabled', true);
        $btn.html('<i class="fas fa-spinner fa-spin me-1"></i> Syncing…');
    });
});
</script>
<?= $this->endSection() ?>
