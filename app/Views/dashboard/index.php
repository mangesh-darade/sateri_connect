<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>
<?php
$stats = $stats ?? [];
$get = static function (array $stats, string $key, $default = 0) {
    return $stats[$key] ?? $default;
};
$recentCampaigns = $recentCampaigns ?? [];
$activityRows = $recentActivity ?? $recent_activity ?? [];
$failedCount = (int) $get($stats, 'failed');
?>
<div class="row g-2 mb-3 kpi-grid">
    <div class="col-lg-3 col-6">
        <a href="<?= site_url('contacts') ?>" class="kpi-card kpi-hero">
            <span class="kpi-icon"><i class="fas fa-address-book"></i></span>
            <span class="kpi-label">Contacts</span>
            <span class="kpi-value"><?= esc(number_format((int) $get($stats, 'contacts'))) ?></span>
            <span class="kpi-meta">Audience library →</span>
        </a>
    </div>
    <div class="col-lg-3 col-6">
        <a href="<?= site_url('campaigns') ?>" class="kpi-card kpi-accent-sky">
            <span class="kpi-icon"><i class="fas fa-bullhorn"></i></span>
            <span class="kpi-label">Campaigns</span>
            <span class="kpi-value"><?= esc(number_format((int) $get($stats, 'campaigns'))) ?></span>
            <span class="kpi-meta">Broadcasts →</span>
        </a>
    </div>
    <div class="col-lg-3 col-6">
        <a href="<?= site_url('chat') ?>" class="kpi-card kpi-accent-green">
            <span class="kpi-icon"><i class="fas fa-comments"></i></span>
            <span class="kpi-label">Open chats</span>
            <span class="kpi-value"><?= esc(number_format((int) $get($stats, 'open_chats'))) ?></span>
            <span class="kpi-meta">Live inbox →</span>
        </a>
    </div>
    <div class="col-lg-3 col-6">
        <a href="<?= site_url('queue') ?>" class="kpi-card kpi-accent-amber">
            <span class="kpi-icon"><i class="fas fa-stream"></i></span>
            <span class="kpi-label">Queue pending</span>
            <span class="kpi-value"><?= esc(number_format((int) $get($stats, 'queue_pending'))) ?></span>
            <span class="kpi-meta">Waiting to send →</span>
        </a>
    </div>
</div>

<div class="row g-2 mb-3 kpi-grid kpi-grid-metrics">
    <div class="col-6 col-md-4 col-xl-2">
        <a href="<?= site_url('reports') ?>" class="kpi-card kpi-compact kpi-accent-teal">
            <span class="kpi-label">Sent</span>
            <span class="kpi-value"><?= esc(number_format((int) $get($stats, 'sent'))) ?></span>
            <span class="kpi-meta">All-time</span>
        </a>
    </div>
    <div class="col-6 col-md-4 col-xl-2">
        <a href="<?= site_url('reports') ?>" class="kpi-card kpi-compact kpi-accent-green">
            <span class="kpi-label">Delivered</span>
            <span class="kpi-value"><?= esc(number_format((int) $get($stats, 'delivered'))) ?></span>
            <span class="kpi-meta">Reached inbox</span>
        </a>
    </div>
    <div class="col-6 col-md-4 col-xl-2">
        <a href="<?= site_url('reports/delivery') ?>" class="kpi-card kpi-compact kpi-accent-sky">
            <span class="kpi-label">Read</span>
            <span class="kpi-value"><?= esc(number_format((int) $get($stats, 'read'))) ?></span>
            <span class="kpi-meta">Opened</span>
        </a>
    </div>
    <div class="col-6 col-md-4 col-xl-2">
        <a href="<?= site_url('reports/delivery') ?>" class="kpi-card kpi-compact kpi-accent-danger<?= $failedCount > 0 ? ' is-alert' : '' ?>">
            <span class="kpi-label">Failed</span>
            <span class="kpi-value"><?= esc(number_format($failedCount)) ?></span>
            <span class="kpi-meta">Needs attention</span>
        </a>
    </div>
    <div class="col-6 col-md-4 col-xl-2">
        <a href="<?= site_url('chat') ?>" class="kpi-card kpi-compact kpi-accent-blue">
            <span class="kpi-label">Replies</span>
            <span class="kpi-value"><?= esc(number_format((int) $get($stats, 'replies'))) ?></span>
            <span class="kpi-meta">Inbound</span>
        </a>
    </div>
    <div class="col-6 col-md-4 col-xl-2">
        <div class="kpi-card kpi-compact kpi-accent-ink">
            <span class="kpi-label">Today</span>
            <span class="kpi-value"><?= esc(number_format((int) $get($stats, 'today'))) ?></span>
            <span class="kpi-meta"><?= esc(number_format((int) $get($stats, 'this_month'))) ?> this month</span>
        </div>
    </div>
