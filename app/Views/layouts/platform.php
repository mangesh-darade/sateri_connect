<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= csrf_hash() ?>">
    <meta name="csrf-header" content="<?= esc(csrf_header()) ?>">
    <?php
    $masterRepo = new \App\Libraries\MasterTenantRepository();
    $platformBranding = $masterRepo->getPlatformBranding();
    $platformSiteName = $platformBranding['site_name'];
    $platformTagline  = $platformBranding['site_tagline'];
    $platformFavicon  = $platformBranding['favicon_url'];
    $platformLogoUrl  = $platformBranding['logo_url'];
    ?>
    <title><?= esc($pageTitle ?? 'Platform') ?> | <?= esc($platformSiteName) ?></title>
    <?php helper('settings'); ?>
    <?= favicon_link_tags($platformFavicon, (string) ($pageTitle ?? 'Platform')) ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Figtree:ital,wght@0,400;0,500;0,600;0,700;1,400&family=Manrope:wght@600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.5.2/css/all.min.css">
    <link rel="stylesheet" href="<?= asset_url('assets/css/platform.css') ?>">
    <?= $this->renderSection('styles') ?>
</head>
<body class="platform-body">
<?php
$navActive = (string) ($navActive ?? '');
$brandInitial = mb_strtoupper(mb_substr($platformSiteName !== '' ? $platformSiteName : 'S', 0, 1));
?>
<div class="platform-frame">
    <aside class="platform-sidebar" aria-label="Platform menu">
        <a href="<?= site_url('platform/clients') ?>" class="platform-sidebar-brand" id="platformBrand" style="text-decoration:none;color:inherit">
            <img id="platformBrandLogo" src="<?= esc($platformLogoUrl) ?>" alt="<?= esc($platformSiteName) ?>" style="max-height:36px;max-width:140px;object-fit:contain;border-radius:6px"<?= $platformLogoUrl === '' ? ' hidden' : '' ?>>
            <div class="platform-rail-mark" id="platformBrandMark"<?= $platformLogoUrl !== '' ? ' hidden' : '' ?>><?= esc($brandInitial) ?></div>
            <div id="platformBrandText"<?= $platformLogoUrl !== '' ? ' hidden' : '' ?>>
                <div class="platform-sidebar-name" id="platformBrandName"><?= esc($platformSiteName) ?></div>
                <div class="platform-sidebar-sub" id="platformBrandTag"><?= esc($platformTagline) ?></div>
            </div>
        </a>

        <nav class="platform-side-nav">
            <div class="platform-side-label">Overview</div>
            <a href="<?= site_url('platform/clients') ?>" class="platform-side-link<?= $navActive === 'dashboard' ? ' is-active' : '' ?>">
                <i class="fas fa-chart-pie" aria-hidden="true"></i>
                <span>Dashboard</span>
            </a>
            <a href="<?= site_url('platform/clients/all') ?>" class="platform-side-link<?= $navActive === 'clients' ? ' is-active' : '' ?>">
                <i class="fas fa-building" aria-hidden="true"></i>
                <span>All clients</span>
            </a>

            <?php $isMainPlatformAdmin = $masterRepo->isPrimaryPlatformAdmin((int) session('platform_admin_id')); ?>
            <div class="platform-side-label">Manage</div>
            <a href="<?= site_url('platform/meta-tech') ?>" class="platform-side-link<?= $navActive === 'meta-tech' ? ' is-active' : '' ?>">
                <i class="fab fa-meta" aria-hidden="true"></i>
                <span>Embedded Signup</span>
            </a>
            <a href="<?= site_url('platform/api-usage') ?>" class="platform-side-link<?= $navActive === 'api-usage' ? ' is-active' : '' ?>">
                <i class="fas fa-plug" aria-hidden="true"></i>
                <span>API Usage</span>
            </a>
            <?php if ($isMainPlatformAdmin): ?>
            <a href="<?= site_url('platform/admins') ?>" class="platform-side-link<?= $navActive === 'admins' ? ' is-active' : '' ?>">
                <i class="fas fa-user-shield" aria-hidden="true"></i>
                <span>Super admins</span>
            </a>
            <?php endif; ?>

            <div class="platform-side-label">Guides</div>
            <a href="<?= site_url('platform/guide') ?>" class="platform-side-link<?= $navActive === 'guide' ? ' is-active' : '' ?>">
                <i class="fas fa-book" aria-hidden="true"></i>
                <span>Guide</span>
            </a>

            <div class="platform-side-footer">
                <?php if ($isMainPlatformAdmin): ?>
                <a href="<?= site_url('platform/settings') ?>" class="platform-side-link<?= $navActive === 'settings' ? ' is-active' : '' ?>">
                    <i class="fas fa-sliders-h" aria-hidden="true"></i>
                    <span>Settings &amp; Branding</span>
                </a>
                <?php endif; ?>
                <form action="<?= site_url('logout') ?>" method="post" style="margin:0">
                    <?= csrf_field() ?>
                    <button type="submit" class="platform-side-link is-danger">
                        <i class="fas fa-right-from-bracket" aria-hidden="true"></i>
                        <span>Logout</span>
                    </button>
                </form>
                <div id="platformSidePowered" style="margin-top:0.75rem;padding-top:0.65rem;border-top:1px solid rgba(255,255,255,0.08);font-size:0.7rem;color:rgba(232,238,240,0.5);display:flex;align-items:center;justify-content:center;gap:5px;flex-wrap:wrap"<?= empty($platformBranding['powered_by_enabled']) ? ' hidden' : '' ?>>
                    <span>Powered by</span>
                    <a id="platformSidePoweredLink" href="<?= esc($platformBranding['powered_by_url']) ?>" target="_blank" rel="noopener noreferrer" style="color:#7dd3fc;text-decoration:none;font-weight:600;display:inline-flex;align-items:center;gap:4px">
                        <img id="platformSidePoweredLogo" src="<?= esc($platformBranding['powered_by_logo_url']) ?>" alt="" style="height:13px;width:auto;vertical-align:middle;border-radius:2px"<?= $platformBranding['powered_by_logo_url'] === '' ? ' hidden' : '' ?>>
                        <span id="platformSidePoweredName"><?= esc($platformBranding['powered_by_name']) ?></span>
                        <i class="fas fa-arrow-up-right-from-square" style="font-size:8px"></i>
                    </a>
                </div>
            </div>
        </nav>
    </aside>

    <div class="platform-main" style="display:flex;flex-direction:column;min-height:100vh">
        <header class="platform-topbar">
            <div class="platform-topbar-copy">
                <p class="platform-eyebrow">Super admin</p>
                <h1 class="platform-title"><?= esc($pageTitle ?? 'Clients') ?></h1>
            </div>
            <div class="platform-topbar-actions">
                <?= $this->renderSection('header_actions') ?>
            </div>
        </header>

        <?php if (session()->getFlashdata('success')): ?>
            <div class="platform-alert platform-alert-ok"><?= esc(session()->getFlashdata('success')) ?></div>
        <?php endif; ?>
        <?php if (session()->getFlashdata('error')): ?>
            <div class="platform-alert platform-alert-err"><?= esc(session()->getFlashdata('error')) ?></div>
        <?php endif; ?>

        <div class="platform-content" style="flex:1">
            <?= $this->renderSection('content') ?>
        </div>

        <footer style="padding:1.25rem 2rem;margin-top:auto;border-top:1px solid var(--pf-line);display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:0.75rem;font-size:0.8rem;color:var(--pf-muted)">
            <div>
                &copy; <?= date('Y') ?> <strong id="platformFooterName"><?= esc($platformSiteName) ?></strong>. All rights reserved.
            </div>
            <div id="platformPoweredBy" style="display:inline-flex;align-items:center;gap:6px"<?= empty($platformBranding['powered_by_enabled']) ? ' hidden' : '' ?>>
                <span>Powered by</span>
                <a id="platformPoweredByLink" href="<?= esc($platformBranding['powered_by_url']) ?>" target="_blank" rel="noopener noreferrer" style="color:var(--pf-teal);font-weight:700;display:inline-flex;align-items:center;gap:5px;text-decoration:none">
                    <img id="platformPoweredByLogo" src="<?= esc($platformBranding['powered_by_logo_url']) ?>" alt="" style="height:15px;width:auto;vertical-align:middle;border-radius:2px"<?= $platformBranding['powered_by_logo_url'] === '' ? ' hidden' : '' ?>>
                    <span id="platformPoweredByName"><?= esc($platformBranding['powered_by_name']) ?></span>
                    <i class="fas fa-arrow-up-right-from-square" style="font-size:9px"></i>
                </a>
            </div>
        </footer>
    </div>
</div>
<?= $this->renderSection('scripts') ?>
</body>
</html>
