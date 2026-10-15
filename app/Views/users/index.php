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
            <?= (int) ($seats['users'] ?? count($users ?? [])) ?>
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
            <tbody>
                <?php foreach (($users ?? []) as $u): ?>
                    <tr>
                        <td class="fw-semibold"><?= esc($u['name']) ?></td>
                        <td><?= esc($u['email']) ?></td>
                        <td><?= esc($u['phone'] ?? '—') ?></td>
                        <td><?= esc($u['role_name'] ?? $u['role_id'] ?? '') ?></td>
                        <td><?= view('partials/status_badge', ['status' => $u['status'] ?? 'active']) ?></td>
                        <td class="text-muted small text-nowrap"><?= esc(format_app_datetime($u['last_login'] ?? null)) ?></td>
                        <td class="text-end">
                            <div class="table-actions justify-content-end">
                            <?php if (function_exists('can') && can('users.edit')): ?>
                                <a href="<?= site_url('users/' . (int) $u['id'] . '/edit') ?>" class="btn btn-sm btn-outline-secondary" title="Edit"><i class="fas fa-edit"></i></a>
                            <?php endif; ?>
                            <?php if (function_exists('can') && can('users.delete')): ?>
                                <button type="button" class="btn btn-sm btn-outline-danger" data-confirm-delete data-url="<?= site_url('users/' . (int) $u['id'] . '/delete') ?>" title="Delete"><i class="fas fa-trash"></i></button>
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
<script>$(function(){ if($.fn.DataTable){ $('#usersTable').DataTable(); } });</script>
<?= $this->endSection() ?>
