<?= $this->extend('layouts/main') ?>

<?= $this->section('header_actions') ?>
<div class="d-flex align-items-center gap-2">
    <a href="<?= site_url('campaigns?channel=email') ?>" class="btn btn-outline-secondary btn-sm shadow-sm">
        <i class="fas fa-arrow-left me-1"></i> Back to Campaigns
    </a>
    <a href="<?= site_url('email-manager?tab=campaigns') ?>" class="btn btn-outline-primary btn-sm shadow-sm">
        <i class="fas fa-sliders-h me-1"></i> Email Manager
    </a>
</div>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php
$id = (int) ($campaign['id'] ?? 0);
$name = (string) ($campaign['name'] ?? ('Campaign #' . $id));
$subject = (string) ($campaign['subject'] ?? 'No Subject');
$status = (string) ($campaign['status'] ?? 'draft');
$mode = (string) ($campaign['mode'] ?? 'recipients');
$labelName = (string) ($campaign['label_name'] ?? '');
$htmlContent = (string) ($campaign['html_content'] ?? '');
$lastError = (string) ($campaign['last_error'] ?? '');
$sentAt = ! empty($campaign['sent_at']) ? format_app_datetime($campaign['sent_at']) : 'Not dispatched yet';
$createdAt = ! empty($campaign['created_at']) ? format_app_datetime($campaign['created_at']) : '—';
$isActive = in_array($status, ['queued', 'sending', 'running'], true);
?>

