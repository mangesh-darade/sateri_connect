<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>
<?php
$provider = $provider ?? 'smtp';
$providerLabel = $providerLabel ?? 'SMTP';
$providerDetail = $providerDetail ?? '';
$defaultTo = $defaultTo ?? '';
$isCheerio = ! empty($isCheerio);
?>
<div class="page-list">
    <?= view('emails/_provider_banner', [
        'provider'       => $provider,
        'providerLabel'  => $providerLabel,
        'providerDetail' => $providerDetail,
        'defaultTo'      => $defaultTo,
        'mode'           => null,
    ]) ?>

    <div class="row g-3">
        <?php if (function_exists('can') && can('emails.send')): ?>
        <div class="col-md-6">
            <div class="card form-card h-100 email-mode-card">
                <div class="card-body d-flex flex-column">
                    <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                        <h5 class="mb-0"><i class="fas fa-paper-plane text-success me-2"></i>Single send</h5>
                        <span class="badge text-bg-dark">Via <?= esc($providerLabel) ?></span>
                    </div>
                    <p class="text-muted small mb-3">Send one email to one recipient.</p>
                    <a href="<?= site_url('emails/send') ?>" class="btn btn-wa btn-sm mt-auto align-self-start">Compose single</a>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card form-card h-100 email-mode-card">
                <div class="card-body d-flex flex-column">
                    <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                        <h5 class="mb-0"><i class="fas fa-mail-bulk text-success me-2"></i>Bulk send</h5>
                        <span class="badge text-bg-dark">Via <?= esc($providerLabel) ?></span>
                    </div>
                    <p class="text-muted small mb-3">
                        Send to many recipients<?= $isCheerio ? ', or to a Cheerio contact label' : '' ?>.
                    </p>
                    <a href="<?= site_url('emails/bulk') ?>" class="btn btn-wa btn-sm mt-auto align-self-start">Compose bulk</a>
                </div>
            </div>
        </div>
        <?php else: ?>
        <div class="col-12">
            <div class="activity-empty">
                <i class="fas fa-lock"></i>
                You can view this page but need <strong>emails.send</strong> permission to compose.
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>
<?= $this->endSection() ?>

<?= $this->section('styles') ?>
<link rel="stylesheet" href="<?= base_url('assets/css/emails.css') ?>?v=2">
<?= $this->endSection() ?>
