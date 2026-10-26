<?= $this->extend('layouts/main') ?>

<?= $this->section('header_actions') ?>
<?php if (function_exists('can') && can('sequences.create')): ?>
    <a href="<?= site_url('sequences/create') ?>" class="btn btn-wa btn-sm"><i class="fas fa-plus me-1"></i> New sequence</a>
<?php endif; ?>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<div class="page-list">
<div class="card">
    <div class="card-header d-flex align-items-center justify-content-between gap-2">
        <h2 class="card-title mb-0">Sequences</h2>
        <span class="small text-muted">Multi-step drips · exit on reply</span>
    </div>
    <div class="card-body py-3">
        <?php if (! empty($hasSequences)): ?>
        <table class="table table-sm table-hover align-middle w-100" id="sequencesTable">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Steps</th>
                    <th>Active enrollments</th>
                    <th>Status</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody></tbody>
        </table>
        <?php else: ?>
            <div class="page-hint">No sequences yet. Create a multi-step drip (text or template) and enroll contacts.</div>
        <?php endif; ?>
    </div>
</div>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
$(function () {
    var esc = AppDT.esc;
    AppDT.table('#sequencesTable', {
        url: <?= json_encode(site_url('sequences/data')) ?>,
        order: [[0, 'asc']],
        emptyText: 'No sequences found',
        columns: [
            {data: null, className: 'fw-semibold', render: function (r, t) { return t === 'display' ? esc(r.name) : r.name; }},
            {data: 'steps'},
            {data: 'enrollments'},
            {data: null, render: function (r, t) { return t === 'display' ? (r.active ? 'On' : 'Off') : (r.active ? 1 : 0); }},
            {data: null, orderable: false, className: 'text-end', render: function (r) {
                var h = '<div class="table-actions justify-content-end">';
                if (r.edit_url) h += '<a href="' + esc(r.edit_url) + '" class="btn btn-sm btn-outline-secondary" title="Edit"><i class="fas fa-edit"></i></a>';
                if (r.delete_url) h += '<button type="button" class="btn btn-sm btn-outline-danger" title="Delete" data-confirm-delete data-url="' + esc(r.delete_url) + '" data-title="Delete sequence?" data-text="This cannot be undone."><i class="fas fa-trash"></i></button>';
                return h + '</div>';
            }}
        ]
    });
});
</script>
<?= $this->endSection() ?>
