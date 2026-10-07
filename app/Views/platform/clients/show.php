<?= $this->extend('layouts/platform') ?>

<?= $this->section('content') ?>
<?php
$tenant = $tenant ?? [];
$key    = (string) ($tenant['key'] ?? '');
$meta   = $meta ?? [];
$stats  = $stats ?? [];
$trend  = $stats['trend'] ?? [];
$emailTrend = $stats['email_trend'] ?? [];
$fmt = static function ($n): string {
    return number_format((int) $n);
};
$health = (string) ($stats['health'] ?? 'down');
$badgeClass = $health === 'ok' ? 'platform-badge-ok' : ($health === 'warn' ? 'platform-badge-warn' : 'platform-badge-down');
$waReady    = ! empty($stats['meta_ready']);
$emailReady = ! empty($stats['email_ready']);
$emailProvider = (string) ($stats['email_provider_label'] ?? '—');
$clientUrl  = 'platform/clients/' . rawurlencode($key);

$senderBadge = static function (string $status): string {
    return match ($status) {
        'verified' => 'platform-badge-ok',
        'failed', 'disabled' => 'platform-badge-down',
        default => 'platform-badge-warn',
    };
};

/**
 * 14-day bar chart. $tip builds the tooltip for one day row.
 */
$bars = static function (array $rows, string $caption, callable $tip): string {
    if ($rows === []) {
        return '<p class="text-muted mb-0">No data in the last 14 days.</p>';
    }
    $max = 1;
    foreach ($rows as $day) {
        $max = max($max, (int) ($day['sent'] ?? 0));
    }
    $html = '<div class="platform-bars">';
    foreach ($rows as $day) {
        $h = max(4, (int) round(((int) ($day['sent'] ?? 0) / $max) * 100));
        $html .= '<div class="platform-bar" style="height:' . $h . '%" title="' . esc($tip($day)) . '"></div>';
    }
    $html .= '</div><div class="platform-bar-label"><span>' . esc((string) ($rows[0]['date'] ?? ''))
        . '</span><span>' . esc($caption) . '</span><span>' . esc((string) ($rows[count($rows) - 1]['date'] ?? '')) . '</span></div>';

    return $html;
};
$sum = static function (array $rows, string $field): int {
    return array_sum(array_map(static fn ($d) => (int) ($d[$field] ?? 0), $rows));
};
$waTip    = static fn (array $d): string => ($d['date'] ?? '') . ': ' . (int) ($d['sent'] ?? 0) . ' sent · ' . (int) ($d['replies'] ?? 0) . ' replies';
$emailTip = static fn (array $d): string => ($d['date'] ?? '') . ': ' . (int) ($d['sent'] ?? 0) . ' sent · ' . (int) ($d['opened'] ?? 0) . ' opened';
?>

<section class="platform-card">
    <div class="platform-card-head">
        <div>
            <h2><?= esc((string) ($tenant['name'] ?? $key)) ?></h2>
            <p><?= esc($key) ?> · DB <?= esc((string) ($tenant['db_database'] ?? '')) ?></p>
            <div class="platform-health-row">
                <span class="platform-badge <?= $badgeClass ?>">
                    <i class="fas fa-circle" style="font-size:0.45rem"></i>
                    <?= esc((string) ($stats['health_label'] ?? $health)) ?>
                </span>
                <span class="platform-badge <?= $waReady ? 'platform-badge-ok' : 'platform-badge-warn' ?>">WhatsApp <?= $waReady ? 'ready' : 'setup' ?></span>
                <span class="platform-badge <?= $emailReady ? 'platform-badge-ok' : 'platform-badge-warn' ?>">Email <?= $emailReady ? 'ready' : 'setup' ?> · <?= esc($emailProvider) ?></span>
                <?php if (! empty($stats['last_message_at'])): ?>
                    <span class="platform-client-meta">Last message · <?= esc((string) $stats['last_message_at']) ?></span>
                <?php endif; ?>
            </div>
        </div>
        <div class="platform-actions">
            <a class="btn-pf" href="<?= site_url('platform/clients') ?>">← All clients</a>
            <form action="<?= site_url($clientUrl . '/enter') ?>" method="post">
                <?= csrf_field() ?>
                <button type="submit" class="btn-pf btn-pf-primary">Open workspace</button>
            </form>
        </div>
    </div>

    <nav class="platform-tabs" role="tablist">
        <button type="button" class="platform-tab is-active" role="tab" data-tab="all">All</button>
        <button type="button" class="platform-tab" role="tab" data-tab="whatsapp">
            <span class="platform-tab-dot <?= $waReady ? 'is-ok' : '' ?>"></span>WhatsApp
        </button>
        <button type="button" class="platform-tab" role="tab" data-tab="email">
            <span class="platform-tab-dot <?= $emailReady ? 'is-ok' : '' ?>"></span>Email
        </button>
    </nav>
