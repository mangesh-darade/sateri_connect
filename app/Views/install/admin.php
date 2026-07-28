<?= $this->extend('layouts/install') ?>

<?= $this->section('content') ?>
<h3 class="mb-1" style="font-family:var(--font-display);color:var(--wa-ink)">Admin account</h3>
<p class="text-muted mb-3">Create the first super admin for the control panel.</p>
<form action="<?= site_url('install/admin') ?>" method="post">
    <?= csrf_field() ?>
    <div class="row g-3">
        <div class="col-md-6">
            <label class="form-label">Full name</label>
            <input type="text" name="name" class="form-control" required value="<?= esc(old('name') ?? 'Administrator') ?>">
        </div>
        <div class="col-md-6">
            <label class="form-label">Email</label>
            <input type="email" name="email" class="form-control" required value="<?= esc(old('email') ?? '') ?>">
        </div>
        <div class="col-md-6">
            <label class="form-label">Password</label>
            <input type="password" name="password" class="form-control" required minlength="8" autocomplete="new-password">
        </div>
        <div class="col-md-6">
            <label class="form-label">Confirm password</label>
            <input type="password" name="password_confirm" class="form-control" required minlength="8" autocomplete="new-password">
        </div>
    </div>
    <div class="d-flex justify-content-between mt-4">
        <a href="<?= site_url('install/database') ?>" class="btn btn-outline-secondary">Back</a>
        <button type="submit" class="btn btn-wa">Continue <i class="fas fa-arrow-right ms-1"></i></button>
    </div>
</form>
<?= $this->endSection() ?>
