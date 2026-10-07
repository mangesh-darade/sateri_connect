<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>
<?php
$activeTab = $activeTab ?? 'whatsapp';
$from = esc($from ?? date('Y-m-01'));
$to   = esc($to ?? date('Y-m-d'));
$wa = $wa ?? ['summary' => [], 'charts' => [], 'campaigns' => []];
$email = $email ?? ['summary' => [], 'charts' => [], 'campaigns' => [], 'logs' => []];
$waSum = $wa['summary'] ?? [];
$emailSum = $email['summary'] ?? [];
?>
<div class="page-list analytics-page page-stack" id="analyticsPage">
    <div class="card">
        <div class="card-body py-3">
            <form method="get" action="<?= site_url('analytics') ?>" class="filter-bar mb-0">
                <input type="hidden" name="tab" value="<?= esc($activeTab) ?>">
                <input type="date" name="from" class="form-control form-control-sm" style="max-width:150px" value="<?= esc($from) ?>" title="From">
                <input type="date" name="to" class="form-control form-control-sm" style="max-width:150px" value="<?= esc($to) ?>" title="To">
                <div class="filter-bar-actions">
                    <button type="submit" class="btn btn-wa btn-sm"><i class="fas fa-filter me-1"></i> Apply</button>
                </div>
            </form>
        </div>
    </div>

    <ul class="nav nav-tabs em-tabs">
        <li class="nav-item">
            <a class="nav-link <?= $activeTab === 'whatsapp' ? 'active' : '' ?>"
               href="<?= site_url('analytics?tab=whatsapp&from=' . urlencode($from) . '&to=' . urlencode($to)) ?>">
                <i class="fab fa-whatsapp me-1"></i> WhatsApp Analytics
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= $activeTab === 'email' ? 'active' : '' ?>"
               href="<?= site_url('analytics?tab=email&from=' . urlencode($from) . '&to=' . urlencode($to)) ?>">
                <i class="fas fa-envelope me-1"></i> Email Analytics
            </a>
        </li>
    </ul>

    <?php if ($activeTab === 'whatsapp'): ?>
        <div class="page-section">
            <div class="page-section-head">
                <h2 class="page-section-title">Delivery snapshot</h2>
            </div>
            <div class="row g-2">
            <?php
            $cards = [
                ['Sent', $waSum['sent'] ?? 0, 'kpi-accent-teal'],
                ['Delivered', $waSum['delivered'] ?? 0, 'kpi-accent-green'],
                ['Read', $waSum['read'] ?? 0, 'kpi-accent-sky'],
                ['Failed', $waSum['failed'] ?? 0, 'kpi-accent-danger'],
                ['Replies', $waSum['replies'] ?? 0, 'kpi-accent-amber'],
            ];
            foreach ($cards as [$label, $num, $accent]):
            ?>
            <div class="col-6 col-md">
                <div class="kpi-card <?= $accent ?>">
                    <span class="kpi-label"><?= esc($label) ?></span>
                    <span class="kpi-value"><?= esc(number_format((int) $num)) ?></span>
                </div>
            </div>
            <?php endforeach; ?>
            </div>
        </div>

        <div class="row g-2">
            <div class="col-lg-8">
                <div class="dash-panel">
                    <div class="panel-head"><h3>WhatsApp delivery trend</h3></div>
                    <div class="panel-body" style="height:320px">
                        <?php $ch = $wa['charts'] ?? []; ?>
                        <canvas id="waTrendChart"
                            data-labels='<?= json_encode($ch['labels'] ?? [], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) ?>'
                            data-sent='<?= json_encode($ch['sent'] ?? [], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) ?>'
                            data-delivered='<?= json_encode($ch['delivered'] ?? [], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) ?>'
                            data-failed='<?= json_encode($ch['failed'] ?? [], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) ?>'></canvas>
                    </div>
                </div>
            </div>
            <div class="col-lg-4">
                <div class="dash-panel">
                    <div class="panel-head"><h3>Status mix</h3></div>
                    <div class="panel-body" style="height:320px">
                        <canvas id="waMixChart"
                            data-delivered="<?= (int) ($waSum['delivered'] ?? 0) ?>"
                            data-read="<?= (int) ($waSum['read'] ?? 0) ?>"
                            data-failed="<?= (int) ($waSum['failed'] ?? 0) ?>"
                            data-replies="<?= (int) ($waSum['replies'] ?? 0) ?>"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <div class="dash-panel">
            <div class="panel-head d-flex justify-content-between">
                <h3>Recent WhatsApp campaigns</h3>
                <a href="<?= site_url('reports') ?>" class="btn btn-xs btn-outline-secondary">Full reports</a>
            </div>
            <div class="panel-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0">
                        <thead><tr><th>Name</th><th>Status</th><th>Sent</th><th>Delivered</th><th>Failed</th></tr></thead>
                        <tbody>
                        <?php if (empty($wa['campaigns'])): ?>
                            <tr><td colspan="5" class="text-muted text-center py-3">No campaigns.</td></tr>
                        <?php else: ?>
                            <?php foreach ($wa['campaigns'] as $c): ?>
                            <?php
                            if (! is_array($c)) {
                                continue;
                            }
                            $waCampaignId = (int) ($c['id'] ?? 0);
                            $waCampaignName = (string) ($c['name'] ?? ($waCampaignId > 0 ? ('Campaign #' . $waCampaignId) : 'Campaign'));
                            $waCampaignStatus = (string) ($c['status'] ?? 'unknown');
                            ?>
                            <tr>
                                <td>
                                    <?php if ($waCampaignId > 0): ?>
                                        <a href="<?= site_url('campaigns/' . $waCampaignId) ?>"><?= esc($waCampaignName) ?></a>
                                    <?php else: ?>
                                        <?= esc($waCampaignName) ?>
                                    <?php endif; ?>
                                </td>
                                <td><?= esc($waCampaignStatus) ?></td>
                                <td><?= (int) ($c['sent_count'] ?? 0) ?></td>
                                <td><?= (int) ($c['delivered_count'] ?? 0) ?></td>
                                <td><?= (int) ($c['failed_count'] ?? 0) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    <?php else: ?>
        <?php
        $sent = (int) ($emailSum['sent'] ?? 0);
        $total = (int) ($emailSum['total'] ?? 0);
        $opened = (int) ($emailSum['opened'] ?? 0);
        $clicked = (int) ($emailSum['clicked'] ?? 0);
        $failed = (int) ($emailSum['failed'] ?? 0);
        $openRate = (float) ($emailSum['open_rate'] ?? 0.0);
        $clickRate = (float) ($emailSum['click_rate'] ?? 0.0);
        $unsubCount = (int) ($email['unsub_count'] ?? 0);
        $unsubscribes = $email['unsubscribes'] ?? [];
        $deliveryRate = $total > 0 ? round(($sent / $total) * 100, 1) : 0.0;
        $ctor = $opened > 0 ? round(($clicked / $opened) * 100, 1) : 0.0;
        ?>
        <div class="page-section">
            <div class="page-section-head">
                <h2 class="page-section-title">Email Delivery &amp; Engagement Snapshot</h2>
            </div>
            <div class="row g-2">
                <div class="col-6 col-md-2">
                    <div class="kpi-card kpi-accent-teal">
                        <span class="kpi-label">Total Sent</span>
                        <span class="kpi-value"><?= number_format($sent) ?></span>
                        <span class="small text-muted" style="font-size:0.72rem;"><?= $deliveryRate ?>% delivered</span>
                    </div>
                </div>
                <div class="col-6 col-md-2">
                    <div class="kpi-card kpi-accent-green">
                        <span class="kpi-label">Unique Opens</span>
                        <span class="kpi-value"><?= number_format($opened) ?></span>
                        <span class="small text-success fw-semibold" style="font-size:0.72rem;"><?= $openRate ?>% open rate</span>
                    </div>
                </div>
                <div class="col-6 col-md-2">
                    <div class="kpi-card kpi-accent-amber">
                        <span class="kpi-label">Unique Clicks</span>
                        <span class="kpi-value"><?= number_format($clicked) ?></span>
                        <span class="small text-warning fw-semibold" style="font-size:0.72rem;"><?= $clickRate ?>% click rate</span>
                    </div>
                </div>
                <div class="col-6 col-md-2">
                    <div class="kpi-card kpi-accent-sky">
                        <span class="kpi-label">CTOR</span>
                        <span class="kpi-value"><?= $ctor ?>%</span>
                        <span class="small text-muted" style="font-size:0.72rem;">Click-to-Open</span>
                    </div>
                </div>
                <div class="col-6 col-md-2">
                    <div class="kpi-card kpi-accent-danger">
                        <span class="kpi-label">Unsubscribed</span>
                        <span class="kpi-value"><?= number_format($unsubCount) ?></span>
                        <span class="small text-muted" style="font-size:0.72rem;">Suppressed list</span>
                    </div>
                </div>
                <div class="col-6 col-md-2">
                    <div class="kpi-card kpi-accent-danger">
                        <span class="kpi-label">Failed / Bounce</span>
                        <span class="kpi-value"><?= number_format($failed) ?></span>
                        <span class="small text-muted" style="font-size:0.72rem;">Undelivered</span>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-2">
            <div class="col-lg-8">
                <div class="dash-panel">
                    <div class="panel-head"><h3>Email send trend</h3></div>
                    <div class="panel-body" style="height:320px">
                        <?php $ch = $email['charts'] ?? []; ?>
                        <canvas id="emailTrendChart"
                            data-labels='<?= json_encode($ch['labels'] ?? [], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) ?>'
                            data-sent='<?= json_encode($ch['sent'] ?? [], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) ?>'
                            data-failed='<?= json_encode($ch['failed'] ?? [], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) ?>'></canvas>
                    </div>
                </div>
            </div>
            <div class="col-lg-4">
                <div class="dash-panel">
                    <div class="panel-head"><h3>Sent vs failed</h3></div>
                    <div class="panel-body" style="height:320px">
                        <canvas id="emailMixChart"
                            data-sent="<?= (int) ($emailSum['sent'] ?? 0) ?>"
                            data-failed="<?= (int) ($emailSum['failed'] ?? 0) ?>"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <!-- Recent Outbound Engagement Activity -->
        <div class="row g-2 mt-1">
            <div class="col-lg-8">
                <div class="dash-panel">
                    <div class="panel-head d-flex justify-content-between align-items-center">
                        <h3 class="mb-0">Recent Outbound Activity &amp; Engagement</h3>
                        <span class="text-muted small">Real-time open &amp; click tracking</span>
                    </div>
                    <div class="panel-body p-0">
                        <div class="table-responsive">
                            <table class="table table-sm table-hover align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Recipient</th>
                                        <th>Subject</th>
                                        <th>Status</th>
                                        <th>Opens</th>
                                        <th>Clicks</th>
                                        <th>When</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php if (empty($email['logs'])): ?>
                                    <tr><td colspan="6" class="text-muted text-center py-4">No email logs yet. Sends will appear here.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($email['logs'] as $log): ?>
                                    <?php
                                    if (! is_array($log)) {
                                        continue;
                                    }
                                    $logStatus = (string) ($log['status'] ?? 'unknown');
                                    $openCount = (int) ($log['open_count'] ?? 0);
                                    $clickCount = (int) ($log['click_count'] ?? 0);
                                    ?>
                                    <tr>
                                        <td>
                                            <span class="fw-semibold text-dark"><?= esc($log['to_email'] ?? '—') ?></span>
                                            <div class="d-flex align-items-center gap-2" style="font-size:0.7rem;">
                                                <?php if (! empty($log['kind'])): ?>
                                                    <span class="text-muted"><?= esc(ucfirst((string) $log['kind'])) ?></span>
                                                <?php endif; ?>
                                                <?php if (! empty($log['id'])): ?>
                                                    <a href="#" class="js-email-recipients text-decoration-none fw-semibold" data-log-id="<?= (int) $log['id'] ?>"><i class="fas fa-users me-1"></i><span>Recipients</span></a>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td class="text-truncate small" style="max-width: 180px;" title="<?= esc($log['subject'] ?? '') ?>">
                                            <?= esc($log['subject'] ?? '—') ?>
                                        </td>
                                        <td>
                                            <span class="badge text-bg-<?= $logStatus === 'sent' ? 'success' : ($logStatus === 'queued' ? 'warning' : 'danger') ?> rounded-pill px-2">
                                                <?= esc(ucfirst($logStatus)) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($openCount > 0): ?>
                                                <span class="badge bg-info-subtle text-info border border-info-subtle" title="Last opened: <?= esc($log['opened_at'] ?? '') ?>">
                                                    <i class="fas fa-eye me-1"></i><?= $openCount ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="text-muted opacity-50">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($clickCount > 0): ?>
                                                <span class="badge bg-warning-subtle text-warning border border-warning-subtle" title="Last clicked: <?= esc($log['clicked_at'] ?? '') ?>">
                                                    <i class="fas fa-mouse-pointer me-1"></i><?= $clickCount ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="text-muted opacity-50">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-muted small text-nowrap">
                                            <?= esc(format_app_datetime($log['created_at'] ?? null)) ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Unsubscribe Suppression List -->
            <div class="col-lg-4">
                <div class="dash-panel">
                    <div class="panel-head d-flex justify-content-between align-items-center">
                        <h3 class="mb-0">Suppression List</h3>
                        <span class="badge bg-danger rounded-pill"><?= number_format($unsubCount) ?></span>
                    </div>
                    <div class="panel-body p-0">
                        <?php if (empty($unsubscribes)): ?>
                            <div class="text-center py-4 text-muted small">
                                <i class="fas fa-shield-alt fa-2x mb-2 text-success opacity-75"></i>
                                <p class="mb-0">No unsubscribes recorded.</p>
                            </div>
                        <?php else: ?>
                            <ul class="list-group list-group-flush small">
                                <?php foreach ($unsubscribes as $u): ?>
                                    <li class="list-group-item d-flex justify-content-between align-items-center py-2 px-3">
                                        <div class="text-truncate me-2">
                                            <div class="fw-semibold text-dark text-truncate"><?= esc($u['email']) ?></div>
                                            <div class="text-muted" style="font-size: 0.7rem;"><?= esc($u['reason'] ?? 'User requested') ?></div>
                                        </div>
                                        <span class="badge bg-secondary-subtle text-secondary" style="font-size: 0.68rem;">
                                            <?= \App\Libraries\AppDateTime::format($u['created_at'] ?? null, 'd M') ?>
                                        </span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>
<?= view('partials/email_recipients_modal') ?>
<?= $this->endSection() ?>

<?= $this->section('styles') ?>
<link rel="stylesheet" href="<?= asset_url('assets/css/email-manager.css') ?>">
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= asset_url('assets/js/analytics.js') ?>"></script>
<script src="<?= asset_url('assets/js/email-recipients.js') ?>"></script>
<?= $this->endSection() ?>
