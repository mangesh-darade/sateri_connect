<?= $this->extend('layouts/platform') ?>

<?= $this->section('content') ?>
<?php
$branding = $branding ?? [];
$siteName = (string) ($branding['site_name'] ?? 'Sateri Platform');
$siteTagline = (string) ($branding['site_tagline'] ?? 'Super Admin Console');
$logoUrl = (string) ($branding['logo_url'] ?? '');
$faviconUrl = (string) ($branding['favicon_url'] ?? '');
?>

<div style="max-width:880px;display:flex;flex-direction:column;gap:1.5rem">

    <div class="platform-section-label" style="margin-bottom:0">
        <h2 style="font-size:1.35rem;font-weight:700">Platform Branding &amp; Appearance</h2>
        <p>Customize the Super Admin console's site name, sidebar logo/icon, and browser tab favicon.</p>
    </div>

    <!-- Live Browser Tab Preview (Hidden/Commented as requested) -->
    <!--
    <section class="platform-card" style="padding:1.25rem 1.5rem">
        <h3 style="font-size:0.95rem;font-weight:700;margin:0 0 0.75rem;text-transform:uppercase;letter-spacing:0.04em;color:var(--pf-muted)">
            <i class="fas fa-eye me-1"></i> Live Browser Tab Preview
        </h3>
        <div style="background:#1e293b;border-radius:10px 10px 0 0;padding:10px 14px 0;display:inline-flex;align-items:center;min-width:280px;max-width:340px;box-shadow:0 4px 12px rgba(0,0,0,0.15)">
            <div style="background:#0f172a;color:#f8fafc;padding:7px 14px;border-radius:8px 8px 0 0;display:flex;align-items:center;gap:8px;font-size:0.84rem;width:100%">
                <?php if ($faviconUrl !== ''): ?>
                    <img src="<?= esc($faviconUrl) ?>" alt="Favicon" style="width:16px;height:16px;object-fit:contain;border-radius:2px" id="previewTabFavicon">
                <?php else: ?>
                    <span style="background:var(--pf-teal);color:#fff;width:16px;height:16px;border-radius:3px;display:inline-flex;align-items:center;justify-content:center;font-size:9px;font-weight:700" id="previewTabFavicon">S</span>
                <?php endif; ?>
                <span style="font-weight:500;white-space:nowrap;overflow:hidden;text-overflow:ellipsis" id="previewTabTitle"><?= esc($siteName) ?></span>
                <span style="margin-left:auto;color:#64748b;font-size:11px">&times;</span>
            </div>
        </div>
        <div style="background:#0f172a;height:10px;border-radius:0 6px 6px 6px"></div>
    </section>
    -->

    <!-- Main Branding Form -->
    <form method="post" action="<?= site_url('platform/settings') ?>" enctype="multipart/form-data" class="platform-card" style="padding:1.75rem;display:flex;flex-direction:column;gap:1.5rem">
        <?= csrf_field() ?>

        <!-- Site Identity -->
        <div>
            <h3 style="font-size:1.05rem;font-weight:700;margin:0 0 1rem;color:var(--pf-ink)">
                <i class="fas fa-globe me-1"></i> Site Identity
            </h3>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.25rem">
                <div>
                    <label class="platform-label">Platform / Site Name <span class="text-danger">*</span></label>
                    <input class="platform-input" type="text" name="site_name" id="inputSiteName" value="<?= esc($siteName) ?>" placeholder="e.g. Sateri Platform" required>
                    <small style="color:var(--pf-muted);font-size:0.8rem">Displays in the browser tab title and sidebar brand.</small>
                </div>
                <div>
                    <label class="platform-label">Site Tagline</label>
                    <input class="platform-input" type="text" name="site_tagline" value="<?= esc($siteTagline) ?>" placeholder="e.g. Super Admin Console">
                    <small style="color:var(--pf-muted);font-size:0.8rem">Subtext displayed under the sidebar brand title.</small>
                </div>
            </div>
        </div>

        <hr style="border:0;border-top:1px solid var(--pf-line);margin:0">

        <!-- Favicon Upload -->
        <div>
            <h3 style="font-size:1.05rem;font-weight:700;margin:0 0 0.5rem;color:var(--pf-ink)">
                <i class="fas fa-star me-1"></i> Browser Favicon
            </h3>
            <p style="color:var(--pf-muted);font-size:0.85rem;margin-bottom:1rem">
                The small icon displayed in the browser tab, bookmark bar, and shortcuts (ICO, PNG, or SVG). Recommended: 32x32 or 64x64 px.
            </p>

            <div style="display:flex;align-items:center;gap:1.5rem;background:var(--pf-paper-2,#fafbf8);border:1px solid var(--pf-line);border-radius:10px;padding:1.25rem">
                <!-- Current Favicon Preview -->
                <div style="width:56px;height:56px;border-radius:10px;background:#ffffff;border:1px solid var(--pf-line);display:flex;align-items:center;justify-content:center;box-shadow:0 2px 6px rgba(0,0,0,0.05);flex-shrink:0">
                    <?php if ($faviconUrl !== ''): ?>
                        <img src="<?= esc($faviconUrl) ?>" alt="Current Favicon" style="max-width:32px;max-height:32px;object-fit:contain" id="currentFaviconImg">
                    <?php else: ?>
                        <span style="font-size:1.5rem;color:var(--pf-muted);font-weight:700">S</span>
                    <?php endif; ?>
                </div>

                <!-- Upload Input -->
                <div style="flex:1">
                    <input class="platform-input" type="file" name="site_favicon" id="inputSiteFavicon" accept=".ico,.png,.jpg,.jpeg,.webp,.gif,.svg,image/*" style="padding:0.4rem 0.6rem">
                    <div style="display:flex;align-items:center;gap:1rem;margin-top:0.4rem">
                        <small style="color:var(--pf-muted);font-size:0.8rem">Max 512 KB (PNG, ICO, WEBP, SVG)</small>
                        <?php if ($faviconUrl !== ''): ?>
                            <label style="font-size:0.8rem;color:var(--pf-down);display:inline-flex;align-items:center;gap:0.35rem;cursor:pointer">
                                <input type="checkbox" name="remove_favicon" value="1">
                                Remove custom favicon
                            </label>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <hr style="border:0;border-top:1px solid var(--pf-line);margin:0">

        <!-- Logo / App Icon Upload -->
        <div>
            <h3 style="font-size:1.05rem;font-weight:700;margin:0 0 0.5rem;color:var(--pf-ink)">
                <i class="fas fa-image me-1"></i> Platform App Icon / Logo
            </h3>
            <p style="color:var(--pf-muted);font-size:0.85rem;margin-bottom:1rem">
                The primary logo displayed at the top of the sidebar navigation. Recommended: transparent PNG or SVG, max height 40px.
            </p>

            <div style="display:flex;align-items:center;gap:1.5rem;background:var(--pf-paper-2,#fafbf8);border:1px solid var(--pf-line);border-radius:10px;padding:1.25rem">
                <!-- Current Logo Preview -->
                <div style="width:120px;height:56px;border-radius:8px;background:#121c22;border:1px solid var(--pf-line);display:flex;align-items:center;justify-content:center;padding:6px;flex-shrink:0">
                    <?php if ($logoUrl !== ''): ?>
                        <img src="<?= esc($logoUrl) ?>" alt="Current Logo" style="max-height:36px;max-width:100%;object-fit:contain" id="currentLogoImg">
                    <?php else: ?>
                        <div style="display:flex;align-items:center;gap:6px">
                            <span class="platform-rail-mark" style="width:26px;height:26px;font-size:12px">S</span>
                            <span style="color:#e8eef0;font-size:0.85rem;font-weight:700">Sateri</span>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Upload Input -->
                <div style="flex:1">
                    <input class="platform-input" type="file" name="site_logo" id="inputSiteLogo" accept=".png,.jpg,.jpeg,.webp,.gif,.svg,image/*" style="padding:0.4rem 0.6rem">
                    <div style="display:flex;align-items:center;gap:1rem;margin-top:0.4rem">
                        <small style="color:var(--pf-muted);font-size:0.8rem">Max 2 MB (PNG, WEBP, SVG, JPG)</small>
                        <?php if ($logoUrl !== ''): ?>
                            <label style="font-size:0.8rem;color:var(--pf-down);display:inline-flex;align-items:center;gap:0.35rem;cursor:pointer">
                                <input type="checkbox" name="remove_logo" value="1">
                                Remove custom logo
                            </label>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="platform-actions" style="margin-top:0.5rem">
            <button type="submit" class="btn-pf btn-pf-primary" style="padding:0.65rem 1.5rem">
                <i class="fas fa-save me-1"></i> Save Platform Branding
            </button>
            <a href="<?= site_url('platform/clients') ?>" class="btn-pf">Cancel</a>
        </div>
    </form>
</div>

<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
var siteNameInput = document.getElementById('inputSiteName');
if (siteNameInput) {
    siteNameInput.addEventListener('input', function() {
        var tabTitle = document.getElementById('previewTabTitle');
        if (tabTitle) {
            tabTitle.textContent = this.value.trim() || 'Sateri Platform';
        }
    });
}

var faviconInput = document.getElementById('inputSiteFavicon');
if (faviconInput) {
    faviconInput.addEventListener('change', function(e) {
        if (e.target.files && e.target.files[0]) {
            var reader = new FileReader();
            reader.onload = function(ev) {
                var tabFav = document.getElementById('previewTabFavicon');
                if (tabFav) {
                    if (tabFav.tagName.toLowerCase() === 'img') {
                        tabFav.src = ev.target.result;
                    } else {
                        var newImg = document.createElement('img');
                        newImg.src = ev.target.result;
                        newImg.style.width = '16px';
                        newImg.style.height = '16px';
                        newImg.style.objectFit = 'contain';
                        newImg.id = 'previewTabFavicon';
                        tabFav.parentNode.replaceChild(newImg, tabFav);
                    }
                }
            };
            reader.readAsDataURL(e.target.files[0]);
        }
    });
}
</script>
<?= $this->endSection() ?>