</section>

<?php /* ---------------- ALL ---------------- */ ?>
<div class="platform-tab-panel" data-panel="all">
    <section class="platform-card">
        <h3 class="platform-section-title">Workspace overview</h3>
        <div class="platform-mini-kpis is-wide">
            <div class="platform-mini-kpi"><span>Users</span><strong><?= $fmt($stats['users'] ?? 0) ?></strong></div>
            <div class="platform-mini-kpi"><span>Active users</span><strong><?= $fmt($stats['users_active'] ?? 0) ?></strong></div>
            <div class="platform-mini-kpi"><span>Contacts</span><strong><?= $fmt($stats['contacts'] ?? 0) ?></strong></div>
            <div class="platform-mini-kpi"><span>WhatsApp sent</span><strong><?= $fmt($stats['sent'] ?? 0) ?></strong></div>
            <div class="platform-mini-kpi"><span>WA delivery %</span><strong><?= esc((string) ($stats['delivery_rate'] ?? 0)) ?>%</strong></div>
            <div class="platform-mini-kpi"><span>WA replies</span><strong><?= $fmt($stats['replies'] ?? 0) ?></strong></div>
            <div class="platform-mini-kpi"><span>Emails sent</span><strong><?= $fmt($stats['email_sent'] ?? 0) ?></strong></div>
            <div class="platform-mini-kpi"><span>Email open %</span><strong><?= esc((string) ($stats['email_open_rate'] ?? 0)) ?>%</strong></div>
            <div class="platform-mini-kpi"><span>Email bounces</span><strong><?= $fmt($stats['email_bounced'] ?? 0) ?></strong></div>
            <div class="platform-mini-kpi"><span>WA campaigns</span><strong><?= $fmt($stats['campaigns'] ?? 0) ?></strong></div>
            <div class="platform-mini-kpi"><span>Open chats</span><strong><?= $fmt($stats['open_chats'] ?? 0) ?></strong></div>
            <div class="platform-mini-kpi"><span>Unsubscribed</span><strong><?= $fmt($stats['email_unsubscribed'] ?? 0) ?></strong></div>
        </div>
    </section>

    <section class="platform-split">
        <div class="platform-card">
            <h3 class="platform-section-title">WhatsApp · last 14 days</h3>
            <?= $bars($trend, 'Sent / day', $waTip) ?>
        </div>
        <div class="platform-card">
            <h3 class="platform-section-title">Email · last 14 days</h3>
            <?= $bars($emailTrend, 'Emails / day', $emailTip) ?>
        </div>
    </section>

    <section class="platform-split">
        <div class="platform-card">
            <h3 class="platform-section-title">Client login details</h3>
            <form method="post" action="<?= site_url($clientUrl . '/login') ?>" class="platform-form-grid">
                <?= csrf_field() ?>
                <div>
                    <label class="platform-label">Admin name</label>
                    <input class="platform-input" type="text" name="admin_name" value="<?= esc((string) ($adminName ?? $stats['admin_name'] ?? 'Admin')) ?>">
                </div>
                <div>
                    <label class="platform-label">Admin email</label>
                    <input class="platform-input" type="email" name="admin_email" required value="<?= esc((string) ($adminEmail ?? $stats['admin_email'] ?? '')) ?>">
                </div>
                <div class="full">
                    <label class="platform-label">New password</label>
                    <input class="platform-input" type="text" name="admin_password" minlength="8" placeholder="Leave blank to keep current password">
                    <div class="platform-help">Only fill when creating or resetting the password.</div>
                </div>
                <div class="full">
                    <button type="submit" class="btn-pf">Save login</button>
                </div>
            </form>
        </div>
        <div class="platform-card">
            <h3 class="platform-section-title">Channel status</h3>
            <div class="platform-mini-kpis">
                <div class="platform-mini-kpi"><span>WhatsApp</span><strong><?= $waReady ? 'Ready' : 'Setup' ?></strong></div>
                <div class="platform-mini-kpi"><span>Email</span><strong><?= $emailReady ? 'Ready' : 'Setup' ?></strong></div>
                <div class="platform-mini-kpi"><span>Email provider</span><strong><?= esc($emailProvider) ?></strong></div>
                <div class="platform-mini-kpi"><span>Phone number ID</span><strong><?= esc((string) (($meta['phone_number_id'] ?? '') !== '' ? $meta['phone_number_id'] : '—')) ?></strong></div>
                <div class="platform-mini-kpi"><span>Senders verified</span><strong><?= $fmt($stats['email_senders_verified'] ?? 0) ?>/<?= $fmt($stats['email_senders_total'] ?? 0) ?></strong></div>
                <div class="platform-mini-kpi"><span>WA queue</span><strong><?= $fmt($stats['queue'] ?? 0) ?></strong></div>
            </div>
        </div>
    </section>
