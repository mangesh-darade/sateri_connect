<?= $this->extend('layouts/main') ?>

<?= $this->section('header_actions') ?>
<div class="d-flex align-items-center gap-1">
    <a href="<?= site_url('reports') ?>" class="btn btn-outline-secondary btn-xs px-2 py-1" style="font-size: 0.76rem;">
        <i class="fas fa-arrow-left me-1"></i> Reports Hub
    </a>
    <?php
    $exportQs = http_build_query(array_filter([
        'from'        => $from ?? null,
        'to'          => $to ?? null,
        'channel'     => $channel ?? 'whatsapp',
        'campaign_id' => $campaignId ?? null,
    ]));
    ?>
    <?php if (function_exists('can') && can('reports.export')): ?>
        <a href="<?= site_url('reports/export-excel?' . $exportQs) ?>" class="btn btn-xs btn-outline-secondary px-2 py-1" style="font-size: 0.76rem;" title="Export CSV/Excel">
            <i class="fas fa-file-csv me-1 text-success"></i> Excel
        </a>
        <a href="<?= site_url('reports/export-pdf?' . $exportQs) ?>" class="btn btn-xs btn-outline-secondary px-2 py-1" style="font-size: 0.76rem;" title="Print Report">
            <i class="fas fa-print me-1 text-primary"></i> Print
        </a>
    <?php endif; ?>
</div>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php
$channel    = $channel ?? 'whatsapp';
$isWhatsapp = ($channel === 'whatsapp');
$isEmail    = ($channel === 'email');
$stats      = $stats ?? [];
$daily      = $daily ?? [];
$breakdown  = $breakdown ?? [];
$emailStats = $emailStats ?? [];
$emailDaily = $emailDaily ?? [];
$emailLogs  = $emailLogs ?? [];
$campaigns  = $campaigns ?? [];

// Calculate WhatsApp Rates
$waSent      = (int) ($stats['sent'] ?? 0);
$waDelivered = (int) ($stats['delivered'] ?? 0);
$waRead      = (int) ($stats['read'] ?? 0);
$waFailed    = (int) ($stats['failed'] ?? 0);
$waReplies   = (int) ($stats['replies'] ?? 0);

$waDeliveryRate = $waSent > 0 ? round(($waDelivered / $waSent) * 100, 1) : 0.0;
$waReadRate     = $waDelivered > 0 ? round(($waRead / $waDelivered) * 100, 1) : 0.0;
$waFailRate     = $waSent > 0 ? round(($waFailed / $waSent) * 100, 1) : 0.0;

// Email Rates
$emSent      = (int) ($emailStats['sent'] ?? 0);
$emOpened    = (int) ($emailStats['opened'] ?? 0);
$emClicked   = (int) ($emailStats['clicked'] ?? 0);
$emFailed    = (int) ($emailStats['failed'] ?? 0);
$emUnsubs    = (int) ($emailStats['unsubscribes'] ?? 0);
$emOpenRate  = (float) ($emailStats['open_rate'] ?? 0.0);
$emClickRate = (float) ($emailStats['click_rate'] ?? 0.0);
$emFailRate  = $emSent > 0 ? round(($emFailed / $emSent) * 100, 1) : 0.0;
?>

