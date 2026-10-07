<?php
/**
 * Email sending health warnings (reputation guard + CAN-SPAM postal address).
 *
 * @var array<string, mixed>|null $reputation      EmailReputationGuard::status()
 * @var string|null               $companyAddress  setting `email_company_address`
 * @var bool                      $showAddressHint show the "add postal address" warning
 */
$reputation      = is_array($reputation ?? null) ? $reputation : [];
$showAddressHint = ($showAddressHint ?? true) && trim((string) ($companyAddress ?? '')) === '';
?>
<?php if ($reputation !== [] && empty($reputation['healthy'])): ?>
<div class="alert alert-danger d-flex align-items-start gap-3 shadow-sm" role="alert">
    <i class="fas fa-exclamation-circle mt-1"></i>
    <div class="flex-grow-1">
        <div class="fw-semibold">Bulk &amp; campaign emails are paused to protect your sender reputation</div>
        <div class="small"><?= esc((string) ($reputation['message'] ?? '')) ?></div>
        <div class="small text-muted mt-1">
            Last <?= (int) ($reputation['window_days'] ?? 7) ?> days:
            <?= (int) ($reputation['sent'] ?? 0) ?> sent ·
            <?= esc((string) ($reputation['bounce_rate'] ?? 0)) ?>% bounced ·
            <?= esc((string) ($reputation['complaint_rate'] ?? 0)) ?>% spam complaints
        </div>
    </div>
</div>
<?php endif; ?>
<?php if ($showAddressHint): ?>
<div class="alert alert-warning d-flex align-items-start gap-3 shadow-sm" role="alert">
    <i class="fas fa-map-marker-alt mt-1"></i>
    <div class="flex-grow-1">
        <div class="fw-semibold">Add your business postal address</div>
        <div class="small">Anti-spam laws (CAN-SPAM) require a physical address in every marketing email. Emails still send, but add it in Email Settings so it appears in the footer next to the unsubscribe link.</div>
    </div>
    <a href="<?= site_url('settings/email') ?>#emailComplianceCard" class="btn btn-sm btn-warning text-nowrap">Add Address</a>
</div>
<?php endif; ?>
