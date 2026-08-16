<?= $this->extend('layouts/auth') ?>

<?= $this->section('content') ?>
<?php
$selectedKey    = strtolower(trim((string) ($selectedTenantKey ?? '')));
$selectedTenant = $selectedTenant ?? null;
$isPlatform     = $selectedKey === '_platform';
?>

<div class="auth-card-head">
    <h2>Welcome back</h2>
    <?php if ($isPlatform): ?>
        <p class="auth-desc">
            Platform super admin login
            · <a href="<?= site_url('login') ?>">Back to login</a>
        </p>
    <?php elseif (is_array($selectedTenant)): ?>
        <p class="auth-desc">
            Signing in to
            <strong><?= esc((string) ($selectedTenant['name'] ?? $selectedKey)) ?></strong>
        </p>
    <?php else: ?>
        <p class="auth-desc">Sign in to your automation console.</p>
    <?php endif; ?>
</div>
<form action="<?= site_url('login') ?>" method="post" autocomplete="on" class="auth-form">
    <?= csrf_field() ?>
    <?php if ($selectedKey !== '' && $selectedKey !== '_email'): ?>
        <input type="hidden" name="tenant_key" value="<?= esc($selectedKey) ?>">
    <?php endif; ?>
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
    <?php if ($isPlatform): ?>
        <a href="<?= site_url('login') ?>">Back to login</a>
    <?php else: ?>
        <a href="<?= site_url('signup') ?>">Create a new account</a>
        <div class="mt-2">
            <a href="<?= site_url('login?tenant=_platform') ?>" class="text-muted small">
                <i class="fas fa-shield-halved me-1"></i>Platform super admin
            </a>
        </div>
    <?php endif; ?>
</div>
<?= $this->endSection() ?>
