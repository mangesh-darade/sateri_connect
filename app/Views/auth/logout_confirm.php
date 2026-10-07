<?= $this->extend('layouts/auth') ?>

<?= $this->section('content') ?>
<div class="auth-card-head">
    <h2>Sign out</h2>
    <p class="auth-desc">Are you sure you want to sign out?</p>
</div>
<form action="<?= site_url('logout') ?>" method="post" class="auth-form">
    <?= csrf_field() ?>
    <button type="submit" class="btn btn-wa auth-submit"><i class="fas fa-right-from-bracket me-1"></i> Sign out</button>
</form>
<div class="auth-links">
    <a href="<?= site_url() ?>"><i class="fas fa-arrow-left me-1"></i> Stay signed in</a>
</div>
<?= $this->endSection() ?>
