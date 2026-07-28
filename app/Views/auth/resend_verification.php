<?= $this->extend('layouts/auth') ?>

<?= $this->section('content') ?>
<div class="auth-card-head">
    <h2>Verify your email</h2>
    <p class="auth-desc">Enter your email and we’ll send a fresh verification link.</p>
</div>
<form action="<?= site_url('resend-verification') ?>" method="post" class="auth-form">
    <?= csrf_field() ?>
    <div class="mb-3">
        <label class="form-label" for="email">Email</label>
        <div class="auth-field">
            <i class="fas fa-envelope" aria-hidden="true"></i>
            <input type="email" id="email" name="email" class="form-control" placeholder="you@company.com" value="<?= esc(old('email') ?? ($email ?? '')) ?>" required autofocus>
        </div>
    </div>
    <button type="submit" class="btn btn-wa auth-submit">Send verification link</button>
</form>
<div class="auth-links">
    <a href="<?= site_url('login') ?>"><i class="fas fa-arrow-left me-1"></i> Back to sign in</a>
</div>
<?= $this->endSection() ?>
