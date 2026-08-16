<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= esc($pageTitle ?? 'Platform') ?> | Sateri Platform</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Figtree:ital,wght@0,400;0,500;0,600;0,700;1,400&family=Manrope:wght@600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.5.2/css/all.min.css">
    <link rel="stylesheet" href="<?= asset_url('assets/css/platform.css') ?>">
</head>
<body class="platform-body">
<?php
$navActive = (string) ($navActive ?? '');
$platformName = (string) ($platformName ?? 'Admin');
?>
<div class="platform-frame">
    <aside class="platform-sidebar" aria-label="Platform menu">
        <div class="platform-sidebar-brand">
            <div class="platform-rail-mark">S</div>
            <div>
                <div class="platform-sidebar-name">Sateri</div>
                <div class="platform-sidebar-sub">Platform Admin</div>
            </div>
        </div>

        <nav class="platform-side-nav">
            <div class="platform-side-label">Overview</div>
            <a href="<?= site_url('platform/clients') ?>" class="platform-side-link<?= $navActive === 'dashboard' ? ' is-active' : '' ?>">
                <i class="fas fa-chart-pie" aria-hidden="true"></i>
                <span>Dashboard</span>
            </a>
            <a href="<?= site_url('platform/clients') ?>#clients" class="platform-side-link<?= $navActive === 'clients' ? ' is-active' : '' ?>">
                <i class="fas fa-building" aria-hidden="true"></i>
                <span>All clients</span>
            </a>

            <div class="platform-side-label">Manage</div>
            <a href="<?= site_url('platform/clients/create') ?>" class="platform-side-link<?= $navActive === 'create' ? ' is-active' : '' ?>">
                <i class="fas fa-plus" aria-hidden="true"></i>
                <span>Create client</span>
            </a>

            <div class="platform-side-footer">
                <div class="platform-side-user">
                    <i class="fas fa-user-shield" aria-hidden="true"></i>
                    <span><?= esc($platformName) ?></span>
                </div>
                <a href="<?= site_url('logout') ?>" class="platform-side-link is-danger">
                    <i class="fas fa-right-from-bracket" aria-hidden="true"></i>
                    <span>Logout</span>
                </a>
            </div>
        </nav>
    </aside>

    <div class="platform-main">
        <header class="platform-topbar">
            <div class="platform-topbar-copy">
                <p class="platform-eyebrow">Super admin</p>
                <h1 class="platform-title"><?= esc($pageTitle ?? 'Clients') ?></h1>
            </div>
            <div class="platform-topbar-actions">
                <a href="<?= site_url('platform/clients/create') ?>" class="btn-pf btn-pf-primary"><i class="fas fa-plus"></i> New client</a>
            </div>
        </header>

        <?php if (session()->getFlashdata('success')): ?>
            <div class="platform-alert platform-alert-ok"><?= esc(session()->getFlashdata('success')) ?></div>
        <?php endif; ?>
        <?php if (session()->getFlashdata('error')): ?>
            <div class="platform-alert platform-alert-err"><?= esc(session()->getFlashdata('error')) ?></div>
        <?php endif; ?>

        <div class="platform-content">
            <?= $this->renderSection('content') ?>
        </div>
    </div>
</div>
<?= $this->renderSection('scripts') ?>
</body>
</html>
