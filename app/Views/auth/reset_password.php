<?= $this->extend('layouts/auth') ?>

<?= $this->section('content') ?>
<div class="auth-card-head">
    <h2>Choose a new password</h2>
    <p class="auth-desc">Pick something strong — at least 8 characters.</p>
</div>
<form action="<?= site_url('reset-password/' . esc($token ?? '', 'url')) ?>" method="post" class="auth-form">
    <?= csrf_field() ?>
    <input type="hidden" name="token" value="<?= esc($token ?? '') ?>">
    <div class="mb-3">
        <label class="form-label" for="email">Email</label>
        <div class="auth-field">
            <i class="fas fa-envelope" aria-hidden="true"></i>
            <input type="email" id="email" name="email" class="form-control" placeholder="you@company.com" value="<?= esc(old('email') ?? ($email ?? '')) ?>" required>
        </div>
    </div>
    <div class="mb-3">
        <label class="form-label" for="password">New password</label>
        <div class="auth-field">
            <i class="fas fa-lock" aria-hidden="true"></i>
            <input type="password" id="password" name="password" class="form-control" placeholder="••••••••" minlength="8" required>
        </div>
    </div>
    <div class="mb-3">
        <label class="form-label" for="password_confirm">Confirm password</label>
        <div class="auth-field">
            <i class="fas fa-lock" aria-hidden="true"></i>
            <input type="password" id="password_confirm" name="password_confirm" class="form-control" placeholder="••••••••" minlength="8" required>
        </div>
    </div>
    <button type="submit" class="btn btn-wa auth-submit">Update password</button>
</form>
<div class="auth-links">
    <a href="<?= site_url('login') ?>"><i class="fas fa-arrow-left me-1"></i> Back to sign in</a>
</div>
<?= $this->endSection() ?>
