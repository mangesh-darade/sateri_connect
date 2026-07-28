<?= $this->extend('layouts/auth') ?>

<?= $this->section('content') ?>
<div class="auth-card-head">
    <h2>Create account</h2>
    <p class="auth-desc">Set up your access in a few quick steps. We’ll email you a verification link before first sign-in.</p>
</div>
<form action="<?= site_url('signup') ?>" method="post" autocomplete="on" class="auth-form">
    <?= csrf_field() ?>
    <div class="mb-3">
        <label class="form-label" for="name">Full name</label>
        <div class="auth-field">
            <i class="fas fa-user" aria-hidden="true"></i>
            <input type="text" id="name" name="name" class="form-control" placeholder="Your full name" value="<?= esc(old('name') ?? '') ?>" required autofocus>
        </div>
    </div>
    <div class="mb-3">
        <label class="form-label" for="email">Email</label>
        <div class="auth-field">
            <i class="fas fa-envelope" aria-hidden="true"></i>
            <input type="email" id="email" name="email" class="form-control" placeholder="you@company.com" value="<?= esc(old('email') ?? '') ?>" required>
        </div>
    </div>
    <div class="mb-3">
        <label class="form-label" for="password">Password</label>
        <div class="auth-field">
            <i class="fas fa-lock" aria-hidden="true"></i>
            <input type="password" id="password" name="password" class="form-control" placeholder="Minimum 8 characters" minlength="8" required>
            <button type="button" class="auth-password-toggle" data-password-toggle="#password" aria-label="Show password" aria-pressed="false">
                <i class="fas fa-eye" aria-hidden="true"></i>
            </button>
        </div>
    </div>
    <div class="mb-3">
        <label class="form-label" for="password_confirm">Confirm password</label>
        <div class="auth-field">
            <i class="fas fa-lock" aria-hidden="true"></i>
            <input type="password" id="password_confirm" name="password_confirm" class="form-control" placeholder="Repeat your password" minlength="8" required>
            <button type="button" class="auth-password-toggle" data-password-toggle="#password_confirm" aria-label="Show password" aria-pressed="false">
                <i class="fas fa-eye" aria-hidden="true"></i>
            </button>
        </div>
    </div>
    <button type="submit" class="btn btn-wa auth-submit"><i class="fas fa-user-plus me-1"></i> Sign up</button>
</form>
<div class="auth-links">
    <a href="<?= site_url('login') ?>"><i class="fas fa-arrow-left me-1"></i> Back to sign in</a>
</div>
<?= $this->endSection() ?>
