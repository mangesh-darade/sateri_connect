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
            <p>Search, sort and page through every super admin account.</p>
        </div>
    </div>
    <div class="platform-table-wrap">
        <table class="platform-table" id="adminsTable" style="width:100%">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Access</th>
                    <th>Status</th>
                    <th>Created</th>
                </tr>
            </thead>
            <tbody></tbody>
        </table>
    </div>
</section>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<?= view('partials/platform_dt_assets') ?>
<script>
AppDT.table('#adminsTable', {
    url: <?= json_encode(site_url('platform/admins/data')) ?>,
    order: [[2, 'desc']],
    emptyText: 'No super admins yet',
    columns: [
        { data: null, render: function (r) {
            return '<div class="platform-client-name">' + AppDT.esc(r.name) + '</div>' + (r.is_you ? '<div class="platform-client-meta">You</div>' : '');
        } },
        { data: null, render: function (r) { return AppDT.esc(r.email); } },
        { data: null, render: function (r) {
            return r.is_primary ? '<span class="platform-badge platform-badge-ok">Main · all clients</span>' : '<span class="platform-badge">Own clients</span>';
        } },
        { data: null, render: function (r) {
            var t = String(r.status || 'active');
            var on = t.toLowerCase() === 'active';
            return '<span class="platform-badge ' + (on ? 'platform-badge-ok' : 'platform-badge-warn') + '">' + AppDT.esc(t.charAt(0).toUpperCase() + t.slice(1)) + '</span>';
        } },
        { data: null, render: function (r) { return AppDT.esc(r.created_at); } }
    ]
});
</script>
<?= $this->endSection() ?>
