<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>
<?php
$provider = $provider ?? 'smtp';
$providerLabel = $providerLabel ?? 'SMTP';
$providerDetail = $providerDetail ?? '';
$defaultTo = $defaultTo ?? 'sateri.mangesh@gmail.com';
$defaultCampaign = $defaultCampaign ?? 'app-direct';
$campaigns = $campaigns ?? [];
?>
<div class="form-shell form-shell-lg">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <a href="<?= site_url('emails') ?>" class="btn btn-sm btn-outline-secondary"><i class="fas fa-arrow-left me-1"></i> Emails</a>
    </div>

    <?= view('emails/_provider_banner', [
        'provider'       => $provider,
        'providerLabel'  => $providerLabel,
        'providerDetail' => $providerDetail,
        'defaultTo'      => $defaultTo,
        'mode'           => 'single',
    ]) ?>

    <div class="card form-card" id="emailSingleCard"
         data-send-url="<?= site_url('emails/send') ?>"
         data-provider="<?= esc($provider) ?>">
        <form id="emailSingleForm" method="post" action="<?= site_url('emails/send') ?>">
            <?= csrf_field() ?>
            <div class="card-body">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                    <h5 class="mb-0">Single email</h5>
                    <span class="badge text-bg-dark">Send via <?= esc($providerLabel) ?></span>
                </div>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label" for="emailTo">To <span class="text-danger">*</span></label>
                        <input type="email" class="form-control" id="emailTo" name="to" required
                               value="<?= esc(old('to') ?? $defaultTo) ?>" placeholder="name@example.com">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="emailCampaign">Campaign name</label>
                        <input type="text" class="form-control" id="emailCampaign" name="campaign_name"
                               value="<?= esc(old('campaign_name') ?? $defaultCampaign) ?>"
                               placeholder="app-direct">
                        <div class="form-text">Used as analytics label (Cheerio / SendGrid).</div>
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="emailSubject">Subject <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="emailSubject" name="subject" required maxlength="250"
                               value="<?= esc(old('subject') ?? '') ?>" placeholder="Subject line">
                    </div>
                    <div class="col-12">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <label class="form-label mb-0" for="emailBody">Message <span class="text-danger">*</span></label>
                            <div class="form-check form-switch mb-0">
                                <input class="form-check-input" type="checkbox" role="switch" id="emailIsHtml" name="is_html" value="1">
                                <label class="form-check-label" for="emailIsHtml">HTML</label>
                            </div>
                        </div>
                        <textarea class="form-control" id="emailBody" name="body" rows="10" required
                                  placeholder="Write your message…"><?= esc(old('body') ?? '') ?></textarea>
                    </div>
                </div>
            </div>
            <div class="card-footer d-flex justify-content-end gap-2">
                <a href="<?= site_url('emails') ?>" class="btn btn-outline-secondary">Cancel</a>
                <button type="submit" class="btn btn-wa" id="btnSendSingle">
                    <i class="fas fa-paper-plane me-1"></i> Send via <?= esc($providerLabel) ?>
                </button>
            </div>
        </form>
        <div id="emailSingleResult" class="px-3 pb-3"></div>
    </div>
</div>
<?= $this->endSection() ?>

<?= $this->section('styles') ?>
<link rel="stylesheet" href="<?= base_url('assets/css/emails.css') ?>?v=2">
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= base_url('assets/js/emails.js') ?>?v=2"></script>
<?= $this->endSection() ?>
