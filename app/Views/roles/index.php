<?= $this->extend('layouts/main') ?>

<?= $this->section('header_actions') ?>
<?php if (function_exists('can') && can('roles.edit')): ?>
<button type="submit" form="rolesMatrixForm" class="btn btn-wa btn-sm"><i class="fas fa-save me-1"></i> Save permissions</button>
<?php endif; ?>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php
$rolesList = $roles ?? [];
$permissions = $permissions ?? [];
$matrix = $role_permissions ?? $matrix ?? [];
$grouped = [];
$first = reset($permissions);
if (is_array($first) && array_is_list($permissions) && isset($first['slug'])) {
    foreach ($permissions as $perm) {
        $mod = $perm['module'] ?? 'general';
        $grouped[$mod][] = $perm;
    }
} else {
    $grouped = $permissions;
}
?>
<?php
$seats = $seats ?? [];
$canAddRole = ! empty($seats['can_add_role']);
$maxRoles = $seats['max_roles'] ?? null;
?>
<div class="page-list">
<div class="page-hint"><i class="fas fa-info-circle"></i><span>System roles stay locked. Toggle module permissions for custom and editable roles, then save. New roles are allowed only up to the platform limit<?php if ($maxRoles !== null): ?> (<?= (int) ($seats['roles'] ?? count($rolesList)) ?> of <?= (int) $maxRoles ?>)<?php endif; ?>.</span></div>
<?php if (function_exists('can') && can('roles.create')): ?>
<div class="card">
    <div class="card-header d-flex align-items-center justify-content-between gap-2">
        <h2 class="card-title mb-0">New role</h2>
        <span class="small text-muted">
            <?= (int) ($seats['roles'] ?? count($rolesList)) ?>
            <?php if ($maxRoles !== null): ?>of <?= (int) $maxRoles ?><?php endif; ?>
            roles<?= $canAddRole ? '' : ' · limit reached' ?>
        </span>
    </div>
    <div class="card-body">
        <?php if ($canAddRole): ?>
        <form action="<?= site_url('roles') ?>" method="post" class="row g-2 align-items-end">
            <?= csrf_field() ?>
            <div class="col-md-4">
                <label class="form-label" for="roleName">Name</label>
                <input class="form-control" id="roleName" name="name" required maxlength="100" value="<?= esc(old('name') ?? '') ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label" for="roleSlug">Slug</label>
                <input class="form-control" id="roleSlug" name="slug" required maxlength="100" pattern="[A-Za-z0-9_-]+" value="<?= esc(old('slug') ?? '') ?>" placeholder="agent">
            </div>
            <div class="col-md-3">
                <label class="form-label" for="roleDescription">Description</label>
                <input class="form-control" id="roleDescription" name="description" maxlength="255" value="<?= esc(old('description') ?? '') ?>">
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-wa w-100">Create role</button>
            </div>
        </form>
        <?php else: ?>
        <p class="text-muted mb-0">This workspace has reached its role limit. A platform admin can raise it on the client page.</p>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>
<div class="card">
    <div class="card-header"><h2 class="card-title mb-0">Permission matrix</h2></div>
    <form id="rolesMatrixForm" action="<?= site_url('roles/update') ?>" method="post">
        <?= csrf_field() ?>
        <div class="card-body p-0">
            <div class="roles-matrix-wrap">
            <table class="table table-hover table-sm mb-0 align-middle roles-matrix">
                <thead>
                    <tr>
                        <th style="min-width:220px">Permission</th>
                        <?php foreach ($rolesList as $role): ?>
                            <th class="text-center"><?= esc($role['name']) ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($grouped as $module => $perms): ?>
                        <tr class="module-row">
                            <td colspan="<?= 1 + count($rolesList) ?>"><?= esc(ucfirst($module)) ?></td>
                        </tr>
                        <?php foreach ($perms as $perm): ?>
                            <tr>
                                <td>
                                    <div><?= esc($perm['name']) ?></div>
                                    <code class="small text-muted"><?= esc($perm['slug']) ?></code>
                                </td>
                                <?php foreach ($rolesList as $role): ?>
                                    <?php
                                    $rid = (int) $role['id'];
                                    $pid = (int) $perm['id'];
                                    $checked = false;
                                    if (isset($matrix[$rid]) && is_array($matrix[$rid])) {
                                        $checked = in_array($pid, $matrix[$rid], false) || in_array($perm['slug'], $matrix[$rid], true);
                                    } elseif (! empty($role['permission_ids']) && is_array($role['permission_ids'])) {
                                        $checked = in_array($pid, $role['permission_ids'], false);
                                    }
                                    $locked = in_array($role['slug'] ?? '', ['super-admin', 'super_admin'], true);
                                    ?>
                                    <td class="text-center">
                                        <input type="checkbox"
                                               class="form-check-input"
                                               name="permissions[<?= $rid ?>][]"
                                               value="<?= $pid ?>"
                                               <?= $checked || $locked ? 'checked' : '' ?>
                                               <?= $locked ? 'disabled' : '' ?>>
                                        <?php if ($locked): ?>
                                            <input type="hidden" name="permissions[<?= $rid ?>][]" value="<?= $pid ?>">
                                        <?php endif; ?>
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>
    </form>
</div>
</div>
<?= $this->endSection() ?>

<?= $this->section('styles') ?>
<style>
.roles-matrix .form-check-input { accent-color: var(--brand-500); }
.roles-save-bar {
    position: sticky;
    bottom: 0;
    z-index: 2;
    background: var(--surface);
    border-top: 1px solid var(--border);
}
</style>
<?= $this->endSection() ?>
