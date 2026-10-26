<?= $this->extend('layouts/platform') ?>

<?= $this->section('header_actions') ?>
<a class="btn-pf" href="<?= site_url('platform/clients/all') ?>"><i class="fas fa-arrow-left" aria-hidden="true"></i> All clients</a>
<form action="<?= site_url('platform/clients/' . rawurlencode((string) ($tenant['key'] ?? '')) . '/enter') ?>" method="post" style="margin:0">
    <?= csrf_field() ?>
    <button type="submit" class="btn-pf btn-pf-primary"><i class="fas fa-arrow-right-to-bracket" aria-hidden="true"></i> Open workspace</button>
</form>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php
$tenant = $tenant ?? [];
$key    = (string) ($tenant['key'] ?? '');
$name   = (string) ($appName ?? $tenant['name'] ?? $key);
$meta   = $meta ?? [];
$stats  = $stats ?? [];
$seats  = $seats ?? [];
$trend  = $stats['trend'] ?? [];
$emailTrend = $stats['email_trend'] ?? [];
$fmt = static fn ($n): string => number_format((int) $n);
$waReady    = ! empty($stats['meta_ready']);
$emailReady = ! empty($stats['email_ready']);
$emailProvider = (string) ($stats['email_provider_label'] ?? '—');
$clientUrl  = 'platform/clients/' . rawurlencode($key);

$cur       = (string) ($stats['wallet_currency'] ?? 'INR');
$symbol    = strtoupper($cur) === 'INR' ? '₹' : strtoupper($cur) . ' ';
$balance   = (float) ($stats['wallet_balance'] ?? 0);
$walletOn  = ! empty($stats['wallet_enabled']);
$cCode     = strtolower(trim((string) ($tenant['country_code'] ?? '')));
$cName     = $cCode !== '' ? \App\Libraries\WhatsAppCountries::nameOf($cCode) : '';
$cMarkets  = \App\Libraries\WhatsAppCountries::MARKETS;
$cOthers   = \App\Libraries\WhatsAppCountries::OTHERS;
asort($cMarkets);
asort($cOthers);

$health     = (string) ($stats['health'] ?? 'down');
$healthCls  = $health === 'ok' ? 'platform-badge-ok' : ($health === 'warn' ? 'platform-badge-warn' : 'platform-badge-down');
$adminMail  = (string) ($adminEmail ?? $stats['admin_email'] ?? '');

$initials = static function (string $n): string {
    $out = '';
    foreach (array_slice(array_filter(preg_split('/[\s_\-]+/', trim($n)) ?: []), 0, 2) as $p) {
        $out .= mb_strtoupper(mb_substr($p, 0, 1));
    }

    return $out !== '' ? $out : '?';
};
$hue = hexdec(substr(md5($key), 0, 4)) % 360;

// Setup checklist: what is left before this client is fully live.
$checks = [
    ['done' => $cName !== '',                      'label' => 'Country chosen',               'hint' => 'Sets wallet currency and timezone', 'tab' => 'wallet'],
    ['done' => $adminMail !== '',                  'label' => 'Admin login created',          'hint' => $adminMail !== '' ? $adminMail : 'Add an admin email', 'tab' => 'access'],
    ['done' => $waReady,                           'label' => 'WhatsApp connected',           'hint' => 'Phone number ID and token',          'tab' => 'whatsapp'],
    ['done' => $emailReady,                        'label' => 'Email sender verified',        'hint' => $emailProvider,                       'tab' => 'email'],
    ['done' => $walletOn && $balance > 0,          'label' => 'Wallet on with a balance',     'hint' => $walletOn ? 'Add money to start sending' : 'Turn the wallet on', 'tab' => 'wallet'],
];
$doneCount = count(array_filter($checks, static fn (array $c): bool => $c['done']));

$senderBadge = static fn (string $status): string => match ($status) {
    'verified' => 'platform-badge-ok',
    'failed', 'disabled' => 'platform-badge-down',
    default => 'platform-badge-warn',
};

/** 14-day bar chart. $tip builds the tooltip for one day row. */
$bars = static function (array $rows, string $caption, callable $tip): string {
    if ($rows === []) {
        return '<p class="pfd-muted">No data in the last 14 days.</p>';
    }
    $max = 1;
    foreach ($rows as $day) {
        $max = max($max, (int) ($day['sent'] ?? 0));
    }
    $html = '<div class="platform-bars">';
    foreach ($rows as $day) {
        $sent = (int) ($day['sent'] ?? 0);
        $h = $sent === 0 ? 3 : max(10, (int) round(($sent / $max) * 88));
        $html .= '<div class="platform-bar" style="height:' . $h . 'px" title="' . esc($tip($day)) . '"></div>';
    }
    $html .= '</div><div class="platform-bar-label"><span>' . esc((string) ($rows[0]['date'] ?? ''))
        . '</span><span>' . esc($caption) . '</span><span>' . esc((string) ($rows[count($rows) - 1]['date'] ?? '')) . '</span></div>';

    return $html;
};
$sum = static fn (array $rows, string $field): int => array_sum(array_map(static fn ($d) => (int) ($d[$field] ?? 0), $rows));
$waTip    = static fn (array $d): string => ($d['date'] ?? '') . ': ' . (int) ($d['sent'] ?? 0) . ' sent · ' . (int) ($d['replies'] ?? 0) . ' replies';
$emailTip = static fn (array $d): string => ($d['date'] ?? '') . ': ' . (int) ($d['sent'] ?? 0) . ' sent · ' . (int) ($d['opened'] ?? 0) . ' opened';
$stat = static fn (string $label, string $value, string $sub = '', string $cls = ''): string =>
    '<div class="pfd-stat"><span>' . esc($label) . '</span><strong class="' . $cls . '">' . $value . '</strong>' . ($sub !== '' ? '<em>' . esc($sub) . '</em>' : '') . '</div>';
