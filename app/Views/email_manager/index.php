<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>
<?php
$activeTab = $activeTab ?? 'builder';
$providerLabel = $providerLabel ?? 'SMTP';
$isCheerio = ! empty($isCheerio);
$canSend = function_exists('can') && can('emails.send');
$builders = $builders ?? [];
$drips = $drips ?? [];
$campaigns = $campaigns ?? [];
$senders = $senders ?? [];
$verifications = $verifications ?? [];
$tabUrl = static fn (string $t): string => site_url('email-manager?tab=' . $t);
?>
<div class="page-list email-manager" id="emailManager"
     data-can-send="<?= $canSend ? '1' : '0' ?>"
     data-is-cheerio="<?= $isCheerio ? '1' : '0' ?>">

    <div class="em-hero mb-3">
        <div>
            <h4 class="mb-1">Email Manager</h4>
            <p class="text-muted small mb-0">
                Builder · Drips · Verifier · HTML campaigns · Sender &amp; Domain IDs
                · via <strong><?= esc($providerLabel) ?></strong>
                <?php if ($isCheerio): ?>
                    <span class="badge text-bg-success ms-1">Cheerio</span>
                <?php endif; ?>
            </p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <?php if ($canSend): ?>
                <a href="<?= site_url('emails/send') ?>" class="btn btn-sm btn-outline-secondary"><i class="fas fa-paper-plane me-1"></i> Quick single</a>
                <a href="<?= site_url('emails/bulk') ?>" class="btn btn-sm btn-outline-secondary"><i class="fas fa-mail-bulk me-1"></i> Quick bulk</a>
            <?php endif; ?>
            <a href="<?= site_url('analytics?tab=email') ?>" class="btn btn-sm btn-wa"><i class="fas fa-chart-pie me-1"></i> Email analytics</a>
        </div>
    </div>

    <?php if ($isCheerio): ?>
    <div class="alert alert-light border mb-3 py-2 small">
        <i class="fas fa-info-circle text-success me-1"></i>
        Cheerio sends use <code>single-email/send</code> &amp; <code>label-email/send</code>.
        Sender ID must be <strong>verified in Cheerio Dashboard</strong> for inbox delivery.
        Optional <code>emailBuilderId</code> maps to Cheerio’s email builder templates.
    </div>
    <?php endif; ?>

    <ul class="nav nav-tabs em-tabs mb-3" role="tablist">
        <?php
        $tabs = [
            'builder'    => ['Email Builder', 'fa-paint-brush'],
            'drips'      => ['Email Drips', 'fa-stream'],
            'verifier'   => ['Email Verifier', 'fa-check-double'],
            'campaigns'  => ['HTML Campaign', 'fa-bullhorn'],
            'senders'    => ['Sender & Domain', 'fa-id-badge'],
        ];
        foreach ($tabs as $key => [$label, $icon]):
        ?>
        <li class="nav-item">
            <a class="nav-link <?= $activeTab === $key ? 'active' : '' ?>" href="<?= $tabUrl($key) ?>">
                <i class="fas <?= $icon ?> me-1"></i> <?= esc($label) ?>
            </a>
        </li>
        <?php endforeach; ?>
    </ul>

    <div class="tab-content">
        <?php if ($activeTab === 'builder'): ?>
            <?= view('email_manager/_tab_builder', compact('builders', 'canSend')) ?>
        <?php elseif ($activeTab === 'drips'): ?>
            <?= view('email_manager/_tab_drips', compact('drips', 'builders', 'canSend')) ?>
        <?php elseif ($activeTab === 'verifier'): ?>
            <?= view('email_manager/_tab_verifier', compact('verifications')) ?>
        <?php elseif ($activeTab === 'campaigns'): ?>
            <?= view('email_manager/_tab_campaigns', compact('campaigns', 'builders', 'canSend', 'isCheerio')) ?>
        <?php else: ?>
            <?= view('email_manager/_tab_senders', compact('senders', 'canSend', 'isCheerio')) ?>
        <?php endif; ?>
    </div>
</div>
<?= $this->endSection() ?>

<?= $this->section('styles') ?>
<link rel="stylesheet" href="<?= base_url('assets/css/email-manager.css') ?>?v=1">
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= base_url('assets/js/email-manager.js') ?>?v=1"></script>
<?= $this->endSection() ?>
