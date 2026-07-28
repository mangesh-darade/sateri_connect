<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>
<?php
$providerShort = function_exists('whatsapp_provider_short') ? whatsapp_provider_short() : 'Cheerio';
$providerLabel = function_exists('whatsapp_provider_label') ? whatsapp_provider_label() : 'provider';
$dashUrl       = function_exists('whatsapp_provider_dashboard_url') ? whatsapp_provider_dashboard_url() : 'https://app.cheerio.in/';
$dashLabel     = function_exists('whatsapp_provider_dashboard_label') ? whatsapp_provider_dashboard_label() : 'provider dashboard';
$syncLabel     = function_exists('whatsapp_sync_label') ? whatsapp_sync_label() : 'Sync templates';
$isMeta        = function_exists('is_meta_provider') && is_meta_provider();
?>
<div class="page-toolbar">
    <div class="toolbar-actions">
        <a href="<?= site_url('templates') ?>" class="btn btn-outline-secondary btn-sm">
            <i class="fas fa-arrow-left me-1"></i> Back
        </a>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header"><h3 class="card-title">Submit to <?= esc($providerLabel) ?></h3></div>
            <div class="card-body">
                <form action="<?= site_url('templates') ?>" method="post">
                    <?= csrf_field() ?>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Template name</label>
                            <input type="text" name="name" class="form-control" required
                                   pattern="[a-z0-9_]+" value="<?= esc(old('name') ?? '') ?>"
                                   placeholder="order_ready">
                            <div class="form-text">Lowercase letters, numbers, underscores only.</div>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Language</label>
                            <input type="text" name="language" class="form-control"
                                   value="<?= esc(old('language') ?? 'en_US') ?>" placeholder="en_US">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Category</label>
                            <select name="category" class="form-select">
                                <?php
                                $cat = old('category') ?? 'UTILITY';
                                foreach (['UTILITY' => 'Utility', 'MARKETING' => 'Marketing', 'AUTHENTICATION' => 'Authentication'] as $value => $label):
                                ?>
                                    <option value="<?= $value ?>" <?= $cat === $value ? 'selected' : '' ?>><?= $label ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Header (optional)</label>
                            <input type="text" name="header" class="form-control" maxlength="60"
                                   value="<?= esc(old('header') ?? '') ?>" placeholder="Order update">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Body</label>
                            <textarea name="body" class="form-control" rows="5" required
                                      placeholder="Hello {{1}}, your order {{2}} is ready."><?= esc(old('body') ?? '') ?></textarea>
                            <div class="form-text">Use {{1}}, {{2}} for variables. <?= esc($providerShort) ?> / WABA must approve before you can send.</div>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Variable examples (comma-separated)</label>
                            <input type="text" name="body_examples" class="form-control"
                                   value="<?= esc(old('body_examples') ?? '') ?>"
                                   placeholder="Vipin, ORD-1001">
                            <div class="form-text">Required when the body has variables.</div>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Footer (optional)</label>
                            <input type="text" name="footer" class="form-control" maxlength="60"
                                   value="<?= esc(old('footer') ?? '') ?>" placeholder="Thank you">
                        </div>
                    </div>
                    <button type="submit" class="btn btn-wa mt-3"><i class="fas fa-paper-plane me-1"></i> Submit to <?= esc($providerLabel) ?></button>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="dash-panel">
            <div class="panel-head"><h3>How this works</h3></div>
            <div class="panel-body">
                <ol class="small ps-3 mb-3" style="color:var(--text-muted)">
                    <li class="mb-2">Template is submitted to <?= esc($providerShort) ?> for review.</li>
                    <li class="mb-2">Status starts as <em>PENDING</em>.</li>
                    <li class="mb-2">After approval, click <strong><?= esc($syncLabel) ?></strong>.</li>
                    <li>Use it in Live Chat or Campaigns.</li>
                </ol>
                <p class="small mb-0 text-muted">
                    WABA / business verification is done in the <a href="<?= esc($dashUrl) ?>" target="_blank" rel="noopener"><?= esc($dashLabel) ?></a>.
                    This app creates templates via <?= esc($providerLabel) ?> and sends them after approval<?= $isMeta ? ' (needs WABA ID in Settings)' : '' ?>.
                </p>
            </div>
        </div>
    </div>
</div>
<?= $this->endSection() ?>
