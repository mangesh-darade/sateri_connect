<?= $this->extend('layouts/main') ?>

<?= $this->section('header_actions') ?>
<?php
$seats = $seats ?? [];
$canAddUser = ! empty($seats['can_add_user']);
$maxUsers = $seats['max_users'] ?? null;
?>
<?php if (function_exists('can') && can('users.create') && $canAddUser): ?>
    <a href="<?= site_url('users/create') ?>" class="btn btn-wa btn-sm"><i class="fas fa-plus me-1"></i> Add user</a>
<?php endif; ?>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<div class="page-list">

<div class="card">
    <div class="card-header d-flex align-items-center justify-content-between gap-2">
        <h2 class="card-title mb-0">Team users</h2>
        <span class="small text-muted">
            <?= (int) ($seats['users'] ?? 0) ?>
            <?php if ($maxUsers !== null): ?>
                of <?= (int) $maxUsers ?>
            <?php endif; ?>
            users
            <?php if (! $canAddUser): ?>
                · limit reached
            <?php endif; ?>
        </span>
    </div>
    <div class="card-body py-3">
        <table class="table table-sm table-hover align-middle w-100" id="usersTable">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Phone</th>
                    <th>Role</th>
                    <th>Status</th>
                    <th>Last Login</th>
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
    var canEdit = <?= (function_exists('can') && can('users.edit')) ? 'true' : 'false' ?>;
    var canDelete = <?= (function_exists('can') && can('users.delete')) ? 'true' : 'false' ?>;
    var base = <?= json_encode(rtrim(site_url('users'), '/')) ?>;
    var colors = { active: 'success', inactive: 'secondary', blocked: 'danger' };

    AppDT.table('#usersTable', {
        url: <?= json_encode(site_url('users/data')) ?>,
        order: [[0, 'asc']],
        emptyText: 'No users found',
        columns: [
            { data: null, className: 'fw-semibold', render: function (r) { return AppDT.esc(r.name); } },
            { data: null, render: function (r) { return AppDT.esc(r.email); } },
            { data: null, render: function (r) { return AppDT.esc(r.phone || '—'); } },
            { data: null, render: function (r) { return AppDT.esc(r.role_name); } },
            { data: null, render: function (r) {
                var st = String(r.status || 'active').toLowerCase();
                return '<span class="badge bg-' + (colors[st] || 'secondary') + '">' + AppDT.esc(st.charAt(0).toUpperCase() + st.slice(1)) + '</span>';
            } },
            { data: null, className: 'text-muted small text-nowrap', render: function (r) { return AppDT.esc(r.last_login); } },
            { data: null, orderable: false, className: 'text-end', render: function (r) {
                var h = '<div class="table-actions justify-content-end">';
                if (canEdit) {
                    h += '<a href="' + AppDT.esc(base + '/' + r.id + '/edit') + '" class="btn btn-sm btn-outline-secondary" title="Edit"><i class="fas fa-edit"></i></a> ';
                }
                if (canDelete) {
                    h += '<button type="button" class="btn btn-sm btn-outline-danger" data-confirm-delete data-url="' + AppDT.esc(base + '/' + r.id + '/delete') + '" title="Delete"><i class="fas fa-trash"></i></button>';
                }
                return h + '</div>';
            } }
        ]
    });
});
</script>
<?= $this->endSection() ?>
