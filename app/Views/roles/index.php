<?= $this->extend('layouts/main') ?>

<?= $this->section('header_actions') ?>
<?php
$seats = $seats ?? [];
$canAddRole = ! empty($seats['can_add_role']);
$canCreateRole = function_exists('can') && can('roles.create');
$maxRoles = $seats['max_roles'] ?? null;
$roleCount = (int) ($seats['roles'] ?? 0);
?>
<?php if ($canCreateRole): ?>
<span class="small text-muted text-nowrap" title="Roles used against the platform limit">
    <?= $roleCount ?><?php if ($maxRoles !== null): ?> / <?= (int) $maxRoles ?><?php endif; ?> roles
</span>
<button type="button" class="btn btn-wa btn-sm" data-bs-toggle="modal" data-bs-target="#createRoleModal">
    <i class="fas fa-plus me-1"></i> Create role
</button>
<?php endif; ?>
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
$canCreateRole = function_exists('can') && can('roles.create');
$maxRoles = $seats['max_roles'] ?? null;
$roleCount = (int) ($seats['roles'] ?? count($rolesList));
$reopenRoleModal = $canCreateRole && (old('name') !== null || old('slug') !== null || old('description') !== null);
?>
<div class="page-list">
<div class="card">
    <div class="card-header">
        <h2 class="card-title mb-0">Permission matrix</h2>
    </div>
    <form id="rolesMatrixForm" action="<?= site_url('roles/update') ?>" method="post">
        <?= csrf_field() ?>
        <div class="card-body p-0">
            <div class="roles-matrix-wrap">
            <table class="table table-hover table-sm mb-0 align-middle roles-matrix">
                <thead>
                    <tr>
                        <th class="perm-col">Permission</th>
                        <?php foreach ($rolesList as $role): ?>
                            <?php $rid = (int) $role['id']; ?>
                            <?php $locked = in_array($role['slug'] ?? '', ['super-admin', 'super_admin'], true); ?>
                            <th class="role-col">
                                <div class="roles-col-head">
                                    <span><?= esc($role['name']) ?></span>
                                    <?php if (! $locked && function_exists('can') && can('roles.edit')): ?>
                                        <label class="roles-check" title="Select all permissions for <?= esc($role['name']) ?>">
                                            <input type="checkbox"
                                                   class="roles-col-toggle"
                                                   data-role="<?= $rid ?>"
                                                   aria-label="Select all permissions for <?= esc($role['name']) ?>">
                                            <span class="roles-check-ui" aria-hidden="true"></span>
                                        </label>
                                        <input type="hidden" name="matrix_roles[]" value="<?= $rid ?>">
                                    <?php endif; ?>
                                </div>
                            </th>
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
                                <td class="perm-col">
                                    <div class="roles-perm-name"><?= esc($perm['name']) ?></div>
                                    <div class="roles-perm-slug"><?= esc($perm['slug']) ?></div>
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
                                    <td class="roles-matrix-check">
                                        <label class="roles-check">
                                            <input type="checkbox"
                                                   class="roles-perm-toggle"
                                                   data-role="<?= $rid ?>"
                                                   name="permissions[<?= $rid ?>][]"
                                                   value="<?= $pid ?>"
                                                   <?= $checked || $locked ? 'checked' : '' ?>
                                                   <?= $locked ? 'disabled' : '' ?>>
                                            <span class="roles-check-ui" aria-hidden="true"></span>
                                        </label>
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

