<?= $this->extend('layouts/platform') ?>

<?= $this->section('content') ?>
<?php
$branding         = $branding ?? [];
$siteName         = (string) ($branding['site_name'] ?? 'Sateri Platform');
$siteTagline      = (string) ($branding['site_tagline'] ?? 'Super Admin Console');
$logoUrl          = (string) ($branding['logo_url'] ?? '');
$faviconUrl       = (string) ($branding['favicon_url'] ?? '');
$poweredByEnabled = ! empty($branding['powered_by_enabled']);
$poweredByName    = (string) ($branding['powered_by_name'] ?? 'Sateri Technologies');
$poweredByUrl     = (string) ($branding['powered_by_url'] ?? 'https://sateritechnologies.com');
$poweredByLogoUrl = (string) ($branding['powered_by_logo_url'] ?? '');
?>

<div style="max-width:960px;margin:0 auto;display:flex;flex-direction:column;gap:1.75rem">

    <!-- Header Banner -->
    <div style="background:var(--pf-card);border:1px solid var(--pf-line);border-radius:14px;padding:1.5rem 1.75rem;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:1rem;box-shadow:0 1px 3px rgba(0,0,0,0.02)">
        <div style="display:flex;align-items:center;gap:1rem">
            <div style="width:48px;height:48px;border-radius:12px;background:linear-gradient(135deg,#0b6e4f,#10b981);color:#fff;display:flex;align-items:center;justify-content:center;font-size:1.35rem;box-shadow:0 4px 12px rgba(11,110,79,0.25)">
                <i class="fas fa-sliders-h"></i>
            </div>
            <div>
                <h2 style="margin:0;font-size:1.25rem;font-weight:800;color:var(--pf-ink);letter-spacing:-0.01em">Platform Settings &amp; Branding</h2>
                <p style="margin:0.25rem 0 0;color:var(--pf-muted);font-size:0.85rem">
                    Manage global site identity, logos, browser favicon, and "Powered by" white-label attribution.
                </p>
            </div>
        </div>
        <div style="display:flex;align-items:center;gap:0.5rem">
            <span style="display:inline-flex;align-items:center;gap:6px;padding:5px 12px;border-radius:20px;background:#e8f6ef;color:#0d5c40;font-size:0.75rem;font-weight:700">
                <i class="fas fa-shield-alt"></i> Super Admin Only
            </span>
        </div>
    </div>

    <!-- Main Settings Form -->
    <form method="post" action="<?= site_url('platform/settings') ?>" enctype="multipart/form-data" style="display:flex;flex-direction:column;gap:1.5rem">
        <?= csrf_field() ?>

        <!-- SECTION 1: Site Identity -->
        <section class="platform-card" style="padding:1.75rem;border-radius:14px">
            <div style="display:flex;align-items:center;gap:0.75rem;margin-bottom:1.25rem;padding-bottom:1rem;border-bottom:1px solid var(--pf-line)">
                <div style="width:34px;height:34px;border-radius:8px;background:rgba(11,110,79,0.1);color:var(--pf-teal);display:flex;align-items:center;justify-content:center;font-size:0.95rem">
                    <i class="fas fa-globe"></i>
                </div>
                <div>
                    <h3 style="margin:0;font-size:1rem;font-weight:750;color:var(--pf-ink)">General Identity</h3>
                    <p style="margin:0;font-size:0.8rem;color:var(--pf-muted)">Platform titles displayed in browser tabs and console navigation</p>
                </div>
            </div>

            <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(300px, 1fr));gap:1.25rem">
                <div>
                    <label class="platform-label" for="inputSiteName" style="font-weight:700">
                        Platform / Site Name <span class="text-danger" style="color:var(--pf-down)">*</span>
                    </label>
                    <input class="platform-input" type="text" name="site_name" id="inputSiteName" value="<?= esc($siteName) ?>" placeholder="e.g. Sateri Platform" required style="font-size:0.9rem;padding:0.65rem 0.85rem">
                    <small style="color:var(--pf-muted);font-size:0.78rem;margin-top:0.35rem;display:block">
                        Used in page <code>&lt;title&gt;</code> tags and sidebar header title.
                    </small>
                </div>
                <div>
                    <label class="platform-label" for="inputSiteTagline" style="font-weight:700">
                        Console Tagline / Subtitle
                    </label>
                    <input class="platform-input" type="text" name="site_tagline" id="inputSiteTagline" value="<?= esc($siteTagline) ?>" placeholder="e.g. Super Admin Console" style="font-size:0.9rem;padding:0.65rem 0.85rem">
                    <small style="color:var(--pf-muted);font-size:0.78rem;margin-top:0.35rem;display:block">
                        Appears beneath the site name in the dark sidebar menu.
                    </small>
                </div>
            </div>
        </section>

        <!-- SECTION 2: Visual Assets (Logo & Favicon) -->
        <section class="platform-card" style="padding:1.75rem;border-radius:14px">
            <div style="display:flex;align-items:center;gap:0.75rem;margin-bottom:1.25rem;padding-bottom:1rem;border-bottom:1px solid var(--pf-line)">
                <div style="width:34px;height:34px;border-radius:8px;background:rgba(196,92,18,0.1);color:var(--pf-coral);display:flex;align-items:center;justify-content:center;font-size:0.95rem">
                    <i class="fas fa-palette"></i>
                </div>
                <div>
                    <h3 style="margin:0;font-size:1rem;font-weight:750;color:var(--pf-ink)">Visual Branding Assets</h3>
                    <p style="margin:0;font-size:0.8rem;color:var(--pf-muted)">Upload official platform logo and browser tab favicon</p>
                </div>
            </div>

            <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(360px, 1fr));gap:1.5rem">
                <!-- Platform Logo Card -->
                <div style="border:1px solid var(--pf-line);border-radius:10px;padding:1.25rem;background:#fafbf8;display:flex;flex-direction:column;gap:1rem">
                    <div style="display:flex;align-items:center;justify-content:space-between">
                        <label class="platform-label" style="font-weight:700;margin:0">
                            <i class="fas fa-image me-1" style="color:var(--pf-teal)"></i> Platform Logo
                        </label>
                        <span style="font-size:0.72rem;background:#e2e8f0;padding:2px 8px;border-radius:6px;font-weight:600;color:#475569">Max 2MB</span>
                    </div>

                    <!-- Current Logo Viewport -->
                    <div style="background:#121c22;border:1px dashed rgba(255,255,255,0.15);border-radius:8px;padding:1.25rem;display:flex;align-items:center;justify-content:center;min-height:90px">
                        <?php if ($logoUrl !== ''): ?>
                            <img src="<?= esc($logoUrl) ?>" alt="Platform Logo" id="logoPreviewImg" style="max-height:48px;max-width:200px;object-fit:contain">
                        <?php else: ?>
                            <div id="logoDefaultPlaceholder" style="display:flex;align-items:center;gap:0.6rem;color:#f8fafc">
                                <div class="platform-rail-mark" style="width:34px;height:34px;font-size:0.95rem">S</div>
                                <div>
                                    <div style="font-weight:800;font-size:0.92rem"><?= esc($siteName) ?></div>
                                    <div style="font-size:0.65rem;color:rgba(255,255,255,0.5);text-transform:uppercase"><?= esc($siteTagline) ?></div>
                                </div>
                            </div>
                            <img src="" alt="Preview" id="logoPreviewImg" style="max-height:48px;max-width:200px;object-fit:contain;display:none">
                        <?php endif; ?>
                    </div>

                    <div>
                        <input class="platform-input" type="file" name="site_logo" id="inputSiteLogo" accept=".png,.jpg,.jpeg,.webp,.gif,image/png,image/jpeg,image/webp,image/gif" style="font-size:0.82rem;padding:0.45rem">
                        <div style="display:flex;align-items:center;justify-content:space-between;margin-top:0.4rem;font-size:0.78rem">
                            <span style="color:var(--pf-muted)">SVG, PNG or WEBP (transparent)</span>
                            <?php if ($logoUrl !== ''): ?>
                                <label style="color:var(--pf-down);display:inline-flex;align-items:center;gap:0.35rem;cursor:pointer;font-weight:600">
                                    <input type="checkbox" name="remove_logo" value="1">
                                    <i class="fas fa-trash-alt"></i> Remove
                                </label>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Favicon Card -->
                <div style="border:1px solid var(--pf-line);border-radius:10px;padding:1.25rem;background:#fafbf8;display:flex;flex-direction:column;gap:1rem">
                    <div style="display:flex;align-items:center;justify-content:space-between">
                        <label class="platform-label" style="font-weight:700;margin:0">
                            <i class="fas fa-bookmark me-1" style="color:var(--pf-coral)"></i> Browser Tab Favicon
                        </label>
                        <span style="font-size:0.72rem;background:#e2e8f0;padding:2px 8px;border-radius:6px;font-weight:600;color:#475569">Max 512KB</span>
                    </div>

                    <!-- Current Favicon Viewport -->
                    <div style="background:#0f172a;border:1px dashed rgba(255,255,255,0.15);border-radius:8px;padding:1.25rem;display:flex;align-items:center;justify-content:center;gap:1.5rem;min-height:90px">
                        <div style="display:flex;align-items:center;gap:0.75rem;background:rgba(255,255,255,0.06);padding:6px 14px;border-radius:8px">
                            <?php if ($faviconUrl !== ''): ?>
                                <img src="<?= esc($faviconUrl) ?>" alt="Favicon" id="favPreviewImg" style="width:24px;height:24px;object-fit:contain;border-radius:3px">
                            <?php else: ?>
                                <span id="favDefaultBadge" style="background:var(--pf-teal);color:#fff;width:24px;height:24px;border-radius:4px;display:inline-flex;align-items:center;justify-content:center;font-size:11px;font-weight:700">S</span>
                                <img src="" alt="Preview" id="favPreviewImg" style="width:24px;height:24px;object-fit:contain;border-radius:3px;display:none">
                            <?php endif; ?>
                            <span style="color:#f8fafc;font-size:0.85rem;font-weight:600">Browser Tab Icon</span>
                        </div>
                    </div>

                    <div>
                        <input class="platform-input" type="file" name="site_favicon" id="inputSiteFavicon" accept=".ico,.png,.jpg,.jpeg,.webp,image/png,image/jpeg,image/webp,image/gif" style="font-size:0.82rem;padding:0.45rem">
                        <div style="display:flex;align-items:center;justify-content:space-between;margin-top:0.4rem;font-size:0.78rem">
                            <span style="color:var(--pf-muted)">Square PNG, ICO or SVG (32x32)</span>
                            <?php if ($faviconUrl !== ''): ?>
                                <label style="color:var(--pf-down);display:inline-flex;align-items:center;gap:0.35rem;cursor:pointer;font-weight:600">
                                    <input type="checkbox" name="remove_favicon" value="1">
                                    <i class="fas fa-trash-alt"></i> Remove
                                </label>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- SECTION 3: "Powered By" Attribution & White-Labeling -->
        <section class="platform-card" style="padding:1.75rem;border-radius:14px">
            <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:0.75rem;margin-bottom:1.25rem;padding-bottom:1rem;border-bottom:1px solid var(--pf-line)">
                <div style="display:flex;align-items:center;gap:0.75rem">
                    <div style="width:34px;height:34px;border-radius:8px;background:rgba(56,189,248,0.12);color:#0284c7;display:flex;align-items:center;justify-content:center;font-size:0.95rem">
                        <i class="fas fa-copyright"></i>
                    </div>
                    <div>
                        <h3 style="margin:0;font-size:1rem;font-weight:750;color:var(--pf-ink)">&quot;Powered By&quot; Attribution</h3>
                        <p style="margin:0;font-size:0.8rem;color:var(--pf-muted)">Configure the developer / provider badge shown in sidebar footer and layout bottom</p>
                    </div>
                </div>

                <!-- Enable / Disable Switch -->
                <label style="display:inline-flex;align-items:center;gap:0.6rem;cursor:pointer;background:#f1f5f9;padding:6px 14px;border-radius:20px;font-size:0.82rem;font-weight:700;color:var(--pf-ink)">
                    <input type="checkbox" name="powered_by_enabled" id="togglePoweredBy" value="1" <?= $poweredByEnabled ? 'checked' : '' ?> style="accent-color:var(--pf-teal);width:16px;height:16px;cursor:pointer">
                    <span>Show &quot;Powered by&quot;</span>
                </label>
            </div>

            <!-- Powered By Configuration Fields -->
            <div id="poweredByFields" style="display:flex;flex-direction:column;gap:1.25rem;<?= $poweredByEnabled ? '' : 'opacity:0.6;' ?>">
                <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(280px, 1fr));gap:1.25rem">
                    <div>
                        <label class="platform-label" for="inputPoweredByName" style="font-weight:700">
                            Provider / Brand Name
                        </label>
                        <input class="platform-input" type="text" name="powered_by_name" id="inputPoweredByName" value="<?= esc($poweredByName) ?>" placeholder="e.g. Sateri Technologies" style="font-size:0.9rem;padding:0.65rem 0.85rem">
                        <small style="color:var(--pf-muted);font-size:0.78rem;margin-top:0.35rem;display:block">
                            Company or product name displayed after &quot;Powered by&quot;.
                        </small>
                    </div>
                    <div>
                        <label class="platform-label" for="inputPoweredByUrl" style="font-weight:700">
                            Website / Link URL
                        </label>
                        <input class="platform-input" type="url" name="powered_by_url" id="inputPoweredByUrl" value="<?= esc($poweredByUrl) ?>" placeholder="https://example.com" style="font-size:0.9rem;padding:0.65rem 0.85rem">
                        <small style="color:var(--pf-muted);font-size:0.78rem;margin-top:0.35rem;display:block">
                            Destination link when users click on the Powered By attribution.
                        </small>
                    </div>
                </div>

                <!-- Powered By Logo Upload & Live Preview -->
                <div style="border:1px solid var(--pf-line);border-radius:10px;padding:1.25rem;background:#fafbf8;display:grid;grid-template-columns:repeat(auto-fit, minmax(300px, 1fr));gap:1.25rem;align-items:center">
                    <div>
                        <label class="platform-label" style="font-weight:700">
                            <i class="fas fa-file-image me-1" style="color:#0284c7"></i> Provider Micro-Logo / Icon (Optional)
                        </label>
                        <input class="platform-input" type="file" name="powered_by_logo" id="inputPoweredByLogo" accept=".png,.jpg,.jpeg,.webp,image/png,image/jpeg,image/webp,image/gif" style="font-size:0.82rem;padding:0.45rem">
                        <div style="display:flex;align-items:center;justify-content:space-between;margin-top:0.4rem;font-size:0.78rem">
                            <span style="color:var(--pf-muted)">Small logo or icon (Max 1MB, approx 16x16 or 24x24)</span>
                            <?php if ($poweredByLogoUrl !== ''): ?>
                                <label style="color:var(--pf-down);display:inline-flex;align-items:center;gap:0.35rem;cursor:pointer;font-weight:600">
                                    <input type="checkbox" name="remove_powered_by_logo" value="1">
                                    <i class="fas fa-trash-alt"></i> Remove
                                </label>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Live Attribution Preview Card -->
                    <div style="background:#ffffff;border:1px solid #e2e8f0;border-radius:8px;padding:1rem 1.25rem;box-shadow:0 2px 6px rgba(0,0,0,0.02)">
                        <div style="font-size:0.72rem;font-weight:700;color:var(--pf-muted);text-transform:uppercase;letter-spacing:0.04em;margin-bottom:0.6rem">
                            <i class="fas fa-eye me-1"></i> Live Attribution Preview
                        </div>
                        <div style="display:flex;align-items:center;gap:8px;font-size:0.86rem;color:#475569;background:#f8fafc;padding:8px 12px;border-radius:6px;border:1px solid #e2e8f0">
                            <span>Powered by</span>
                            <a href="<?= esc($poweredByUrl) ?>" id="previewPoweredByLink" target="_blank" rel="noopener noreferrer" style="color:var(--pf-teal);font-weight:700;display:inline-flex;align-items:center;gap:5px;text-decoration:none">
                                <?php if ($poweredByLogoUrl !== ''): ?>
                                    <img src="<?= esc($poweredByLogoUrl) ?>" alt="Logo" id="previewPoweredByLogo" style="height:15px;width:auto;vertical-align:middle;border-radius:2px">
                                <?php else: ?>
                                    <img src="" alt="Logo" id="previewPoweredByLogo" style="height:15px;width:auto;vertical-align:middle;border-radius:2px;display:none">
                                <?php endif; ?>
                                <span id="previewPoweredByName"><?= esc($poweredByName) ?></span>
                                <i class="fas fa-arrow-up-right-from-square" style="font-size:9px"></i>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Sticky Action Bar -->
        <div style="background:var(--pf-card);border:1px solid var(--pf-line);border-radius:12px;padding:1rem 1.5rem;display:flex;align-items:center;justify-content:space-between;box-shadow:0 4px 12px rgba(0,0,0,0.03)">
            <span style="font-size:0.82rem;color:var(--pf-muted)">
                <i class="fas fa-info-circle me-1"></i> Changes take effect immediately across all console screens.
            </span>
            <div style="display:flex;align-items:center;gap:0.75rem">
                <a href="<?= site_url('platform/clients') ?>" class="btn-pf" style="padding:0.65rem 1.25rem">
                    Cancel
                </a>
                <button type="submit" class="btn-pf btn-pf-primary" style="padding:0.65rem 1.75rem;font-size:0.88rem;box-shadow:0 2px 6px rgba(11,110,79,0.25)">
                    <i class="fas fa-save me-1"></i> Save Platform Settings
                </button>
            </div>
        </div>
    </form>