?>

<!-- ============ Hero ============ -->
<section class="platform-card pfd-hero">
    <div class="pfd-hero-main">
        <span class="pfd-avatar" style="--h:<?= $hue ?>" aria-hidden="true"><?= esc($initials($name)) ?></span>
        <div class="pfd-hero-text">
            <h2><?= esc($name) ?></h2>
            <div class="pfd-meta"><?= esc($key) ?> · <?= esc((string) ($tenant['db_database'] ?? '')) ?></div>
            <div class="pfd-pills">
                <span class="platform-badge <?= $healthCls ?>"><?= esc((string) ($stats['health_label'] ?? $health)) ?></span>
                <?php if ($cName !== ''): ?>
                    <span class="pfd-pill"><i class="fas fa-earth-asia" aria-hidden="true"></i> <?= esc($cName) ?> · <?= esc($cur) ?></span>
                <?php else: ?>
                    <a class="pfd-pill is-warn" href="#wallet"><i class="fas fa-triangle-exclamation" aria-hidden="true"></i> Country not set</a>
                <?php endif; ?>
                <span class="pfd-pill <?= $waReady ? 'is-ok' : '' ?>"><i class="fab fa-whatsapp" aria-hidden="true"></i> WhatsApp <?= $waReady ? 'ready' : 'setup' ?></span>
                <span class="pfd-pill <?= $emailReady ? 'is-ok' : '' ?>"><i class="fas fa-envelope" aria-hidden="true"></i> Email <?= $emailReady ? 'ready' : 'setup' ?></span>
                <span class="pfd-pill <?= $walletOn ? 'is-ok' : '' ?>"><i class="fas fa-wallet" aria-hidden="true"></i> Wallet <?= $walletOn ? 'on' : 'off' ?></span>
            </div>
        </div>
    </div>
    <div class="pfd-hero-actions">
        <button type="button" class="btn-pf btn-pf-primary js-add-money" data-url="<?= site_url($clientUrl . '/wallet') ?>" data-name="<?= esc($name, 'attr') ?>" data-balance="<?= esc($symbol . number_format($balance, 2), 'attr') ?>" data-cur="<?= esc($cur, 'attr') ?>"><i class="fas fa-plus" aria-hidden="true"></i> Add money</button>
        <a class="btn-pf" href="<?= site_url($clientUrl . '/wallet') ?>"><i class="fas fa-wallet" aria-hidden="true"></i> Wallet</a>
        <a class="btn-pf" href="<?= site_url('platform/wallet-report?tenant=' . rawurlencode($key)) ?>"><i class="fas fa-chart-column" aria-hidden="true"></i> Report</a>
        <a class="btn-pf" href="<?= site_url($clientUrl . '/settings') ?>"><i class="fas fa-gear" aria-hidden="true"></i> Settings</a>
    </div>
</section>

<!-- ============ Key numbers ============ -->
<div class="pfd-stats">
    <?= $stat('Users', $fmt($stats['users'] ?? 0), $fmt($stats['users_active'] ?? 0) . ' active') ?>
    <?= $stat('Contacts', $fmt($stats['contacts'] ?? 0), $fmt($stats['open_chats'] ?? 0) . ' open chats') ?>
    <?= $stat('WhatsApp sent', $fmt($stats['sent'] ?? 0), ((string) ($stats['delivery_rate'] ?? 0)) . '% delivered') ?>
    <?= $stat('WA failed', $fmt($stats['failed'] ?? 0), ((string) ($stats['fail_rate'] ?? 0)) . '% fail rate', (int) ($stats['failed'] ?? 0) > 0 ? 'is-bad' : '') ?>
    <?= $stat('Emails sent', $fmt($stats['email_sent'] ?? 0), ((string) ($stats['email_open_rate'] ?? 0)) . '% opened') ?>
    <?= $stat('Wallet balance', esc($symbol) . number_format($balance, 2), $walletOn ? 'Wallet on' : 'Wallet off', $walletOn && $balance <= 0 ? 'is-bad' : '') ?>
</div>

