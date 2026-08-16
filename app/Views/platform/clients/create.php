<?= $this->extend('layouts/platform') ?>

<?= $this->section('content') ?>
<div class="platform-card">
    <div class="platform-card-head">
        <div>
            <h2>Create client</h2>
            <p>Provisions a new database, admin login, and master routing row.</p>
        </div>
        <a class="btn-pf" href="<?= site_url('platform/clients') ?>">← Dashboard</a>
    </div>
    <form method="post" action="<?= site_url('platform/clients') ?>" class="platform-form-grid">
        <?= csrf_field() ?>
        <div>
            <label class="platform-label">Client key</label>
            <input class="platform-input" type="text" name="key" required pattern="[a-z0-9][a-z0-9_-]{1,62}" placeholder="shop2" value="<?= esc(old('key') ?? '') ?>">
            <div class="platform-help">Unique slug (a-z, 0-9, _, -)</div>
        </div>
        <div>
            <label class="platform-label">Display name</label>
            <input class="platform-input" type="text" name="name" required placeholder="Shop 2" value="<?= esc(old('name') ?? '') ?>">
        </div>
        <div>
            <label class="platform-label">MySQL database</label>
            <input class="platform-input" type="text" name="database" placeholder="sateri_shop2" value="<?= esc(old('database') ?? '') ?>">
        </div>
        <div>
            <label class="platform-label">DB host</label>
            <input class="platform-input" type="text" name="hostname" value="<?= esc(old('hostname') ?? 'localhost') ?>">
        </div>
        <div>
            <label class="platform-label">DB user</label>
            <input class="platform-input" type="text" name="username" value="<?= esc(old('username') ?? 'root') ?>">
        </div>
        <div>
            <label class="platform-label">DB password</label>
            <input class="platform-input" type="password" name="db_password" value="<?= esc(old('db_password') ?? '') ?>" autocomplete="new-password">
        </div>
        <div>
            <label class="platform-label">Port</label>
            <input class="platform-input" type="number" name="port" value="<?= esc(old('port') ?? '3306') ?>">
        </div>
        <div class="full"><hr style="border:0;border-top:1px solid rgba(26,18,40,.08);margin:.2rem 0"></div>
        <div>
            <label class="platform-label">Admin name</label>
            <input class="platform-input" type="text" name="admin_name" value="<?= esc(old('admin_name') ?? 'Admin') ?>">
        </div>
        <div>
            <label class="platform-label">Admin email</label>
            <input class="platform-input" type="email" name="admin_email" required value="<?= esc(old('admin_email') ?? '') ?>">
        </div>
        <div>
            <label class="platform-label">Admin password</label>
            <input class="platform-input" type="text" name="admin_password" required minlength="8" value="<?= esc(old('admin_password') ?? '') ?>">
        </div>
        <div class="full platform-actions">
            <button type="submit" class="btn-pf btn-pf-accent">Create client</button>
            <a href="<?= site_url('platform/clients') ?>" class="btn-pf">Cancel</a>
        </div>
    </form>
</div>
<?= $this->endSection() ?>
