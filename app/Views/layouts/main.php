<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= csrf_hash() ?>">
    <meta name="csrf-header" content="<?= csrf_header() ?>">
    <?php
    $appName    = function_exists('setting') ? (string) setting('app_name', 'WhatsApp Automation') : 'WhatsApp Automation';
    $appTagline = function_exists('setting') ? (string) setting('app_tagline', 'Automation console') : 'Automation console';
    $siteLogo    = function_exists('setting_asset_url') ? setting_asset_url('site_logo') : '';
    $siteFavicon = function_exists('setting_asset_url') ? setting_asset_url('site_favicon') : '';
    if ($siteFavicon === '' && $siteLogo !== '') {
        $siteFavicon = $siteLogo;
    }
    ?>
    <title><?= esc($title ?? 'Dashboard') ?> | <?= esc($appName) ?></title>
    <?php if ($siteFavicon !== ''): ?>
        <link rel="icon" href="<?= esc($siteFavicon) ?>">
        <link rel="shortcut icon" href="<?= esc($siteFavicon) ?>">
        <link rel="apple-touch-icon" href="<?= esc($siteFavicon) ?>">
    <?php endif; ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,400;0,9..40,500;0,9..40,600;0,9..40,700;1,9..40,400&family=Onest:wght@500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.5.2/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/admin-lte@3.2.0/dist/css/adminlte.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11.10.8/dist/sweetalert2.min.css">
    <link rel="stylesheet" href="<?= asset_url('assets/css/app.css') ?>">
    <link rel="stylesheet" href="<?= asset_url('assets/css/sidebar.css') ?>">
    <?= $this->renderSection('styles') ?>