</div>

<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
// Dynamic update of Live Powered By preview
var pNameInput = document.getElementById('inputPoweredByName');
var pUrlInput = document.getElementById('inputPoweredByUrl');
var pPreviewName = document.getElementById('previewPoweredByName');
var pPreviewLink = document.getElementById('previewPoweredByLink');
var pLogoInput = document.getElementById('inputPoweredByLogo');
var pPreviewLogo = document.getElementById('previewPoweredByLogo');

if (pNameInput && pPreviewName) {
    pNameInput.addEventListener('input', function() {
        pPreviewName.textContent = this.value.trim() || 'Sateri Technologies';
    });
}

if (pUrlInput && pPreviewLink) {
    pUrlInput.addEventListener('input', function() {
        pPreviewLink.href = this.value.trim() || '#';
    });
}

if (pLogoInput && pPreviewLogo) {
    pLogoInput.addEventListener('change', function(e) {
        if (e.target.files && e.target.files[0]) {
            var reader = new FileReader();
            reader.onload = function(ev) {
                pPreviewLogo.src = ev.target.result;
                pPreviewLogo.style.display = 'inline-block';
            };
            reader.readAsDataURL(e.target.files[0]);
        }
    });
}

// Logo preview
var logoInput = document.getElementById('inputSiteLogo');
var logoPreview = document.getElementById('logoPreviewImg');
var logoPlaceholder = document.getElementById('logoDefaultPlaceholder');