<!-- ============ Tabs ============ -->
<nav class="pfd-tabs" role="tablist" aria-label="Client sections">
    <button type="button" class="pfd-tab is-active" role="tab" data-tab="all" aria-selected="true"><i class="fas fa-gauge-high" aria-hidden="true"></i> Overview</button>
    <button type="button" class="pfd-tab" role="tab" data-tab="whatsapp" aria-selected="false"><i class="fab fa-whatsapp" aria-hidden="true"></i> WhatsApp<span class="pfd-dot <?= $waReady ? 'is-ok' : '' ?>"></span></button>
    <button type="button" class="pfd-tab" role="tab" data-tab="email" aria-selected="false"><i class="fas fa-envelope" aria-hidden="true"></i> Email<span class="pfd-dot <?= $emailReady ? 'is-ok' : '' ?>"></span></button>
    <button type="button" class="pfd-tab" role="tab" data-tab="wallet" aria-selected="false"><i class="fas fa-wallet" aria-hidden="true"></i> Wallet &amp; country</button>
    <button type="button" class="pfd-tab" role="tab" data-tab="access" aria-selected="false"><i class="fas fa-user-shield" aria-hidden="true"></i> Access &amp; limits</button>
</nav>

<!-- ---------- Overview ---------- -->
<div class="platform-tab-panel" data-panel="all">
    <div class="pfd-grid">
        <section class="platform-card">
            <div class="pfd-card-head">
                <h3>Setup progress</h3>
                <span class="pfd-progress-text"><?= $doneCount ?> of <?= count($checks) ?> done</span>
            </div>
            <div class="pfd-progress"><i style="width:<?= (int) round(($doneCount / max(1, count($checks))) * 100) ?>%"></i></div>
            <ul class="pfd-checks">
                <?php foreach ($checks as $c): ?>
                    <li class="<?= $c['done'] ? 'is-done' : '' ?>">
                        <span class="pfd-check" aria-hidden="true"><i class="fas <?= $c['done'] ? 'fa-check' : 'fa-circle' ?>"></i></span>
                        <span class="pfd-check-text"><strong><?= esc($c['label']) ?></strong><small><?= esc((string) $c['hint']) ?></small></span>
                        <?php if (! $c['done']): ?><a class="pfd-fix js-goto-tab" href="#<?= esc($c['tab']) ?>" data-tab="<?= esc($c['tab']) ?>">Fix</a><?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>

        <section class="platform-card">
            <div class="pfd-card-head"><h3>Channels</h3></div>
            <dl class="pfd-dl">
                <div><dt>WhatsApp</dt><dd><span class="platform-badge <?= $waReady ? 'platform-badge-ok' : 'platform-badge-warn' ?>"><?= $waReady ? 'Ready' : 'Setup needed' ?></span></dd></div>
                <div><dt>Phone number ID</dt><dd><?= esc((string) (($meta['phone_number_id'] ?? '') !== '' ? $meta['phone_number_id'] : '—')) ?></dd></div>
                <div><dt>WA queue</dt><dd><?= $fmt($stats['queue'] ?? 0) ?> waiting</dd></div>
                <div><dt>Email</dt><dd><span class="platform-badge <?= $emailReady ? 'platform-badge-ok' : 'platform-badge-warn' ?>"><?= $emailReady ? 'Ready' : 'Setup needed' ?></span></dd></div>
                <div><dt>Email provider</dt><dd><?= esc($emailProvider) ?></dd></div>
                <div><dt>Senders verified</dt><dd><?= $fmt($stats['email_senders_verified'] ?? 0) ?> of <?= $fmt($stats['email_senders_total'] ?? 0) ?></dd></div>
            </dl>
        </section>
    </div>

    <div class="pfd-grid">
        <section class="platform-card">
            <div class="pfd-card-head"><h3>WhatsApp · last 14 days</h3></div>
            <?= $bars($trend, 'Sent / day', $waTip) ?>
        </section>
        <section class="platform-card">
            <div class="pfd-card-head"><h3>Email · last 14 days</h3></div>
            <?= $bars($emailTrend, 'Emails / day', $emailTip) ?>
        </section>
    </div>
</div>