<?php if ($canCreateRole): ?>
<div class="modal fade" id="createRoleModal" tabindex="-1" aria-labelledby="createRoleModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="createRoleModalLabel">Create role</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <?php
            $roleErrors = session()->getFlashdata('errors');
            $roleError = session()->getFlashdata('error');
            ?>
            <?php if ($canAddRole): ?>
            <form id="createRoleForm" action="<?= site_url('roles') ?>" method="post" novalidate>
                <?= csrf_field() ?>
                <div class="modal-body">
                    <p class="small text-muted mb-3">
                        Allowed roles:
                        <strong><?= $roleCount ?><?php if ($maxRoles !== null): ?> of <?= (int) $maxRoles ?><?php else: ?> (no limit)<?php endif; ?></strong>
                    </p>
                    <div id="createRoleErrors" class="alert alert-danger py-2 small<?= empty($roleErrors) && empty($roleError) ? ' d-none' : '' ?>" role="alert">
                        <ul class="mb-0 ps-3">
                            <?php if (is_string($roleError) && $roleError !== ''): ?>
                                <li><?= esc($roleError) ?></li>
                            <?php endif; ?>
                            <?php if (is_array($roleErrors)): ?>
                                <?php foreach ($roleErrors as $err): ?>
                                    <li><?= esc(is_array($err) ? implode(' ', $err) : (string) $err) ?></li>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </ul>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="roleName">Name</label>
                        <input class="form-control" id="roleName" name="name" required maxlength="100" value="<?= esc(old('name') ?? '') ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="roleSlug">Slug</label>
                        <input class="form-control" id="roleSlug" name="slug" required maxlength="100" pattern="[A-Za-z0-9_-]+" value="<?= esc(old('slug') ?? '') ?>" placeholder="agent">
                    </div>
                    <div class="mb-0">
                        <label class="form-label" for="roleDescription">Description</label>
                        <input class="form-control" id="roleDescription" name="description" maxlength="255" value="<?= esc(old('description') ?? '') ?>">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-wa">Create role</button>
                </div>
            </form>
            <?php else: ?>
            <?php
            $roleLimitMessage = $maxRoles !== null
                ? 'Role limit reached (' . $roleCount . ' of ' . (int) $maxRoles . '). A platform admin can raise it on the client page.'
                : 'Role limit reached. A platform admin can raise it on the client page.';
            ?>
            <div class="modal-body">
                <p class="small text-muted mb-2">
                    Allowed roles: <strong><?= $roleCount ?><?php if ($maxRoles !== null): ?> of <?= (int) $maxRoles ?><?php endif; ?></strong>
                </p>
                <div class="alert alert-danger py-2 small mb-0" role="alert"><?= esc($roleLimitMessage) ?></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endif; ?>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
(function () {
    var nameInput = document.getElementById('roleName');
    var slugInput = document.getElementById('roleSlug');
    if (nameInput && slugInput) {
        var slugTouched = slugInput.value !== '';
        slugInput.addEventListener('input', function () { slugTouched = true; });
        nameInput.addEventListener('input', function () {
            if (slugTouched) return;
            slugInput.value = nameInput.value
                .toLowerCase()
                .replace(/[^a-z0-9]+/g, '-')
                .replace(/^-+|-+$/g, '')
                .slice(0, 100);
        });
    }
    var createForm = document.getElementById('createRoleForm');
    if (createForm) {
        createForm.addEventListener('submit', function (event) {
            var name = (nameInput && nameInput.value || '').trim();
            var slug = (slugInput && slugInput.value || '').trim();
            var description = (document.getElementById('roleDescription').value || '').trim();
            var errors = [];
            if (name.length < 2 || name.length > 100) {
                errors.push('Name must be between 2 and 100 characters.');
            }
            if (!/^[A-Za-z0-9_-]{2,100}$/.test(slug)) {
                errors.push('Slug must be 2–100 characters and use only letters, numbers, dashes, or underscores.');
            } else if (slug.toLowerCase() === 'super-admin' || slug.toLowerCase() === 'super_admin') {
                errors.push('That role slug is reserved.');
            }
            if (description.length > 255) {
                errors.push('Description must be 255 characters or fewer.');
            }
            var box = document.getElementById('createRoleErrors');
            if (!errors.length || !box) {
                return;
            }
            event.preventDefault();
            box.classList.remove('d-none');
            box.innerHTML = '<ul class="mb-0 ps-3">' + errors.map(function (msg) {
                return '<li>' + String(msg).replace(/[&<>"']/g, function (ch) {
                    return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[ch];
                }) + '</li>';
            }).join('') + '</ul>';
        });
    }
    document.querySelectorAll('.roles-col-toggle').forEach(function (toggle) {
        var roleId = toggle.getAttribute('data-role');
        var boxes = function () {
            return Array.prototype.filter.call(
                document.querySelectorAll('.roles-perm-toggle[data-role="' + roleId + '"]'),
                function (box) { return ! box.disabled; }
            );
        };
        var syncHead = function () {
            var list = boxes();
            var on = list.filter(function (box) { return box.checked; }).length;
            toggle.checked = list.length > 0 && on === list.length;
            toggle.indeterminate = on > 0 && on < list.length;
        };
        toggle.addEventListener('change', function () {
            boxes().forEach(function (box) { box.checked = toggle.checked; });
            toggle.indeterminate = false;
        });
        boxes().forEach(function (box) {
            box.addEventListener('change', syncHead);
        });
        syncHead();
    });
    <?php if (! empty($reopenRoleModal)): ?>
    var modalEl = document.getElementById('createRoleModal');
    if (modalEl && window.bootstrap) {
        bootstrap.Modal.getOrCreateInstance(modalEl).show();
    }
    <?php endif; ?>
})();
</script>
<?= $this->endSection() ?>
