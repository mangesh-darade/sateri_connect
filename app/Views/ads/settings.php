<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>
<div class="page-list" id="adsSettingsPage">
<div class="card" style="max-width: 760px;">
    <div class="card-header"><h2 class="card-title mb-0">Ad settings</h2></div>
    <div class="card-body py-3">
        <form id="adsSettingsForm" autocomplete="off">
            <h3 class="h6">Spending limit</h3>
            <p class="small text-muted">No campaign can have a daily budget above this limit. Ads cannot be launched until you set it.</p>
            <div class="mb-4" style="max-width: 280px;">
                <label class="form-label" for="adsCap">Maximum daily budget per campaign (INR)</label>
                <input type="number" min="0" step="1" class="form-control" id="adsCap" name="cap" value="<?= esc((string) ($values['cap'] > 0 ? $values['cap'] : ''), 'attr') ?>" placeholder="e.g. 1000">
                <div class="invalid-feedback"></div>
            </div>

            <h3 class="h6">Mode</h3>
            <div class="mb-4">
                <div class="form-check">
                    <input class="form-check-input" type="radio" name="mode" id="adsModeMock" value="mock" <?= $values['mode'] === 'mock' ? 'checked' : '' ?>>
                    <label class="form-check-label" for="adsModeMock"><strong>Test mode</strong> <span class="text-muted small">Builds and launches campaigns without sending anything to Meta. No money is spent.</span></label>
                </div>
                <div class="form-check">
                    <input class="form-check-input" type="radio" name="mode" id="adsModeLive" value="live" <?= $values['mode'] === 'live' ? 'checked' : '' ?>>
                    <label class="form-check-label" for="adsModeLive"><strong>Live</strong> <span class="text-muted small">Creates real ads on your Meta ad account. Meta charges your ad account's payment method.</span></label>
                </div>
            </div>

            <h3 class="h6">Meta connection (needed for live mode)</h3>
            <div class="row g-3 mb-4">
                <div class="col-md-6">
                    <label class="form-label" for="adsAccount">Ad account ID</label>
                    <input type="text" class="form-control font-monospace" id="adsAccount" name="account" value="<?= esc($values['account'], 'attr') ?>" placeholder="1234567890">
                    <div class="invalid-feedback"></div>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="adsPage">Facebook Page ID</label>
                    <input type="text" class="form-control font-monospace" id="adsPage" name="page" value="<?= esc($values['page'], 'attr') ?>">
                    <div class="invalid-feedback"></div>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="adsWhatsapp">WhatsApp number for the ad</label>
                    <input type="text" class="form-control" id="adsWhatsapp" name="whatsapp" value="<?= esc($values['whatsapp'], 'attr') ?>" placeholder="919876543210">
                    <div class="form-text">With country code, digits only.</div>
                    <div class="invalid-feedback"></div>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="adsToken">Access token</label>
                    <input type="password" class="form-control" id="adsToken" name="token" value="" autocomplete="new-password" placeholder="<?= $values['token_saved'] ? 'Saved. Leave blank to keep it.' : 'Paste the token' ?>">
                    <div class="form-text">Stored encrypted and never shown again.</div>
                    <div class="invalid-feedback"></div>
                </div>
            </div>

            <button type="submit" class="btn btn-wa">Save settings</button>
        </form>
    </div>
</div>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= asset_url('assets/js/ads.js') ?>"></script>
<?= $this->endSection() ?>
