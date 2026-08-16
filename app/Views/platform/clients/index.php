<?= $this->extend('layouts/platform') ?>

<?= $this->section('content') ?>
<?php
$totals  = $totals ?? [];
$clients = $clients ?? [];
$fmt = static function ($n): string {
    return number_format((int) $n);
};
?>

<section class="platform-kpi-grid">
    <div class="platform-kpi">
        <div class="platform-kpi-label">Clients</div>
        <div class="platform-kpi-value"><?= $fmt($totals['clients'] ?? 0) ?></div>
        <div class="platform-kpi-hint"><?= $fmt($totals['online'] ?? 0) ?> healthy · <?= $fmt($totals['warn'] ?? 0) ?> setup</div>
    </div>
    <div class="platform-kpi">
        <div class="platform-kpi-label">Contacts</div>
        <div class="platform-kpi-value"><?= $fmt($totals['contacts'] ?? 0) ?></div>
        <div class="platform-kpi-hint">Across all workspaces</div>
    </div>
    <div class="platform-kpi">
        <div class="platform-kpi-label">Messages sent</div>
        <div class="platform-kpi-value"><?= $fmt($totals['sent'] ?? 0) ?></div>
        <div class="platform-kpi-hint">Delivery <?= esc((string) ($totals['delivery_rate'] ?? 0)) ?>%</div>
    </div>
    <div class="platform-kpi">
        <div class="platform-kpi-label">Failed</div>
        <div class="platform-kpi-value"><?= $fmt($totals['failed'] ?? 0) ?></div>
        <div class="platform-kpi-hint">Fail rate <?= esc((string) ($totals['fail_rate'] ?? 0)) ?>%</div>
    </div>
    <div class="platform-kpi">
        <div class="platform-kpi-label">Open chats</div>
        <div class="platform-kpi-value"><?= $fmt($totals['open_chats'] ?? 0) ?></div>
        <div class="platform-kpi-hint">Queue <?= $fmt($totals['queue'] ?? 0) ?> pending</div>
    </div>
    <div class="platform-kpi">
        <div class="platform-kpi-label">Meta ready</div>
        <div class="platform-kpi-value"><?= $fmt($totals['meta_ready'] ?? 0) ?>/<?= $fmt($totals['clients'] ?? 0) ?></div>
        <div class="platform-kpi-hint">WhatsApp connected</div>
    </div>
</section>

<div class="platform-section-label">
    <h2>Analytics</h2>
    <p>Live performance across every client workspace</p>
</div>

<section class="platform-charts-grid">
    <article class="platform-card platform-chart-card">
        <div class="platform-card-head">
            <div>
                <h2>Message trends</h2>
                <p>Sent · delivered · failed · replies · 14 days</p>
            </div>
        </div>
        <div class="platform-chart-wrap is-lg">
            <canvas id="pfTrendChart" aria-label="Message trends chart"></canvas>
        </div>
    </article>
    <article class="platform-card platform-chart-card">
        <div class="platform-card-head">
            <div>
                <h2>Fleet health</h2>
                <p>Healthy · setup needed · offline</p>
            </div>
        </div>
        <div class="platform-chart-wrap">
            <canvas id="pfHealthChart" aria-label="Fleet health chart"></canvas>
        </div>
    </article>
</section>

<section class="platform-charts-grid">
    <article class="platform-card platform-chart-card">
        <div class="platform-card-head">
            <div>
                <h2>Contacts by client</h2>
                <p>Audience size vs messages sent</p>
            </div>
        </div>
        <div class="platform-chart-wrap">
            <canvas id="pfContactsChart" aria-label="Contacts by client chart"></canvas>
        </div>
    </article>
    <article class="platform-card platform-chart-card">
        <div class="platform-card-head">
            <div>
                <h2>Delivery mix</h2>
                <p>Delivered vs failed vs other</p>
            </div>
        </div>
        <div class="platform-chart-wrap">
            <canvas id="pfDeliveryChart" aria-label="Delivery mix chart"></canvas>
        </div>
    </article>
</section>

<div class="platform-section-label" id="clients">
    <h2>Client fleet</h2>
    <p>Health, usage and Meta status for every workspace</p>
</div>

