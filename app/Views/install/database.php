<?= $this->extend('layouts/install') ?>

<?= $this->section('content') ?>
<h3 class="mb-1" style="font-family:var(--font-display);color:var(--wa-ink)">Database</h3>
<p class="text-muted mb-3">MySQL credentials — we’ll write <code>.env</code> and run migrations.</p>
<form action="<?= site_url('install/database') ?>" method="post">
    <?= csrf_field() ?>
    <div class="row g-3">
        <div class="col-md-8">
            <label class="form-label">Hostname</label>
            <input type="text" name="hostname" class="form-control" value="<?= esc(old('hostname') ?? ($db['hostname'] ?? '127.0.0.1')) ?>" required>
        </div>
        <div class="col-md-4">
            <label class="form-label">Port</label>
            <input type="number" name="port" class="form-control" value="<?= esc(old('port') ?? ($db['port'] ?? '3306')) ?>" required>
        </div>
        <div class="col-md-6">
            <label class="form-label">Database name</label>
            <input type="text" name="database" class="form-control" value="<?= esc(old('database') ?? ($db['database'] ?? 'apiwa')) ?>" required>
        </div>
        <div class="col-md-6">
            <label class="form-label">Username</label>
            <input type="text" name="username" class="form-control" value="<?= esc(old('username') ?? ($db['username'] ?? 'root')) ?>" required>
        </div>
        <div class="col-md-6">
            <label class="form-label">Password</label>
            <input type="password" name="password" class="form-control" value="<?= esc(old('password') ?? '') ?>" autocomplete="new-password">
        </div>
        <div class="col-md-6">
            <label class="form-label">Base URL</label>
            <input type="url" name="baseURL" class="form-control" value="<?= esc(old('baseURL') ?? ($baseURL ?? site_url('/'))) ?>" required>
        </div>
    </div>
    <div class="form-check mt-3 mb-3">
        <input class="form-check-input" type="checkbox" name="run_migrate" value="1" id="runMigrate" checked>
        <label class="form-check-label" for="runMigrate">Run migrations and seeders after saving</label>
    </div>
    <div class="d-flex justify-content-between">
        <a href="<?= site_url('install/requirements') ?>" class="btn btn-outline-secondary">Back</a>
        <button type="submit" class="btn btn-wa">Save &amp; continue <i class="fas fa-arrow-right ms-1"></i></button>
    </div>
</form>
<?= $this->endSection() ?>
