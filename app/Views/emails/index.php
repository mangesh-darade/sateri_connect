<?= $this->extend('layouts/main') ?>

<?= $this->section('header_actions') ?>
<?php if (function_exists('can') && can('emails.send')): ?>
    <a href="<?= site_url('emails/send') ?>" class="btn btn-wa btn-sm"><i class="fas fa-paper-plane me-1"></i> Compose single</a>
    <a href="<?= site_url('emails/bulk') ?>" class="btn btn-outline-secondary btn-sm"><i class="fas fa-mail-bulk me-1"></i> Compose bulk</a>
<?php endif; ?>
<a href="<?= site_url('email-manager') ?>" class="btn btn-outline-secondary btn-sm"><i class="fas fa-cog me-1"></i> Email Manager</a>
<?= $this->endSection() ?>

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

    <div class="launch-grid">
        <?php if (function_exists('can') && can('emails.send')): ?>
            <div class="card launch-card">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start gap-2">
                        <h3 class="launch-card-title"><i class="fas fa-paper-plane"></i> Single send</h3>
                        <span class="badge text-bg-light border">Via <?= esc($providerLabel) ?></span>
                    </div>
                    <p class="launch-card-copy">Send one email to one recipient with a clear subject and body.</p>
                    <a href="<?= site_url('emails/send') ?>" class="btn btn-wa btn-sm align-self-start">Open composer</a>
                </div>
            </div>
            <div class="card launch-card">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start gap-2">
                        <h3 class="launch-card-title"><i class="fas fa-mail-bulk"></i> Bulk send</h3>
                        <span class="badge text-bg-light border">Via <?= esc($providerLabel) ?></span>
                    </div>
                    <p class="launch-card-copy">
                        Send to many recipients<?= $isCheerio ? ', or to a Cheerio contact label' : '' ?>.
                    </p>
                    <a href="<?= site_url('emails/bulk') ?>" class="btn btn-wa btn-sm align-self-start">Open bulk composer</a>
                </div>
            </div>
        <?php else: ?>
            <div class="activity-empty">
                <i class="fas fa-lock"></i>
                You can view this page but need <strong>emails.send</strong> permission to compose.
            </div>
        <?php endif; ?>
    </div>
</div>
<?= $this->endSection() ?>

<?= $this->section('styles') ?>
<link rel="stylesheet" href="<?= base_url('assets/css/emails.css') ?>?v=2">
<?= $this->endSection() ?>