</div>

<div class="row g-2 mb-3">
    <div class="col-lg-8">
        <div class="dash-panel">
            <div class="panel-head">
                <h3>Message trends (14 days)</h3>
            </div>
            <div class="panel-body">
                <div class="chart-frame">
                    <?php $trends = $charts['trends'] ?? []; ?>
                    <canvas id="chartTrends"
                        data-labels='<?= json_encode($trends['labels'] ?? []) ?>'
                        data-sent='<?= json_encode($trends['sent'] ?? []) ?>'
                        data-delivered='<?= json_encode($trends['delivered'] ?? []) ?>'
                        data-read='<?= json_encode($trends['read'] ?? []) ?>'
                        data-failed='<?= json_encode($trends['failed'] ?? []) ?>'
                        data-replies='<?= json_encode($trends['replies'] ?? []) ?>'></canvas>
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
                <div class="chart-frame">
                    <?php $camp = $charts['campaigns'] ?? []; ?>
                    <canvas id="chartCampaigns"
                        data-labels='<?= json_encode($camp['labels'] ?? []) ?>'
                        data-values='<?= json_encode($camp['values'] ?? []) ?>'></canvas>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row g-2 mb-2">
    <div class="col-lg-5">
        <div class="dash-panel">
            <div class="panel-head">
                <h3>Recent campaigns</h3>
                <div class="d-flex gap-1">
                    <?php if (function_exists('can') && can('campaigns.create')): ?>
                        <a href="<?= site_url('campaigns/create') ?>" class="btn btn-sm btn-wa">New</a>
                    <?php endif; ?>
                    <a href="<?= site_url('campaigns') ?>" class="btn btn-sm btn-outline-secondary">All</a>
                </div>
            </div>
            <div class="panel-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0 align-middle">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Status</th>
                                <th class="text-end">Sent</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (! empty($recentCampaigns)): ?>
                                <?php foreach ($recentCampaigns as $c): ?>
                                    <tr>
                                        <td>
                                            <a class="fw-semibold text-decoration-none" href="<?= site_url('campaigns/' . (int) ($c['id'] ?? 0)) ?>">
                                                <?= esc($c['name'] ?? 'Campaign') ?>
                                            </a>
                                        </td>
                                        <td><?= view('partials/status_badge', ['status' => $c['status'] ?? 'draft']) ?></td>
                                        <td class="text-end"><?= esc((string) ($c['sent_count'] ?? 0)) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="3">
                                        <div class="activity-empty py-4">
                                            <i class="fas fa-bullhorn"></i>
                                            No campaigns yet
                                            <?php if (function_exists('can') && can('campaigns.create')): ?>
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
            </div>
            <div class="panel-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0 align-middle">
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
                                        <td class="text-muted small text-nowrap"><?= esc($row['created_at'] ?? '') ?></td>
                                        <td><?= esc($row['user_name'] ?? 'System') ?></td>
                                        <td><span class="badge badge-soft"><?= esc($row['action'] ?? $row['event'] ?? '') ?></span></td>
                                        <td class="small text-muted"><?= esc($row['description'] ?? $row['details'] ?? '') ?></td>
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
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
window.dashboardCharts = <?= json_encode($charts ?? []) ?>;
</script>
<script src="<?= base_url('assets/js/dashboard.js') ?>?v=2"></script>
<?= $this->endSection() ?>