</div>

<?php /* ---------------- WHATSAPP ---------------- */ ?>
<div class="platform-tab-panel" data-panel="whatsapp" hidden>
    <section class="platform-deep-top">
        <div class="platform-card" style="margin-bottom:0">
            <h3 class="platform-section-title">WhatsApp</h3>
            <div class="platform-mini-kpis">
                <div class="platform-mini-kpi"><span>Campaigns</span><strong><?= $fmt($stats['campaigns'] ?? 0) ?></strong></div>
                <div class="platform-mini-kpi"><span>Sent</span><strong><?= $fmt($stats['sent'] ?? 0) ?></strong></div>
                <div class="platform-mini-kpi"><span>Delivered</span><strong><?= $fmt($stats['delivered'] ?? 0) ?></strong></div>
                <div class="platform-mini-kpi"><span>Read</span><strong><?= $fmt($stats['read'] ?? 0) ?></strong></div>
                <div class="platform-mini-kpi"><span>Failed</span><strong><?= $fmt($stats['failed'] ?? 0) ?></strong></div>
                <div class="platform-mini-kpi"><span>Replies</span><strong><?= $fmt($stats['replies'] ?? 0) ?></strong></div>
                <div class="platform-mini-kpi"><span>Open chats</span><strong><?= $fmt($stats['open_chats'] ?? 0) ?></strong></div>
                <div class="platform-mini-kpi"><span>Queue</span><strong><?= $fmt($stats['queue'] ?? 0) ?></strong></div>
                <div class="platform-mini-kpi"><span>Delivery %</span><strong><?= esc((string) ($stats['delivery_rate'] ?? 0)) ?>%</strong></div>
                <div class="platform-mini-kpi"><span>Fail %</span><strong><?= esc((string) ($stats['fail_rate'] ?? 0)) ?>%</strong></div>
            </div>
            <?php if (! $waReady): ?>
                <div class="platform-help" style="margin-top:0.75rem">Phone number ID and access token are required. Fill Meta settings below or let the client use Connect WhatsApp.</div>
            <?php endif; ?>
        </div>

        <div class="platform-card" style="margin-bottom:0">
            <h3 class="platform-section-title">Performance · last 14 days</h3>
            <?= $bars($trend, 'Sent / day', $waTip) ?>
            <?php if ($trend !== []): ?>
                <div class="platform-mini-kpis" style="margin-top:0.9rem">
                    <div class="platform-mini-kpi"><span>14d sent</span><strong><?= $fmt($sum($trend, 'sent')) ?></strong></div>
                    <div class="platform-mini-kpi"><span>14d failed</span><strong><?= $fmt($sum($trend, 'failed')) ?></strong></div>
                    <div class="platform-mini-kpi"><span>14d replies</span><strong><?= $fmt($sum($trend, 'replies')) ?></strong></div>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <section class="platform-split">
        <div class="platform-card">
            <h3 class="platform-section-title">Recent campaigns</h3>
            <?php if (empty($stats['recent_campaigns'])): ?>
                <p class="text-muted mb-0">No WhatsApp campaigns yet.</p>
            <?php else: ?>
                <div class="platform-table-wrap">
                    <table class="platform-table">
                        <thead>
                        <tr><th>Name</th><th>Status</th><th>Created</th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($stats['recent_campaigns'] as $camp): ?>
                            <tr>
                                <td><?= esc((string) ($camp['name'] ?? '—')) ?></td>
                                <td><?= esc((string) ($camp['status'] ?? '—')) ?></td>
                                <td><?= esc((string) ($camp['created_at'] ?? '—')) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <div class="platform-card">
            <h3 class="platform-section-title">Meta / WhatsApp settings</h3>
            <form method="post" action="<?= site_url($clientUrl . '/meta') ?>#whatsapp" class="platform-form-grid">
                <?= csrf_field() ?>
                <div>
                    <label class="platform-label">Display / app name</label>
                    <input class="platform-input" type="text" name="app_name" value="<?= esc((string) ($appName ?? '')) ?>">
                </div>
                <div>
                    <label class="platform-label">Meta App ID</label>
                    <input class="platform-input" type="text" name="app_id" value="<?= esc((string) ($meta['app_id'] ?? '')) ?>">
                </div>
                <div>
                    <label class="platform-label">WABA ID</label>
                    <input class="platform-input" type="text" name="waba_id" value="<?= esc((string) ($meta['waba_id'] ?? '')) ?>">
                </div>
                <div>
                    <label class="platform-label">Phone number ID</label>
                    <input class="platform-input" type="text" name="phone_number_id" value="<?= esc((string) ($meta['phone_number_id'] ?? '')) ?>">
                </div>
                <div>
                    <label class="platform-label">Business ID</label>
                    <input class="platform-input" type="text" name="business_id" value="<?= esc((string) ($meta['business_id'] ?? '')) ?>">
                </div>
                <div>
                    <label class="platform-label">Webhook verify token</label>
                    <input class="platform-input" type="text" name="verify_token" value="<?= esc((string) ($meta['verify_token'] ?? '')) ?>">
                </div>
                <div>
                    <label class="platform-label">Access token</label>
                    <input class="platform-input" type="password" name="access_token" value="<?= esc((string) ($meta['access_token'] ?? '')) ?>" autocomplete="new-password" placeholder="Leave blank to keep">
                </div>
                <div>
                    <label class="platform-label">App secret</label>
                    <input class="platform-input" type="password" name="app_secret" value="<?= esc((string) ($meta['app_secret'] ?? '')) ?>" autocomplete="new-password" placeholder="Leave blank to keep">
                </div>
                <div class="full">
                    <button type="submit" class="btn-pf btn-pf-primary">Save Meta settings</button>
                </div>
            </form>
        </div>
    </section>
