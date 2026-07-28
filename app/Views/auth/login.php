<?= $this->extend('layouts/auth') ?>

<?= $this->section('content') ?>
<div class="auth-card-head">
    <h2>Welcome back</h2>
    <p class="auth-desc">Sign in to your automation console.</p>
</div>
<form action="<?= site_url('login') ?>" method="post" autocomplete="on" class="auth-form">
    <?= csrf_field() ?>
    <div class="mb-3">
        <label class="form-label" for="email">Email</label>
        <div class="auth-field">
            <i class="fas fa-envelope" aria-hidden="true"></i>
            <input type="email" id="email" name="email" class="form-control" placeholder="you@company.com" value="<?= esc(old('email') ?? '') ?>" required autofocus>
        </div>
    </div>
    <div class="mb-3">
        <label class="form-label" for="password">Password</label>
        <div class="auth-field">
            <i class="fas fa-lock" aria-hidden="true"></i>
            <input type="password" id="password" name="password" class="form-control" placeholder="••••••••" required>
            <button type="button" class="auth-password-toggle" data-password-toggle="#password" aria-label="Show password" aria-pressed="false">
                <i class="fas fa-eye" aria-hidden="true"></i>
            </button>
        </div>
    </div>
    <div class="auth-form-row">
        <div class="form-check mb-0">
            <input type="checkbox" class="form-check-input" id="remember" name="remember" value="1" <?= old('remember') ? 'checked' : '' ?>>
            <label class="form-check-label" for="remember">Remember me</label>
        </div>
        <a href="<?= site_url('forgot-password') ?>" class="auth-inline-link">Forgot password?</a>
    </div>
    <button type="submit" class="btn btn-wa auth-submit"><i class="fas fa-arrow-right-to-bracket me-1"></i> Sign in</button>
</form>
<div class="auth-links">
    <a href="<?= site_url('signup') ?>">Create a new account</a>
</div>
<?= $this->endSection() ?>
