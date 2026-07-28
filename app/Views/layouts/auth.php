<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= csrf_hash() ?>">
    <meta name="csrf-header" content="<?= csrf_header() ?>">
    <?php
    $appName     = function_exists('setting') ? (string) setting('app_name', 'WhatsApp Automation') : 'WhatsApp Automation';
    $appTagline  = function_exists('setting') ? (string) setting('app_tagline', 'Automation console') : 'Automation console';
    $siteLogo    = function_exists('setting_asset_url') ? setting_asset_url('site_logo') : '';
    $siteFavicon = function_exists('setting_asset_url') ? setting_asset_url('site_favicon') : '';
    if ($siteFavicon === '' && $siteLogo !== '') {
        $siteFavicon = $siteLogo;
    }
    ?>
    <title><?= esc($title ?? 'Login') ?> | <?= esc($appName) ?></title>
    <?php if ($siteFavicon !== ''): ?>
        <link rel="icon" href="<?= esc($siteFavicon) ?>">
        <link rel="shortcut icon" href="<?= esc($siteFavicon) ?>">
        <link rel="apple-touch-icon" href="<?= esc($siteFavicon) ?>">
    <?php endif; ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.5.2/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11.10.8/dist/sweetalert2.min.css">
    <link rel="stylesheet" href="<?= base_url('assets/css/app.css') ?>?v=auth6">
</head>
<body class="auth-page auth-simple">
<div class="auth-shell auth-shell-simple">
    <main class="auth-panel">
        <div class="auth-card">
            <div class="auth-card-brand<?= $siteLogo !== '' ? ' has-logo-only' : '' ?>">
                <?php if ($siteLogo !== ''): ?>
                    <img src="<?= esc($siteLogo) ?>" alt="<?= esc($appName) ?>" class="auth-logo">
                <?php else: ?>
                    <span class="auth-logo-fallback" aria-hidden="true"><i class="fab fa-whatsapp"></i></span>
                    <div class="auth-brand-text">
                        <div class="name"><?= esc($appName) ?></div>
                        <?php if ($appTagline !== ''): ?>
                            <div class="tag"><?= esc($appTagline) ?></div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
            <?= view('partials/alerts') ?>
            <?= $this->renderSection('content') ?>
        </div>
    </main>
</div>
<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.10.8/dist/sweetalert2.all.min.js"></script>
<script>
    window.APP = {
        baseUrl: <?= json_encode(rtrim(site_url(), '/')) ?>,
        csrfToken: <?= json_encode(csrf_hash()) ?>,
        csrfHeader: <?= json_encode(csrf_header()) ?>,
        csrfName: <?= json_encode(csrf_token()) ?>
    };
</script>
<script src="<?= base_url('assets/js/app.js') ?>"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-password-toggle]').forEach(function (button) {
        var target = document.querySelector(button.getAttribute('data-password-toggle'));
        if (!target) {
            return;
        }

        var icon = button.querySelector('i');
        button.addEventListener('click', function () {
            var isHidden = target.type === 'password';
            target.type = isHidden ? 'text' : 'password';
            button.setAttribute('aria-label', isHidden ? 'Hide password' : 'Show password');
            button.setAttribute('aria-pressed', isHidden ? 'true' : 'false');

            if (icon) {
                icon.classList.toggle('fa-eye', !isHidden);
                icon.classList.toggle('fa-eye-slash', isHidden);
            }
        });
    });
});
</script>
<?= $this->renderSection('scripts') ?>
</body>
</html>
