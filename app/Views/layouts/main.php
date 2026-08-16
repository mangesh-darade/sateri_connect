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
    $appTimezone = 'UTC';
    try {
        $appTimezone = (string) (function_exists('setting')
            ? (setting('app_timezone') ?: setting('timezone', 'UTC'))
            : 'UTC');
    } catch (Throwable $e) {
        $appTimezone = 'UTC';
    }
    if ($appTimezone === '') {
        $appTimezone = 'UTC';
    }
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
    <nav class="main-header navbar navbar-expand navbar-white navbar-light app-topbar">
        <ul class="navbar-nav">
            <li class="nav-item">
                <a class="nav-link app-topbar-toggle" data-widget="pushmenu" href="#" role="button" aria-label="Toggle menu"><i class="fas fa-bars"></i></a>
            </li>
        </ul>
        <ul class="navbar-nav ms-auto align-items-center app-topbar-actions">
            <li class="nav-item">
                <div class="nav-clock" id="navAppClock" data-timezone="<?= esc($appTimezone, 'attr') ?>" title="<?= esc($appTimezone) ?>" aria-live="polite">
                    <span class="nav-clock-time" id="navAppClockTime">--:--:--</span>
                    <span class="nav-clock-date" id="navAppClockDate">---- -- ----</span>
                </div>
            </li>
            <li class="nav-item dropdown" id="navNotifWrap">
                <a class="nav-link app-topbar-icon" id="navNotifToggle" data-bs-toggle="dropdown" data-bs-auto-close="outside" href="#" aria-expanded="false" aria-label="Notifications">
                    <i class="far fa-bell"></i>
                    <?php $notifCount = (int) ($unread_notifications ?? (is_array($notifications ?? null) ? count($notifications) : 0)); ?>
                    <span class="badge navbar-badge<?= $notifCount > 0 ? '' : ' d-none' ?>" id="navNotifBadge"><?= esc((string) $notifCount) ?></span>
                </a>
                <div class="dropdown-menu dropdown-menu-end notif-panel" id="navNotifMenu">
                    <div class="notif-panel-head">
                        <div>
                            <div class="notif-panel-title">Notifications</div>
                            <div class="notif-panel-sub" id="navNotifHeader"><?= $notifCount > 0 ? esc((string) $notifCount) . ' unread' : 'You\'re all caught up' ?></div>
                        </div>
                        <button type="button" class="notif-panel-action" id="navNotifMarkAll"<?= $notifCount > 0 ? '' : ' disabled' ?>>Mark all read</button>
                    </div>
                    <div class="notif-panel-list" id="navNotifList">
                    <?php if (! empty($notifications) && is_array($notifications)): ?>
                        <?php foreach (array_slice($notifications, 0, 8) as $note): ?>
                            <?php
                            $nTitle = (string) ($note['display_title'] ?? $note['title'] ?? 'Notification');
                            $nPhone = (string) ($note['display_subtitle'] ?? $note['contact_phone'] ?? '');
                            $nBody  = (string) ($note['display_body'] ?? $note['message'] ?? '');
                            $nInit  = (string) ($note['avatar_initials'] ?? 'N');
                            $nColor = (string) ($note['avatar_color'] ?? '#7c3aed');
                            ?>
                            <a href="<?= esc($note['link'] ?? '#') ?>" class="notif-item nav-notif-item" data-id="<?= (int) ($note['id'] ?? 0) ?>">
                                <span class="notif-avatar" style="background:<?= esc($nColor) ?>"><?= esc($nInit) ?></span>
                                <span class="notif-item-body">
                                    <span class="notif-item-top">
                                        <span class="notif-item-name"><?= esc($nTitle) ?></span>
                                        <span class="notif-item-time"><?= esc(format_app_datetime($note['created_at'] ?? null, 'g:i A', '')) ?></span>
                                    </span>
                                    <?php if ($nPhone !== ''): ?>
                                        <span class="notif-item-phone"><?= esc($nPhone) ?></span>
                                    <?php endif; ?>
                                    <?php if ($nBody !== ''): ?>
                                        <span class="notif-item-msg"><?= esc($nBody) ?></span>
                                    <?php endif; ?>
                                </span>
                            </a>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="notif-empty nav-notif-empty">
                            <i class="far fa-bell-slash"></i>
                            <div class="notif-empty-title">No new notifications</div>
                            <div class="notif-empty-sub">New WhatsApp replies will appear here live.</div>
                        </div>
                    <?php endif; ?>
                    </div>
                    <div class="notif-panel-foot">
                        <button type="button" class="notif-panel-action" id="navNotifBrowserBtn">Enable browser alerts</button>
                    </div>
                </div>
            </li>
            <li class="nav-item dropdown user-menu">
                <?php
                $waAccount = $waAccount ?? [];
                $waName = trim((string) ($waAccount['display_name'] ?? ''));
                $waPhone = trim((string) ($waAccount['phone'] ?? ''));
                $waPic = trim((string) ($waAccount['profile_picture_url'] ?? ''));
                $waConnected = ! empty($waAccount['connected']);
                $menuAvatar = $waPic !== ''
                    ? $waPic
                    : (string) ($user['avatar'] ?? base_url('assets/img/avatar.png'));
                $menuTitle = $waName !== '' ? $waName : (string) ($user['name'] ?? session('user_name') ?? 'User');
                $menuPhone = $waPhone !== '' ? $waPhone : (string) ($user['email'] ?? session('user_email') ?? '');
                ?>
                <a href="#" class="nav-link dropdown-toggle user-menu-toggle app-topbar-user" data-bs-toggle="dropdown" aria-expanded="false">
                    <img src="<?= esc($menuAvatar) ?>" class="user-image img-circle js-wa-avatar" alt="<?= esc($menuTitle) ?>" referrerpolicy="no-referrer">
                    <span class="app-topbar-user-meta d-none d-md-flex">
                        <span class="app-topbar-user-name js-wa-name"><?= esc($menuTitle) ?></span>
                        <span class="app-topbar-user-role js-wa-phone"><?= esc($menuPhone !== '' ? $menuPhone : (string) ($user['role_name'] ?? session('role_name') ?? 'Member')) ?></span>
                    </span>
                    <i class="fas fa-chevron-down app-topbar-user-caret d-none d-md-inline" aria-hidden="true"></i>
                </a>
                <ul class="dropdown-menu dropdown-menu-lg dropdown-menu-end user-menu-dropdown">
                    <li class="user-header bg-wa wa-account-header">
                        <img src="<?= esc($menuAvatar) ?>" class="img-circle wa-account-avatar js-wa-avatar" alt="<?= esc($menuTitle) ?>" referrerpolicy="no-referrer">
                        <div class="wa-account-copy">
                            <div class="wa-account-name js-wa-name"><?= esc($menuTitle) ?></div>
                            <?php if ($menuPhone !== ''): ?>
                                <div class="wa-account-phone js-wa-phone-row"><i class="fab fa-whatsapp" aria-hidden="true"></i> <span class="js-wa-phone"><?= esc($menuPhone) ?></span></div>
                            <?php else: ?>
                                <div class="wa-account-phone js-wa-phone-row d-none"><i class="fab fa-whatsapp" aria-hidden="true"></i> <span class="js-wa-phone"></span></div>
                            <?php endif; ?>
                            <div class="wa-account-provider">
                                <span class="provider-chip <?= function_exists('is_meta_provider') && is_meta_provider() ? 'is-meta' : 'is-cheerio' ?>">
                                    <i class="<?= function_exists('is_meta_provider') && is_meta_provider() ? 'fab fa-meta' : 'fas fa-bolt' ?>"></i>
                                    <?= esc(function_exists('whatsapp_provider_short') ? whatsapp_provider_short() : 'WA') ?>
                                </span>
                                <?php if ($waConnected): ?>
                                    <span class="wa-account-status">Connected</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </li>
                    <li class="user-body">
                        <div class="user-meta-chip" title="Signed-in user">
                            <i class="fas fa-user-shield"></i>
                            <?= esc($user['name'] ?? session('user_name') ?? 'User') ?>
                            <span class="text-muted">·</span>
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
        <?php
        $userName  = (string) ($user['name'] ?? session('user_name') ?? 'User');
        $userRole  = (string) ($user['role_name'] ?? session('role_name') ?? 'Member');
        $userEmail = (string) ($user['email'] ?? session('user_email') ?? '');
        $userAvatar = (string) ($user['avatar'] ?? base_url('assets/img/avatar.png'));
        $inboxBadge = (int) ($notifCount ?? 0);
        $mobileNavTabs = [];
        $workspaceLabel = $appTagline !== '' ? $appTagline : 'Workspace';
        $brandInitial = mb_strtoupper(mb_substr($appName !== '' ? $appName : 'S', 0, 1));
        ?>
        <a href="<?= site_url('dashboard') ?>" class="brand-link elint-brand elint-workspace<?= $siteLogo !== '' ? ' has-logo-only' : '' ?>" title="<?= esc($appName) ?>">
            <?php if ($siteLogo !== ''): ?>
                <img src="<?= esc($siteLogo) ?>" alt="<?= esc($appName) ?>" class="brand-image brand-logo-img">
            <?php else: ?>
                <span class="elint-brand-mark" aria-hidden="true"><?= esc($brandInitial) ?></span>
            <?php endif; ?>
            <span class="elint-brand-copy">
                <span class="brand-text"><?= esc($appName !== '' ? $appName : 'Sateri Connect') ?></span>
                <span class="brand-sub"><?= esc($workspaceLabel) ?></span>
            </span>
        </a>
        <div class="sidebar">
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
                    'match' => ['guide', 'guide/local', 'guide/production', 'guide/meta-official', 'guide/meta-screenshots', 'guide/automations'],
                    'children' => [
                        ['label' => 'Local Guide', 'icon' => 'monitor-smartphone', 'url' => site_url('guide/local'), 'match' => ['guide/local']],
                        ['label' => 'Production Guide', 'icon' => 'server', 'url' => site_url('guide/production'), 'match' => ['guide/production']],
                        ['label' => 'Meta Publish Guide', 'icon' => 'book-open', 'url' => site_url('guide/meta-official'), 'match' => ['guide/meta-official']],
                        ['label' => 'Meta Screenshot Guide', 'icon' => 'image', 'url' => site_url('guide/meta-screenshots'), 'match' => ['guide/meta-screenshots']],
                        ['label' => 'Automations Guide', 'icon' => 'bot', 'url' => site_url('guide/automations'), 'match' => ['guide/automations']],
                    ],
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

            // Mobile app-style bottom tabs (desktop uses sidebar only).
            $mobileNavTabs = [];
            $addMobileTab = static function (
                array &$tabs,
                string $label,
                string $icon,
                string $url,
                array $match,
                ?int $badge = null,
                ?string $action = null
            ): void {
                $tabs[] = [
                    'label'  => $label,
                    'icon'   => $icon,
                    'url'    => $url,
                    'match'  => $match,
                    'badge'  => $badge,
                    'action' => $action,
                ];
            };
            if (function_exists('can') && can('dashboard.view')) {
                $addMobileTab($mobileNavTabs, 'Home', 'layout-dashboard', site_url('dashboard'), ['dashboard']);
            }
            if (function_exists('can') && can('chat.view')) {
                $addMobileTab(
                    $mobileNavTabs,
                    'Inbox',
                    'message-circle',
                    site_url('chat?channel=whatsapp'),
                    ['chat'],
                    $inboxBadge > 0 ? $inboxBadge : null
                );
            }
            if (function_exists('can') && can('contacts.view')) {
                $addMobileTab($mobileNavTabs, 'Contacts', 'users', site_url('contacts'), ['contacts', 'customer-groups']);
            }
            if (function_exists('can') && can('campaigns.view')) {
                $addMobileTab($mobileNavTabs, 'Broadcasts', 'megaphone', site_url('campaigns'), ['campaigns']);
            }
            $addMobileTab($mobileNavTabs, 'More', 'menu', '#', [], null, 'pushmenu');
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
                <div class="page-intro">
                    <ol class="breadcrumb page-intro-crumb mb-0 d-none d-md-flex">
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
                    <div class="d-flex flex-wrap align-items-start justify-content-between gap-2 page-header-row">
                        <div class="min-w-0 page-header-title">
                            <div class="page-intro-title-row">
                                <h1 class="mb-0"><?= esc($title ?? 'Dashboard') ?></h1>
                                <?php if (function_exists('whatsapp_provider_short')): ?>
                                    <span class="provider-chip <?= function_exists('is_meta_provider') && is_meta_provider() ? 'is-meta' : 'is-cheerio' ?>" title="<?= esc(function_exists('whatsapp_provider_label') ? whatsapp_provider_label() : '') ?>">
                                        <i class="<?= function_exists('is_meta_provider') && is_meta_provider() ? 'fab fa-meta' : 'fas fa-bolt' ?>"></i>
                                        <?= esc(whatsapp_provider_short()) ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                            <?php if (! empty($subtitle)): ?>
                                <p class="page-subtitle mb-0 d-none d-md-block"><?= esc($subtitle) ?></p>
                            <?php endif; ?>
                        </div>
                        <?php if ($headerActionsHtml !== ''): ?>
                            <div class="ms-auto d-flex align-items-center justify-content-end gap-2 flex-wrap header-page-actions" role="toolbar" aria-label="Page actions">
                                <?= $headerActionsHtml ?>
                            </div>
                        <?php endif; ?>
                    </div>
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

    <?php if (empty($fullBleed) && ! empty($mobileNavTabs)): ?>
    <nav class="mobile-bottom-nav" aria-label="Primary mobile navigation">
        <?php foreach ($mobileNavTabs as $tab): ?>
            <?php
            $tabActive = false;
            $tabAction = (string) ($tab['action'] ?? '');
            if ($tabAction === '') {
                foreach (($tab['match'] ?? []) as $pattern) {
                    if (! is_string($pattern)) {
                        continue;
                    }
                    if ($navUriMatches($currentUri, $pattern)) {
                        $tabActive = true;
                        break;
                    }
                }
                if (! $tabActive && ($tab['label'] ?? '') === 'Home' && ($currentUri === '' || $currentUri === '/')) {
                    $tabActive = true;
                }
            }
            $tabBadge = (int) ($tab['badge'] ?? 0);
            $isMore = $tabAction === 'pushmenu';
            ?>
            <?php if ($isMore): ?>
                <a href="#"
                   class="mobile-bottom-nav__item"
                   data-widget="pushmenu"
                   role="button"
                   aria-label="Open menu">
                    <?= $renderIcon((string) ($tab['icon'] ?? 'menu')) ?>
                    <span class="mobile-bottom-nav__label"><?= esc($tab['label']) ?></span>
                </a>
            <?php else: ?>
                <a href="<?= esc($tab['url']) ?>"
                   class="mobile-bottom-nav__item<?= $tabActive ? ' is-active' : '' ?>"
                   aria-label="<?= esc($tab['label']) ?>"
                   aria-current="<?= $tabActive ? 'page' : 'false' ?>">
                    <span class="mobile-bottom-nav__icon-wrap">
                        <?= $renderIcon((string) ($tab['icon'] ?? 'circle')) ?>
                        <?php if ($tabBadge > 0): ?>
                            <span class="mobile-bottom-nav__badge"><?= $tabBadge > 99 ? '99+' : $tabBadge ?></span>
                        <?php endif; ?>
                    </span>
                    <span class="mobile-bottom-nav__label"><?= esc($tab['label']) ?></span>
                </a>
            <?php endif; ?>
        <?php endforeach; ?>
    </nav>
    <?php endif; ?>

    <div id="notifLiveStack" class="notif-live-stack" aria-live="polite" aria-relevant="additions"></div>

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
        timezone: <?= json_encode($appTimezone) ?>,
        whatsappProvider: <?= json_encode(function_exists('whatsapp_provider') ? whatsapp_provider() : 'cheerio') ?>,
        whatsappProviderShort: <?= json_encode(function_exists('whatsapp_provider_short') ? whatsapp_provider_short() : 'Cheerio') ?>,
        whatsappProviderLabel: <?= json_encode(function_exists('whatsapp_provider_label') ? whatsapp_provider_label() : 'Cheerio Direct API') ?>,
        waIdentityNeedsRefresh: <?= ! empty($waIdentityNeedsRefresh) ? 'true' : 'false' ?>,
        waAccount: <?= json_encode([
            'display_name'        => (string) (($waAccount['display_name'] ?? '')),
            'phone'               => (string) (($waAccount['phone'] ?? '')),
            'profile_picture_url' => (string) (($waAccount['profile_picture_url'] ?? '')),
            'connected'           => ! empty($waAccount['connected']),
        ], JSON_UNESCAPED_SLASHES) ?>
    };
</script>
<script src="<?= asset_url('assets/js/app.js') ?>"></script>
<?= $this->renderSection('scripts') ?>
</body>
</html>
