<?= $this->extend('layouts/main') ?>

<?= $this->section('header_actions') ?>
<a href="<?= site_url('emails') ?>" class="btn btn-sm btn-outline-secondary"><i class="fas fa-arrow-left me-1"></i> Back</a>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php
$provider = $provider ?? 'smtp';
$providerLabel = $providerLabel ?? 'SMTP';
$providerDetail = $providerDetail ?? '';
$isCheerio = ! empty($isCheerio);
$defaultCampaign = $defaultCampaign ?? 'app-direct';
$campaigns = $campaigns ?? [];
$tags = $tags ?? [];
$contactsWithEmail = $contactsWithEmail ?? [];
$maxRecipients = (int) ($maxRecipients ?? 100);
$defaultTo = $defaultTo ?? 'sateri.mangesh@gmail.com';
?>
<div class="form-shell form-shell-lg">
    <?= view('emails/_provider_banner', [
        'provider'       => $provider,
        'providerLabel'  => $providerLabel,
        'providerDetail' => $providerDetail,
        'defaultTo'      => $defaultTo,
        'mode'           => 'bulk',
    ]) ?>

    <div class="card form-card" id="emailBulkCard"
         data-send-url="<?= site_url('emails/bulk') ?>"
         data-provider="<?= esc($provider) ?>"
         data-max="<?= $maxRecipients ?>">
        <form id="emailBulkForm" method="post" action="<?= site_url('emails/bulk') ?>">
            <?= csrf_field() ?>
            <div class="card-body">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                    <h5 class="mb-0">Bulk email</h5>
                    <span class="badge text-bg-dark">Send via <?= esc($providerLabel) ?></span>
                </div>

                <div class="mb-3">
                    <label class="form-label d-block">Audience mode</label>
                    <div class="btn-group" role="group">
                        <input type="radio" class="btn-check" name="mode" id="modeRecipients" value="recipients" checked autocomplete="off">
                        <label class="btn btn-outline-secondary" for="modeRecipients">Recipients list</label>
                        <?php if ($isCheerio): ?>
                        <input type="radio" class="btn-check" name="mode" id="modeLabel" value="label" autocomplete="off">
                        <label class="btn btn-outline-secondary" for="modeLabel">Cheerio label</label>
                        <?php endif; ?>
                    </div>
                </div>

                <div id="bulkRecipientsPanel" class="row g-3 mb-1">
                    <div class="col-md-6">
                        <label class="form-label" for="bulkRecipients">Paste emails</label>
                        <textarea class="form-control font-monospace" id="bulkRecipients" name="recipients" rows="6"
                                  placeholder="name@example.com&#10;another@example.com"><?= esc(old('recipients') ?? '') ?></textarea>
                        <div class="form-text">Comma, space, or new-line separated. Max <?= $maxRecipients ?>.</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="bulkContacts">Or pick contacts with email</label>
                        <select class="form-select" id="bulkContacts" name="contact_ids[]" multiple size="8">
                            <?php foreach ($contactsWithEmail as $c): ?>
                                <option value="<?= (int) $c['id'] ?>"><?= esc($c['name'] ?: 'Contact') ?> — <?= esc($c['email']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text"><?= count($contactsWithEmail) ?> contact(s) with email shown (first 300).</div>
                    </div>
                </div>

                <?php if ($isCheerio): ?>
                <div id="bulkLabelPanel" class="row g-3 mb-1 d-none">
                    <div class="col-md-6">
                        <label class="form-label" for="bulkLabelName">Cheerio label name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="bulkLabelName" name="label_name" list="tagNameList"
                               placeholder="Exact label name in Cheerio" value="<?= esc(old('label_name') ?? '') ?>">
                        <datalist id="tagNameList">
                            <?php foreach ($tags as $tag): ?>
                                <option value="<?= esc($tag['name']) ?>"></option>
                            <?php endforeach; ?>
                        </datalist>
                        <div class="form-text">Sends to everyone in that Cheerio label (contact group) in one request.</div>
                    </div>
                </div>
                <?php endif; ?>

                <div class="row g-3 mt-1">
                    <div class="col-md-6">
                        <label class="form-label" for="bulkCampaign">Campaign name</label>
                        <input type="text" class="form-control" id="bulkCampaign" name="campaign_name"
                               value="<?= esc(old('campaign_name') ?? $defaultCampaign) ?>"
                               placeholder="bulk-<?= esc(date('Ymd-His')) ?>">
                        <div class="form-text">Blank ठेवल्यास auto campaign नाव तयार होईल.</div>
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="bulkSubject">Subject <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="bulkSubject" name="subject" required maxlength="250"
                               value="<?= esc(old('subject') ?? '') ?>">
                    </div>
                    <div class="col-12">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <label class="form-label mb-0" for="bulkBody">Message <span class="text-danger">*</span></label>
                            <div class="form-check form-switch mb-0">
                                <input class="form-check-input" type="checkbox" role="switch" id="bulkIsHtml" name="is_html" value="1">
                                <label class="form-check-label" for="bulkIsHtml">HTML</label>
                            </div>
                        </div>
                        <textarea class="form-control" id="bulkBody" name="body" rows="10" required><?= esc(old('body') ?? '') ?></textarea>
                    </div>
                </div>
            </div>
            <div class="card-footer d-flex justify-content-end gap-2">
                <a href="<?= site_url('emails') ?>" class="btn btn-outline-secondary">Cancel</a>
                <button type="submit" class="btn btn-wa" id="btnSendBulk">
                    <i class="fas fa-mail-bulk me-1"></i> Send bulk via <?= esc($providerLabel) ?>
                </button>
            </div>
        </form>
        <div id="emailBulkResult" class="px-3 pb-3"></div>
    </div>
</div>
<?= $this->endSection() ?>

<?= $this->section('styles') ?>
<link rel="stylesheet" href="<?= asset_url('assets/css/emails.css') ?>">
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= asset_url('assets/js/emails.js') ?>"></script>
<?= $this->endSection() ?>