<section class="platform-card">
    <div class="platform-card-head">
        <div>
            <h2>All clients</h2>
            <p><?= $fmt(count($clients)) ?> workspace<?= count($clients) === 1 ? '' : 's' ?> registered</p>
        </div>
        <a class="btn-pf btn-pf-primary" href="<?= site_url('platform/clients/create') ?>">Create client</a>
    </div>

    <?php if ($clients === []): ?>
        <div class="platform-empty">
            <strong>No clients yet</strong>
            <p>Create the first workspace to start tracking performance.</p>
            <a class="btn-pf btn-pf-accent" href="<?= site_url('platform/clients/create') ?>">Create client</a>
        </div>
    <?php else: ?>
        <div class="platform-table-wrap">
            <table class="platform-table">
                <thead>
                <tr>
                    <th>Client</th>
                    <th>Health</th>
                    <th>Users</th>
                    <th>Contacts</th>
                    <th>Sent</th>
                    <th>Failed</th>
                    <th>Chats</th>
                    <th>Meta</th>
                    <th class="is-actions">Actions</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($clients as $c): ?>
                    <?php
                    $key = (string) ($c['key'] ?? '');
                    $health = (string) ($c['health'] ?? 'down');
                    $badgeClass = $health === 'ok' ? 'platform-badge-ok' : ($health === 'warn' ? 'platform-badge-warn' : 'platform-badge-down');
                    ?>
                    <tr>
                        <td>
                            <div class="platform-client-name"><?= esc((string) ($c['name'] ?? $key)) ?></div>
                            <div class="platform-client-meta"><?= esc($key) ?> · <?= esc((string) ($c['db_database'] ?? '')) ?></div>
                        </td>
                        <td>
                            <span class="platform-badge <?= $badgeClass ?>">
                                <?= esc((string) ($c['health_label'] ?? $health)) ?>
                            </span>
                            <?php if (! empty($c['error'])): ?>
                                <div class="platform-client-meta"><?= esc((string) $c['error']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td><span class="platform-stat-chip"><?= $fmt($c['users'] ?? 0) ?></span></td>
                        <td><span class="platform-stat-chip"><?= $fmt($c['contacts'] ?? 0) ?></span></td>
                        <td><span class="platform-stat-chip"><?= $fmt($c['sent'] ?? 0) ?></span></td>
                        <td><span class="platform-stat-chip"><?= $fmt($c['failed'] ?? 0) ?></span></td>
                        <td><span class="platform-stat-chip"><?= $fmt($c['open_chats'] ?? 0) ?></span></td>
                        <td>
                            <?php if (! empty($c['meta_ready'])): ?>
                                <span class="platform-badge platform-badge-ok">Ready</span>
                            <?php else: ?>
                                <span class="platform-badge platform-badge-warn">Setup</span>
                            <?php endif; ?>
                        </td>
                        <td class="is-actions">
                            <div class="platform-actions">
                                <a class="btn-pf btn-pf-primary" href="<?= site_url('platform/clients/' . rawurlencode($key)) ?>">Deep view</a>
                                <form action="<?= site_url('platform/clients/' . rawurlencode($key) . '/enter') ?>" method="post">
                                    <?= csrf_field() ?>
                                    <button type="submit" class="btn-pf">Open</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.2/dist/chart.umd.min.js"></script>
<script>
(function () {
    var data = <?= $chartsJson ?? '{}' ?>;
    if (!window.Chart || !data || !data.trend) return;

    var teal = '#0b6e4f';
    var coral = '#c45c12';
    var ink = '#1a2330';
    var mute = '#6b7780';
    var soft = '#6f9b4c';

    Chart.defaults.font.family = "'Figtree', system-ui, sans-serif";
    Chart.defaults.color = mute;
    Chart.defaults.plugins.legend.labels.usePointStyle = true;
    Chart.defaults.plugins.legend.labels.boxWidth = 8;
    Chart.defaults.plugins.legend.labels.padding = 14;

    function lineChart(el, trend) {
        new Chart(el, {
            type: 'line',
            data: {
                labels: trend.labels || [],
                datasets: [
                    { label: 'Sent', data: trend.sent || [], borderColor: teal, backgroundColor: 'rgba(11,110,79,.10)', tension: 0.35, fill: true, borderWidth: 2.5, pointRadius: 2 },
                    { label: 'Delivered', data: trend.delivered || [], borderColor: soft, backgroundColor: 'transparent', tension: 0.35, borderWidth: 2, pointRadius: 2 },
                    { label: 'Failed', data: trend.failed || [], borderColor: coral, backgroundColor: 'transparent', tension: 0.35, borderWidth: 2, pointRadius: 2 },
                    { label: 'Replies', data: trend.replies || [], borderColor: ink, backgroundColor: 'transparent', tension: 0.35, borderWidth: 2, borderDash: [5, 4], pointRadius: 2 }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: { legend: { position: 'bottom' } },
                scales: {
                    x: { grid: { display: false }, ticks: { maxRotation: 0 } },
                    y: { beginAtZero: true, grid: { color: 'rgba(26,35,48,.06)' }, ticks: { precision: 0 } }
                }
            }
        });
    }

    var elTrend = document.getElementById('pfTrendChart');
    if (elTrend) lineChart(elTrend, data.trend || {});

    var health = data.health || {};
    var elHealth = document.getElementById('pfHealthChart');
    if (elHealth) {
        new Chart(elHealth, {
            type: 'doughnut',
            data: {
                labels: health.labels || [],
                datasets: [{ data: health.values || [], backgroundColor: [teal, '#c79200', coral], borderWidth: 0, hoverOffset: 4 }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { position: 'bottom' } },
                cutout: '68%'
            }
        });
    }

    var clients = data.clients || {};
    var elContacts = document.getElementById('pfContactsChart');
    if (elContacts) {
        new Chart(elContacts, {
            type: 'bar',
            data: {
                labels: clients.labels || [],
                datasets: [
                    { label: 'Contacts', data: clients.contacts || [], backgroundColor: teal, borderRadius: 5, maxBarThickness: 28 },
                    { label: 'Sent', data: clients.sent || [], backgroundColor: 'rgba(196,92,18,.78)', borderRadius: 5, maxBarThickness: 28 }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { position: 'bottom' } },
                scales: {
                    x: { grid: { display: false } },
                    y: { beginAtZero: true, grid: { color: 'rgba(26,35,48,.06)' }, ticks: { precision: 0 } }
                }
            }
        });
    }

    var delivery = data.delivery || {};
    var elDelivery = document.getElementById('pfDeliveryChart');
    if (elDelivery) {
        new Chart(elDelivery, {
            type: 'doughnut',
            data: {
                labels: delivery.labels || [],
                datasets: [{ data: delivery.values || [], backgroundColor: [teal, coral, '#c2cdc6'], borderWidth: 0, hoverOffset: 4 }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { position: 'bottom' } },
                cutout: '62%'
            }
        });
    }
})();
</script>
<?= $this->endSection() ?>