<div class="email-campaign-details page-stack pb-4">
    <!-- Top Header & Status Banner -->
    <div class="card border-0 shadow-sm rounded-4 mb-4 overflow-hidden" style="border: 1px solid #e2e8f0 !important;">
        <div class="p-4 bg-white">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
                <div class="d-flex align-items-center gap-3">
                    <div class="rounded-3 d-flex align-items-center justify-content-center text-white shadow-sm flex-shrink-0"
                         style="width: 48px; height: 48px; background: linear-gradient(135deg, #0284c7 0%, #0369a1 100%);">
                        <i class="fas fa-envelope-open-text fs-5"></i>
                    </div>
                    <div>
                        <div class="d-flex align-items-center gap-2 flex-wrap mb-1">
                            <h3 class="mb-0 fw-bold text-dark" style="font-size: 1.35rem; letter-spacing: -0.3px;">
                                <?= esc($name) ?>
                            </h3>
                            <span class="badge text-bg-light border px-2 py-1">
                                <i class="fas fa-at text-muted me-1"></i> Email Campaign
                            </span>
                            <?= view('partials/status_badge', ['status' => $status]) ?>
                            <?php if ($isActive): ?>
                                <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-2 py-1">
                                    <span class="spinner-grow spinner-grow-sm me-1" role="status" style="width: 0.65rem; height: 0.65rem;"></span> Sending
                                </span>
                            <?php endif; ?>
                        </div>
                        <p class="text-muted small mb-0">
                            Subject: <span class="fw-semibold text-dark"><?= esc($subject) ?></span> · Created on <?= esc($createdAt) ?>
                        </p>
                    </div>
                </div>

                <div class="d-flex align-items-center gap-2">
                    <button type="button" class="btn btn-outline-secondary btn-sm" onclick="window.location.reload();">
                        <i class="fas fa-sync-alt me-1"></i> Refresh
                    </button>
                    <?php if ($canSend && in_array($status, ['draft', 'failed'], true)): ?>
                        <button type="button" class="btn btn-primary btn-sm shadow-sm js-send-camp-btn" data-id="<?= $id ?>">
                            <i class="fas fa-paper-plane me-1"></i> Broadcast Now
                        </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <?php if (! empty($lastError)): ?>
            <div class="px-4 py-2 bg-danger-subtle border-top border-danger-subtle d-flex align-items-center gap-2 text-danger small">
                <i class="fas fa-exclamation-triangle flex-shrink-0"></i>
                <div><strong>Delivery Issue:</strong> <?= esc($lastError) ?></div>
            </div>
        <?php endif; ?>
    </div>

    <!-- 5 Key Metrics Strip -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-4 col-xl">
            <div class="card border-0 shadow-sm rounded-3 h-100 p-3 bg-white" style="border: 1px solid #e2e8f0 !important;">
                <div class="d-flex justify-content-between align-items-center text-muted small mb-1">
                    <span>Recipients</span>
                    <i class="fas fa-users text-primary opacity-75"></i>
                </div>
                <div class="fs-4 fw-bold text-dark"><?= number_format($totalTarget) ?></div>
                <div class="text-muted" style="font-size: 0.72rem;">Total target audience</div>
            </div>
        </div>

        <div class="col-6 col-md-4 col-xl">
            <div class="card border-0 shadow-sm rounded-3 h-100 p-3 bg-white" style="border: 1px solid #e2e8f0 !important;">
                <div class="d-flex justify-content-between align-items-center text-muted small mb-1">
                    <span>Sent</span>
                    <i class="fas fa-check-circle text-success opacity-75"></i>
                </div>
                <div class="fs-4 fw-bold text-success"><?= number_format($sentCount) ?></div>
                <div class="text-muted" style="font-size: 0.72rem;">
                    <?= $totalTarget > 0 ? round(($sentCount / $totalTarget) * 100, 1) : 0 ?>% dispatched
                </div>
            </div>
        </div>

        <div class="col-6 col-md-4 col-xl">
            <div class="card border-0 shadow-sm rounded-3 h-100 p-3 bg-white" style="border: 1px solid #e2e8f0 !important;">
                <div class="d-flex justify-content-between align-items-center text-muted small mb-1">
                    <span>Failed</span>
                    <i class="fas fa-times-circle text-danger opacity-75"></i>
                </div>
                <div class="fs-4 fw-bold <?= $failedCount > 0 ? 'text-danger' : 'text-secondary' ?>"><?= number_format($failedCount) ?></div>
                <div class="text-muted" style="font-size: 0.72rem;">Delivery errors</div>
            </div>
        </div>

        <div class="col-6 col-md-6 col-xl">
            <div class="card border-0 shadow-sm rounded-3 h-100 p-3 bg-white" style="border: 1px solid #e2e8f0 !important;">
                <div class="d-flex justify-content-between align-items-center text-muted small mb-1">
                    <span>Opened</span>
                    <i class="fas fa-envelope-open text-info opacity-75"></i>
                </div>
                <div class="fs-4 fw-bold text-info"><?= number_format($uniqueOpens) ?></div>
                <div class="text-muted" style="font-size: 0.72rem;">
                    Open rate: <strong><?= $openRate ?>%</strong> (<?= number_format($totalOpens) ?> total)
                </div>
            </div>
        </div>

        <div class="col-12 col-md-6 col-xl">
            <div class="card border-0 shadow-sm rounded-3 h-100 p-3 bg-white" style="border: 1px solid #e2e8f0 !important;">
                <div class="d-flex justify-content-between align-items-center text-muted small mb-1">
                    <span>Clicked</span>
                    <i class="fas fa-mouse-pointer text-warning opacity-75"></i>
                </div>
                <div class="fs-4 fw-bold text-warning"><?= number_format($uniqueClicks) ?></div>
                <div class="text-muted" style="font-size: 0.72rem;">
                    Click rate: <strong><?= $clickRate ?>%</strong> (<?= number_format($totalClicks) ?> total)
                </div>
            </div>
        </div>
    </div>

    <!-- Main Content: Left Details & Recipients, Right Preview -->
    <div class="row g-4">
        <!-- Left: Overview & Recipients Table -->
        <div class="col-lg-7">
            <!-- Campaign Setup Details -->
            <div class="card border-0 shadow-sm rounded-4 mb-4" style="border: 1px solid #e2e8f0 !important;">
                <div class="card-header bg-white py-3 border-bottom d-flex align-items-center justify-content-between">
                    <h6 class="mb-0 fw-bold text-dark">
                        <i class="fas fa-info-circle text-primary me-2"></i> Campaign Configuration
                    </h6>
                    <span class="badge rounded-pill bg-light text-dark border px-2.5 py-1" style="font-size: 0.75rem;">
                        Provider: <strong><?= esc($providerLabel) ?></strong>
                    </span>
                </div>
                <div class="card-body p-3">
                    <div class="row g-3 small">
                        <div class="col-sm-6">
                            <span class="text-muted d-block">Audience Mode</span>
                            <span class="fw-semibold text-dark">
                                <?php if ($mode === 'label'): ?>
                                    <span class="badge bg-secondary-subtle text-secondary border">Customer Group</span>
                                    <?= esc($labelName !== '' ? $labelName : 'All') ?>
                                <?php else: ?>
                                    <span class="badge bg-secondary-subtle text-secondary border">Direct Recipients</span>
                                    <?= count($recipients) ?> contacts
                                <?php endif; ?>
                            </span>
                        </div>
                        <div class="col-sm-6">
                            <span class="text-muted d-block">Dispatched Time</span>
                            <span class="fw-semibold text-dark"><?= esc($sentAt) ?></span>
                        </div>
                        <div class="col-sm-6">
                            <span class="text-muted d-block">Email Template</span>
                            <span class="fw-semibold text-dark">
                                <?php if ($builder): ?>
                                    <i class="fas fa-palette text-muted me-1"></i> <?= esc($builder['name']) ?>
                                <?php else: ?>
                                    <span class="text-muted">Standard HTML Editor</span>
                                <?php endif; ?>
                            </span>
                        </div>
                        <div class="col-sm-6">
                            <span class="text-muted d-block">Tracking</span>
                            <span class="badge bg-success-subtle text-success border border-success-subtle">
                                <i class="fas fa-check me-1"></i> Open &amp; Click Tracking Active
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Recipients & Delivery Table -->
            <div class="card border-0 shadow-sm rounded-4 overflow-hidden" style="border: 1px solid #e2e8f0 !important;">
                <div class="card-header bg-white py-3 border-bottom d-flex flex-wrap align-items-center justify-content-between gap-2">
                    <div>
                        <h6 class="mb-0 fw-bold text-dark">
                            <i class="fas fa-address-book text-primary me-2"></i> Targeted Recipients &amp; Engagement
                        </h6>
                        <span class="text-muted small" style="font-size: 0.74rem;">
                            <?= count($recipients) ?> recipient email(s) in this campaign
                        </span>
                    </div>
                    <div>
                        <input type="text" id="recipientFilterInput" class="form-control form-control-sm" placeholder="Filter recipient…" style="font-size: 0.8rem; width: 170px;">
                    </div>
                </div>

                <div class="table-responsive" style="max-height: 480px;">
                    <table class="table table-hover align-middle mb-0" id="recipientsTable">
                        <thead class="bg-light sticky-top">
                            <tr class="small text-muted text-uppercase" style="font-size: 0.72rem; letter-spacing: 0.5px;">
                                <th class="ps-3">Recipient</th>
                                <th>Delivery Status</th>
                                <th class="text-center">Opens</th>
                                <th class="text-center">Clicks</th>
                            </tr>
                        </thead>
                        <tbody class="small" id="recipientsTableBody">
                            <?php if ($recipients === []): ?>
                                <tr>
                                    <td colspan="4" class="text-center py-4 text-muted">
                                        <i class="fas fa-user-slash opacity-25 fs-4 d-block mb-1"></i>
                                        No individual recipient addresses logged.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($recipients as $recEmail): ?>
                                    <?php
                                    $recEmailLower = strtolower(trim($recEmail));
                                    $contact = $contactsMap[$recEmailLower] ?? null;
                                    $contactName = $contact['name'] ?? null;
                                    $contactMobile = $contact['mobile'] ?? null;
                                    $emailLog = $logByEmail[$recEmailLower] ?? null;

                                    $recStatus = $status === 'sent' ? 'sent' : ($status === 'failed' ? 'failed' : 'queued');
                                    if ($emailLog && ! empty($emailLog['status'])) {
                                        $recStatus = $emailLog['status'];
                                    }
                                    $recOpens = $emailLog ? (int) ($emailLog['open_count'] ?? 0) : 0;
                                    $recClicks = $emailLog ? (int) ($emailLog['click_count'] ?? 0) : 0;
                                    ?>
                                    <tr class="recipient-row" data-search="<?= esc($recEmailLower . ' ' . ($contactName ?? '')) ?>">
                                        <td class="ps-3 py-2.5">
                                            <div class="d-flex align-items-center gap-2">
                                                <div class="rounded-circle bg-light border d-flex align-items-center justify-content-center text-secondary fw-bold"
                                                     style="width: 32px; height: 32px; font-size: 0.75rem;">
                                                    <?= esc(strtoupper(substr($contactName ?: $recEmail, 0, 1))) ?>
                                                </div>
                                                <div>
                                                    <div class="fw-semibold text-dark">
                                                        <?= esc($contactName ?: $recEmail) ?>
                                                    </div>
                                                    <?php if ($contactName): ?>
                                                        <div class="text-muted text-truncate" style="font-size: 0.74rem;">
                                                            <?= esc($recEmail) ?>
                                                            <?php if ($contactMobile): ?> · <?= esc($contactMobile) ?><?php endif; ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <?= view('partials/status_badge', ['status' => $recStatus]) ?>
                                        </td>
                                        <td class="text-center">
                                            <?php if ($recOpens > 0): ?>
                                                <span class="badge bg-info-subtle text-info border px-2 py-1">
                                                    <i class="fas fa-eye me-1"></i> <?= $recOpens ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="text-muted">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center">
                                            <?php if ($recClicks > 0): ?>
                                                <span class="badge bg-warning-subtle text-warning border px-2 py-1">
                                                    <i class="fas fa-mouse-pointer me-1"></i> <?= $recClicks ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="text-muted">—</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Right: Message Content Preview -->
        <div class="col-lg-5">
            <div class="card border-0 shadow-sm rounded-4 overflow-hidden sticky-top" style="top: 80px; border: 1px solid #e2e8f0 !important;">
                <div class="card-header bg-white py-3 border-bottom d-flex align-items-center justify-content-between">
                    <h6 class="mb-0 fw-bold text-dark">
                        <i class="fas fa-file-invoice text-primary me-2"></i> Message Preview
                    </h6>
                    <span class="badge text-bg-light border small">
                        HTML Content
                    </span>
                </div>

                <!-- Email Header Mockup -->
                <div class="p-3 bg-light border-bottom small text-muted">
                    <div class="d-flex align-items-center justify-content-between mb-1">
                        <span><strong>From:</strong> System (<?= esc($providerLabel) ?>)</span>
                        <span class="badge bg-white border text-secondary" style="font-size: 0.68rem;">Verified Sender</span>
                    </div>
                    <div class="mb-1"><strong>Subject:</strong> <span class="text-dark fw-semibold"><?= esc($subject) ?></span></div>
                    <div><strong>Target:</strong> <?= count($recipients) ?> contact(s)</div>
                </div>

                <!-- Rendered Email Body -->
                <div class="p-3 bg-white" style="min-height: 380px; max-height: 600px; overflow-y: auto;">
                    <?php if (trim($htmlContent) !== ''): ?>
                        <div class="email-preview-render border rounded-3 p-3 bg-white" style="font-family: Arial, sans-serif; font-size: 0.9rem; line-height: 1.6; color: #1e293b;">
                            <?= $htmlContent /* Render HTML email template */ ?>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-5 text-muted">
                            <i class="fas fa-file-alt opacity-25 fs-1 d-block mb-2"></i>
                            <p class="mb-0">No HTML body provided for this campaign.</p>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="card-footer bg-light py-2 text-center small text-muted border-top" style="font-size: 0.75rem;">
                    <i class="fas fa-shield-alt text-success me-1"></i> Includes auto-unsubscribe link &amp; engagement tracking
                </div>
            </div>
        </div>
    </div>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