if (logoInput && logoPreview) {
    logoInput.addEventListener('change', function(e) {
        if (e.target.files && e.target.files[0]) {
            var reader = new FileReader();
            reader.onload = function(ev) {
                logoPreview.src = ev.target.result;
                logoPreview.style.display = 'block';
                if (logoPlaceholder) logoPlaceholder.style.display = 'none';
            };
            reader.readAsDataURL(e.target.files[0]);
        }
    });
}

// Favicon preview
var favInput = document.getElementById('inputSiteFavicon');
var favPreview = document.getElementById('favPreviewImg');
var favBadge = document.getElementById('favDefaultBadge');

if (favInput && favPreview) {
    favInput.addEventListener('change', function(e) {
        if (e.target.files && e.target.files[0]) {
            var reader = new FileReader();
            reader.onload = function(ev) {
                favPreview.src = ev.target.result;
                favPreview.style.display = 'inline-block';
                if (favBadge) favBadge.style.display = 'none';
            };
            reader.readAsDataURL(e.target.files[0]);
        }
    });
}

// Toggle Powered By
var togglePB = document.getElementById('togglePoweredBy');
var pbFields = document.getElementById('poweredByFields');
if (togglePB && pbFields) {
    togglePB.addEventListener('change', function() {
        if (this.checked) {
            pbFields.style.opacity = '1';
            pbFields.style.pointerEvents = 'auto';
        } else {
            pbFields.style.opacity = '0.45';
        }
    });
}
</script>
<?= $this->endSection() ?>
