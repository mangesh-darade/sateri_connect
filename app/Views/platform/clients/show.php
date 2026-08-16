<?= $this->extend('layouts/platform') ?>

<?= $this->section('content') ?>
<?php
$tenant = $tenant ?? [];
$key    = (string) ($tenant['key'] ?? '');
$meta   = $meta ?? [];
$stats  = $stats ?? [];
$trend  = $stats['trend'] ?? [];
$fmt = static function ($n): string {
    return number_format((int) $n);
};
$health = (string) ($stats['health'] ?? 'down');
$badgeClass = $health === 'ok' ? 'platform-badge-ok' : ($health === 'warn' ? 'platform-badge-warn' : 'platform-badge-down');
$maxSent = 1;
foreach ($trend as $day) {
    $maxSent = max($maxSent, (int) ($day['sent'] ?? 0), (int) ($day['replies'] ?? 0));
}
?>

<section class="platform-deep-top">
    <div class="platform-card" style="margin-bottom:0">
        <div class="platform-card-head">
            <div>
                <h2><?= esc((string) ($tenant['name'] ?? $key)) ?></h2>
                <p><?= esc($key) ?> · DB <?= esc((string) ($tenant['db_database'] ?? '')) ?></p>
                <div class="platform-health-row">
                    <span class="platform-badge <?= $badgeClass ?>">
                        <i class="fas fa-circle" style="font-size:0.45rem"></i>
                        <?= esc((string) ($stats['health_label'] ?? $health)) ?>
                    </span>
                    <?php if (! empty($stats['meta_ready'])): ?>
                        <span class="platform-badge platform-badge-ok">Meta ready</span>
                    <?php else: ?>
                        <span class="platform-badge platform-badge-warn">Meta incomplete</span>
                    <?php endif; ?>
                    <?php if (! empty($stats['last_message_at'])): ?>
                        <span class="platform-client-meta">Last message · <?= esc((string) $stats['last_message_at']) ?></span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="platform-actions">
                <a class="btn-pf" href="<?= site_url('platform/clients') ?>">← All clients</a>
                <form action="<?= site_url('platform/clients/' . rawurlencode($key) . '/enter') ?>" method="post">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn-pf btn-pf-primary">Open workspace</button>
                </form>
            </div>
        </div>
        <div class="platform-mini-kpis">
            <div class="platform-mini-kpi"><span>Users</span><strong><?= $fmt($stats['users'] ?? 0) ?></strong></div>
            <div class="platform-mini-kpi"><span>Contacts</span><strong><?= $fmt($stats['contacts'] ?? 0) ?></strong></div>
            <div class="platform-mini-kpi"><span>Campaigns</span><strong><?= $fmt($stats['campaigns'] ?? 0) ?></strong></div>
            <div class="platform-mini-kpi"><span>Sent</span><strong><?= $fmt($stats['sent'] ?? 0) ?></strong></div>
            <div class="platform-mini-kpi"><span>Delivered</span><strong><?= $fmt($stats['delivered'] ?? 0) ?></strong></div>
            <div class="platform-mini-kpi"><span>Failed</span><strong><?= $fmt($stats['failed'] ?? 0) ?></strong></div>
            <div class="platform-mini-kpi"><span>Replies</span><strong><?= $fmt($stats['replies'] ?? 0) ?></strong></div>
            <div class="platform-mini-kpi"><span>Open chats</span><strong><?= $fmt($stats['open_chats'] ?? 0) ?></strong></div>
            <div class="platform-mini-kpi"><span>Queue</span><strong><?= $fmt($stats['queue'] ?? 0) ?></strong></div>
            <div class="platform-mini-kpi"><span>Delivery %</span><strong><?= esc((string) ($stats['delivery_rate'] ?? 0)) ?>%</strong></div>
            <div class="platform-mini-kpi"><span>Fail %</span><strong><?= esc((string) ($stats['fail_rate'] ?? 0)) ?>%</strong></div>
            <div class="platform-mini-kpi"><span>Active users</span><strong><?= $fmt($stats['users_active'] ?? 0) ?></strong></div>
        </div>
    </div>

    <div class="platform-card" style="margin-bottom:0">
        <h3 class="platform-section-title">Performance · last 14 days</h3>
        <?php if ($trend === []): ?>
            <p class="text-muted mb-0">No trend data (DB offline or empty).</p>
        <?php else: ?>
            <div class="platform-bars" title="Outbound messages per day">
                <?php foreach ($trend as $day): ?>
                    <?php
                    $h = (int) round((((int) ($day['sent'] ?? 0)) / $maxSent) * 100);
                    $h = max(4, $h);
                    ?>
                    <div class="platform-bar" style="height: <?= (int) $h ?>%" title="<?= esc(($day['date'] ?? '') . ': ' . (int) ($day['sent'] ?? 0) . ' sent') ?>"></div>
                <?php endforeach; ?>
            </div>
            <div class="platform-bar-label">
                <span><?= esc((string) ($trend[0]['date'] ?? '')) ?></span>
                <span>Sent / day</span>
                <span><?= esc((string) ($trend[count($trend) - 1]['date'] ?? '')) ?></span>
            </div>
            <div class="platform-mini-kpis" style="margin-top:0.9rem">
                <?php
                $tSent = $tFail = $tRep = 0;
                foreach ($trend as $day) {
                    $tSent += (int) ($day['sent'] ?? 0);
                    $tFail += (int) ($day['failed'] ?? 0);
                    $tRep  += (int) ($day['replies'] ?? 0);
                }
                ?>
                <div class="platform-mini-kpi"><span>14d sent</span><strong><?= $fmt($tSent) ?></strong></div>
                <div class="platform-mini-kpi"><span>14d failed</span><strong><?= $fmt($tFail) ?></strong></div>
                <div class="platform-mini-kpi"><span>14d replies</span><strong><?= $fmt($tRep) ?></strong></div>
            </div>
        <?php endif; ?>
    </div>
</section>

<?php if (! empty($stats['recent_campaigns'])): ?>
<section class="platform-card">
    <h3 class="platform-section-title">Recent campaigns</h3>
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
</section>
<?php endif; ?>

<section class="platform-split">
    <div class="platform-card">
        <h3 class="platform-section-title">Meta / WhatsApp settings</h3>
        <form method="post" action="<?= site_url('platform/clients/' . rawurlencode($key) . '/meta') ?>" class="platform-form-grid">
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

    <div class="platform-card">
        <h3 class="platform-section-title">Client login details</h3>
        <form method="post" action="<?= site_url('platform/clients/' . rawurlencode($key) . '/login') ?>" class="platform-form-grid">
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
</section>
<?= $this->endSection() ?>
