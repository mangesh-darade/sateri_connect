<?= $this->extend('layouts/platform') ?>

<?= $this->section('content') ?>
<?php
$tech = $tech ?? [];
$ready = ! empty($tech['ready']);
$source = (string) ($tech['source'] ?? 'none');
?>

<div style="display:flex;flex-direction:column;gap:1.5rem">

    <!-- Top Status Banner -->
    <section class="platform-card" style="padding:1.5rem">
        <div class="platform-card-head" style="margin-bottom:0.75rem">
            <div>
                <div style="display:flex;align-items:center;gap:0.6rem;margin-bottom:0.25rem">
                    <h2 style="margin:0;font-size:1.35rem;font-weight:700">Meta Tech Provider &amp; Embedded Signup</h2>
                    <span class="platform-badge <?= $ready ? 'platform-badge-ok' : 'platform-badge-warn' ?>">
                        <?= $ready ? '<i class="fas fa-check-circle me-1"></i> Ready for Embedded Signup' : '<i class="fas fa-exclamation-triangle me-1"></i> Setup Required' ?>
                    </span>
                </div>
                <p style="margin:0;color:var(--pf-muted);font-size:0.92rem">
                    Configure one centralized Meta Developer App for your entire SaaS platform.
                    Clients connect their WhatsApp Business Account via popup in 1-click without creating their own Meta app.
                </p>
            </div>
            <?php if ($source !== 'none'): ?>
                <div class="platform-stat-chip" style="font-size:0.8rem">Source: <?= esc(strtoupper($source)) ?></div>
            <?php endif; ?>
        </div>

        <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(180px, 1fr));gap:0.75rem;margin-top:1rem;background:var(--pf-paper-2,#fafbf8);border:1px solid var(--pf-line);border-radius:10px;padding:1rem">
            <div>
                <div style="font-size:0.75rem;text-transform:uppercase;color:var(--pf-muted);font-weight:600">App ID</div>
                <div style="font-weight:700;font-size:0.95rem;color:<?= !empty($tech['app_id']) ? 'var(--pf-ok)' : 'var(--pf-down)' ?>">
                    <?= !empty($tech['app_id']) ? '<i class="fas fa-check"></i> Configured' : '<i class="fas fa-times"></i> Missing' ?>
                </div>
            </div>
            <div>
                <div style="font-size:0.75rem;text-transform:uppercase;color:var(--pf-muted);font-weight:600">Config ID</div>
                <div style="font-weight:700;font-size:0.95rem;color:<?= !empty($tech['config_id']) ? 'var(--pf-ok)' : 'var(--pf-down)' ?>">
                    <?= !empty($tech['config_id']) ? '<i class="fas fa-check"></i> Configured' : '<i class="fas fa-times"></i> Missing' ?>
                </div>
            </div>
            <div>
                <div style="font-size:0.75rem;text-transform:uppercase;color:var(--pf-muted);font-weight:600">App Secret</div>
                <div style="font-weight:700;font-size:0.95rem;color:<?= !empty($tech['app_secret']) ? 'var(--pf-ok)' : 'var(--pf-down)' ?>">
                    <?= !empty($tech['app_secret']) ? '<i class="fas fa-lock"></i> Encrypted' : '<i class="fas fa-times"></i> Missing' ?>
                </div>
            </div>
            <div>
                <div style="font-size:0.75rem;text-transform:uppercase;color:var(--pf-muted);font-weight:600">Graph API</div>
                <div style="font-weight:700;font-size:0.95rem;color:var(--pf-ink)">
                    <?= esc($tech['api_version'] ?? 'v25.0') ?>
                </div>
            </div>
            <div>
                <div style="font-size:0.75rem;text-transform:uppercase;color:var(--pf-muted);font-weight:600">Webhook Token</div>
                <div style="font-weight:700;font-size:0.95rem;color:<?= !empty($webhookVerifyToken) ? 'var(--pf-ok)' : 'var(--pf-warn)' ?>">
                    <?= !empty($webhookVerifyToken) ? '<i class="fas fa-check"></i> Set' : '<i class="fas fa-info-circle"></i> Default' ?>
                </div>
            </div>
        </div>
    </section>

    <!-- Two-column grid: Form on Left, Copyable Meta Dev URLs on Right -->
    <div style="display:grid;grid-template-columns:1.15fr 0.85fr;gap:1.5rem;align-items:start">

        <!-- Column 1: Configuration Form -->
        <section class="platform-card" style="padding:1.5rem">
            <h3 style="font-size:1.1rem;font-weight:700;margin:0 0 1rem"><i class="fas fa-sliders-h me-1"></i> Tech Provider Credentials</h3>

            <form method="post" action="<?= site_url('platform/meta-tech') ?>" style="display:flex;flex-direction:column;gap:1rem">
                <?= csrf_field() ?>

                <div>
                    <label class="platform-label" style="display:flex;justify-content:space-between">
                        <span>Meta App ID <span class="text-danger">*</span></span>
                        <small style="color:var(--pf-muted)">Meta Developer &rarr; App Dashboard</small>
                    </label>
                    <input class="platform-input" type="text" name="app_id" value="<?= esc((string) ($tech['app_id'] ?? '')) ?>" placeholder="e.g. 123456789012345" required>
                </div>

                <div>
                    <label class="platform-label" style="display:flex;justify-content:space-between">
                        <span>Embedded Signup Config ID <span class="text-danger">*</span></span>
                        <small style="color:var(--pf-muted)">Facebook Login for Business &rarr; Configurations</small>
                    </label>
                    <input class="platform-input" type="text" name="config_id" value="<?= esc((string) ($tech['config_id'] ?? '')) ?>" placeholder="e.g. 987654321098765" required>
                </div>

                <div>
                    <label class="platform-label" style="display:flex;justify-content:space-between">
                        <span>Meta App Secret <span class="text-danger">*</span></span>
                        <small style="color:var(--pf-muted)">App Settings &rarr; Basic</small>
                    </label>
                    <input class="platform-input" type="password" name="app_secret" value="<?= esc((string) ($tech['app_secret'] ?? '')) ?>" placeholder="Leave blank to keep saved secret" autocomplete="off">
                </div>

                <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem">
                    <div>
                        <label class="platform-label">Graph API Version</label>
                        <input class="platform-input" type="text" name="api_version" value="<?= esc((string) ($tech['api_version'] ?? 'v25.0')) ?>" placeholder="v25.0">
                    </div>
                    <div>
                        <label class="platform-label">Webhook Verify Token</label>
                        <input class="platform-input" type="text" name="webhook_verify_token" value="<?= esc($webhookVerifyToken) ?>" placeholder="e.g. sateri_meta_verify_2026">
                    </div>
                </div>

                <div class="platform-actions" style="margin-top:0.5rem;display:flex;gap:0.75rem;align-items:center">
                    <button type="submit" class="btn-pf btn-pf-primary" style="padding:0.6rem 1.25rem">
                        <i class="fas fa-save me-1"></i> Save Tech Provider
                    </button>
                    <a class="btn-pf" href="https://developers.facebook.com/apps/" target="_blank" rel="noopener">
                        <i class="fas fa-external-link-alt me-1"></i> Meta App Dashboard
                    </a>
                </div>
            </form>
        </section>

        <!-- Column 2: Meta Developer Copyable URLs (Mandatory for Meta Review) -->
        <section class="platform-card" style="padding:1.5rem">
            <h3 style="font-size:1.1rem;font-weight:700;margin:0 0 0.5rem"><i class="fas fa-copy me-1"></i> Meta Console Configuration</h3>
            <p style="color:var(--pf-muted);font-size:0.85rem;margin-bottom:1rem">
                Copy these URLs directly into your Meta App settings to satisfy Meta's technical &amp; legal policies:
            </p>

            <div style="display:flex;flex-direction:column;gap:0.85rem">
                <!-- Allowed Domain -->
                <div>
                    <label style="font-size:0.78rem;font-weight:600;color:var(--pf-muted);display:block;margin-bottom:0.2rem">
                        JavaScript SDK Allowed Domain <span style="font-size:0.72rem">(FB Login &rarr; Settings)</span>
                    </label>
                    <div style="display:flex;gap:0.4rem">
                        <input type="text" readonly class="platform-input" style="font-size:0.82rem;font-family:monospace;background:#f8fafc" value="<?= esc($sdkOrigin) ?>" id="copySdkOrigin">
                        <button type="button" class="btn-pf" onclick="copyValue('copySdkOrigin', this)" title="Copy"><i class="fas fa-copy"></i></button>
                    </div>
                </div>

                <!-- Webhook Callback URL -->
                <div>
                    <label style="font-size:0.78rem;font-weight:600;color:var(--pf-muted);display:block;margin-bottom:0.2rem">
                        Webhook Callback URL <span style="font-size:0.72rem">(WhatsApp &rarr; Configuration)</span>
                    </label>
                    <div style="display:flex;gap:0.4rem">
                        <input type="text" readonly class="platform-input" style="font-size:0.82rem;font-family:monospace;background:#f8fafc" value="<?= esc($webhookUrl) ?>" id="copyWebhookUrl">
                        <button type="button" class="btn-pf" onclick="copyValue('copyWebhookUrl', this)" title="Copy"><i class="fas fa-copy"></i></button>
                    </div>
                </div>

                <!-- Webhook Verify Token -->
                <div>
                    <label style="font-size:0.78rem;font-weight:600;color:var(--pf-muted);display:block;margin-bottom:0.2rem">
                        Webhook Verify Token
                    </label>
                    <div style="display:flex;gap:0.4rem">
                        <input type="text" readonly class="platform-input" style="font-size:0.82rem;font-family:monospace;background:#f8fafc" value="<?= esc($webhookVerifyToken !== '' ? $webhookVerifyToken : 'sateri_meta_verify_2026') ?>" id="copyVerifyToken">
                        <button type="button" class="btn-pf" onclick="copyValue('copyVerifyToken', this)" title="Copy"><i class="fas fa-copy"></i></button>
                    </div>
                </div>

                <!-- Privacy Policy URL -->
                <div>
                    <label style="font-size:0.78rem;font-weight:600;color:var(--pf-muted);display:block;margin-bottom:0.2rem">
                        Privacy Policy URL <span style="color:var(--pf-coral);font-size:0.72rem">(Required by Meta Policy)</span>
                    </label>
                    <div style="display:flex;gap:0.4rem">
                        <input type="text" readonly class="platform-input" style="font-size:0.82rem;font-family:monospace;background:#f8fafc" value="<?= esc($privacyUrl) ?>" id="copyPrivacyUrl">
                        <button type="button" class="btn-pf" onclick="copyValue('copyPrivacyUrl', this)" title="Copy"><i class="fas fa-copy"></i></button>
                        <a href="<?= esc($privacyUrl) ?>" target="_blank" class="btn-pf" title="Preview"><i class="fas fa-external-link-alt"></i></a>
                    </div>
                </div>

                <!-- Terms of Service URL -->
                <div>
                    <label style="font-size:0.78rem;font-weight:600;color:var(--pf-muted);display:block;margin-bottom:0.2rem">
                        Terms of Service URL <span style="color:var(--pf-coral);font-size:0.72rem">(Required by Meta Policy)</span>
                    </label>
                    <div style="display:flex;gap:0.4rem">
                        <input type="text" readonly class="platform-input" style="font-size:0.82rem;font-family:monospace;background:#f8fafc" value="<?= esc($termsUrl) ?>" id="copyTermsUrl">
                        <button type="button" class="btn-pf" onclick="copyValue('copyTermsUrl', this)" title="Copy"><i class="fas fa-copy"></i></button>
                        <a href="<?= esc($termsUrl) ?>" target="_blank" class="btn-pf" title="Preview"><i class="fas fa-external-link-alt"></i></a>
                    </div>
                </div>

                <!-- Data Deletion URL -->
                <div>
                    <label style="font-size:0.78rem;font-weight:600;color:var(--pf-muted);display:block;margin-bottom:0.2rem">
                        User Data Deletion Callback URL <span style="color:var(--pf-coral);font-size:0.72rem">(App Settings &rarr; Basic)</span>
                    </label>
                    <div style="display:flex;gap:0.4rem">
                        <input type="text" readonly class="platform-input" style="font-size:0.82rem;font-family:monospace;background:#f8fafc" value="<?= esc($dataDeletionUrl) ?>" id="copyDataDeletionUrl">
                        <button type="button" class="btn-pf" onclick="copyValue('copyDataDeletionUrl', this)" title="Copy"><i class="fas fa-copy"></i></button>
                        <a href="<?= esc($dataDeletionUrl) ?>" target="_blank" class="btn-pf" title="Preview"><i class="fas fa-external-link-alt"></i></a>
                    </div>
                </div>
            </div>
        </section>
    </div>

    <!-- Meta Tech Provider Policy & Go-Live Checklist -->
    <section class="platform-card" style="padding:1.5rem">
        <h3 style="font-size:1.15rem;font-weight:700;margin:0 0 0.5rem"><i class="fas fa-clipboard-check me-1"></i> Meta Policy &amp; Go-Live Checklist</h3>
        <p style="color:var(--pf-muted);font-size:0.88rem;margin-bottom:1.25rem">
            Follow these 6 steps in your Meta Developer and Business Portfolio to pass Meta Review and enable Embedded Signup for all client workspaces:
        </p>

        <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(300px, 1fr));gap:1rem">

            <div style="background:var(--pf-paper-2,#fafbf8);border:1px solid var(--pf-line);border-radius:10px;padding:1.1rem">
                <div style="font-weight:700;font-size:0.95rem;margin-bottom:0.4rem;display:flex;align-items:center;gap:0.5rem">
                    <span style="background:var(--pf-teal);color:#fff;width:24px;height:24px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-size:0.75rem">1</span>
                    Business Verification (Mandatory)
                </div>
                <p style="font-size:0.85rem;color:var(--pf-muted);margin:0;line-height:1.5">
                    Meta requires Tech Providers to be verified. Go to <strong>Meta Business Portfolio &rarr; Settings &rarr; Security Center &rarr; Start Verification</strong>. Upload your business registration certificate.
                </p>
            </div>

            <div style="background:var(--pf-paper-2,#fafbf8);border:1px solid var(--pf-line);border-radius:10px;padding:1.1rem">
                <div style="font-weight:700;font-size:0.95rem;margin-bottom:0.4rem;display:flex;align-items:center;gap:0.5rem">
                    <span style="background:var(--pf-teal);color:#fff;width:24px;height:24px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-size:0.75rem">2</span>
                    Add Products in Meta App
                </div>
                <p style="font-size:0.85rem;color:var(--pf-muted);margin:0;line-height:1.5">
                    In your Meta Developer App (type: Business), add two products:
                    <br>&bull; <strong>WhatsApp</strong> (Cloud API)
                    <br>&bull; <strong>Facebook Login for Business</strong>
                </p>
            </div>

            <div style="background:var(--pf-paper-2,#fafbf8);border:1px solid var(--pf-line);border-radius:10px;padding:1.1rem">
                <div style="font-weight:700;font-size:0.95rem;margin-bottom:0.4rem;display:flex;align-items:center;gap:0.5rem">
                    <span style="background:var(--pf-teal);color:#fff;width:24px;height:24px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-size:0.75rem">3</span>
                    Create Config ID for Embedded Signup
                </div>
                <p style="font-size:0.85rem;color:var(--pf-muted);margin:0;line-height:1.5">
                    Go to <strong>Facebook Login for Business &rarr; Configurations &rarr; Create Configuration</strong>.
                    Enable <strong>Embedded Signup</strong> feature. Select permissions:
                    <code>whatsapp_business_management</code> &amp; <code>whatsapp_business_messaging</code>. Copy the Config ID above.
                </p>
            </div>

            <div style="background:var(--pf-paper-2,#fafbf8);border:1px solid var(--pf-line);border-radius:10px;padding:1.1rem">
                <div style="font-weight:700;font-size:0.95rem;margin-bottom:0.4rem;display:flex;align-items:center;gap:0.5rem">
                    <span style="background:var(--pf-teal);color:#fff;width:24px;height:24px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-size:0.75rem">4</span>
                    Add JavaScript SDK Domain
                </div>
                <p style="font-size:0.85rem;color:var(--pf-muted);margin:0;line-height:1.5">
                    Under <strong>Facebook Login for Business &rarr; Settings</strong>, add your domain in <em>"Allowed Domains for the JavaScript SDK"</em>:
                    <br><code><?= esc($sdkOrigin) ?></code>
                </p>
            </div>

            <div style="background:var(--pf-paper-2,#fafbf8);border:1px solid var(--pf-line);border-radius:10px;padding:1.1rem">
                <div style="font-weight:700;font-size:0.95rem;margin-bottom:0.4rem;display:flex;align-items:center;gap:0.5rem">
                    <span style="background:var(--pf-teal);color:#fff;width:24px;height:24px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-size:0.75rem">5</span>
                    Subscribe Webhook Fields
                </div>
                <p style="font-size:0.85rem;color:var(--pf-muted);margin:0;line-height:1.5">
                    In <strong>WhatsApp &rarr; Configuration</strong>, set the Callback URL and Verify Token from the right panel. Subscribe to:
                    <code>messages</code>, <code>messaging_postbacks</code>, <code>message_deliveries</code>, <code>message_reads</code>, and <code>account_update</code>.
                </p>
            </div>

            <div style="background:var(--pf-paper-2,#fafbf8);border:1px solid var(--pf-line);border-radius:10px;padding:1.1rem">
                <div style="font-weight:700;font-size:0.95rem;margin-bottom:0.4rem;display:flex;align-items:center;gap:0.5rem">
                    <span style="background:var(--pf-teal);color:#fff;width:24px;height:24px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-size:0.75rem">6</span>
                    App Review &amp; Switch to Live
                </div>
                <p style="font-size:0.85rem;color:var(--pf-muted);margin:0;line-height:1.5">
                    Save the Privacy Policy, Terms, and Data Deletion URLs into App Settings &rarr; Basic.
                    Request Advanced Access for permissions, then switch the top toggle from <strong>In development</strong> to <strong>Live</strong>.
                </p>
            </div>

        </div>
    </section>

</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
function copyValue(elementId, btn) {
    var el = document.getElementById(elementId);
    if (!el) return;
    el.select();
    el.setSelectionRange(0, 99999);
    navigator.clipboard.writeText(el.value).then(function() {
        var original = btn.innerHTML;
        btn.innerHTML = '<i class="fas fa-check text-success"></i>';
        setTimeout(function() {
            btn.innerHTML = original;
        }, 1500);
    }).catch(function() {
        document.execCommand('copy');
    });
}
</script>
<?= $this->endSection() ?>
