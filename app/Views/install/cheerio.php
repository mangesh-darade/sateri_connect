<?= $this->extend('layouts/install') ?>

<?= $this->section('content') ?>
<h3 class="mb-1" style="font-family:var(--font-display);color:var(--wa-ink)">WhatsApp Provider</h3>
<p class="text-muted mb-3">Choose Cheerio or Meta. You can change this later in Settings.</p>
<form action="<?= site_url('install/cheerio') ?>" method="post">
    <?= csrf_field() ?>
    <div class="mb-3">
        <label class="form-label">Provider</label>
        <select name="whatsapp_provider" class="form-select" id="installProvider">
            <option value="cheerio" <?= old('whatsapp_provider', 'cheerio') === 'cheerio' ? 'selected' : '' ?>>Cheerio Direct API</option>
            <option value="meta" <?= old('whatsapp_provider') === 'meta' ? 'selected' : '' ?>>Meta Cloud API</option>
        </select>
    </div>

    <div id="installCheerioFields">
        <div class="mb-3">
            <label class="form-label">Cheerio API Key</label>
            <input type="password" name="cheerio_api_key" class="form-control" value="<?= esc(old('cheerio_api_key') ?? '') ?>" autocomplete="off" placeholder="x-api-key from app.cheerio.in">
            <div class="form-text"><a href="https://app.cheerio.in/settings/apikey" target="_blank" rel="noopener">Generate API key</a></div>
        </div>
        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label">Webhook verify token</label>
                <input type="text" name="cheerio_webhook_verify_token" class="form-control" value="<?= esc(old('cheerio_webhook_verify_token') ?? bin2hex(random_bytes(8))) ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label">Webhook secret</label>
                <input type="password" name="cheerio_webhook_secret" class="form-control" value="<?= esc(old('cheerio_webhook_secret') ?? '') ?>" autocomplete="off">
            </div>
        </div>
    </div>

    <div id="installMetaFields" class="d-none">
        <div class="mb-3">
            <label class="form-label">Meta Access Token</label>
            <input type="password" name="meta_access_token" class="form-control" value="<?= esc(old('meta_access_token') ?? '') ?>" autocomplete="off">
        </div>
        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label">Phone Number ID</label>
                <input type="text" name="meta_phone_number_id" class="form-control" value="<?= esc(old('meta_phone_number_id') ?? '') ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label">WABA ID</label>
                <input type="text" name="meta_waba_id" class="form-control" value="<?= esc(old('meta_waba_id') ?? '') ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label">API Version</label>
                <input type="text" name="meta_api_version" class="form-control" value="<?= esc(old('meta_api_version') ?? 'v21.0') ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label">Verify token</label>
                <input type="text" name="meta_webhook_verify_token" class="form-control" value="<?= esc(old('meta_webhook_verify_token') ?? bin2hex(random_bytes(8))) ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label">App Secret</label>
                <input type="password" name="meta_webhook_secret" class="form-control" value="<?= esc(old('meta_webhook_secret') ?? '') ?>" autocomplete="off">
            </div>
        </div>
    </div>

    <div class="alert border mt-3" style="background:var(--surface-2);border-color:var(--border)!important">
        Webhook URL after install: <code><?= esc(rtrim(site_url('webhooks'), '/')) ?></code>
    </div>
    <div class="d-flex justify-content-between flex-wrap gap-2 mt-3">
        <a href="<?= site_url('install/admin') ?>" class="btn btn-outline-secondary">Back</a>
        <div class="d-flex gap-2">
            <button type="submit" name="skip" value="1" class="btn btn-outline-secondary">Skip for now</button>
            <button type="submit" class="btn btn-wa">Save &amp; continue <i class="fas fa-arrow-right ms-1"></i></button>
        </div>
    </div>
</form>
<script>
(function () {
    var sel = document.getElementById('installProvider');
    function sync() {
        var meta = sel && sel.value === 'meta';
        document.getElementById('installCheerioFields').classList.toggle('d-none', !!meta);
        document.getElementById('installMetaFields').classList.toggle('d-none', !meta);
    }
    if (sel) { sel.addEventListener('change', sync); sync(); }
})();
</script>
<?= $this->endSection() ?>