</div>

<?php /* ---------------- EMAIL ---------------- */ ?>
<div class="platform-tab-panel" data-panel="email" hidden>
    <section class="platform-deep-top">
        <div class="platform-card" style="margin-bottom:0">
            <h3 class="platform-section-title">Email · <?= esc($emailProvider) ?></h3>
            <div class="platform-mini-kpis">
                <div class="platform-mini-kpi"><span>Sent</span><strong><?= $fmt($stats['email_sent'] ?? 0) ?></strong></div>
                <div class="platform-mini-kpi"><span>Failed</span><strong><?= $fmt($stats['email_failed'] ?? 0) ?></strong></div>
                <div class="platform-mini-kpi"><span>Delivered</span><strong><?= $fmt($stats['email_delivered'] ?? 0) ?></strong></div>
                <div class="platform-mini-kpi"><span>Opened</span><strong><?= $fmt($stats['email_opened'] ?? 0) ?></strong></div>
                <div class="platform-mini-kpi"><span>Clicked</span><strong><?= $fmt($stats['email_clicked'] ?? 0) ?></strong></div>
                <div class="platform-mini-kpi"><span>Bounced</span><strong><?= $fmt($stats['email_bounced'] ?? 0) ?></strong></div>
                <div class="platform-mini-kpi"><span>Complaints</span><strong><?= $fmt($stats['email_complained'] ?? 0) ?></strong></div>
                <div class="platform-mini-kpi"><span>Unsubscribed</span><strong><?= $fmt($stats['email_unsubscribed'] ?? 0) ?></strong></div>
                <div class="platform-mini-kpi"><span>Open %</span><strong><?= esc((string) ($stats['email_open_rate'] ?? 0)) ?>%</strong></div>
                <div class="platform-mini-kpi"><span>Click %</span><strong><?= esc((string) ($stats['email_click_rate'] ?? 0)) ?>%</strong></div>
                <div class="platform-mini-kpi"><span>Bounce %</span><strong><?= esc((string) ($stats['email_bounce_rate'] ?? 0)) ?>%</strong></div>
                <div class="platform-mini-kpi"><span>Senders</span><strong><?= $fmt($stats['email_senders_verified'] ?? 0) ?>/<?= $fmt($stats['email_senders_total'] ?? 0) ?></strong></div>
            </div>
            <?php if (empty($stats['email_configured'])): ?>
                <div class="platform-help" style="margin-top:0.75rem">Email provider credentials are not set. Open the workspace → Settings → Email to configure.</div>
            <?php elseif (! $emailReady): ?>
                <div class="platform-help" style="margin-top:0.75rem">Provider is configured but no verified sender yet. Verify a sender/domain in Email Manager → Senders.</div>
            <?php endif; ?>
        </div>

        <div class="platform-card" style="margin-bottom:0">
            <h3 class="platform-section-title">Performance · last 14 days</h3>
            <?= $bars($emailTrend, 'Emails / day', $emailTip) ?>
            <?php if ($emailTrend !== []): ?>
                <div class="platform-mini-kpis" style="margin-top:0.9rem">
                    <div class="platform-mini-kpi"><span>14d sent</span><strong><?= $fmt($sum($emailTrend, 'sent')) ?></strong></div>
                    <div class="platform-mini-kpi"><span>14d failed</span><strong><?= $fmt($sum($emailTrend, 'failed')) ?></strong></div>
                    <div class="platform-mini-kpi"><span>14d opened</span><strong><?= $fmt($sum($emailTrend, 'opened')) ?></strong></div>
                    <div class="platform-mini-kpi"><span>14d bounced</span><strong><?= $fmt($sum($emailTrend, 'bounced')) ?></strong></div>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <section class="platform-split">
        <div class="platform-card">
            <h3 class="platform-section-title">Recent email campaigns</h3>
            <?php if (empty($stats['recent_email_campaigns'])): ?>
                <p class="text-muted mb-0">No email campaigns yet.</p>
            <?php else: ?>
                <div class="platform-table-wrap">
                    <table class="platform-table">
                        <thead>
                        <tr><th>Name</th><th>Status</th><th>Sent</th><th>Failed</th><th>Date</th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($stats['recent_email_campaigns'] as $ec): ?>
                            <tr>
                                <td>
                                    <div class="platform-client-name"><?= esc((string) ($ec['name'] ?? '—')) ?></div>
                                    <div class="platform-client-meta"><?= esc((string) ($ec['subject'] ?? '')) ?></div>
                                </td>
                                <td><?= esc((string) ($ec['status'] ?? '—')) ?></td>
                                <td><?= $fmt($ec['sent_count'] ?? 0) ?></td>
                                <td><?= $fmt($ec['failed_count'] ?? 0) ?></td>
                                <td><?= esc((string) ($ec['sent_at'] ?? $ec['created_at'] ?? '—')) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <div class="platform-card">
            <h3 class="platform-section-title">Senders &amp; domains</h3>
            <?php if (empty($stats['email_senders'])): ?>
                <p class="text-muted mb-0">No senders added yet.</p>
            <?php else: ?>
                <div class="platform-table-wrap">
                    <table class="platform-table">
                        <thead>
                        <tr><th>Identity</th><th>Type</th><th>Status</th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($stats['email_senders'] as $s): ?>
                            <?php $sStatus = (string) ($s['status'] ?? 'pending'); ?>
                            <tr>
                                <td>
                                    <div class="platform-client-name">
                                        <?= esc((string) (($s['type'] ?? '') === 'domain' ? ($s['domain'] ?? '') : ($s['email'] ?? ''))) ?>
                                        <?php if (! empty($s['is_default'])): ?><span class="platform-client-meta">· default</span><?php endif; ?>
                                    </div>
                                    <div class="platform-client-meta"><?= esc((string) ($s['name'] ?? '')) ?><?= ! empty($s['provider']) ? ' · ' . esc((string) $s['provider']) : '' ?></div>
                                </td>
                                <td><?= esc(ucfirst((string) ($s['type'] ?? 'sender'))) ?></td>
                                <td><span class="platform-badge <?= $senderBadge($sStatus) ?>"><?= esc(ucfirst($sStatus)) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </section>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
(function () {
    var tabs = document.querySelectorAll('.platform-tab[data-tab]');
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
        panels.forEach(function (p) {
            p.hidden = p.getAttribute('data-panel') !== name;
        });
        if (push) history.replaceState(null, '', name === 'all' ? location.pathname : '#' + name);
    }

    tabs.forEach(function (t) {
        t.addEventListener('click', function () { show(t.getAttribute('data-tab'), true); });
    });
    show((location.hash || '#all').slice(1), false);
})();
</script>
<?= $this->endSection() ?>
