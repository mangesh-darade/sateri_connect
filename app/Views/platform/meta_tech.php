<?= $this->extend('layouts/platform') ?>

<?= $this->section('content') ?>
<?php
$tech = $tech ?? [];
$ready = ! empty($tech['ready']);
$maskSecret = static function (string $v): string {
    return $v;
};
?>

<section class="platform-card">
    <div class="platform-card-head">
        <div>
            <h2>Meta Embedded Signup</h2>
            <p>One Tech Provider app for all clients. Customers only click <strong>Connect WhatsApp</strong> — no app create/publish for them.</p>
        </div>
        <span class="platform-badge <?= $ready ? 'platform-badge-ok' : 'platform-badge-warn' ?>">
            <?= $ready ? 'Ready' : 'Not ready' ?>
        </span>
    </div>

    <ol class="platform-steps" style="margin:0 0 1.1rem;padding-left:1.2rem;color:var(--pf-muted,#64748b);font-size:0.9rem;line-height:1.55">
        <li>Meta Developer → your Tech Provider / Solution Partner app</li>
        <li>Facebook Login for Business → create <strong>Embedded Signup</strong> configuration → copy Config ID</li>
        <li>Add this host to <strong>Allowed Domains for the JavaScript SDK</strong>:
            <code><?= esc((string) ($sdkOrigin ?? site_url())) ?></code>
        </li>
        <li>Save App ID, Config ID, App Secret below</li>
        <li>Client Settings → Meta → <strong>Connect WhatsApp</strong> (auto uses these credentials)</li>
    </ol>

    <form method="post" action="<?= site_url('platform/meta-tech') ?>" class="platform-form-grid">
        <?= csrf_field() ?>
        <div>
            <label class="platform-label">Meta App ID</label>
            <input class="platform-input" type="text" name="app_id" value="<?= esc((string) ($tech['app_id'] ?? '')) ?>" placeholder="App Dashboard → App ID" required>
        </div>
        <div>
            <label class="platform-label">Embedded Signup Config ID</label>
            <input class="platform-input" type="text" name="config_id" value="<?= esc((string) ($tech['config_id'] ?? '')) ?>" placeholder="Facebook Login for Business → Configurations" required>
        </div>
        <div>
            <label class="platform-label">App Secret</label>
            <input class="platform-input" type="password" name="app_secret" value="<?= esc((string) ($tech['app_secret'] ?? '')) ?>" placeholder="Leave blank to keep current" autocomplete="off">
        </div>
        <div>
            <label class="platform-label">Graph API version</label>
            <input class="platform-input" type="text" name="api_version" value="<?= esc((string) ($tech['api_version'] ?? 'v25.0')) ?>" placeholder="v25.0">
        </div>
        <div class="platform-actions" style="grid-column:1/-1">
            <button type="submit" class="btn-pf btn-pf-primary"><i class="fas fa-save"></i> Save Tech Provider</button>
            <a class="btn-pf" href="https://developers.facebook.com/docs/whatsapp/embedded-signup/" target="_blank" rel="noopener">Meta docs</a>
        </div>
    </form>
</section>

<section class="platform-card">
    <h3 class="platform-section-title">How clients use it</h3>
    <p class="mb-0" style="color:var(--pf-muted,#64748b);font-size:0.9rem;line-height:1.55">
        After this is Ready, each client only needs a Meta Business Portfolio + WhatsApp number.
        They open <strong>Settings → WhatsApp → Connect WhatsApp</strong>, authorize once, and Sateri stores their WABA / Phone Number ID / token automatically.
        They do <em>not</em> create or publish a Meta Developer app.
    </p>
</section>
<?= $this->endSection() ?>
