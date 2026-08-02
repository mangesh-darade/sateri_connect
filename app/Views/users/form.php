<?= $this->extend('layouts/main') ?>

<?= $this->section('header_actions') ?>
<a href="<?= site_url('users') ?>" class="btn btn-outline-secondary btn-sm"><i class="fas fa-arrow-left me-1"></i> Back</a>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php
$formUser = $user ?? $edit_user ?? $userRow ?? $editUser ?? $user_form ?? [];
$isEdit = ! empty($formUser['id']);
$action = $isEdit ? site_url('users/' . (int) $formUser['id']) : site_url('users');
?>
<div class="page-stack">
<div class="form-shell">
<div class="card form-card">
    <form action="<?= $action ?>" method="post">
        <?= csrf_field() ?>
        <div class="card-body">
            <div class="row g-2">
                <div class="col-md-6">
                    <label class="form-label">Name <span class="text-danger">*</span></label>
                    <input type="text" name="name" class="form-control" required value="<?= esc(old('name') ?? ($formUser['name'] ?? '')) ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Email <span class="text-danger">*</span></label>
                    <input type="email" name="email" class="form-control" required value="<?= esc(old('email') ?? ($formUser['email'] ?? '')) ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Phone</label>
                    <input type="text" name="phone" class="form-control" value="<?= esc(old('phone') ?? ($formUser['phone'] ?? '')) ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Role <span class="text-danger">*</span></label>
                    <select name="role_id" class="form-select" required>
                        <option value="">Select role…</option>
                        <?php foreach (($roles ?? []) as $role): ?>
                            <option value="<?= (int) $role['id'] ?>" <?= (string) (old('role_id') ?? ($formUser['role_id'] ?? '')) === (string) $role['id'] ? 'selected' : '' ?>><?= esc($role['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Password <?= $isEdit ? '' : '<span class="text-danger">*</span>' ?></label>
                    <input type="password" name="password" class="form-control" <?= $isEdit ? '' : 'required' ?> minlength="8" autocomplete="new-password">
                    <?php if ($isEdit): ?><div class="form-text">Leave blank to keep current password.</div><?php endif; ?>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Confirm password</label>
                    <input type="password" name="password_confirm" class="form-control" minlength="8" autocomplete="new-password">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-select">
                        <?php foreach (['active', 'inactive'] as $st): ?>
                            <option value="<?= $st ?>" <?= (old('status') ?? ($formUser['status'] ?? 'active')) === $st ? 'selected' : '' ?>><?= ucfirst($st) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>
        <div class="card-footer d-flex flex-wrap gap-2">
            <button type="submit" class="btn btn-wa btn-sm"><i class="fas fa-save me-1"></i> Save</button>
            <a href="<?= site_url('users') ?>" class="btn btn-outline-secondary btn-sm">Cancel</a>
        </div>
    </form>
</div>
</div>
</div>
<?= $this->endSection() ?>
