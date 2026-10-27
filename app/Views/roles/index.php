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

<?= $this->section('styles') ?>
<style>
    .rp-layout { display: grid; grid-template-columns: 300px minmax(0, 1fr); gap: 1rem; align-items: start; }
    .rp-roles { position: sticky; top: 1rem; }
    .rp-roles-list { display: flex; flex-direction: column; gap: .35rem; padding: .6rem; }
    .rp-role { display: flex; align-items: center; gap: .75rem; width: 100%; padding: .65rem .75rem; border: 1px solid transparent; border-radius: .6rem; background: transparent; text-align: left; cursor: pointer; transition: background .15s, border-color .15s; }
    .rp-role:hover { background: var(--bs-tertiary-bg, #f4f6f8); }
    .rp-role.is-active { background: var(--bs-primary-bg-subtle, #e8f1ff); border-color: var(--bs-primary-border-subtle, #b6d4fe); }
    .rp-role-avatar { flex: 0 0 38px; height: 38px; border-radius: 50%; display: grid; place-items: center; font-weight: 600; background: var(--bs-secondary-bg, #e9ecef); color: var(--bs-body-color, #212529); }
    .rp-role.is-active .rp-role-avatar { background: var(--bs-primary, #0d6efd); color: #fff; }
    .rp-role-main { min-width: 0; flex: 1; }
    .rp-role-name { font-weight: 600; font-size: .92rem; line-height: 1.2; }
    .rp-role-meta { font-size: .76rem; color: var(--bs-secondary-color, #6c757d); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .rp-role-count { font-size: .72rem; font-weight: 600; padding: .15rem .5rem; border-radius: 999px; background: var(--bs-secondary-bg, #e9ecef); }
    .rp-panel[hidden] { display: none !important; }
    .rp-panel-head { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: .75rem; padding: 1rem 1.25rem; border-bottom: 1px solid var(--bs-border-color, #dee2e6); }
    .rp-panel-title { font-size: 1.1rem; font-weight: 600; margin: 0; }
    .rp-panel-sub { font-size: .82rem; color: var(--bs-secondary-color, #6c757d); margin: .15rem 0 0; }
    .rp-toolbar { display: flex; flex-wrap: wrap; align-items: center; gap: .5rem; padding: .75rem 1.25rem; border-bottom: 1px solid var(--bs-border-color, #dee2e6); background: var(--bs-tertiary-bg, #f8f9fa); }
    .rp-toolbar .form-control { max-width: 280px; }
    .rp-summary { margin-left: auto; font-size: .82rem; color: var(--bs-secondary-color, #6c757d); }
    .rp-modules { padding: 1rem 1.25rem; display: grid; grid-template-columns: repeat(auto-fill, minmax(340px, 1fr)); gap: 1rem; }
    .rp-module { border: 1px solid var(--bs-border-color, #dee2e6); border-radius: .75rem; overflow: hidden; }
    .rp-module[hidden] { display: none; }
    .rp-module-head { display: flex; align-items: center; justify-content: space-between; gap: .5rem; padding: .6rem .9rem; background: var(--bs-tertiary-bg, #f8f9fa); border-bottom: 1px solid var(--bs-border-color, #dee2e6); }
    .rp-module-title { font-weight: 600; font-size: .9rem; text-transform: capitalize; }
    .rp-module-count { font-size: .75rem; color: var(--bs-secondary-color, #6c757d); margin-left: .4rem; font-weight: 400; }
    .rp-perm { display: flex; align-items: center; justify-content: space-between; gap: .75rem; padding: .55rem .9rem; border-bottom: 1px solid var(--bs-border-color-translucent, rgba(0,0,0,.06)); cursor: pointer; margin: 0; }
    .rp-perm:last-child { border-bottom: 0; }
    .rp-perm:hover { background: var(--bs-tertiary-bg, #f8f9fa); }
    .rp-perm[hidden] { display: none; }
    .rp-perm-name { font-size: .88rem; font-weight: 500; }
    .rp-perm-slug { font-size: .72rem; color: var(--bs-secondary-color, #6c757d); font-family: var(--bs-font-monospace, monospace); }
    .rp-perm .form-check-input { flex: 0 0 auto; margin: 0; cursor: pointer; }
    .rp-empty { padding: 2rem; text-align: center; color: var(--bs-secondary-color, #6c757d); }
    @media (max-width: 991.98px) {
        .rp-layout { grid-template-columns: 1fr; }
        .rp-roles { position: static; }
        .rp-roles-list { flex-direction: row; overflow-x: auto; }
        .rp-role { min-width: 220px; }
        .rp-modules { grid-template-columns: 1fr; padding: .75rem; }
    }
</style>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php
$rolesList   = $roles ?? [];
$permissions = $permissions ?? [];
$matrix      = $role_permissions ?? $matrix ?? [];
$grouped     = [];
$first       = reset($permissions);
if (is_array($first) && array_is_list($permissions) && isset($first['slug'])) {
    foreach ($permissions as $perm) {
        $mod = $perm['module'] ?? 'general';
        $grouped[$mod][] = $perm;
    }
} else {
    $grouped = $permissions;
}
$totalPerms = 0;
foreach ($grouped as $perms) {
    $totalPerms += count($perms);
}

$seats          = $seats ?? [];
$canAddRole     = ! empty($seats['can_add_role']);
$canCreateRole  = function_exists('can') && can('roles.create');
$canEdit        = function_exists('can') && can('roles.edit');
$canDelete      = function_exists('can') && can('roles.delete');
$maxRoles       = $seats['max_roles'] ?? null;
$roleCount      = (int) ($seats['roles'] ?? count($rolesList));
$reopenRoleModal = $canCreateRole && (old('name') !== null || old('slug') !== null || old('description') !== null);

$roleIsChecked = static function (array $role, array $perm) use ($matrix): bool {
    $rid = (int) $role['id'];
    $pid = (int) $perm['id'];
    if (isset($matrix[$rid]) && is_array($matrix[$rid])) {
        return in_array($pid, $matrix[$rid], false) || in_array($perm['slug'], $matrix[$rid], true);
    }
    if (! empty($role['permission_ids']) && is_array($role['permission_ids'])) {
        return in_array($pid, $role['permission_ids'], false);
    }

    return false;
};
?>
<div class="page-list">
<?php if ($rolesList === []): ?>
    <div class="card"><div class="rp-empty">No roles yet. Create your first role to start assigning permissions.</div></div>
<?php else: ?>
<div class="rp-layout">
    <aside class="card rp-roles" aria-label="Roles">
        <div class="card-header">
            <h2 class="card-title mb-0">Roles</h2>
        </div>
        <div class="rp-roles-list" role="tablist">
            <?php foreach ($rolesList as $i => $role): ?>
                <?php
                $rid   = (int) $role['id'];
                $have  = 0;
                foreach ($grouped as $perms) {
                    foreach ($perms as $perm) {
                        if ($roleIsChecked($role, $perm)) {
                            $have++;
                        }
                    }
                }
                ?>
                <button type="button" class="rp-role<?= $i === 0 ? ' is-active' : '' ?>" role="tab" data-role="<?= $rid ?>" aria-selected="<?= $i === 0 ? 'true' : 'false' ?>">
                    <span class="rp-role-avatar" aria-hidden="true"><?= esc(strtoupper(mb_substr((string) $role['name'], 0, 1))) ?></span>
                    <span class="rp-role-main">
                        <span class="rp-role-name d-block"><?= esc($role['name']) ?></span>
                        <span class="rp-role-meta d-block"><?= esc(($role['description'] ?? '') !== '' ? $role['description'] : $role['slug']) ?></span>
                    </span>
                    <span class="rp-role-count" data-count-role="<?= $rid ?>"><?= $have ?>/<?= $totalPerms ?></span>
                </button>
            <?php endforeach; ?>
        </div>
    </aside>

    <form id="rolesMatrixForm" action="<?= site_url('roles/update') ?>" method="post" class="card mb-0">
        <?= csrf_field() ?>
        <?php foreach ($rolesList as $i => $role): ?>
            <?php $rid = (int) $role['id']; ?>
            <section class="rp-panel" data-panel="<?= $rid ?>"<?= $i === 0 ? '' : ' hidden' ?>>
                <?php if ($canEdit): ?><input type="hidden" name="matrix_roles[]" value="<?= $rid ?>"><?php endif; ?>
                <div class="rp-panel-head">
                    <div>
                        <h2 class="rp-panel-title"><?= esc($role['name']) ?></h2>
                        <p class="rp-panel-sub">
                            <code><?= esc($role['slug']) ?></code>
                            <?php if (($role['description'] ?? '') !== ''): ?> &middot; <?= esc($role['description']) ?><?php endif; ?>
                        </p>
                    </div>
                    <?php if ($canDelete && ! in_array($role['slug'] ?? '', ['admin'], true)): ?>
                        <button type="submit" form="deleteRole<?= $rid ?>" class="btn btn-outline-danger btn-sm" data-confirm="Delete the role &quot;<?= esc($role['name'], 'attr') ?>&quot;? This cannot be undone.">
                            <i class="fas fa-trash me-1"></i> Delete role
                        </button>
                    <?php endif; ?>
                </div>
                <div class="rp-toolbar">
                    <input type="search" class="form-control form-control-sm rp-search" placeholder="Search permissions..." aria-label="Search permissions">
                    <?php if ($canEdit): ?>
                        <button type="button" class="btn btn-outline-secondary btn-sm rp-all" data-role="<?= $rid ?>">Select all</button>
                        <button type="button" class="btn btn-outline-secondary btn-sm rp-none" data-role="<?= $rid ?>">Clear all</button>
                    <?php endif; ?>
                    <span class="rp-summary"><strong class="rp-selected">0</strong> of <?= $totalPerms ?> permissions enabled</span>
                </div>
                <div class="rp-modules">
                    <?php foreach ($grouped as $module => $perms): ?>
                        <div class="rp-module" data-module>
                            <div class="rp-module-head">
                                <span class="rp-module-title"><?= esc(str_replace(['_', '-'], ' ', (string) $module)) ?><span class="rp-module-count"></span></span>
                                <?php if ($canEdit): ?>
                                    <button type="button" class="btn btn-link btn-sm p-0 rp-module-toggle">Toggle all</button>
                                <?php endif; ?>
                            </div>
                            <?php foreach ($perms as $perm): ?>
                                <?php $pid = (int) $perm['id']; ?>
                                <label class="rp-perm" data-search="<?= esc(strtolower($perm['name'] . ' ' . $perm['slug']), 'attr') ?>">
                                    <span>
                                        <span class="rp-perm-name d-block"><?= esc($perm['name']) ?></span>
                                        <span class="rp-perm-slug d-block"><?= esc($perm['slug']) ?></span>
                                    </span>
                                    <span class="form-check form-switch m-0">
                                        <input type="checkbox" class="form-check-input rp-box" role="switch"
                                               data-role="<?= $rid ?>"
                                               name="permissions[<?= $rid ?>][]"
                                               value="<?= $pid ?>"
                                               <?= $roleIsChecked($role, $perm) ? 'checked' : '' ?>
                                               <?= $canEdit ? '' : 'disabled' ?>>
                                    </span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>
                    <div class="rp-empty rp-no-results" hidden style="grid-column: 1 / -1;">No permissions match your search.</div>
                </div>
            </section>
        <?php endforeach; ?>
    </form>
</div>

<?php if ($canDelete): ?>
    <?php foreach ($rolesList as $role): ?>
        <form id="deleteRole<?= (int) $role['id'] ?>" action="<?= site_url('roles/' . (int) $role['id'] . '/delete') ?>" method="post" class="d-none">
            <?= csrf_field() ?>
        </form>
    <?php endforeach; ?>
<?php endif; ?>
<?php endif; ?>
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
    var tabs = document.querySelectorAll('.rp-role');
    var panels = document.querySelectorAll('.rp-panel');
    var panelOf = function (roleId) { return document.querySelector('.rp-panel[data-panel="' + roleId + '"]'); };
    var refresh = function (panel) {
        if (!panel) return;
        var roleId = panel.getAttribute('data-panel');
        var all = panel.querySelectorAll('.rp-box');
        var on = panel.querySelectorAll('.rp-box:checked').length;
        panel.querySelector('.rp-selected').textContent = on;
        var badge = document.querySelector('[data-count-role="' + roleId + '"]');
        if (badge) badge.textContent = on + '/' + all.length;
        panel.querySelectorAll('[data-module]').forEach(function (mod) {
            var boxes = mod.querySelectorAll('.rp-box');
            var n = mod.querySelectorAll('.rp-box:checked').length;
            mod.querySelector('.rp-module-count').textContent = '(' + n + '/' + boxes.length + ')';
        });
    };
    var showRole = function (roleId) {
        tabs.forEach(function (tab) {
            var active = tab.getAttribute('data-role') === String(roleId);
            tab.classList.toggle('is-active', active);
            tab.setAttribute('aria-selected', active ? 'true' : 'false');
        });
        panels.forEach(function (panel) {
            panel.hidden = panel.getAttribute('data-panel') !== String(roleId);
        });
        try { history.replaceState(null, '', '#role-' + roleId); } catch (e) { /* ignore */ }
    };
    tabs.forEach(function (tab) {
        tab.addEventListener('click', function () { showRole(tab.getAttribute('data-role')); });
    });
    var startRole = (location.hash.match(/^#role-(\d+)$/) || [])[1];
    if (startRole && panelOf(startRole)) showRole(startRole);
    panels.forEach(function (panel) {
        refresh(panel);
        panel.addEventListener('change', function (e) {
            if (e.target.classList.contains('rp-box')) refresh(panel);
        });
        var setAll = function (state) {
            panel.querySelectorAll('.rp-box:not(:disabled)').forEach(function (box) { box.checked = state; });
            refresh(panel);
        };
        var allBtn = panel.querySelector('.rp-all');
        var noneBtn = panel.querySelector('.rp-none');
        if (allBtn) allBtn.addEventListener('click', function () { setAll(true); });
        if (noneBtn) noneBtn.addEventListener('click', function () { setAll(false); });
        panel.querySelectorAll('.rp-module-toggle').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var boxes = btn.closest('[data-module]').querySelectorAll('.rp-box:not(:disabled)');
                var allOn = Array.prototype.every.call(boxes, function (box) { return box.checked; });
                boxes.forEach(function (box) { box.checked = !allOn; });
                refresh(panel);
            });
        });
        var search = panel.querySelector('.rp-search');
        search.addEventListener('input', function () {
            var q = search.value.trim().toLowerCase();
            var visible = 0;
            panel.querySelectorAll('[data-module]').forEach(function (mod) {
                var shown = 0;
                mod.querySelectorAll('.rp-perm').forEach(function (row) {
                    var hit = q === '' || row.getAttribute('data-search').indexOf(q) !== -1;
                    row.hidden = !hit;
                    if (hit) shown++;
                });
                mod.hidden = shown === 0;
                visible += shown;
            });
            panel.querySelector('.rp-no-results').hidden = visible !== 0;
        });
    });
    document.querySelectorAll('[data-confirm]').forEach(function (btn) {
        btn.addEventListener('click', function (e) {
            if (!window.confirm(btn.getAttribute('data-confirm'))) e.preventDefault();
        });
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
