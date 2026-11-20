<?= $this->extend('layouts/auth') ?>

<?= $this->section('content') ?>
<div class="auth-card-head">
    <h2>Reset password</h2>
    <p class="auth-desc">Enter your email and we’ll send a reset link.</p>
</div>
<form action="<?= site_url('forgot-password') ?>" method="post" class="auth-form">
    <?= csrf_field() ?>
    <div class="mb-3">
        <label class="form-label" for="email">Email</label>
        <div class="auth-field">
            <i class="fas fa-envelope" aria-hidden="true"></i>
            <input type="email" id="email" name="email" class="form-control" placeholder="you@company.com" value="<?= esc(old('email') ?? '') ?>" required autofocus>
        </div>
    </div>
    <?php if (! empty($whatsappOtp)): ?>
    <div class="mb-3">
        <div class="form-label">Send the reset to</div>
        <div class="form-check">
            <input class="form-check-input" type="radio" name="method" id="methodEmail" value="email" checked>
            <label class="form-check-label" for="methodEmail">Email (reset link)</label>
        </div>
        <div class="form-check">
            <input class="form-check-input" type="radio" name="method" id="methodWhatsapp" value="whatsapp">
            <label class="form-check-label" for="methodWhatsapp">WhatsApp (6 digit code)</label>
        </div>
        <div class="form-text">The code goes to the mobile number saved on your account.</div>
    </div>
    <?php endif; ?>
    <button type="submit" class="btn btn-wa auth-submit"><?= ! empty($whatsappOtp) ? 'Continue' : 'Send reset link' ?></button>
</form>
<div class="auth-links">
    <a href="<?= site_url('login') ?>"><i class="fas fa-arrow-left me-1"></i> Back to sign in</a>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<?php if (! empty($whatsappOtp)): ?>
<script>
$(function () {
    $('input[name="method"]').on('change', function () {
        var byWhatsapp = $('#methodWhatsapp').is(':checked');
        $('.auth-desc').text(byWhatsapp
            ? 'Enter your email and we’ll send a 6 digit code on WhatsApp.'
            : 'Enter your email and we’ll send a reset link.');
    });
});
</script>
<?php endif; ?>
<?= $this->endSection() ?>
