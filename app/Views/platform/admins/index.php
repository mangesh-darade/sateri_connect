<?= $this->extend('layouts/platform') ?>

<?= $this->section('content') ?>
<?php
$admins = $admins ?? [];
$currentId = (int) ($currentId ?? 0);
?>

<section class="platform-card">
    <div class="platform-card-head">
        <div>
            <h2>Add super admin</h2>
            <p>You are the main platform super admin, so you see every client. Each account you add sees only the clients they create.</p>
        </div>
    </div>
    <form method="post" action="<?= site_url('platform/admins') ?>" class="platform-form-grid">
        <?= csrf_field() ?>
        <div>
            <label class="platform-label" for="adminName">Name<span class="req">*</span></label>
            <input class="platform-input" type="text" id="adminName" name="name" required maxlength="150" value="<?= esc(old('name') ?? '') ?>">
        </div>
        <div>
            <label class="platform-label" for="adminEmail">Email<span class="req">*</span></label>
            <input class="platform-input" type="email" id="adminEmail" name="email" required maxlength="191" value="<?= esc(old('email') ?? '') ?>" autocomplete="off">
        </div>
        <div>
            <label class="platform-label" for="adminPassword">Password<span class="req">*</span></label>
            <input class="platform-input" type="password" id="adminPassword" name="password" required minlength="8" autocomplete="new-password">
        </div>
        <div>
            <label class="platform-label" for="adminPasswordConfirm">Confirm password<span class="req">*</span></label>
            <input class="platform-input" type="password" id="adminPasswordConfirm" name="password_confirm" required minlength="8" autocomplete="new-password">
        </div>
        <div class="full platform-actions">
            <button type="submit" class="btn-pf btn-pf-accent">Create super admin</button>
        </div>
    </form>
</section>

<section class="platform-card">
    <div class="platform-card-head">
        <div>
            <h2>Platform super admins</h2>
            <p><?= count($admins) ?> account<?= count($admins) === 1 ? '' : 's' ?></p>
        </div>
    </div>
    <?php if ($admins === []): ?>
        <div class="platform-empty">
            <strong>No super admins yet</strong>
            <p>Create the first platform login above.</p>
        </div>
    <?php else: ?>
        <div class="platform-table-wrap">
            <table class="platform-table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Access</th>
                        <th>Status</th>
                        <th>Created</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($admins as $admin): ?>
                        <?php $isYou = (int) ($admin['id'] ?? 0) === $currentId; ?>
                        <tr>
                            <td>
                                <div class="platform-client-name"><?= esc((string) ($admin['name'] ?? '')) ?></div>
                                <?php if ($isYou): ?>
                                    <div class="platform-client-meta">You</div>
                                <?php endif; ?>
                            </td>
                            <td><?= esc((string) ($admin['email'] ?? '')) ?></td>
                            <td>
                                <?php if ((int) ($admin['is_primary'] ?? 0) === 1): ?>
                                    <span class="platform-badge platform-badge-ok">Main · all clients</span>
                                <?php else: ?>
                                    <span class="platform-badge">Own clients</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="platform-badge <?= strtolower((string) ($admin['status'] ?? '')) === 'active' ? 'platform-badge-ok' : 'platform-badge-warn' ?>">
                                    <?= esc(ucfirst((string) ($admin['status'] ?? 'active'))) ?>
                                </span>
                            </td>
                            <td><?= esc((string) ($admin['created_at'] ?? '')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
<?= $this->endSection() ?>