<!-- ---------- WhatsApp ---------- -->
<div class="platform-tab-panel" data-panel="whatsapp" hidden>
    <div class="pfd-grid is-wide-left">
        <section class="platform-card">
            <div class="pfd-card-head"><h3>WhatsApp results</h3></div>
            <div class="pfd-minis">
                <?php foreach ([
                    'Campaigns' => $fmt($stats['campaigns'] ?? 0), 'Sent' => $fmt($stats['sent'] ?? 0), 'Delivered' => $fmt($stats['delivered'] ?? 0),
                    'Read' => $fmt($stats['read'] ?? 0), 'Failed' => $fmt($stats['failed'] ?? 0), 'Replies' => $fmt($stats['replies'] ?? 0),
                    'Open chats' => $fmt($stats['open_chats'] ?? 0), 'Queue' => $fmt($stats['queue'] ?? 0),
                    'Delivery %' => ((string) ($stats['delivery_rate'] ?? 0)) . '%', 'Fail %' => ((string) ($stats['fail_rate'] ?? 0)) . '%',
                ] as $label => $value): ?>
                    <div><span><?= esc($label) ?></span><strong><?= esc($value) ?></strong></div>
                <?php endforeach; ?>
            </div>
            <?php if (! $waReady): ?>
                <div class="pfd-callout">Phone number ID and access token are required. Connect this client from <a href="<?= site_url('platform/meta-tech') ?>">Embedded Signup</a>, or paste the token in the form below.</div>
            <?php endif; ?>
        </section>
        <section class="platform-card">
            <div class="pfd-card-head"><h3>Last 14 days</h3></div>
            <?= $bars($trend, 'Sent / day', $waTip) ?>
            <?php if ($trend !== []): ?>
                <div class="pfd-minis is-3">
                    <div><span>14d sent</span><strong><?= $fmt($sum($trend, 'sent')) ?></strong></div>
                    <div><span>14d failed</span><strong><?= $fmt($sum($trend, 'failed')) ?></strong></div>
                    <div><span>14d replies</span><strong><?= $fmt($sum($trend, 'replies')) ?></strong></div>
                </div>
            <?php endif; ?>
        </section>
    </div>

    <div class="pfd-grid">
        <section class="platform-card">
            <div class="pfd-card-head"><h3>Recent campaigns</h3></div>
            <?php if (empty($stats['recent_campaigns'])): ?>
                <p class="pfd-muted">No WhatsApp campaigns yet.</p>
            <?php else: ?>
                <div class="platform-table-wrap">
                    <table class="platform-table pfd-table">
                        <thead><tr><th>Name</th><th>Status</th><th>Created</th></tr></thead>
                        <tbody>
                        <?php foreach ($stats['recent_campaigns'] as $camp): ?>
                            <tr>
                                <td><strong><?= esc((string) ($camp['name'] ?? '—')) ?></strong></td>
                                <td><span class="pfd-status"><?= esc(ucfirst((string) ($camp['status'] ?? '—'))) ?></span></td>
                                <td class="pfd-muted"><?= esc((string) ($camp['created_at'] ?? '—')) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>

        <section class="platform-card">
            <div class="pfd-card-head"><h3>Meta / WhatsApp settings</h3></div>
            <form method="post" action="<?= site_url($clientUrl . '/meta') ?>#whatsapp" class="platform-form-grid">
                <?= csrf_field() ?>
                <div><label class="platform-label">Display / app name</label><input class="platform-input" type="text" name="app_name" value="<?= esc((string) ($appName ?? '')) ?>"></div>
                <div><label class="platform-label">Meta App ID</label><input class="platform-input" type="text" name="app_id" value="<?= esc((string) ($meta['app_id'] ?? '')) ?>"></div>
                <div><label class="platform-label">WABA ID</label><input class="platform-input" type="text" name="waba_id" value="<?= esc((string) ($meta['waba_id'] ?? '')) ?>"></div>
                <div><label class="platform-label">Phone number ID</label><input class="platform-input" type="text" name="phone_number_id" value="<?= esc((string) ($meta['phone_number_id'] ?? '')) ?>"></div>
                <div><label class="platform-label">Business ID</label><input class="platform-input" type="text" name="business_id" value="<?= esc((string) ($meta['business_id'] ?? '')) ?>"></div>
                <div><label class="platform-label">Webhook verify token</label><input class="platform-input" type="text" name="verify_token" value="<?= esc((string) ($meta['verify_token'] ?? '')) ?>"></div>
                <div><label class="platform-label">Access token</label><input class="platform-input" type="password" name="access_token" value="<?= esc((string) ($meta['access_token'] ?? '')) ?>" autocomplete="new-password" placeholder="Leave blank to keep"></div>
                <div><label class="platform-label">App secret</label><input class="platform-input" type="password" name="app_secret" value="<?= esc((string) ($meta['app_secret'] ?? '')) ?>" autocomplete="new-password" placeholder="Leave blank to keep"></div>
                <div class="full"><button type="submit" class="btn-pf btn-pf-primary">Save Meta settings</button></div>
            </form>
        </section>
    </div>
</div>

