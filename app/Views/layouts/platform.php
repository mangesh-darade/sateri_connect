<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php
    $masterRepo = new \App\Libraries\MasterTenantRepository();
    $platformBranding = $masterRepo->getPlatformBranding();
    $platformSiteName = $platformBranding['site_name'];
    $platformTagline  = $platformBranding['site_tagline'];
    $platformFavicon  = $platformBranding['favicon_url'];
    $platformLogoUrl  = $platformBranding['logo_url'];
    ?>
    <title><?= esc($pageTitle ?? 'Platform') ?> | <?= esc($platformSiteName) ?></title>
    <?php if ($platformFavicon !== ''): ?>
        <link rel="icon" href="<?= esc($platformFavicon) ?>">
        <link rel="shortcut icon" href="<?= esc($platformFavicon) ?>">
        <link rel="apple-touch-icon" href="<?= esc($platformFavicon) ?>">
    <?php endif; ?>
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
$brandInitial = mb_strtoupper(mb_substr($platformSiteName !== '' ? $platformSiteName : 'S', 0, 1));
?>
<div class="platform-frame">
    <aside class="platform-sidebar" aria-label="Platform menu">
        <a href="<?= site_url('platform/clients') ?>" class="platform-sidebar-brand" style="text-decoration:none;color:inherit">
            <?php if ($platformLogoUrl !== ''): ?>
                <img src="<?= esc($platformLogoUrl) ?>" alt="<?= esc($platformSiteName) ?>" style="max-height:36px;max-width:140px;object-fit:contain;border-radius:6px">
            <?php else: ?>
                <div class="platform-rail-mark"><?= esc($brandInitial) ?></div>
                <div>
                    <div class="platform-sidebar-name"><?= esc($platformSiteName) ?></div>
                    <div class="platform-sidebar-sub"><?= esc($platformTagline) ?></div>
                </div>
            <?php endif; ?>
        </a>

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
            <a href="<?= site_url('platform/meta-tech') ?>" class="platform-side-link<?= $navActive === 'meta-tech' ? ' is-active' : '' ?>">
                <i class="fab fa-meta" aria-hidden="true"></i>
                <span>Embedded Signup</span>
            </a>
            <a href="<?= site_url('platform/settings') ?>" class="platform-side-link<?= $navActive === 'settings' ? ' is-active' : '' ?>">
                <i class="fas fa-sliders-h" aria-hidden="true"></i>
                <span>Settings &amp; Branding</span>
            </a>
            <a href="<?= site_url('platform/schema') ?>" class="platform-side-link<?= $navActive === 'schema' ? ' is-active' : '' ?>">
                <i class="fas fa-database" aria-hidden="true"></i>
                <span>Database Health</span>
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
                <?php if (! empty($platformBranding['powered_by_enabled'])): ?>
                    <div style="margin-top:0.75rem;padding-top:0.65rem;border-top:1px solid rgba(255,255,255,0.08);font-size:0.7rem;color:rgba(232,238,240,0.5);display:flex;align-items:center;justify-content:center;gap:5px;flex-wrap:wrap">
                        <span>Powered by</span>
                        <a href="<?= esc($platformBranding['powered_by_url']) ?>" target="_blank" rel="noopener noreferrer" style="color:#7dd3fc;text-decoration:none;font-weight:600;display:inline-flex;align-items:center;gap:4px">
                            <?php if (! empty($platformBranding['powered_by_logo_url'])): ?>
                                <img src="<?= esc($platformBranding['powered_by_logo_url']) ?>" alt="Logo" style="height:13px;width:auto;vertical-align:middle;border-radius:2px">
                            <?php endif; ?>
                            <span><?= esc($platformBranding['powered_by_name']) ?></span>
                            <i class="fas fa-arrow-up-right-from-square" style="font-size:8px"></i>
                        </a>
                    </div>
                <?php endif; ?>
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
                <a href="<?= site_url('platform/clients/create') ?>" class="btn-pf btn-pf-primary"><i class="fas fa-plus"></i> New client</a>
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
                &copy; <?= date('Y') ?> <strong><?= esc($platformSiteName) ?></strong>. All rights reserved.
            </div>
            <?php if (! empty($platformBranding['powered_by_enabled'])): ?>
                <div style="display:inline-flex;align-items:center;gap:6px">
                    <span>Powered by</span>
                    <a href="<?= esc($platformBranding['powered_by_url']) ?>" target="_blank" rel="noopener noreferrer" style="color:var(--pf-teal);font-weight:700;display:inline-flex;align-items:center;gap:5px;text-decoration:none">
                        <?php if (! empty($platformBranding['powered_by_logo_url'])): ?>
                            <img src="<?= esc($platformBranding['powered_by_logo_url']) ?>" alt="<?= esc($platformBranding['powered_by_name']) ?>" style="height:15px;width:auto;vertical-align:middle;border-radius:2px">
                        <?php endif; ?>
                        <span><?= esc($platformBranding['powered_by_name']) ?></span>
                        <i class="fas fa-arrow-up-right-from-square" style="font-size:9px"></i>
                    </a>
                </div>
            <?php endif; ?>
        </footer>
    </div>
</div>
<?= $this->renderSection('scripts') ?>
</body>
</html>
