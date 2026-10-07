<?= $this->extend('layouts/main') ?>

<?= $this->section('header_actions') ?>
<?php
$canSend = function_exists('can') && can('emails.send');
$activeTab = $activeTab ?? 'campaigns';
?>
<?php if ($canSend): ?>
    <div class="dropdown d-inline-block">
        <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown">
            <i class="fas fa-paper-plane me-1"></i> Quick Send
        </button>
        <ul class="dropdown-menu dropdown-menu-end shadow-sm">
            <li><a class="dropdown-item small" href="<?= site_url('emails/single') ?>"><i class="fas fa-envelope me-2 text-primary"></i> Single Email</a></li>
            <li><a class="dropdown-item small" href="<?= site_url('emails/bulk') ?>"><i class="fas fa-mail-bulk me-2 text-info"></i> Bulk Broadcast</a></li>
        </ul>
    </div>
<?php endif; ?>
<a href="<?= site_url('email-manager?tab=senders') ?>" class="btn btn-sm <?= $activeTab === 'senders' ? 'btn-primary' : 'btn-outline-secondary' ?>">
    <i class="fas fa-id-badge me-1"></i> Sender &amp; Domain Identity
</a>
<a href="<?= site_url('analytics?tab=email') ?>" class="btn btn-sm btn-outline-primary">
    <i class="fas fa-chart-pie me-1"></i> Analytics &amp; Reports
</a>
<a href="<?= site_url('settings/email') ?>" class="btn btn-sm btn-outline-secondary">
    <i class="fas fa-cog me-1"></i> Email Settings
</a>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php
$activeTab = $activeTab ?? 'campaigns';
$providerLabel = $providerLabel ?? 'Amazon SES';
$isCheerio = ! empty($isCheerio);
$canSend = function_exists('can') && can('emails.send');
$builders = $builders ?? [];
$drips = $drips ?? [];
$campaigns = $campaigns ?? [];
$customerGroups = $customerGroups ?? [];
$analyticsSummary = $analyticsSummary ?? [];
$recentLogs = $recentLogs ?? [];
$recentUnsubscribes = $recentUnsubscribes ?? [];
$unsubCount = (int) ($unsubCount ?? 0);
$senders = $senders ?? [];
$verifications = $verifications ?? [];
$tabUrl = static fn (string $t): string => site_url('email-manager?tab=' . $t);
?>
<div class="page-list email-manager" id="emailManager"
     data-can-send="<?= $canSend ? '1' : '0' ?>"
     data-is-cheerio="<?= $isCheerio ? '1' : '0' ?>">

    <!-- Top Compact Status Bar -->
    <div class="em-hero-banner mb-3 p-3 rounded-3 bg-white border d-flex flex-wrap align-items-center justify-content-between gap-3 shadow-sm">
        <div class="d-flex align-items-center gap-3">
            <div class="em-hero-icon bg-primary-subtle text-primary p-2 rounded-3 d-flex align-items-center justify-content-center" style="width: 42px; height: 42px; font-size: 1.15rem;">
                <i class="fas fa-envelope-open-text"></i>
            </div>
            <div>
                <h6 class="mb-0 fw-bold text-dark">Email Marketing Studio</h6>
                <p class="mb-0 text-muted small">Design templates, launch marketing campaigns, and monitor open &amp; click engagement.</p>
            </div>
        </div>
        <div class="d-flex align-items-center gap-2">
            <span class="text-muted small">Active Transport:</span>
            <span class="badge px-3 py-2 rounded-pill bg-primary-subtle text-primary border border-primary-subtle font-monospace">
                <i class="fas fa-check-circle me-1"></i> <?= esc($providerLabel) ?>
            </span>
        </div>
    </div>

    <?= view('partials/email_compliance_banner', [
        'reputation'     => $reputation ?? null,
        'companyAddress' => $companyAddress ?? '',
    ]) ?>

    <?php if ($isCheerio): ?>
    <div class="alert alert-light border py-2 small mb-3">
        <i class="fas fa-info-circle text-success me-1"></i>
        Cheerio mode is active. Sends use <code>single-email/send</code> &amp; <code>label-email/send</code>.
    </div>
    <?php endif; ?>

    <!-- Clean Single-Row Navigation Tabs -->
    <ul class="nav nav-pills em-nav-pills mb-3 bg-white p-1 rounded-3 border shadow-sm d-flex flex-nowrap overflow-x-auto" role="tablist">
        <?php
        $tabs = [
            'campaigns'  => ['Marketing Campaigns', 'fa-bullhorn'],
            'builder'    => ['Templates & Builder', 'fa-paint-brush'],
            'drips'      => ['Auto Drips', 'fa-stream'],
            'verifier'   => ['List Verifier', 'fa-shield-alt'],
            'senders'    => ['Sender Identities', 'fa-id-badge'],
        ];
        foreach ($tabs as $key => [$label, $icon]):
        ?>
        <li class="nav-item">
            <a class="nav-link py-2 px-3 text-nowrap fw-medium <?= $activeTab === $key ? 'active' : '' ?>" href="<?= $tabUrl($key) ?>">
                <i class="fas <?= $icon ?> me-1 opacity-75"></i> <?= esc($label) ?>
            </a>
        </li>
        <?php endforeach; ?>
    </ul>

    <!-- Tab Contents -->
    <div class="tab-content">
        <?php if ($activeTab === 'builder'): ?>
            <?= view('email_manager/_tab_builder', compact('builders', 'canSend', 'isCheerio')) ?>
        <?php elseif ($activeTab === 'drips'): ?>
            <?= view('email_manager/_tab_drips', compact('drips', 'builders', 'canSend', 'isCheerio')) ?>
        <?php elseif ($activeTab === 'verifier'): ?>
            <?= view('email_manager/_tab_verifier', compact('verifications')) ?>
        <?php elseif ($activeTab === 'campaigns'): ?>
            <?= view('email_manager/_tab_campaigns', compact('campaigns', 'builders', 'customerGroups', 'canSend', 'isCheerio')) ?>
        <?php else: ?>
            <?= view('email_manager/_tab_senders', compact('senders', 'canSend', 'isCheerio', 'isSes')) ?>
        <?php endif; ?>
    </div>
</div>
<?= $this->endSection() ?>

<?= $this->section('styles') ?>
<link rel="stylesheet" href="<?= asset_url('assets/css/email-manager.css') ?>">
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<?= $this->include('partials/email_editor_assets') ?>
<script src="<?= asset_url('assets/js/email-manager.js') ?>"></script>
<script src="<?= asset_url('assets/js/email-identities.js') ?>"></script>
<?= $this->endSection() ?>
