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
<div class="page-list analytics-page" id="analyticsPage">
    <div class="em-hero mb-3">
        <div>
            <h4 class="mb-1">Global Analytics</h4>
            <p class="text-muted small mb-0">WhatsApp delivery + Email send performance in one place.</p>
        </div>
        <form method="get" action="<?= site_url('analytics') ?>" class="filter-bar">
            <input type="hidden" name="tab" value="<?= esc($activeTab) ?>">
            <input type="date" name="from" class="form-control form-control-sm" style="max-width:150px" value="<?= $from ?>">
            <input type="date" name="to" class="form-control form-control-sm" style="max-width:150px" value="<?= $to ?>">
            <button type="submit" class="btn btn-wa btn-sm"><i class="fas fa-filter me-1"></i> Apply</button>
        </form>
    </div>

    <ul class="nav nav-tabs em-tabs mb-3">
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
        <div class="row g-2 mb-2">
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

        <div class="row g-2 mb-2">
            <div class="col-lg-8">
                <div class="dash-panel">
                    <div class="panel-head"><h3>WhatsApp delivery trend</h3></div>
                    <div class="panel-body" style="height:320px">
                        <?php $ch = $wa['charts'] ?? []; ?>
                        <canvas id="waTrendChart"
                            data-labels='<?= json_encode($ch['labels'] ?? []) ?>'
                            data-sent='<?= json_encode($ch['sent'] ?? []) ?>'
                            data-delivered='<?= json_encode($ch['delivered'] ?? []) ?>'
                            data-failed='<?= json_encode($ch['failed'] ?? []) ?>'></canvas>
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
        <div class="row g-2 mb-2">
            <?php
            $cards = [
                ['Total logs', $emailSum['total'] ?? 0, 'kpi-accent-teal'],
                ['Sent', $emailSum['sent'] ?? 0, 'kpi-accent-green'],
                ['Failed', $emailSum['failed'] ?? 0, 'kpi-accent-danger'],
                ['Queued', $emailSum['queued'] ?? 0, 'kpi-accent-amber'],
            ];
            foreach ($cards as [$label, $num, $accent]):
            ?>
            <div class="col-6 col-md-3">
                <div class="kpi-card <?= $accent ?>">
                    <span class="kpi-label"><?= esc($label) ?></span>
                    <span class="kpi-value"><?= esc(number_format((int) $num)) ?></span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <div class="row g-2 mb-2">
            <div class="col-lg-8">
                <div class="dash-panel">
                    <div class="panel-head"><h3>Email send trend</h3></div>
                    <div class="panel-body" style="height:320px">
                        <?php $ch = $email['charts'] ?? []; ?>
                        <canvas id="emailTrendChart"
                            data-labels='<?= json_encode($ch['labels'] ?? []) ?>'
                            data-sent='<?= json_encode($ch['sent'] ?? []) ?>'
                            data-failed='<?= json_encode($ch['failed'] ?? []) ?>'></canvas>
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

        <div class="row g-2">
            <div class="col-lg-6">
                <div class="dash-panel">
                    <div class="panel-head d-flex justify-content-between">
                        <h3>HTML campaigns</h3>
                        <a href="<?= site_url('email-manager?tab=campaigns') ?>" class="btn btn-xs btn-outline-secondary">Manage</a>
                    </div>
                    <div class="panel-body p-0">
                        <div class="table-responsive">
                            <table class="table table-sm mb-0">
                                <thead><tr><th>Name</th><th>Status</th><th>Sent</th></tr></thead>
                                <tbody>
                                <?php if (empty($email['campaigns'])): ?>
                                    <tr><td colspan="3" class="text-muted text-center py-3">No email campaigns.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($email['campaigns'] as $c): ?>
                                    <?php
                                    if (! is_array($c)) {
                                        continue;
                                    }
                                    $emailCampaignName = (string) ($c['name'] ?? ('Email Campaign #' . (int) ($c['id'] ?? 0)));
                                    $emailCampaignStatus = (string) ($c['status'] ?? 'draft');
                                    ?>
                                    <tr>
                                        <td><?= esc($emailCampaignName) ?></td>
                                        <td><?= esc($emailCampaignStatus) ?></td>
                                        <td><?= (int) ($c['sent_count'] ?? 0) ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="dash-panel">
                    <div class="panel-head"><h3>Recent email logs</h3></div>
                    <div class="panel-body p-0">
                        <div class="table-responsive">
                            <table class="table table-sm mb-0">
                                <thead><tr><th>When</th><th>Kind</th><th>To</th><th>Status</th></tr></thead>
                                <tbody>
                                <?php if (empty($email['logs'])): ?>
                                    <tr><td colspan="4" class="text-muted text-center py-3">No email logs yet. Sends will appear here.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($email['logs'] as $log): ?>
                                    <?php
                                    if (! is_array($log)) {
                                        continue;
                                    }
                                    $logStatus = (string) ($log['status'] ?? 'unknown');
                                    ?>
                                    <tr>
                                        <td class="small text-nowrap"><?= esc($log['created_at'] ?? '') ?></td>
                                        <td><?= esc($log['kind'] ?? '') ?></td>
                                        <td class="small"><?= esc(mb_strimwidth((string) ($log['to_email'] ?? ''), 0, 40, '…')) ?></td>
                                        <td><span class="badge text-bg-<?= $logStatus === 'sent' ? 'success' : ($logStatus === 'queued' ? 'warning' : 'danger') ?>"><?= esc($logStatus) ?></span></td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>
<?= $this->endSection() ?>

<?= $this->section('styles') ?>
<link rel="stylesheet" href="<?= base_url('assets/css/email-manager.css') ?>?v=1">
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= base_url('assets/js/analytics.js') ?>?v=1"></script>
<?= $this->endSection() ?>