$(function () {
    // ── Live filter for recipients table ─────────────────────
    $('#recipientFilterInput').on('keyup', function () {
        var q = $.trim($(this).val()).toLowerCase();
        $('#recipientsTableBody tr.recipient-row').each(function () {
            var searchData = $(this).data('search') || '';
            if (!q || searchData.indexOf(q) !== -1) {
                $(this).show();
            } else {
                $(this).hide();
            }
        });
    });

    // ── Send Campaign Trigger ────────────────────────────────
    $('.js-send-camp-btn').on('click', function () {
        var campId = $(this).data('id');
        if (!campId) return;

        if (!confirm('Broadcast this email campaign to all recipients now?')) {
            return;
        }

        var $btn = $(this).prop('disabled', true);
        var origHtml = $btn.html();
        $btn.html('<span class="spinner-border spinner-border-sm me-1"></span> Sending…');

        $.ajax({
            url: '<?= site_url('email-manager/campaigns') ?>/' + campId + '/send',
            method: 'POST',
            dataType: 'json',
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        }).done(function (res) {
            if (res && res.success) {
                if (window.APP && APP.toast) {
                    APP.toast(res.message || 'Campaign broadcast sent!', 'success');
                } else {
                    alert(res.message || 'Campaign broadcast sent!');
                }
                setTimeout(function () { window.location.reload(); }, 1200);
            } else {
                alert((res && res.message) || 'Campaign send failed.');
                $btn.prop('disabled', false).html(origHtml);
            }
        }).fail(function (xhr) {
            var msg = (xhr.responseJSON && xhr.responseJSON.message) || 'Error sending campaign.';
            alert(msg);
            $btn.prop('disabled', false).html(origHtml);
        });
    });
});
</script>
<?= $this->endSection() ?>