<!-- ---------- Email ---------- -->
<div class="platform-tab-panel" data-panel="email" hidden>
    <div class="pfd-grid is-wide-left">
        <section class="platform-card">
            <div class="pfd-card-head"><h3>Email · <?= esc($emailProvider) ?></h3></div>
            <div class="pfd-minis">
                <?php foreach ([
                    'Sent' => $fmt($stats['email_sent'] ?? 0), 'Failed' => $fmt($stats['email_failed'] ?? 0), 'Delivered' => $fmt($stats['email_delivered'] ?? 0),
                    'Opened' => $fmt($stats['email_opened'] ?? 0), 'Clicked' => $fmt($stats['email_clicked'] ?? 0), 'Bounced' => $fmt($stats['email_bounced'] ?? 0),
                    'Complaints' => $fmt($stats['email_complained'] ?? 0), 'Unsubscribed' => $fmt($stats['email_unsubscribed'] ?? 0),
                    'Open %' => ((string) ($stats['email_open_rate'] ?? 0)) . '%', 'Click %' => ((string) ($stats['email_click_rate'] ?? 0)) . '%',
                    'Bounce %' => ((string) ($stats['email_bounce_rate'] ?? 0)) . '%',
                    'Senders' => $fmt($stats['email_senders_verified'] ?? 0) . '/' . $fmt($stats['email_senders_total'] ?? 0),
                ] as $label => $value): ?>
                    <div><span><?= esc($label) ?></span><strong><?= esc($value) ?></strong></div>
                <?php endforeach; ?>
            </div>
            <?php if (empty($stats['email_configured'])): ?>
                <div class="pfd-callout">Email provider credentials are not set. <a href="<?= site_url($clientUrl . '/settings') ?>#tabEmail">Open this client's Email settings</a>.</div>
            <?php elseif (! $emailReady): ?>
                <div class="pfd-callout">Provider is configured but no verified sender yet. Verify a sender or domain in Email Manager → Senders.</div>
            <?php endif; ?>
        </section>
        <section class="platform-card">
            <div class="pfd-card-head"><h3>Last 14 days</h3></div>
            <?= $bars($emailTrend, 'Emails / day', $emailTip) ?>
            <?php if ($emailTrend !== []): ?>
                <div class="pfd-minis is-4">
                    <div><span>14d sent</span><strong><?= $fmt($sum($emailTrend, 'sent')) ?></strong></div>
                    <div><span>14d failed</span><strong><?= $fmt($sum($emailTrend, 'failed')) ?></strong></div>
                    <div><span>14d opened</span><strong><?= $fmt($sum($emailTrend, 'opened')) ?></strong></div>
                    <div><span>14d bounced</span><strong><?= $fmt($sum($emailTrend, 'bounced')) ?></strong></div>
                </div>
            <?php endif; ?>
        </section>
    </div>

    <div class="pfd-grid">
        <section class="platform-card">
            <div class="pfd-card-head"><h3>Recent email campaigns</h3></div>
            <?php if (empty($stats['recent_email_campaigns'])): ?>
                <p class="pfd-muted">No email campaigns yet.</p>
            <?php else: ?>
                <div class="platform-table-wrap">
                    <table class="platform-table pfd-table">
                        <thead><tr><th>Campaign</th><th>Status</th><th class="num">Sent</th><th class="num">Failed</th><th>Date</th></tr></thead>
                        <tbody>
                        <?php foreach ($stats['recent_email_campaigns'] as $ec): ?>
                            <tr>
                                <td><strong><?= esc((string) ($ec['name'] ?? '—')) ?></strong><div class="platform-client-meta"><?= esc((string) ($ec['subject'] ?? '')) ?></div></td>
                                <td><span class="pfd-status"><?= esc(ucfirst((string) ($ec['status'] ?? '—'))) ?></span></td>
                                <td class="num"><?= $fmt($ec['sent_count'] ?? 0) ?></td>
                                <td class="num"><?= $fmt($ec['failed_count'] ?? 0) ?></td>
                                <td class="pfd-muted"><?= esc((string) ($ec['sent_at'] ?? $ec['created_at'] ?? '—')) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>

        <section class="platform-card">
            <div class="pfd-card-head"><h3>Senders &amp; domains</h3></div>
            <?php if (empty($stats['email_senders'])): ?>
                <p class="pfd-muted">No senders added yet.</p>
            <?php else: ?>
                <div class="platform-table-wrap">
                    <table class="platform-table pfd-table">
                        <thead><tr><th>Identity</th><th>Type</th><th>Status</th></tr></thead>
                        <tbody>
                        <?php foreach ($stats['email_senders'] as $s): ?>
                            <?php
                            $sStatus = (string) ($s['status'] ?? 'pending');
                            $senderProvider = (string) ($s['provider'] ?? '');
                            if ($senderProvider === 'cheerio') {
                                $senderProvider = 'External';
                            }
                            ?>
                            <tr>
                                <td>
                                    <strong><?= esc((string) (($s['type'] ?? '') === 'domain' ? ($s['domain'] ?? '') : ($s['email'] ?? ''))) ?></strong>
                                    <?php if (! empty($s['is_default'])): ?><span class="pfd-pill">default</span><?php endif; ?>
                                    <div class="platform-client-meta"><?= esc((string) ($s['name'] ?? '')) ?><?= $senderProvider !== '' ? ' · ' . esc($senderProvider) : '' ?></div>
                                </td>
                                <td><?= esc(ucfirst((string) ($s['type'] ?? 'sender'))) ?></td>
                                <td><span class="platform-badge <?= $senderBadge($sStatus) ?>"><?= esc(ucfirst($sStatus)) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    </div>
</div>

