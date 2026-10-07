<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>
<?php
$stats           = $stats ?? [];
$channels        = $channels ?? [];
$recentCampaigns = $recentCampaigns ?? [];
$activityRows    = $recentActivity ?? [];
$canCreate       = function_exists('can') && can('campaigns.create');
$showEmail       = ! function_exists('can') || can('emails.view');

$pct = static fn (int $part, int $whole): string => $whole > 0 ? round($part / $whole * 100, 1) . '% of sent' : "\u{00A0}";

$wa = $channels['whatsapp']['all'] ?? ['sent' => 0, 'delivered' => 0, 'read' => 0, 'failed' => 0, 'replies' => 0];
$em = $channels['email']['all'] ?? ['sent' => 0, 'opened' => 0, 'clicked' => 0, 'bounced' => 0, 'failed' => 0];

$channelCards = [
    [
        'key'     => 'whatsapp',
        'title'   => 'WhatsApp',
        'icon'    => 'fab fa-whatsapp',
        'url'     => site_url('reports/delivery'),
        'today'   => (int) ($channels['whatsapp']['today']['sent'] ?? 0),
        'month'   => (int) ($channels['whatsapp']['month']['sent'] ?? 0),
        'metrics' => [
            ['label' => 'Sent', 'value' => $wa['sent'], 'hint' => 'All-time'],
            ['label' => 'Delivered', 'value' => $wa['delivered'], 'hint' => $pct($wa['delivered'], $wa['sent'])],
            ['label' => 'Read', 'value' => $wa['read'], 'hint' => $pct($wa['read'], $wa['sent'])],
            ['label' => 'Replies', 'value' => $wa['replies'], 'hint' => 'Inbound'],
            ['label' => 'Failed', 'value' => $wa['failed'], 'hint' => $wa['failed'] > 0 ? 'Needs attention' : 'All good', 'alert' => $wa['failed'] > 0],
        ],
    ],
];
if ($showEmail) {
    $channelCards[] = [
        'key'     => 'email',
        'title'   => 'Email',
        'icon'    => 'fas fa-envelope',
        'url'     => site_url('email-manager?tab=analytics'),
        'today'   => (int) ($channels['email']['today']['sent'] ?? 0),
        'month'   => (int) ($channels['email']['month']['sent'] ?? 0),
        'metrics' => [
            ['label' => 'Sent', 'value' => $em['sent'], 'hint' => 'All-time'],
            ['label' => 'Opened', 'value' => $em['opened'], 'hint' => $pct($em['opened'], $em['sent'])],
            ['label' => 'Clicked', 'value' => $em['clicked'], 'hint' => $pct($em['clicked'], $em['sent'])],
            ['label' => 'Bounced', 'value' => $em['bounced'], 'hint' => 'Auto-suppressed', 'alert' => $em['bounced'] > 0],
            ['label' => 'Failed', 'value' => $em['failed'], 'hint' => $em['failed'] > 0 ? 'Needs attention' : 'All good', 'alert' => $em['failed'] > 0],
        ],
    ];
}

$overview = [
    ['url' => 'contacts', 'class' => 'kpi-hero', 'icon' => 'fa-address-book', 'label' => 'Contacts', 'value' => $stats['contacts'] ?? 0, 'meta' => 'Audience library →'],
    ['url' => 'campaigns', 'class' => 'kpi-accent-sky', 'icon' => 'fa-bullhorn', 'label' => 'Campaigns', 'value' => $stats['campaigns'] ?? 0, 'meta' => 'WhatsApp + Email →'],
    ['url' => 'chat', 'class' => 'kpi-accent-green', 'icon' => 'fa-comments', 'label' => 'Open chats', 'value' => $stats['open_chats'] ?? 0, 'meta' => 'Live inbox →'],
    ['url' => 'queue', 'class' => 'kpi-accent-amber', 'icon' => 'fa-stream', 'label' => 'Queue pending', 'value' => $stats['queue_pending'] ?? 0, 'meta' => 'Waiting to send →'],
];
?>
<div class="page-stack dashboard-page">