<div class="delivery-report-compact">

    <!-- Unified Compact Filter & Channel Bar -->
    <div class="card border rounded-3 shadow-none mb-2.5 bg-white" style="border-color: #e2e8f0 !important;">
        <div class="card-body p-2 d-flex flex-wrap align-items-center justify-content-between gap-2">
            <!-- Channel Switcher Tabs -->
            <div class="d-inline-flex p-0.5 rounded-2 bg-light border align-items-center" style="border-color: #e2e8f0 !important;">
                <a class="btn btn-xs fw-semibold px-2.5 py-1 rounded-2 <?= $isWhatsapp ? 'bg-white shadow-xs text-dark' : 'text-muted' ?>"
                   style="font-size: 0.78rem; <?= $isWhatsapp ? 'border: 1px solid #cbd5e1; background: #fff !important;' : 'border: 1px solid transparent;' ?>"
                   href="<?= site_url('reports/delivery?channel=whatsapp&from=' . urlencode($from) . '&to=' . urlencode($to) . ($campaignId ? '&campaign_id=' . (int) $campaignId : '')) ?>">
                    <i class="fab fa-whatsapp me-1 text-success"></i> WhatsApp
                </a>
                <a class="btn btn-xs fw-semibold px-2.5 py-1 rounded-2 <?= $isEmail ? 'bg-white shadow-xs text-dark' : 'text-muted' ?>"
                   style="font-size: 0.78rem; <?= $isEmail ? 'border: 1px solid #cbd5e1; background: #fff !important;' : 'border: 1px solid transparent;' ?>"
                   href="<?= site_url('reports/delivery?channel=email&from=' . urlencode($from) . '&to=' . urlencode($to)) ?>">
                    <i class="fas fa-envelope me-1 text-primary"></i> Email
                </a>
            </div>

            <!-- Inline Filter Form: Clean Single Line, No Wrap -->
            <form method="get" action="<?= site_url('reports/delivery') ?>" class="d-flex align-items-center gap-2 mb-0 flex-nowrap">
                <input type="hidden" name="channel" value="<?= esc($channel) ?>">

                <div class="d-flex align-items-center border rounded-2 px-2 bg-light" style="border-color: #cbd5e1 !important; height: 32px;">
                    <span class="text-muted small fw-medium me-1" style="font-size: 0.72rem;">From:</span>
                    <input type="date" name="from" class="form-control form-control-sm border-0 bg-transparent p-0 text-dark" style="width: 110px; font-size: 0.76rem; box-shadow: none;" value="<?= esc($from) ?>">
                </div>

                <div class="d-flex align-items-center border rounded-2 px-2 bg-light" style="border-color: #cbd5e1 !important; height: 32px;">
                    <span class="text-muted small fw-medium me-1" style="font-size: 0.72rem;">To:</span>
                    <input type="date" name="to" class="form-control form-control-sm border-0 bg-transparent p-0 text-dark" style="width: 110px; font-size: 0.76rem; box-shadow: none;" value="<?= esc($to) ?>">
                </div>

                <?php if ($isWhatsapp): ?>
                    <select name="campaign_id" class="form-select form-select-sm px-2 text-dark" style="width: 170px; height: 32px; font-size: 0.76rem; border-color: #cbd5e1;">
                        <option value="">All Campaigns</option>
                        <?php foreach ($campaigns as $c): ?>
                            <option value="<?= (int) $c['id'] ?>" <?= ((int) $campaignId === (int) $c['id']) ? 'selected' : '' ?>>
                                <?= esc($c['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                <?php endif; ?>

                <button type="submit" class="btn btn-primary btn-sm px-2.5 fw-semibold d-inline-flex align-items-center gap-1" style="height: 32px; font-size: 0.76rem; white-space: nowrap;">
                    <i class="fas fa-filter"></i> Apply
                </button>

                <a href="<?= site_url('reports/delivery?channel=' . esc($channel)) ?>" class="btn btn-outline-secondary btn-sm px-2 d-inline-flex align-items-center justify-content-center" style="height: 32px;" title="Reset Date Filters">
                    <i class="fas fa-undo-alt" style="font-size: 0.7rem;"></i>
                </a>
            </form>
        </div>
    </div>

    <!-- ========================================== -->
    <!-- TAB 1: WHATSAPP DELIVERY CONTENT          -->
    <!-- ========================================== -->
    <?php if ($isWhatsapp): ?>
        <!-- Compact KPI Cards -->
        <div class="row g-2 mb-2">
            <div class="col-6 col-md">
                <div class="card border rounded-3 p-2 bg-white h-100 shadow-none" style="border-left: 3.5px solid #0d9488 !important; border-color: #e2e8f0;">
                    <div class="d-flex justify-content-between align-items-center">
                        <span class="text-uppercase fw-bold text-muted" style="font-size: 0.67rem; letter-spacing: 0.5px;">Sent</span>
                        <i class="fas fa-paper-plane text-teal opacity-50" style="font-size: 0.75rem; color: #0d9488;"></i>
                    </div>
                    <div class="fw-bold text-dark mt-0.5" style="font-size: 1.25rem; line-height: 1.15;"><?= number_format($waSent) ?></div>
                    <span class="text-muted text-truncate" style="font-size: 0.67rem;">Outbound triggers</span>
                </div>
            </div>

            <div class="col-6 col-md">
                <div class="card border rounded-3 p-2 bg-white h-100 shadow-none" style="border-left: 3.5px solid #10b981 !important; border-color: #e2e8f0;">
                    <div class="d-flex justify-content-between align-items-center">
                        <span class="text-uppercase fw-bold text-muted" style="font-size: 0.67rem; letter-spacing: 0.5px;">Delivered</span>
                        <span class="badge bg-success-subtle text-success py-0.5 px-1.5 fw-semibold" style="font-size: 0.64rem;"><?= $waDeliveryRate ?>%</span>
                    </div>
                    <div class="fw-bold text-success mt-0.5" style="font-size: 1.25rem; line-height: 1.15;"><?= number_format($waDelivered) ?></div>
                    <span class="text-success text-truncate fw-medium" style="font-size: 0.67rem;"><i class="fas fa-check-double me-0.5"></i> Reached inbox</span>
                </div>
            </div>

            <div class="col-6 col-md">
                <div class="card border rounded-3 p-2 bg-white h-100 shadow-none" style="border-left: 3.5px solid #0284c7 !important; border-color: #e2e8f0;">
                    <div class="d-flex justify-content-between align-items-center">
                        <span class="text-uppercase fw-bold text-muted" style="font-size: 0.67rem; letter-spacing: 0.5px;">Read</span>
                        <span class="badge bg-info-subtle text-info py-0.5 px-1.5 fw-semibold" style="font-size: 0.64rem;"><?= $waReadRate ?>%</span>
                    </div>
                    <div class="fw-bold text-primary mt-0.5" style="font-size: 1.25rem; line-height: 1.15;"><?= number_format($waRead) ?></div>
                    <span class="text-muted text-truncate" style="font-size: 0.67rem;"><i class="fas fa-eye me-0.5"></i> Read by user</span>
                </div>
            </div>

            <div class="col-6 col-md">
                <div class="card border rounded-3 p-2 bg-white h-100 shadow-none" style="border-left: 3.5px solid #ef4444 !important; border-color: #e2e8f0;">
                    <div class="d-flex justify-content-between align-items-center">
                        <span class="text-uppercase fw-bold text-muted" style="font-size: 0.67rem; letter-spacing: 0.5px;">Failed</span>
                        <span class="badge bg-danger-subtle text-danger py-0.5 px-1.5 fw-semibold" style="font-size: 0.64rem;"><?= $waFailRate ?>%</span>
                    </div>
                    <div class="fw-bold text-danger mt-0.5" style="font-size: 1.25rem; line-height: 1.15;"><?= number_format($waFailed) ?></div>
                    <span class="text-muted text-truncate" style="font-size: 0.67rem;">Undelivered</span>
                </div>
            </div>

            <div class="col-6 col-md">
                <div class="card border rounded-3 p-2 bg-white h-100 shadow-none" style="border-left: 3.5px solid #f59e0b !important; border-color: #e2e8f0;">
                    <div class="d-flex justify-content-between align-items-center">
                        <span class="text-uppercase fw-bold text-muted" style="font-size: 0.67rem; letter-spacing: 0.5px;">Replies</span>
                        <i class="fas fa-reply text-warning opacity-50" style="font-size: 0.75rem;"></i>
                    </div>
                    <div class="fw-bold text-dark mt-0.5" style="font-size: 1.25rem; line-height: 1.15;"><?= number_format($waReplies) ?></div>
                    <span class="text-muted text-truncate" style="font-size: 0.67rem;">Inbound chat replies</span>
                </div>
            </div>
        </div>

        <!-- Compact Daily Delivery Chart -->
        <div class="card border rounded-3 shadow-none mb-2 bg-white" style="border-color: #e2e8f0 !important;">
            <div class="card-header bg-white py-1.5 px-3 border-bottom d-flex align-items-center justify-content-between">
                <div class="fw-bold small text-dark d-flex align-items-center" style="font-size: 0.81rem;">
                    <i class="fab fa-whatsapp text-success me-1.5"></i>
                    <span>Daily Delivery Trends</span>
                </div>
                <span class="badge bg-light text-muted border px-2 py-0.5 fw-normal" style="font-size: 0.68rem;">Sent vs Delivered vs Failed</span>
            </div>
            <div class="card-body p-2" style="height: 210px;">
                <canvas id="waDeliveryChart"
                        data-labels='<?= esc(json_encode(array_column($daily, 'date')), 'attr') ?>'
                        data-sent='<?= esc(json_encode(array_column($daily, 'sent')), 'attr') ?>'
                        data-delivered='<?= esc(json_encode(array_column($daily, 'delivered')), 'attr') ?>'
                        data-failed='<?= esc(json_encode(array_column($daily, 'failed')), 'attr') ?>'></canvas>
            </div>
        </div>

        <!-- Campaign Breakdown Table (if campaigns exist) -->
        <?php if (! empty($breakdown)): ?>
            <div class="card border rounded-3 shadow-none mb-2 bg-white" style="border-color: #e2e8f0 !important;">
                <div class="card-header bg-white py-1.5 px-3 border-bottom d-flex align-items-center justify-content-between">
                    <div class="fw-bold small text-dark d-flex align-items-center" style="font-size: 0.81rem;">
                        <i class="fas fa-bullhorn text-muted me-1.5"></i>
                        <span>Campaign Delivery Breakdown</span>
                    </div>
                    <span class="badge bg-light text-muted border px-2 py-0.5 fw-normal" style="font-size: 0.68rem;">
                        <?= count($breakdown) ?> <?= count($breakdown) === 1 ? 'campaign' : 'campaigns' ?>
                    </span>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover table-sm align-middle mb-0" style="font-size: 0.77rem;">
                        <thead class="table-light text-muted">
                            <tr>
                                <th class="ps-3 py-1">Campaign</th>
                                <th class="py-1 text-end">Sent</th>
                                <th class="py-1 text-end">Delivered</th>
                                <th class="py-1 text-end">Read</th>
                                <th class="py-1 text-end">Failed</th>
                                <th class="py-1 pe-3 text-end">Replies</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($breakdown as $b): ?>
                                <?php
                                $bSent = (int) ($b['sent_count'] ?? 0);
                                $bDel  = (int) ($b['delivered_count'] ?? 0);
                                $bRead = (int) ($b['read_count'] ?? 0);
                                $bFail = (int) ($b['failed_count'] ?? 0);
                                $bRep  = (int) ($b['reply_count'] ?? 0);
                                $bDelPct = $bSent > 0 ? round(($bDel / $bSent) * 100, 1) : 0;
                                ?>
                                <tr>
                                    <td class="ps-3 py-1 fw-semibold text-dark text-truncate" style="max-width: 250px;">
                                        <i class="fas fa-bullhorn text-secondary me-1" style="font-size: 0.7rem;"></i>
                                        <?= esc($b['name']) ?>
                                    </td>
                                    <td class="py-1 text-end fw-semibold"><?= number_format($bSent) ?></td>
                                    <td class="py-1 text-end text-success">
                                        <?= number_format($bDel) ?>
                                        <span class="badge bg-success-subtle text-success ms-1 px-1 py-0.2" style="font-size: 0.64rem;"><?= $bDelPct ?>%</span>
                                    </td>
                                    <td class="py-1 text-end text-primary"><?= number_format($bRead) ?></td>
                                    <td class="py-1 text-end text-danger"><?= number_format($bFail) ?></td>
                                    <td class="py-1 pe-3 text-end text-warning fw-semibold"><?= number_format($bRep) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <!-- ========================================== -->
    <!-- TAB 2: EMAIL DELIVERY CONTENT             -->
    <!-- ========================================== -->
    <?php if ($isEmail): ?>
        <!-- Compact KPI Cards for Email -->
        <div class="row g-2 mb-2">
            <div class="col-6 col-md">
                <div class="card border rounded-3 p-2 bg-white h-100 shadow-none" style="border-left: 3.5px solid #2563eb !important; border-color: #e2e8f0;">
                    <div class="d-flex justify-content-between align-items-center">
                        <span class="text-uppercase fw-bold text-muted" style="font-size: 0.67rem; letter-spacing: 0.5px;">Sent</span>
                        <i class="fas fa-paper-plane text-primary opacity-50" style="font-size: 0.75rem;"></i>
                    </div>
                    <div class="fw-bold text-dark mt-0.5" style="font-size: 1.25rem; line-height: 1.15;"><?= number_format($emSent) ?></div>
                    <span class="text-muted text-truncate" style="font-size: 0.67rem;">Dispatched emails</span>
                </div>
            </div>

            <div class="col-6 col-md">
                <div class="card border rounded-3 p-2 bg-white h-100 shadow-none" style="border-left: 3.5px solid #10b981 !important; border-color: #e2e8f0;">
                    <div class="d-flex justify-content-between align-items-center">
                        <span class="text-uppercase fw-bold text-muted" style="font-size: 0.67rem; letter-spacing: 0.5px;">Opened</span>
                        <span class="badge bg-success-subtle text-success py-0.5 px-1.5 fw-semibold" style="font-size: 0.64rem;"><?= $emOpenRate ?>%</span>
                    </div>
                    <div class="fw-bold text-success mt-0.5" style="font-size: 1.25rem; line-height: 1.15;"><?= number_format($emOpened) ?></div>
                    <span class="text-success text-truncate fw-medium" style="font-size: 0.67rem;"><i class="fas fa-envelope-open me-0.5"></i> Unique opens</span>
                </div>
            </div>

            <div class="col-6 col-md">
                <div class="card border rounded-3 p-2 bg-white h-100 shadow-none" style="border-left: 3.5px solid #6366f1 !important; border-color: #e2e8f0;">
                    <div class="d-flex justify-content-between align-items-center">
                        <span class="text-uppercase fw-bold text-muted" style="font-size: 0.67rem; letter-spacing: 0.5px;">Clicked</span>
                        <span class="badge bg-primary-subtle text-primary py-0.5 px-1.5 fw-semibold" style="font-size: 0.64rem;"><?= $emClickRate ?>%</span>
                    </div>
                    <div class="fw-bold text-primary mt-0.5" style="font-size: 1.25rem; line-height: 1.15;"><?= number_format($emClicked) ?></div>
                    <span class="text-muted text-truncate" style="font-size: 0.67rem;"><i class="fas fa-mouse-pointer me-0.5"></i> Clicked links</span>
                </div>
            </div>

            <div class="col-6 col-md">
                <div class="card border rounded-3 p-2 bg-white h-100 shadow-none" style="border-left: 3.5px solid #ef4444 !important; border-color: #e2e8f0;">
                    <div class="d-flex justify-content-between align-items-center">
                        <span class="text-uppercase fw-bold text-muted" style="font-size: 0.67rem; letter-spacing: 0.5px;">Bounced</span>
                        <span class="badge bg-danger-subtle text-danger py-0.5 px-1.5 fw-semibold" style="font-size: 0.64rem;"><?= $emFailRate ?>%</span>
                    </div>
                    <div class="fw-bold text-danger mt-0.5" style="font-size: 1.25rem; line-height: 1.15;"><?= number_format($emFailed) ?></div>
                    <span class="text-muted text-truncate" style="font-size: 0.67rem;">Failed delivery</span>
                </div>
            </div>

            <div class="col-6 col-md">
                <div class="card border rounded-3 p-2 bg-white h-100 shadow-none" style="border-left: 3.5px solid #f59e0b !important; border-color: #e2e8f0;">
                    <div class="d-flex justify-content-between align-items-center">
                        <span class="text-uppercase fw-bold text-muted" style="font-size: 0.67rem; letter-spacing: 0.5px;">Unsubs</span>
                        <i class="fas fa-user-minus text-warning opacity-50" style="font-size: 0.75rem;"></i>
                    </div>
                    <div class="fw-bold text-dark mt-0.5" style="font-size: 1.25rem; line-height: 1.15;"><?= number_format($emUnsubs) ?></div>
                    <span class="text-muted text-truncate" style="font-size: 0.67rem;">Opt-outs</span>
                </div>
            </div>
        </div>

        <!-- Compact Daily Email Chart -->
        <div class="card border rounded-3 shadow-none mb-2 bg-white" style="border-color: #e2e8f0 !important;">
            <div class="card-header bg-white py-1.5 px-3 border-bottom d-flex align-items-center justify-content-between">
                <div class="fw-bold small text-dark d-flex align-items-center" style="font-size: 0.81rem;">
                    <i class="fas fa-envelope text-primary me-1.5"></i>
                    <span>Daily Delivery Trends</span>
                </div>
                <span class="badge bg-light text-muted border px-2 py-0.5 fw-normal" style="font-size: 0.68rem;">Sent vs Failed</span>
            </div>
            <div class="card-body p-2" style="height: 200px;">
                <canvas id="emailDeliveryChart"
                        data-labels='<?= esc(json_encode(array_column($emailDaily, 'date')), 'attr') ?>'
                        data-sent='<?= esc(json_encode(array_column($emailDaily, 'sent')), 'attr') ?>'
                        data-failed='<?= esc(json_encode(array_column($emailDaily, 'failed')), 'attr') ?>'></canvas>
            </div>
        </div>

        <!-- Compact Recent Email Deliveries Table -->
        <div class="card border rounded-3 shadow-none mb-2 bg-white" style="border-color: #e2e8f0 !important;">
            <div class="card-header bg-white py-1.5 px-3 border-bottom d-flex align-items-center justify-content-between">
                <div class="fw-bold small text-dark d-flex align-items-center" style="font-size: 0.81rem;">
                    <i class="fas fa-list-ul text-muted me-1.5"></i>
                    <span>Recent Dispatches (<?= count($emailLogs) ?>)</span>
                </div>
                <a href="<?= site_url('email-manager?tab=analytics') ?>" class="text-decoration-none fw-semibold" style="font-size: 0.75rem;">
                    Analytics Hub <i class="fas fa-arrow-right ms-0.5"></i>
                </a>
            </div>
            <div class="table-responsive">
                <table class="table table-hover table-sm align-middle mb-0" style="font-size: 0.77rem;">
                    <thead class="table-light text-muted">
                        <tr>
                            <th class="ps-3 py-1">Time</th>
                            <th class="py-1">Recipient</th>
                            <th class="py-1">Subject</th>
                            <th class="py-1">Provider</th>
                            <th class="py-1">Status</th>
                            <th class="py-1 pe-3 text-end">Opens / Clicks</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($emailLogs)): ?>
                            <tr>
                                <td colspan="6" class="text-center py-3 text-muted" style="font-size: 0.76rem;">
                                    No email dispatch logs for this date range.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($emailLogs as $log): ?>
                                <tr>
                                    <td class="ps-3 py-1 text-nowrap text-muted" style="font-size: 0.73rem;">
                                        <?= esc(format_app_datetime($log['created_at'] ?? null, 'd-M H:i', '—')) ?>
                                    </td>
                                    <td class="py-1 fw-semibold text-dark text-truncate" style="max-width: 180px;">
                                        <?= esc($log['to_email'] ?? '—') ?>
                                    </td>
                                    <td class="py-1 text-truncate" style="max-width: 240px;" title="<?= esc($log['subject'] ?? '') ?>">
                                        <?= esc($log['subject'] ?: '(No Subject)') ?>
                                    </td>
                                    <td class="py-1">
                                        <span class="badge bg-light text-secondary border px-1.5 py-0.5" style="font-size: 0.65rem;">
                                            <?= esc(strtoupper($log['provider'] ?? 'SMTP')) ?>
                                        </span>
                                    </td>
                                    <td class="py-1">
                                        <?php if (($log['status'] ?? '') === 'sent'): ?>
                                            <span class="badge bg-success-subtle text-success border border-success-subtle px-1.5 py-0.5" style="font-size: 0.68rem;">
                                                Sent
                                            </span>
                                        <?php elseif (($log['status'] ?? '') === 'failed'): ?>
                                            <span class="badge bg-danger-subtle text-danger border border-danger-subtle px-1.5 py-0.5" style="font-size: 0.68rem;" title="<?= esc($log['message'] ?? '') ?>">
                                                Failed
                                            </span>
                                        <?php else: ?>
                                            <span class="badge bg-warning-subtle text-warning border border-warning-subtle px-1.5 py-0.5" style="font-size: 0.68rem;">
                                                <?= esc(ucfirst($log['status'] ?? 'Queued')) ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="py-1 pe-3 text-end">
                                        <?php if (! empty($log['open_count'])): ?>
                                            <span class="badge bg-info-subtle text-info border border-info-subtle px-1.5 py-0.5" style="font-size: 0.65rem;">
                                                <i class="fas fa-eye me-0.5"></i> <?= (int) $log['open_count'] ?>
                                            </span>
                                        <?php endif; ?>
                                        <?php if (! empty($log['click_count'])): ?>
                                            <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-1.5 py-0.5 ms-1" style="font-size: 0.65rem;">
                                                <i class="fas fa-mouse-pointer me-0.5"></i> <?= (int) $log['click_count'] ?>
                                            </span>
                                        <?php endif; ?>
                                        <?php if (empty($log['open_count']) && empty($log['click_count'])): ?>
                                            <span class="text-muted" style="font-size: 0.7rem;">—</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
$(function () {
    // ── WhatsApp Delivery Chart ──────────────────────────
    var waCanvas = document.getElementById('waDeliveryChart');
    if (waCanvas && typeof Chart !== 'undefined') {
        var waLabels = JSON.parse(waCanvas.getAttribute('data-labels') || '[]');
        var waSent = JSON.parse(waCanvas.getAttribute('data-sent') || '[]');
        var waDelivered = JSON.parse(waCanvas.getAttribute('data-delivered') || '[]');
        var waFailed = JSON.parse(waCanvas.getAttribute('data-failed') || '[]');

        new Chart(waCanvas, {
            type: 'line',
            data: {
                labels: waLabels,
                datasets: [
                    {
                        label: 'Sent',
                        data: waSent,
                        borderColor: '#0d9488',
                        backgroundColor: 'rgba(13, 148, 136, 0.08)',
                        tension: 0.25,
                        borderWidth: 2,
                        pointRadius: 2,
                        fill: true
                    },
                    {
                        label: 'Delivered',
                        data: waDelivered,
                        borderColor: '#10b981',
                        backgroundColor: 'rgba(16, 185, 129, 0.08)',
                        tension: 0.25,
                        borderWidth: 2,
                        pointRadius: 2,
                        fill: true
                    },
                    {
                        label: 'Failed',
                        data: waFailed,
                        borderColor: '#ef4444',
                        backgroundColor: 'rgba(239, 68, 68, 0.05)',
                        tension: 0.25,
                        borderWidth: 1.5,
                        pointRadius: 2,
                        fill: false
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: { boxWidth: 10, font: { size: 11 } }
                    }
                },
                scales: {
                    y: { beginAtZero: true, ticks: { font: { size: 10 } } },
                    x: { ticks: { font: { size: 10 } } }
                }
            }
        });
    }

    // ── Email Delivery Chart ──────────────────────────────
    var emCanvas = document.getElementById('emailDeliveryChart');
    if (emCanvas && typeof Chart !== 'undefined') {
        var emLabels = JSON.parse(emCanvas.getAttribute('data-labels') || '[]');
        var emSent = JSON.parse(emCanvas.getAttribute('data-sent') || '[]');
        var emFailed = JSON.parse(emCanvas.getAttribute('data-failed') || '[]');

        new Chart(emCanvas, {
            type: 'line',
            data: {
                labels: emLabels,
                datasets: [
                    {
                        label: 'Sent',
                        data: emSent,
                        borderColor: '#2563eb',
                        backgroundColor: 'rgba(37, 99, 235, 0.08)',
                        tension: 0.25,
                        borderWidth: 2,
                        pointRadius: 2,
                        fill: true
                    },
                    {
                        label: 'Bounced / Failed',
                        data: emFailed,
                        borderColor: '#ef4444',
                        backgroundColor: 'rgba(239, 68, 68, 0.05)',
                        tension: 0.25,
                        borderWidth: 1.5,
                        pointRadius: 2,
                        fill: false
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: { boxWidth: 10, font: { size: 11 } }
                    }
                },
                scales: {
                    y: { beginAtZero: true, ticks: { font: { size: 10 } } },
                    x: { ticks: { font: { size: 10 } } }
                }
            }
        });
    }
});
</script>
<?= $this->endSection() ?>