<!-- ---------- Wallet & country ---------- -->
<div class="platform-tab-panel" data-panel="wallet" hidden>
    <div class="pfd-grid">
        <section class="platform-card">
            <div class="pfd-card-head">
                <h3>WhatsApp wallet</h3>
                <span class="platform-badge <?= $walletOn ? 'platform-badge-ok' : 'platform-badge-warn' ?>"><?= $walletOn ? 'On' : 'Off' ?></span>
            </div>
            <div class="pfd-balance"><?= esc($symbol) ?><?= number_format($balance, 2) ?></div>
            <p class="pfd-muted"><?= $walletOn ? 'Messages are charged from this balance.' : 'Messages are not charged until the wallet is turned on.' ?></p>
            <div class="pfd-row-actions">
                <button type="button" class="btn-pf btn-pf-primary js-add-money" data-url="<?= site_url($clientUrl . '/wallet') ?>" data-name="<?= esc($name, 'attr') ?>" data-balance="<?= esc($symbol . number_format($balance, 2), 'attr') ?>" data-cur="<?= esc($cur, 'attr') ?>"><i class="fas fa-plus" aria-hidden="true"></i> Add money</button>
                <a class="btn-pf" href="<?= site_url($clientUrl . '/wallet') ?>">Turn on/off, alerts and GST</a>
                <a class="btn-pf" href="<?= site_url('platform/wallet-report?tenant=' . rawurlencode($key)) ?>">Transactions</a>
            </div>
        </section>

        <section class="platform-card">
            <div class="pfd-card-head"><h3>Country</h3></div>
            <?php if ($cName !== ''): ?>
                <dl class="pfd-dl">
                    <div><dt>Country</dt><dd><?= esc($cName) ?> (+<?= esc($cCode) ?>)</dd></div>
                    <div><dt>Wallet currency</dt><dd><?= esc($cur) ?></dd></div>
                    <div><dt>Default timezone</dt><dd><?= esc(\App\Libraries\WhatsAppCountries::timezoneOf($cCode)) ?></dd></div>
                </dl>
            <?php else: ?>
                <div class="pfd-callout is-warn">No country is set for this client yet. Choose one so the wallet currency and timezone are right.</div>
            <?php endif; ?>
            <form method="post" action="<?= site_url($clientUrl . '/country') ?>" class="pfd-inline-form">
                <?= csrf_field() ?>
                <label class="platform-label" for="clientCountry">Change country</label>
                <div class="pfd-inline">
                    <select class="platform-input" id="clientCountry" name="country_code" required>
                        <option value="">Choose a country…</option>
                        <optgroup label="Main markets">
                            <?php foreach ($cMarkets as $code => $cn): ?>
                                <option value="<?= esc((string) $code) ?>" <?= $cCode === (string) $code ? 'selected' : '' ?>><?= esc($cn) ?> (+<?= esc((string) $code) ?>)</option>
                            <?php endforeach; ?>
                        </optgroup>
                        <optgroup label="Other countries">
                            <?php foreach ($cOthers as $code => $cn): ?>
                                <option value="<?= esc((string) $code) ?>" <?= $cCode === (string) $code ? 'selected' : '' ?>><?= esc($cn) ?> (+<?= esc((string) $code) ?>)</option>
                            <?php endforeach; ?>
                        </optgroup>
                    </select>
                    <button type="submit" class="btn-pf btn-pf-accent">Save</button>
                </div>
                <div class="platform-help">The wallet currency follows the country while the wallet has no balance or transactions.</div>
            </form>
        </section>
    </div>
</div>