</head>
<body class="hold-transition sidebar-mini layout-fixed<?= ! empty($fullBleed) ? ' flow-builder-page' : '' ?><?= ! empty($chatPage) ? ' chat-page-active' : '' ?>">
<div class="wrapper">
    <nav class="main-header navbar navbar-expand navbar-white navbar-light">
        <ul class="navbar-nav">
            <li class="nav-item">
                <a class="nav-link" data-widget="pushmenu" href="#" role="button" aria-label="Toggle menu"><i class="fas fa-bars"></i></a>
            </li>
        </ul>
        <ul class="navbar-nav ms-auto align-items-center">
            <li class="nav-item dropdown" id="navNotifWrap">
                <a class="nav-link" id="navNotifToggle" data-bs-toggle="dropdown" href="#" aria-expanded="false" aria-label="Notifications">
                    <i class="far fa-bell"></i>
                    <?php $notifCount = (int) ($unread_notifications ?? (is_array($notifications ?? null) ? count($notifications) : 0)); ?>
                    <span class="badge navbar-badge<?= $notifCount > 0 ? '' : ' d-none' ?>" id="navNotifBadge"><?= esc((string) $notifCount) ?></span>
                </a>
                <div class="dropdown-menu dropdown-menu-lg dropdown-menu-end" id="navNotifMenu">
                    <span class="dropdown-item dropdown-header" id="navNotifHeader"><?= esc((string) $notifCount) ?> Notifications</span>
                    <div class="dropdown-divider"></div>
                    <div id="navNotifList">
                    <?php if (! empty($notifications) && is_array($notifications)): ?>
                        <?php foreach (array_slice($notifications, 0, 8) as $note): ?>
                            <a href="<?= esc($note['link'] ?? '#') ?>" class="dropdown-item nav-notif-item" data-id="<?= (int) ($note['id'] ?? 0) ?>">
                                <i class="fas fa-<?= esc($note['type'] ?? 'info') === 'error' ? 'exclamation-circle text-danger' : ((string) ($note['type'] ?? '') === 'chat' ? 'comment text-success' : 'info-circle text-primary') ?> me-2"></i>
                                <?= esc($note['title'] ?? 'Notification') ?>
                                <?php if (! empty($note['message'])): ?>
                                    <div class="small text-muted text-truncate" style="max-width:16rem"><?= esc($note['message']) ?></div>
                                <?php endif; ?>
                                <span class="float-end text-muted text-sm"><?= esc($note['created_at'] ?? '') ?></span>
                            </a>
                            <div class="dropdown-divider"></div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <span class="dropdown-item text-muted nav-notif-empty">No new notifications</span>
                        <div class="dropdown-divider"></div>
                    <?php endif; ?>
                    </div>
                    <button type="button" class="dropdown-item dropdown-footer text-start" id="navNotifMarkAll">Mark all as read</button>
                    <button type="button" class="dropdown-item dropdown-footer text-start border-top" id="navNotifBrowserBtn">Enable browser alerts</button>
                </div>
            </li>
            <li class="nav-item dropdown user-menu">
                <a href="#" class="nav-link dropdown-toggle user-menu-toggle" data-bs-toggle="dropdown" aria-expanded="false">
                    <img src="<?= esc($user['avatar'] ?? base_url('assets/img/avatar.png')) ?>" class="user-image img-circle" alt="User">
                    <span class="d-none d-md-inline"><?= esc($user['name'] ?? session('user_name') ?? 'User') ?></span>
                </a>
                <ul class="dropdown-menu dropdown-menu-lg dropdown-menu-end user-menu-dropdown">
                    <li class="user-header bg-wa">
                        <img src="<?= esc($user['avatar'] ?? base_url('assets/img/avatar.png')) ?>" class="img-circle" alt="User">
                        <p>
                            <?= esc($user['name'] ?? session('user_name') ?? 'User') ?>
                            <small><?= esc($user['role_name'] ?? session('role_name') ?? '') ?></small>
                        </p>
                    </li>
                    <li class="user-body">
                        <div class="user-meta-chip">
                            <i class="fas fa-shield-halved"></i>
                            <?= esc($user['role_name'] ?? session('role_name') ?? 'Member') ?>
                        </div>
                    </li>
                    <li class="user-footer">
                        <?php if (function_exists('can') && can('settings.view')): ?>
                            <a href="<?= site_url('settings') ?>" class="btn btn-outline-secondary btn-sm"><i class="fas fa-gear me-1"></i> Settings</a>
                        <?php endif; ?>
                        <a href="<?= site_url('logout') ?>" class="btn btn-wa btn-sm"><i class="fas fa-right-from-bracket me-1"></i> Sign out</a>
                    </li>
                </ul>
            </li>
        </ul>
    </nav>

    <aside class="main-sidebar sidebar-light-primary elint-sidebar elint-sidebar--stack" aria-label="Main navigation">
        <a href="<?= site_url('dashboard') ?>" class="brand-link elint-brand<?= $siteLogo !== '' ? ' has-logo-only' : '' ?>">
            <?php if ($siteLogo !== ''): ?>
                <img src="<?= esc($siteLogo) ?>" alt="<?= esc($appName) ?>" class="brand-image brand-logo-img">
            <?php else: ?>
                <span class="elint-brand-mark" aria-hidden="true">E</span>
                <span class="elint-brand-copy">
                    <span class="brand-text"><?= esc($appName !== '' ? $appName : 'ElintOm') ?></span>
                    <span class="brand-sub"><?= esc($appTagline !== '' ? $appTagline : 'CRM • Marketing Platform') ?></span>
                </span>
            <?php endif; ?>
        </a>
        <div class="sidebar">
            <?php
            $userName  = (string) ($user['name'] ?? session('user_name') ?? 'User');
            $userRole  = (string) ($user['role_name'] ?? session('role_name') ?? 'Member');
            $userEmail = (string) ($user['email'] ?? session('user_email') ?? '');
            $userAvatar = (string) ($user['avatar'] ?? base_url('assets/img/avatar.png'));
            $inboxBadge = (int) ($notifCount ?? 0);
            ?>
            <div class="elint-user-strip">
                <img src="<?= esc($userAvatar) ?>" class="elint-user-avatar" alt="<?= esc($userName) ?>">
                <div class="elint-user-meta">
                    <div class="elint-user-name"><?= esc($userName) ?></div>
                    <div class="elint-user-mail"><?= esc($userEmail !== '' ? $userEmail : $userRole) ?></div>
                </div>
                <span class="elint-user-chip" title="Workspace">WS</span>
            </div>

            <?php
            $currentUri = trim(uri_string(), '/');
            $req = service('request');
            $chatChannel = strtolower(trim((string) ($req->getGet('channel') ?? '')));
            if ($chatChannel === '' && str_starts_with($currentUri, 'chat')) {
                $chatChannel = 'whatsapp';
            }

            $navUriMatches = static function (string $uri, string $pattern): bool {
                $uri     = trim($uri, '/');
                $pattern = trim($pattern, '/');
                if ($pattern === '') {
                    return $uri === '';
                }

                return $uri === $pattern || str_starts_with($uri . '/', $pattern . '/');
            };

            $navBestChildIndex = static function (array $children, string $uri, string $channel) use ($navUriMatches): ?int {
                $bestIdx = null;
                $bestLen = -1;
                foreach ($children as $i => $child) {
                    $childChannel = strtolower(trim((string) ($child['channel'] ?? '')));
                    if ($childChannel !== '') {
                        if (str_starts_with($uri, 'chat') && $channel === $childChannel) {
                            return $i;
                        }
                        continue;
                    }
                    foreach (($child['match'] ?? []) as $pattern) {
                        if (! is_string($pattern)) {
                            continue;
                        }
                        if ($navUriMatches($uri, $pattern)) {
                            $len = strlen(trim($pattern, '/'));
                            if ($len > $bestLen) {
                                $bestLen = $len;
                                $bestIdx = $i;
                            }
                        }
                    }
                }

                return $bestIdx;
            };

            $navGroups = [];

            if (function_exists('can') && can('dashboard.view')) {
                $navGroups[] = [
                    'title' => '',
                    'items' => [[
                        'label' => 'Dashboard',
                        'icon' => 'layout-dashboard',
                        'url' => site_url('dashboard'),
                        'match' => ['dashboard'],
                    ]],
                ];
            }

            // Inbox (Cheerio-like IA — first after dashboard)
            $inboxItems = [];
            if (function_exists('can') && can('chat.view')) {
                $settingsSvc = function_exists('service') ? service('settingsService') : null;
                $pageMsg = function_exists('service') ? service('pageMessaging') : null;
                $igReady = $settingsSvc && $pageMsg && $settingsSvc->isInstagramInboxEnabled() && $pageMsg->isConfigured();
                $fbReady = $settingsSvc && $pageMsg && $settingsSvc->isMessengerInboxEnabled() && $pageMsg->isConfigured();
                $teamChildren = [
                    [
                        'label' => 'WhatsApp',
                        'icon' => 'message-circle',
                        'url' => site_url('chat?channel=whatsapp'),
                        'match' => ['chat'],
                        'channel' => 'whatsapp',
                    ],
                    [
                        'label' => 'Messenger',
                        'icon' => 'send',
                        'url' => site_url('chat?channel=messenger'),
                        'match' => ['chat'],
                        'channel' => 'messenger',
                        'hint' => $fbReady ? null : 'Setup in Settings',
                    ],
                    [
                        'label' => 'Instagram',
                        'icon' => 'instagram',
                        'url' => site_url('chat?channel=instagram'),
                        'match' => ['chat'],
                        'channel' => 'instagram',
                        'hint' => $igReady ? null : 'Setup in Settings',
                    ],
                ];
                $inboxItems[] = [
                    'label' => 'Team Inbox',
                    'icon' => 'message-circle',
                    'url' => site_url('chat?channel=whatsapp'),
                    'match' => ['chat'],
                    'badge' => $inboxBadge,
                    'children' => $teamChildren,
                ];
            }
            if ($inboxItems !== []) {
                $navGroups[] = ['title' => 'Inbox', 'items' => $inboxItems];
            }

            $dataItems = [];
            if (function_exists('can') && can('contacts.view')) {
                $dataItems[] = [
                    'label' => 'Contacts',
                    'icon' => 'users',
                    'url' => site_url('contacts'),
                    'match' => ['contacts', 'customer-groups'],
                    'children' => [
                        ['label' => 'All Contacts', 'icon' => 'users', 'url' => site_url('contacts'), 'match' => ['contacts']],
                        ['label' => 'Customer Groups', 'icon' => 'tags', 'url' => site_url('customer-groups'), 'match' => ['customer-groups']],
                        ['label' => 'Import Contacts', 'icon' => 'file-up', 'url' => site_url('contacts/import'), 'match' => ['contacts/import']],
                        ['label' => 'Duplicate Check', 'icon' => 'copy', 'url' => site_url('contacts/duplicates'), 'match' => ['contacts/duplicates']],
                    ],
                ];
            }
            if ($dataItems !== []) {
                $navGroups[] = ['title' => 'Data', 'items' => $dataItems];
            }

            $marketingItems = [];
            if (function_exists('can') && can('campaigns.view')) {
                $marketingItems[] = [
                    'label' => 'Broadcasts',
                    'icon' => 'megaphone',
                    'url' => site_url('campaigns'),
                    'match' => ['campaigns'],
                    'children' => [
                        ['label' => 'Campaigns', 'icon' => 'megaphone', 'url' => site_url('campaigns'), 'match' => ['campaigns']],
                        ['label' => 'Create Broadcast', 'icon' => 'plus', 'url' => site_url('campaigns/create'), 'match' => ['campaigns/create']],
                    ],
                ];
            }
            if (function_exists('can') && can('emails.view')) {
                $marketingItems[] = [
                    'label' => 'Email Manager',
                    'icon' => 'mail',
                    'url' => site_url('email-manager'),
                    'match' => ['email-manager', 'emails'],
                    'children' => [
                        ['label' => 'Email Manager', 'icon' => 'mail-open', 'url' => site_url('email-manager'), 'match' => ['email-manager']],
                        ['label' => 'Send Single Email', 'icon' => 'send', 'url' => site_url('emails/send'), 'match' => ['emails/send']],
                        ['label' => 'Bulk Email', 'icon' => 'mails', 'url' => site_url('emails/bulk'), 'match' => ['emails/bulk']],
                    ],
                ];
            }
            if (function_exists('can') && can('templates.view')) {
                $marketingItems[] = [
                    'label' => 'Template Library',
                    'icon' => 'sparkles',
                    'url' => site_url('templates'),
                    'match' => ['templates'],
                    'children' => [
                        ['label' => 'WhatsApp Templates', 'icon' => 'file-text', 'url' => site_url('templates'), 'match' => ['templates']],
                        ['label' => 'Create Template', 'icon' => 'plus', 'url' => site_url('templates/create'), 'match' => ['templates/create']],
                    ],
                ];
            }
            if ($marketingItems !== []) {
                $navGroups[] = ['title' => 'Marketing', 'items' => $marketingItems];
            }

            $automationItems = [];
            if (function_exists('can') && can('automations.view')) {
                $automationItems[] = [
                    'label' => 'Workflows',
                    'icon' => 'bot',
                    'url' => site_url('automations'),
                    'match' => ['automations'],
                    'children' => [
                        ['label' => 'Automations', 'icon' => 'bot', 'url' => site_url('automations'), 'match' => ['automations']],
                    ],
                ];
            }
            if (function_exists('can') && can('sequences.view')) {
                $automationItems[] = [
                    'label' => 'Sequences',
                    'icon' => 'list-ordered',
                    'url' => site_url('sequences'),
                    'match' => ['sequences'],
                ];
            }
            if (function_exists('can') && can('keywords.view')) {
                $automationItems[] = [
                    'label' => 'Keywords',
                    'icon' => 'key-round',
                    'url' => site_url('keywords'),
                    'match' => ['keywords'],
                    'children' => [
                        ['label' => 'All Keywords', 'icon' => 'key-round', 'url' => site_url('keywords'), 'match' => ['keywords']],
                        ['label' => 'Create Keyword', 'icon' => 'plus', 'url' => site_url('keywords/create'), 'match' => ['keywords/create']],
                    ],
                ];
            }
            if (function_exists('can') && (can('queue.view') || can('automations.view'))) {
                $automationItems[] = [
                    'label' => 'Queue',
                    'icon' => 'clock-3',
                    'url' => site_url('queue'),
                    'match' => ['queue'],
                ];
            }
            if ($automationItems !== []) {
                $navGroups[] = ['title' => 'Automation', 'items' => $automationItems];
            }

            $analyticsItems = [];
            if (function_exists('can') && can('reports.view')) {
                $analyticsItems[] = [
                    'label' => 'Analytics',
                    'icon' => 'bar-chart-3',
                    'url' => site_url('analytics'),
                    'match' => ['analytics', 'reports'],
                    'children' => [
                        ['label' => 'Overview', 'icon' => 'pie-chart', 'url' => site_url('analytics'), 'match' => ['analytics']],
                        ['label' => 'Reports', 'icon' => 'bar-chart-3', 'url' => site_url('reports'), 'match' => ['reports']],
                        ['label' => 'Delivery Report', 'icon' => 'truck', 'url' => site_url('reports/delivery'), 'match' => ['reports/delivery']],
                    ],
                ];
            }
            if ($analyticsItems !== []) {
                $navGroups[] = ['title' => 'Analytics', 'items' => $analyticsItems];
            }

            $systemItems = [];
            if (function_exists('can') && can('users.view')) {
                $systemItems[] = ['label' => 'Users', 'icon' => 'users', 'url' => site_url('users'), 'match' => ['users']];
            }
            if (function_exists('can') && can('roles.view')) {
                $systemItems[] = ['label' => 'Roles', 'icon' => 'shield', 'url' => site_url('roles'), 'match' => ['roles']];
            }
            if (function_exists('can') && can('guide.view')) {
                $systemItems[] = [
                    'label' => 'Setup Workspace',
                    'icon' => 'wrench',
                    'url' => site_url('guide/local'),
                    'match' => ['guide', 'guide/local', 'guide/production', 'guide/automations'],
                ];
            }
            if ($systemItems !== []) {
                $navGroups[] = ['title' => 'System', 'items' => $systemItems];
            }

            $renderIcon = static function (string $name): string {
                $name = trim($name);
                if ($name === '') {
                    $name = 'circle';
                }
                if (str_starts_with($name, 'fa') || str_contains($name, ' ')) {
                    return '<i class="nav-icon ' . esc($name) . '" aria-hidden="true"></i>';
                }

                return '<i class="nav-icon" data-lucide="' . esc($name, 'attr') . '" aria-hidden="true"></i>';
            };
            ?>
            <div class="elint-menu-search">
                <i data-lucide="search" class="elint-menu-search-icon" aria-hidden="true"></i>
                <input type="search" id="sidebarMenuSearch" class="elint-menu-search-input" placeholder="Search menu..." aria-label="Search menu" autocomplete="off">
            </div>
            <nav class="sidebar-nav-scroll app-sidebar-nav" id="elintSidebarNav">
                <?php foreach ($navGroups as $group): ?>
                    <?php if ($group['title'] !== ''): ?>
                        <div class="app-sidebar-group-title"><?= esc(strtoupper($group['title'])) ?></div>
                    <?php endif; ?>
                    <ul class="nav nav-pills nav-sidebar flex-column app-sidebar-list" role="menu">
                        <?php foreach ($group['items'] as $item): ?>
                            <?php
                            $patterns = $item['match'] ?? [];
                            $children = is_array($item['children'] ?? null) ? $item['children'] : [];
                            $bestChildIdx = $children !== []
                                ? $navBestChildIndex($children, $currentUri, $chatChannel)
                                : null;

                            $isActive = $bestChildIdx !== null;
                            if (! $isActive) {
                                foreach ($patterns as $pattern) {
                                    if (! is_string($pattern)) {
                                        continue;
                                    }
                                    if ($navUriMatches($currentUri, $pattern)) {
                                        $isActive = true;
                                        break;
                                    }
                                }
                            }
                            // Dashboard home: also active on empty URI
                            if (! $isActive && ($item['label'] ?? '') === 'Dashboard' && ($currentUri === '' || $currentUri === '/')) {
                                $isActive = true;
                            }
                            $badge = (int) ($item['badge'] ?? 0);
                            ?>
                            <li class="nav-item app-sidebar-item<?= $children !== [] ? ' has-tree' : '' ?><?= ($children !== [] && $isActive) ? ' menu-open' : '' ?>" data-menu-label="<?= esc(strtolower($item['label']), 'attr') ?>">
                                <a href="<?= esc($item['url']) ?>"
                                   class="nav-link<?= $isActive ? ' active' : '' ?><?= $children !== [] ? ' app-sidebar-toggle' : '' ?>"
                                   <?= $children !== [] ? ' data-toggle="tree" aria-expanded="' . ($isActive ? 'true' : 'false') . '"' : '' ?>
                                   title="<?= esc($item['label']) ?>"
                                   aria-label="<?= esc($item['label']) ?>"
                                   aria-current="<?= $isActive ? 'page' : 'false' ?>">
                                    <?= $renderIcon((string) ($item['icon'] ?? 'circle')) ?>
                                    <p>
                                        <span class="elint-menu-label"><?= esc($item['label']) ?></span>
                                        <?php if ($badge > 0): ?>
                                            <span class="elint-menu-badge"><?= $badge > 99 ? '99+' : $badge ?></span>
                                        <?php endif; ?>
                                    </p>
                                    <?php if ($children !== []): ?>
                                        <i class="right sidebar-link-arrow" data-lucide="chevron-down" aria-hidden="true"></i>
                                    <?php endif; ?>
                                </a>
                                <?php if ($children !== []): ?>
                                    <ul class="nav nav-treeview app-sidebar-tree">
                                        <?php foreach ($children as $childIdx => $child): ?>
                                            <?php $childActive = ($bestChildIdx !== null && (int) $bestChildIdx === (int) $childIdx); ?>
                                            <li class="nav-item<?= $childActive ? ' is-active' : '' ?>" data-menu-label="<?= esc(strtolower($child['label']), 'attr') ?>">
                                                <a href="<?= esc($child['url']) ?>"
                                                   class="nav-link<?= $childActive ? ' active' : '' ?>"
                                                   title="<?= esc($child['label']) ?>"
                                                   aria-current="<?= $childActive ? 'page' : 'false' ?>">
                                                    <?= $renderIcon((string) ($child['icon'] ?? 'circle')) ?>
                                                    <p>
                                                        <?= esc($child['label']) ?>
                                                        <?php if (! empty($child['hint'])): ?>
                                                            <span class="flyout-hint"><?= esc($child['hint']) ?></span>
                                                        <?php endif; ?>
                                                    </p>
                                                </a>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endforeach; ?>
            </nav>
            <?php if (function_exists('can') && can('settings.view')): ?>
            <div class="sidebar-footer-links">
                <ul class="nav nav-pills nav-sidebar flex-column mb-0">
                    <li class="nav-item">
                        <a href="<?= site_url('settings') ?>" class="nav-link <?= str_starts_with(uri_string(), 'settings') ? 'active' : '' ?>" title="Settings" aria-label="Settings">
                            <i class="nav-icon" data-lucide="settings" aria-hidden="true"></i>
                            <p>Settings</p>
                        </a>
                    </li>
                </ul>
            </div>
            <?php endif; ?>
        </div>
    </aside>

    <div class="content-wrapper">
        <?php if (empty($fullBleed)): ?>
        <div class="content-header">
            <div class="container-fluid">
                <?php
                $headerActionsHtml = trim((string) $this->renderSection('header_actions'));
                ?>
                <?php /* Standard page header: breadcrumb left → title + provider chip left → actions right */ ?>
                <ol class="breadcrumb mb-1 d-none d-md-flex">
                    <li class="breadcrumb-item"><a href="<?= site_url('dashboard') ?>">Home</a></li>
                    <?php if (! empty($breadcrumb) && is_array($breadcrumb)): ?>
                        <?php foreach ($breadcrumb as $crumb): ?>
                            <?php if (! empty($crumb['url'])): ?>
                                <li class="breadcrumb-item"><a href="<?= esc($crumb['url']) ?>"><?= esc($crumb['label'] ?? '') ?></a></li>
                            <?php else: ?>
                                <li class="breadcrumb-item active"><?= esc($crumb['label'] ?? '') ?></li>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <li class="breadcrumb-item active"><?= esc($title ?? '') ?></li>
                    <?php endif; ?>
                </ol>
                <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-0">
                    <div class="min-w-0">
                        <h1 class="text-truncate d-inline-flex align-items-center flex-wrap gap-2 mb-0">
                            <?= esc($title ?? 'Dashboard') ?>
                            <?php if (function_exists('whatsapp_provider_short')): ?>
                                <span class="provider-chip <?= function_exists('is_meta_provider') && is_meta_provider() ? 'is-meta' : 'is-cheerio' ?>" title="<?= esc(function_exists('whatsapp_provider_label') ? whatsapp_provider_label() : '') ?>">
                                    <i class="<?= function_exists('is_meta_provider') && is_meta_provider() ? 'fab fa-meta' : 'fas fa-bolt' ?>"></i>
                                    <?= esc(whatsapp_provider_short()) ?>
                                </span>
                            <?php endif; ?>
                        </h1>
                        <?php if (! empty($subtitle)): ?>
                            <p class="page-subtitle mb-0 text-truncate d-none d-md-block"><?= esc($subtitle) ?></p>
                        <?php endif; ?>
                    </div>
                    <?php if ($headerActionsHtml !== ''): ?>
                        <div class="ms-auto d-flex align-items-center justify-content-end gap-2 flex-wrap header-page-actions">
                            <?= $headerActionsHtml ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>
        <section class="content">
            <div class="container-fluid">
                <?= view('partials/alerts') ?>
                <?= $this->renderSection('content') ?>
            </div>
        </section>
    </div>

    <?php if (empty($fullBleed)): ?>
    <footer class="main-footer">
        <strong>&copy; <?= date('Y') ?> <?= esc(function_exists('setting') ? (string) setting('app_name', 'WhatsApp Automation Platform') : 'WhatsApp Automation Platform') ?></strong>
        · <?= esc(function_exists('whatsapp_provider_label') ? whatsapp_provider_label() : 'WhatsApp API') ?>
        <div class="float-end d-none d-sm-inline-block">v1.0.0</div>
    </footer>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/admin-lte@3.2.0/dist/js/adminlte.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/lucide@0.469.0/dist/umd/lucide.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.10.8/dist/sweetalert2.all.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.2/dist/chart.umd.min.js"></script>
<script>
    window.APP = {
        baseUrl: <?= json_encode(rtrim(site_url(), '/')) ?>,
        csrfToken: <?= json_encode(csrf_hash()) ?>,
        csrfHeader: <?= json_encode(csrf_header()) ?>,
        csrfName: <?= json_encode(csrf_token()) ?>,
        appName: <?= json_encode($appName) ?>,
        favicon: <?= json_encode($siteFavicon !== '' ? $siteFavicon : base_url('assets/img/avatar.png')) ?>,
        liveNotif: true,
        whatsappProvider: <?= json_encode(function_exists('whatsapp_provider') ? whatsapp_provider() : 'cheerio') ?>,
        whatsappProviderShort: <?= json_encode(function_exists('whatsapp_provider_short') ? whatsapp_provider_short() : 'Cheerio') ?>,
        whatsappProviderLabel: <?= json_encode(function_exists('whatsapp_provider_label') ? whatsapp_provider_label() : 'Cheerio Direct API') ?>
    };
</script>
<script src="<?= asset_url('assets/js/app.js') ?>"></script>
<?= $this->renderSection('scripts') ?>
</body>
</html>
