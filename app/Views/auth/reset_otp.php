<?= $this->extend('layouts/auth') ?>

<?= $this->section('content') ?>
<div class="auth-card-head">
    <h2>Enter the code</h2>
    <p class="auth-desc">Type the 6 digit code we sent on WhatsApp. It is valid for 10 minutes.</p>
</div>
<form action="<?= site_url('reset-password/otp') ?>" method="post" class="auth-form">
    <?= csrf_field() ?>
    <div class="mb-3">
        <label class="form-label" for="email">Email</label>
        <div class="auth-field">
            <i class="fas fa-envelope" aria-hidden="true"></i>
            <input type="email" id="email" name="email" class="form-control" placeholder="you@company.com" value="<?= esc(old('email') ?? ($email ?? '')) ?>" required>
        </div>
    </div>
    <div class="mb-3">
        <label class="form-label" for="otp">6 digit code</label>
        <div class="auth-field">
            <i class="fab fa-whatsapp" aria-hidden="true"></i>
            <input type="text" id="otp" name="otp" class="form-control" placeholder="123456" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" required autofocus>
        </div>
    </div>
    <button type="submit" class="btn btn-wa auth-submit">Verify code</button>
</form>
<div class="auth-links">
    <a href="<?= site_url('forgot-password') ?>"><i class="fas fa-arrow-left me-1"></i> Send a new code</a>
</div>
<?= $this->endSection() ?>
