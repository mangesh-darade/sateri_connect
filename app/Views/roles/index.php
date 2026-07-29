<?= $this->extend('layouts/main') ?>

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
<div class="card">
    <form action="<?= site_url('roles/update') ?>" method="post">
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
        <?php if (function_exists('can') && can('roles.edit')): ?>
        <div class="card-footer roles-save-bar">
            <button type="submit" class="btn btn-wa btn-sm"><i class="fas fa-save me-1"></i> Save permissions</button>
        </div>
        <?php endif; ?>
    </form>
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