<div class="page-section">
    <div class="row g-3 kpi-grid">
        <?php foreach ($overview as $k): ?>
            <div class="col-lg-3 col-6">
                <a href="<?= site_url($k['url']) ?>" class="kpi-card kpi-compact <?= esc($k['class'], 'attr') ?>">
                    <span class="kpi-icon"><i class="fas <?= esc($k['icon'], 'attr') ?>"></i></span>
                    <span class="kpi-label"><?= esc($k['label']) ?></span>
                    <span class="kpi-value"><?= esc(number_format((int) $k['value'])) ?></span>
                    <span class="kpi-meta"><?= esc($k['meta']) ?></span>
                </a>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<div class="page-section">
    <div class="row g-3 dash-row">
        <?php foreach ($channelCards as $card): ?>
            <div class="<?= count($channelCards) > 1 ? 'col-xl-6' : 'col-12' ?>">
                <?= view('dashboard/_channel_card', $card) ?>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<div class="page-section">
    <div class="row g-3 dash-row">
        <div class="col-lg-8">
            <div class="dash-panel">
                <div class="panel-head">
                    <h3>Sent by channel · 14 days</h3>
                </div>
                <div class="panel-body">
                    <div class="chart-frame">
                        <canvas id="chartTrends" data-show-email="<?= $showEmail ? '1' : '0' ?>"></canvas>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="dash-panel">
                <div class="panel-head">
                    <h3>Campaigns by status</h3>
                </div>
                <div class="panel-body">
                    <div class="chart-frame" id="chartCampaignsFrame">
                        <canvas id="chartCampaigns"></canvas>
                        <div class="chart-empty d-none" id="chartCampaignsEmpty" aria-hidden="true">
                            <i class="fas fa-chart-pie"></i>
                            <div class="chart-empty-title">No campaigns yet</div>
                            <div class="chart-empty-sub">Create a WhatsApp or Email campaign to see status mix here.</div>
                            <?php if ($canCreate): ?>
                                <a href="<?= site_url('campaigns/create') ?>" class="btn btn-sm btn-wa mt-1">New campaign</a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="page-section">
    <div class="row g-3 dash-row">
        <div class="col-lg-5">
            <div class="dash-panel">
                <div class="panel-head">
                    <h3>Recent campaigns</h3>
                    <div class="d-flex gap-1">
                        <?php if ($canCreate): ?>
                            <a href="<?= site_url('campaigns/create') ?>" class="btn btn-sm btn-wa">New</a>
                        <?php endif; ?>
                        <a href="<?= site_url('campaigns') ?>" class="btn btn-sm btn-outline-secondary">All</a>
                    </div>
                </div>
                <div class="panel-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm table-hover mb-0 align-middle">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Status</th>
                                    <th class="text-end">Sent</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($recentCampaigns !== []): ?>
                                    <?php foreach ($recentCampaigns as $c): ?>
                                        <tr>
                                            <td>
                                                <a class="fw-semibold text-decoration-none d-inline-flex align-items-center gap-2" href="<?= esc($c['url'], 'attr') ?>">
                                                    <i class="<?= $c['channel'] === 'email' ? 'fas fa-envelope text-primary' : 'fab fa-whatsapp text-success' ?>" title="<?= $c['channel'] === 'email' ? 'Email' : 'WhatsApp' ?>"></i>
                                                    <?= esc($c['name']) ?>
                                                </a>
                                            </td>
                                            <td><?= view('partials/status_badge', ['status' => $c['status']]) ?></td>
                                            <td class="text-end"><?= esc(number_format($c['sent'])) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="3">
                                            <div class="activity-empty py-4">
                                                <i class="fas fa-bullhorn"></i>
                                                No campaigns yet
                                                <?php if ($canCreate): ?>
                                                    <div class="mt-2"><a href="<?= site_url('campaigns/create') ?>" class="btn btn-sm btn-wa">Create campaign</a></div>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-7">
            <div class="dash-panel">
                <div class="panel-head">
                    <h3>Recent activity</h3>
                    <?php if (function_exists('can') && (can('reports.view') || can('settings.view') || can('users.view'))): ?>
                        <a href="<?= site_url('activity-logs') ?>" class="btn btn-sm btn-outline-secondary">All</a>
                    <?php endif; ?>
                </div>
                <div class="panel-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm table-hover mb-0 align-middle">
                            <thead>
                                <tr>
                                    <th>Time</th>
                                    <th>User</th>
                                    <th>Action</th>
                                    <th>Details</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (! empty($activityRows) && is_array($activityRows)): ?>
                                    <?php foreach ($activityRows as $row): ?>
                                        <tr>
                                            <td class="text-muted small text-nowrap"><?= esc(format_app_datetime($row['created_at'] ?? null)) ?></td>
                                            <td><?= esc($row['user_name'] ?? 'System') ?></td>
                                            <td><span class="badge badge-soft"><?= esc($row['action'] ?? '') ?></span></td>
                                            <td class="small text-muted"><?= esc($row['description'] ?? '') ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="4">
                                            <div class="activity-empty">
                                                <i class="fas fa-inbox"></i>
                                                No recent activity yet
                                            </div>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
window.dashboardCharts = <?= json_encode($charts ?? [], JSON_HEX_TAG | JSON_HEX_AMP) ?>;
</script>
<script src="<?= asset_url('assets/js/dashboard.js') ?>"></script>
<?= $this->endSection() ?>