<!-- ---------- Access & limits ---------- -->
<div class="platform-tab-panel" data-panel="access" hidden>
    <div class="pfd-grid">
        <section class="platform-card">
            <div class="pfd-card-head"><h3>Client login</h3></div>
            <form method="post" action="<?= site_url($clientUrl . '/login') ?>" class="platform-form-grid">
                <?= csrf_field() ?>
                <div><label class="platform-label">Admin name</label><input class="platform-input" type="text" name="admin_name" value="<?= esc((string) ($adminName ?? $stats['admin_name'] ?? 'Admin')) ?>"></div>
                <div><label class="platform-label">Admin email</label><input class="platform-input" type="email" name="admin_email" required value="<?= esc($adminMail) ?>"></div>
                <?php
                $split     = \App\Libraries\WhatsAppCountries::splitPhone((string) ($adminPhone ?? ''), $cCode);
                $phoneCode = $split['code'] !== '' ? $split['code'] : ($cCode !== '' ? $cCode : '91');
                $allCountries = \App\Libraries\WhatsAppCountries::all();
                asort($allCountries);
                ?>
                <div>
                    <label class="platform-label" for="loginPhoneCountry">Country code</label>
                    <select class="platform-input" id="loginPhoneCountry" name="phone_country">
                        <?php foreach ($allCountries as $pc => $pn): ?>
                            <option value="<?= esc((string) $pc, 'attr') ?>" <?= (string) $pc === $phoneCode ? 'selected' : '' ?>><?= esc($pn) ?> (+<?= esc((string) $pc) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="platform-label" for="loginPhone">Mobile number</label>
                    <div class="pf-phone">
                        <span class="pf-phone-code" id="loginPhoneCode">+<?= esc($phoneCode) ?></span>
                        <input class="platform-input" id="loginPhone" type="tel" name="admin_phone" inputmode="numeric" autocomplete="tel-national"
                               value="<?= esc(old('admin_phone') ?? $split['national']) ?>" placeholder="Not set" aria-describedby="loginPhoneHint">
                    </div>
                    <div class="platform-help" id="loginPhoneHint">Leave blank to keep the current number.</div>
                </div>
                <div class="full">
                    <label class="platform-label">New password</label>
                    <input class="platform-input" type="text" name="admin_password" minlength="8" placeholder="Leave blank to keep the current password">
                    <div class="platform-help">Only fill this when creating or resetting the password.</div>
                </div>
                <div class="full"><button type="submit" class="btn-pf btn-pf-primary">Save login</button></div>
            </form>
        </section>

        <section class="platform-card" id="limits">
            <div class="pfd-card-head"><h3>Users and roles limit</h3></div>
            <p class="pfd-muted">This client can create users and roles only up to these numbers. Existing ones stay. Leave a field empty for no limit.</p>
            <form method="post" action="<?= site_url($clientUrl . '/limits') ?>" class="platform-form-grid">
                <?= csrf_field() ?>
                <div>
                    <label class="platform-label" for="maxUsers">Max users</label>
                    <input class="platform-input" type="number" min="1" id="maxUsers" name="max_users" value="<?= esc((string) ($seats['max_users'] ?? '')) ?>" placeholder="No limit">
                    <div class="platform-help">Now: <?= (int) ($seats['users'] ?? 0) ?> user<?= (int) ($seats['users'] ?? 0) === 1 ? '' : 's' ?></div>
                </div>
                <div>
                    <label class="platform-label" for="maxRoles">Max roles</label>
                    <input class="platform-input" type="number" min="1" id="maxRoles" name="max_roles" value="<?= esc((string) ($seats['max_roles'] ?? '')) ?>" placeholder="No limit">
                    <div class="platform-help">Now: <?= (int) ($seats['roles'] ?? 0) ?> role<?= (int) ($seats['roles'] ?? 0) === 1 ? '' : 's' ?></div>
                </div>
                <div class="full"><button type="submit" class="btn-pf btn-pf-primary">Save limits</button></div>
            </form>
        </section>
    </div>

    <?php if (! empty($canAssignOwner)): ?>
    <section class="platform-card">
        <div class="pfd-card-head"><h3>Super admin owner</h3></div>
        <p class="pfd-muted">This client shows on that super admin's dashboard and Embedded Signup.</p>
        <form method="post" action="<?= site_url($clientUrl . '/owner') ?>" class="pfd-inline-form">
            <?= csrf_field() ?>
            <label class="platform-label" for="clientOwner">Owner</label>
            <div class="pfd-inline">
                <select class="platform-input" id="clientOwner" name="platform_admin_id" required>
                    <?php foreach (($platformAdmins ?? []) as $admin): ?>
                        <?php if (strtolower((string) ($admin['status'] ?? '')) !== 'active') { continue; } ?>
                        <option value="<?= (int) $admin['id'] ?>" <?= (int) ($tenant['platform_admin_id'] ?? 0) === (int) $admin['id'] ? 'selected' : '' ?>>
                            <?= esc((string) ($admin['name'] ?? $admin['email'] ?? '')) ?><?= (int) ($admin['is_primary'] ?? 0) === 1 ? ' (Main)' : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn-pf btn-pf-accent">Save owner</button>
            </div>
        </form>
    </section>
    <?php endif; ?>
</div>

<!-- ============ Add money dialog ============ -->
<dialog class="pfc-dialog" id="pfcMoney" aria-labelledby="pfcMoneyTitle">
    <form method="post" id="pfcMoneyForm" action="">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="credit">
        <input type="hidden" name="back" value="show">
        <div class="pfc-dialog-head">
            <div>
                <h3 id="pfcMoneyTitle">Add money</h3>
                <p><span id="pfcMoneyName"></span> · balance <strong id="pfcMoneyBalance"></strong></p>
            </div>
            <button type="button" class="pfc-dialog-x" id="pfcMoneyClose" aria-label="Close">✕</button>
        </div>
        <label class="platform-label" for="pfcMoneyAmount">Amount (<span id="pfcMoneyCur"></span>)</label>
        <input class="platform-input" id="pfcMoneyAmount" type="number" name="amount" step="0.01" min="0.01" required inputmode="decimal" placeholder="0.00">
        <div class="pfc-quick" aria-label="Quick amounts">
            <button type="button" data-amt="500">500</button>
            <button type="button" data-amt="1000">1,000</button>
            <button type="button" data-amt="5000">5,000</button>
            <button type="button" data-amt="10000">10,000</button>
        </div>
        <label class="platform-label" for="pfcMoneyNote" style="margin-top:.75rem">Note <span class="pfc-opt">(payment reference, optional)</span></label>
        <input class="platform-input" id="pfcMoneyNote" type="text" name="note" maxlength="255" placeholder="e.g. UPI ref 4821…">
        <div class="pfc-dialog-foot">
            <button type="button" class="btn-pf" id="pfcMoneyCancel">Cancel</button>
            <button type="submit" class="btn-pf btn-pf-primary"><i class="fas fa-plus" aria-hidden="true"></i> Add to wallet</button>
        </div>
    </form>
</dialog>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
(function () {
    var tabs = document.querySelectorAll('.pfd-tab[data-tab]');
    var panels = document.querySelectorAll('.platform-tab-panel[data-panel]');
    if (!tabs.length) return;

    function show(name, push) {
        var found = false;
        tabs.forEach(function (t) {
            var on = t.getAttribute('data-tab') === name;
            if (on) found = true;
            t.classList.toggle('is-active', on);
            t.setAttribute('aria-selected', on ? 'true' : 'false');
        });
        if (!found) return show('all', push);
        panels.forEach(function (p) { p.hidden = p.getAttribute('data-panel') !== name; });
        if (push) history.replaceState(null, '', name === 'all' ? location.pathname : '#' + name);
    }
    tabs.forEach(function (t) { t.addEventListener('click', function () { show(t.getAttribute('data-tab'), true); }); });
    document.querySelectorAll('.js-goto-tab').forEach(function (a) {
        a.addEventListener('click', function (e) { e.preventDefault(); show(a.getAttribute('data-tab'), true); window.scrollTo({ top: 0, behavior: 'smooth' }); });
    });
    window.addEventListener('hashchange', function () { show((location.hash || '#all').slice(1), false); });
    show((location.hash || '#all').slice(1), false);

    // Add money dialog
    var dlg = document.getElementById('pfcMoney');
    var form = document.getElementById('pfcMoneyForm');
    var amount = document.getElementById('pfcMoneyAmount');
    document.querySelectorAll('.js-add-money').forEach(function (btn) {
        btn.addEventListener('click', function () {
            form.setAttribute('action', btn.getAttribute('data-url'));
            document.getElementById('pfcMoneyName').textContent = btn.getAttribute('data-name');
            document.getElementById('pfcMoneyBalance').textContent = btn.getAttribute('data-balance');
            document.getElementById('pfcMoneyCur').textContent = btn.getAttribute('data-cur');
            form.reset();
            if (typeof dlg.showModal === 'function') dlg.showModal(); else dlg.setAttribute('open', '');
            amount.focus();
        });
    });
    function close() { if (typeof dlg.close === 'function') dlg.close(); else dlg.removeAttribute('open'); }
    document.getElementById('pfcMoneyClose').addEventListener('click', close);
    document.getElementById('pfcMoneyCancel').addEventListener('click', close);
    dlg.addEventListener('click', function (e) { if (e.target === dlg) close(); });
    document.querySelectorAll('.pfc-quick button').forEach(function (b) {
        b.addEventListener('click', function () { amount.value = b.getAttribute('data-amt'); amount.focus(); });
    });
    form.addEventListener('submit', function () {
        var btn = form.querySelector('button[type="submit"]');
        if (btn) { btn.disabled = true; btn.textContent = 'Adding…'; }
    });
})();
</script>
<style>
.pf-phone { display: flex; align-items: stretch; }
.pf-phone-code { display: inline-flex; align-items: center; padding: 0 0.7rem; border: 1px solid var(--pf-line); border-right: 0; border-radius: 10px 0 0 10px; background: var(--pf-paper-2); font-weight: 700; font-size: 0.86rem; color: var(--pf-ink); white-space: nowrap; }
.pf-phone .platform-input { border-radius: 0 10px 10px 0; flex: 1; min-width: 0; }
.platform-input.is-invalid { border-color: var(--pf-down); background: #fff5f4; }
.platform-help.is-error { color: var(--pf-down); font-weight: 600; }
</style>
<script>
(function () {
    // Same per-country rules as the server (WhatsAppCountries::validatePhone).
    var RULES = <?= json_encode(\App\Libraries\WhatsAppCountries::phoneRules(), JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var sel = document.getElementById('loginPhoneCountry');
    var phone = document.getElementById('loginPhone');
    var code = document.getElementById('loginPhoneCode');
    var hint = document.getElementById('loginPhoneHint');
    if (!sel || !phone) return;

    function describe(c) {
        var r = RULES[c] || { min: 6, max: 14, first: '' };
        return (r.min === r.max ? r.min + ' digits' : r.min + ' to ' + r.max + ' digits') + (r.first ? ', starting with ' + r.first.replace(/[\[\]]/g, '') : '');
    }
    function check() {
        var c = sel.value, r = RULES[c] || { min: 6, max: 14, first: '' };
        var d = (phone.value || '').replace(/\D+/g, '');
        if (d === '') return '';
        if (d.indexOf('00') === 0) d = d.slice(2);
        if (d.length > r.max && d.indexOf(c) === 0) d = d.slice(c.length);
        d = d.replace(/^0+/, '');
        var ok = d.length >= r.min && d.length <= r.max && (!r.first || new RegExp('^' + r.first).test(d));
        return ok ? '' : 'Enter a valid mobile number for ' + sel.options[sel.selectedIndex].text.replace(/\s*\(\+\d+\)$/, '') + ' (' + describe(c) + ', without the +' + c + ').';
    }
    function show() {
        code.textContent = '+' + sel.value;
        var msg = check();
        phone.classList.toggle('is-invalid', msg !== '');
        hint.classList.toggle('is-error', msg !== '');
        hint.textContent = msg !== '' ? msg : (phone.value === '' ? 'Leave blank to keep the current number.' : 'Mobile number: ' + describe(sel.value) + ', without the +' + sel.value + '.');
        phone.setCustomValidity(msg);
    }
    sel.addEventListener('change', show);
    phone.addEventListener('input', show);
    show();
})();
</script>
<?= $this->endSection() ?>
