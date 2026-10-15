<?= $this->extend('layouts/platform') ?>

<?= $this->section('content') ?>
<?php
$tech = $tech ?? [];
$clients = $clients ?? [];
$ready = ! empty($tech['app_id']) && ! empty($tech['config_id']) && ! empty($tech['app_secret']);
$canEditTech = ! empty($canEditTech);
$ownsAllClients = ! empty($ownsAllClients);
?>

<div style="display:flex;flex-direction:column;gap:1.5rem">

    <section class="platform-card" style="padding:1.5rem">
        <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(180px, 1fr));gap:0.75rem;background:var(--pf-paper-2,#fafbf8);border:1px solid var(--pf-line);border-radius:10px;padding:1rem">
            <div>
                <div style="font-size:0.75rem;text-transform:uppercase;color:var(--pf-muted);font-weight:600">App ID</div>
                <div style="font-weight:700;font-size:0.95rem;color:<?= !empty($tech['app_id']) ? 'var(--pf-ok)' : 'var(--pf-down)' ?>;word-break:break-all">
                    <?= !empty($tech['app_id']) ? esc((string) $tech['app_id']) : '<i class="fas fa-times"></i> Missing' ?>
                </div>
            </div>
            <div>
                <div style="font-size:0.75rem;text-transform:uppercase;color:var(--pf-muted);font-weight:600">Config ID</div>
                <div style="font-weight:700;font-size:0.95rem;color:<?= !empty($tech['config_id']) ? 'var(--pf-ok)' : 'var(--pf-down)' ?>;word-break:break-all">
                    <?= !empty($tech['config_id']) ? esc((string) $tech['config_id']) : '<i class="fas fa-times"></i> Missing' ?>
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
                <div style="font-weight:700;font-size:0.95rem;color:<?= !empty($webhookTokenSet) || !empty($webhookVerifyToken) ? 'var(--pf-ok)' : 'var(--pf-warn)' ?>">
                    <?= !empty($webhookTokenSet) || !empty($webhookVerifyToken) ? '<i class="fas fa-check"></i> Set' : '<i class="fas fa-info-circle"></i> Default' ?>
                </div>
            </div>
        </div>
    </section>

    <!-- Two-column grid: Form on Left, Copyable Meta Dev URLs on Right -->
    <div style="display:grid;grid-template-columns:1.15fr 0.85fr;gap:1.5rem;align-items:start">

        <!-- Column 1: Configuration Form -->
        <section class="platform-card" style="padding:1.5rem">
            <?php if (! $canEditTech): ?>
            <h3 style="font-size:1.1rem;font-weight:700;margin:0 0 0.35rem"><i class="fab fa-whatsapp me-1"></i> Your clients</h3>
            <p style="margin:0;color:var(--pf-muted);font-size:0.88rem">Connect WhatsApp for the clients you created. The shared Meta app stays with the main platform super admin.</p>
            <?php else: ?>
            <h3 style="font-size:1.1rem;font-weight:700;margin:0 0 0.35rem"><i class="fas fa-sliders-h me-1"></i> Your Meta app</h3>
            <p style="margin:0 0 1rem;color:var(--pf-muted);font-size:0.88rem">This App ID and Embedded Signup Config ID belong to your super admin login. Other super admins use their own. Connect WhatsApp below only for your clients.</p>

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

                <div class="platform-actions" style="margin-top:0.5rem;display:flex;gap:0.75rem;align-items:center;flex-wrap:wrap">
                    <button type="submit" class="btn-pf btn-pf-primary" style="padding:0.6rem 1.25rem">
                        <i class="fas fa-save me-1"></i> Save Tech Provider
                    </button>
                    <button type="button" class="btn-pf" id="btnTestMetaTech" style="padding:0.6rem 1.25rem;border-color:var(--pf-teal);color:var(--pf-teal);font-weight:700">
                        <i class="fas fa-plug me-1"></i> Test Meta API
                    </button>
                    <a class="btn-pf" href="https://developers.facebook.com/apps/" target="_blank" rel="noopener">
                        <i class="fas fa-external-link-alt me-1"></i> Meta App Dashboard
                    </a>
                </div>
                <div id="metaTestResult" style="display:none;margin-top:1rem;padding:0.75rem 1rem;border-radius:8px;font-size:0.85rem"></div>
            </form>
            <?php endif; ?>
        </section>

        <?php if ($canEditTech): ?>
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
        <?php endif; ?>
    </div>

    <section class="platform-card" style="padding:1.5rem" id="platformEmbeddedSignup"
             data-app-id="<?= esc((string) ($tech['app_id'] ?? '')) ?>"
             data-config-id="<?= esc((string) ($tech['config_id'] ?? '')) ?>"
             data-api-version="<?= esc((string) ($tech['api_version'] ?? 'v25.0')) ?>"
             data-ready="<?= $ready ? '1' : '0' ?>"
             data-csrf-name="<?= esc(csrf_token()) ?>"
             data-csrf-hash="<?= esc(csrf_hash()) ?>"
             data-connect-url="<?= esc(site_url('platform/meta-tech/connect')) ?>"
             data-status-url="<?= esc(site_url('platform/meta-tech/client')) ?>">
        <h3 style="font-size:1.1rem;font-weight:700;margin:0 0 0.35rem"><i class="fab fa-whatsapp me-1"></i> Connect a client</h3>
        <p style="margin:0 0 1rem;color:var(--pf-muted);font-size:0.88rem"><?= $ownsAllClients ? 'Every client is listed. Connect opens the Meta popup with your App ID and Config ID.' : 'Only your clients are listed. Connect opens the Meta popup with your App ID and Config ID, then saves the number on that client.' ?></p>
        <?php if (! $ready): ?>
            <div style="background:#fff7e8;color:#7a4b00;border:1px solid #f0d7a2;border-radius:8px;padding:0.65rem 0.85rem;font-size:0.85rem;margin-bottom:0.85rem">
                Save your App ID, Config ID, and App Secret above before connecting a client.
            </div>
        <?php endif; ?>
        <?php if ($clients === []): ?>
            <p style="margin:0;color:var(--pf-muted)">No clients yet. Create a client first.</p>
        <?php else: ?>
            <div class="platform-table-wrap">
                <table class="platform-table">
                    <thead>
                    <tr><th>Client</th><th>WhatsApp</th><th></th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($clients as $client): ?>
                        <tr data-client-row="<?= esc($client['key']) ?>">
                            <td>
                                <div class="platform-client-name"><?= esc($client['name']) ?></div>
                                <div class="platform-client-meta"><?= esc($client['key']) ?></div>
                            </td>
                            <td><span class="platform-badge platform-badge-warn js-wa-status">Not connected</span></td>
                            <td style="text-align:right">
                                <button type="button" class="btn-pf btn-pf-primary js-connect-wa" data-tenant-key="<?= esc($client['key']) ?>" <?= $ready ? '' : 'disabled' ?> style="padding:0.45rem 0.9rem">
                                    <i class="fab fa-whatsapp me-1"></i> Connect WhatsApp
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
        <div id="metaEmbedResult" style="display:none;margin-top:0.85rem;padding:0.65rem 0.85rem;border-radius:8px;font-size:0.85rem"></div>
    </section>

    <?php if ($canEditTech): ?>
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
    <?php endif; ?>

</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= asset_url('assets/js/meta-embedded-signup.js') ?>"></script>
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

var metaTestBtn = document.getElementById('btnTestMetaTech');
if (metaTestBtn) metaTestBtn.addEventListener('click', function() {
    var btn = this;
    var originalHtml = btn.innerHTML;
    var resultBox = document.getElementById('metaTestResult');

    var form = btn.closest('form');
    var formData = new FormData(form);

    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Testing Meta API...';
    resultBox.style.display = 'none';

    fetch('<?= site_url('platform/meta-tech/test') ?>', {
        method: 'POST',
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: formData
    })
    .then(function(res) {
        return res.json().then(function(data) {
            return { ok: res.ok, data: data };
        });
    })
    .then(function(res) {
        resultBox.style.display = 'block';
        if (res.ok && res.data.status === 'success') {
            resultBox.style.background = '#e8f6ef';
            resultBox.style.color = '#0d5c40';
            resultBox.style.border = '1px solid #b9dfcb';
            resultBox.innerHTML = '<i class="fas fa-check-circle me-1"></i> ' + (res.data.message || 'Meta connection verified.');
        } else {
            resultBox.style.background = '#fdecea';
            resultBox.style.color = '#8f1f16';
            resultBox.style.border = '1px solid #f0bfb8';
            resultBox.innerHTML = '<i class="fas fa-exclamation-triangle me-1"></i> ' + (res.data.message || 'Failed to verify Meta API.');
        }
    })
    .catch(function(err) {
        resultBox.style.display = 'block';
        resultBox.style.background = '#fdecea';
        resultBox.style.color = '#8f1f16';
        resultBox.style.border = '1px solid #f0bfb8';
        resultBox.innerHTML = '<i class="fas fa-exclamation-triangle me-1"></i> Request error: ' + err.message;
    })
    .finally(function() {
        btn.disabled = false;
        btn.innerHTML = originalHtml;
    });
});

(function () {
    var box = document.getElementById('platformEmbeddedSignup');
    if (!box) return;
    var appId = (box.getAttribute('data-app-id') || '').trim();
    var configId = (box.getAttribute('data-config-id') || '').trim();
    var apiVersion = (box.getAttribute('data-api-version') || 'v25.0').trim() || 'v25.0';
    var ready = box.getAttribute('data-ready') === '1';
    var csrfName = box.getAttribute('data-csrf-name') || '';
    var csrfHash = box.getAttribute('data-csrf-hash') || '';
    var connectUrl = box.getAttribute('data-connect-url') || '';
    var statusUrl = (box.getAttribute('data-status-url') || '').replace(/\/$/, '');
    var resultBox = document.getElementById('metaEmbedResult');
    var activeKey = '';
    var pendingCode = null;
    var pendingSession = null;
    var completing = false;
    var localHost = /^(localhost|127\.0\.0\.1)$/i.test(window.location.hostname);
    var httpsOk = window.location.protocol === 'https:' || localHost;

    function setResult(text, ok) {
        if (!resultBox) return;
        resultBox.style.display = 'block';
        resultBox.style.background = ok ? '#e8f6ef' : '#fdecea';
        resultBox.style.color = ok ? '#0d5c40' : '#8f1f16';
        resultBox.style.border = ok ? '1px solid #b9dfcb' : '1px solid #f0bfb8';
        resultBox.textContent = text;
    }

    function markRow(key) {
        var row = document.querySelector('[data-client-row="' + key + '"] .js-wa-status');
        if (!row) return;
        row.textContent = 'Connected';
        row.className = 'platform-badge platform-badge-ok js-wa-status';
    }

    function tryComplete() {
        if (completing || !pendingCode || !pendingSession || !activeKey) return;
        completing = true;
        var code = pendingCode;
        var session = pendingSession;
        pendingCode = null;
        pendingSession = null;
        setResult('Saving WhatsApp on ' + activeKey + '…', true);
        var body = new FormData();
        body.append(csrfName, csrfHash);
        body.append('tenant_key', activeKey);
        body.append('code', code);
        body.append('waba_id', session.waba_id || '');
        body.append('phone_number_id', session.phone_number_id || '');
        body.append('business_id', session.business_id || '');
        fetch(connectUrl, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': csrfHash },
            body: body
        }).then(function (res) {
            return res.json().then(function (data) { return { ok: res.ok, data: data }; });
        }).then(function (res) {
            var payload = res.data || {};
            setResult(payload.message || (res.ok ? 'WhatsApp connected' : 'Embedded Signup failed'), !!(res.ok && payload.success));
            if (res.ok && payload.success) markRow(activeKey);
        }).catch(function (err) {
            setResult(err.message || 'Embedded Signup failed', false);
        }).finally(function () { completing = false; });
    }

    function onSession(data) {
        var ev = String((data && data.event) || '');
        var finish = { FINISH: 1, FINISH_ONLY_WABA: 1, FINISH_WHATSAPP_BUSINESS_APP_ONBOARDING: 1, FINISH_OBO_MIGRATION: 1, FINISH_GRANT_ONLY_API_ACCESS: 1 };
        if (finish[ev]) {
            var d = data.data || {};
            pendingSession = {
                phone_number_id: String(d.phone_number_id || ''),
                waba_id: String(d.waba_id || ''),
                business_id: String(d.business_id || '')
            };
            tryComplete();
        }
    }

    if (ready && appId && window.APP && APP.MetaEmbeddedSignup) {
        APP.MetaEmbeddedSignup.preload({ appId: appId, apiVersion: apiVersion }).catch(function () {});
    }

    document.querySelectorAll('[data-client-row]').forEach(function (row) {
        var key = row.getAttribute('data-client-row') || '';
        var badge = row.querySelector('.js-wa-status');
        if (!key || !badge || !statusUrl) return;
        fetch(statusUrl + '/' + encodeURIComponent(key), {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        }).then(function (res) {
            return res.json();
        }).then(function (payload) {
            var data = (payload && payload.data) || {};
            if (!data.connected) return;
            badge.textContent = data.phone_number_id ? ('Connected · ' + data.phone_number_id) : 'Connected';
            badge.className = 'platform-badge platform-badge-ok js-wa-status';
        }).catch(function () {});
    });

    document.querySelectorAll('.js-connect-wa').forEach(function (btn) {
        btn.addEventListener('click', function () {
            activeKey = btn.getAttribute('data-tenant-key') || '';
            if (!ready || !appId || !configId) {
                setResult('Save App ID, Config ID, and App Secret first.', false);
                return;
            }
            if (!httpsOk) {
                setResult('Open this platform page over HTTPS or localhost before connecting.', false);
                return;
            }
            if (!window.APP || !APP.MetaEmbeddedSignup) {
                setResult('Facebook SDK failed to load.', false);
                return;
            }
            setResult('Opening Meta for ' + activeKey + '…', true);
            APP.MetaEmbeddedSignup.launch({
                appId: appId,
                configId: configId,
                apiVersion: apiVersion,
                onSession: onSession,
                onCode: function (code) {
                    pendingCode = code;
                    tryComplete();
                },
                onCancel: function () {
                    if (!pendingSession) setResult('Meta login did not return an auth code.', false);
                }
            }).catch(function (err) {
                setResult((err && err.message) || 'Could not launch Embedded Signup', false);
            });
        });
    });
})();
</script>
<?= $this->endSection() ?>
