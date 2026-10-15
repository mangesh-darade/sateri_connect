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
$schemaClients    = $schemaClients ?? [];
$schemaTableCount = (int) ($schemaTableCount ?? 0);
$schemaSnapshotAt = $schemaSnapshotAt ?? null;
$brandInitial     = mb_strtoupper(mb_substr($siteName !== '' ? $siteName : 'S', 0, 1));
?>

<nav class="platform-tabs" role="tablist" aria-label="Platform settings">
    <button type="button" class="platform-tab is-active" role="tab" data-tab="identity" aria-selected="true">
        <i class="fas fa-globe" aria-hidden="true"></i> Identity
    </button>
    <button type="button" class="platform-tab" role="tab" data-tab="branding" aria-selected="false">
        <i class="fas fa-image" aria-hidden="true"></i> Branding
    </button>
    <button type="button" class="platform-tab" role="tab" data-tab="powered" aria-selected="false">
        <i class="fas fa-copyright" aria-hidden="true"></i> Powered by
    </button>
    <button type="button" class="platform-tab" role="tab" data-tab="database-health" aria-selected="false">
        <i class="fas fa-database" aria-hidden="true"></i> Database
    </button>
</nav>

<form method="post" action="<?= site_url('platform/settings') ?>" enctype="multipart/form-data" id="platformSettingsForm">
    <?= csrf_field() ?>

    <section class="platform-card platform-tab-panel" data-panel="identity">
        <h3 class="platform-section-title">Site identity</h3>
        <p class="platform-settings-lead">Name and subtitle shown in the browser tab and the sidebar.</p>
        <div class="platform-form-grid">
            <div>
                <label class="platform-label" for="inputSiteName">Platform name <span class="req">*</span></label>
                <input class="platform-input" type="text" name="site_name" id="inputSiteName" value="<?= esc($siteName) ?>" placeholder="Sateri Platform" required>
            </div>
            <div>
                <label class="platform-label" for="inputSiteTagline">Console tagline</label>
                <input class="platform-input" type="text" name="site_tagline" id="inputSiteTagline" value="<?= esc($siteTagline) ?>" placeholder="Super Admin Console">
                <div class="platform-help">Shown under the name in the dark sidebar.</div>
            </div>
        </div>
    </section>

    <section class="platform-card platform-tab-panel" data-panel="branding" hidden>
        <h3 class="platform-section-title">Logo and favicon</h3>
        <p class="platform-settings-lead">Logo replaces the letter mark in the sidebar. Favicon is the browser-tab icon.</p>
        <div class="platform-settings-assets">
            <div class="platform-asset">
                <div class="platform-asset-head">
                    <label class="platform-label" for="inputSiteLogo">Platform logo</label>
                    <span class="platform-asset-limit">Max 2MB</span>
                </div>
                <div class="platform-asset-preview is-dark">
                    <div id="logoDefaultPlaceholder" class="platform-asset-fallback"<?= $logoUrl !== '' ? ' hidden' : '' ?>>
                        <div class="platform-rail-mark"><?= esc($brandInitial) ?></div>
                        <div>
                            <strong><?= esc($siteName) ?></strong>
                            <span><?= esc($siteTagline) ?></span>
                        </div>
                    </div>
                    <img src="<?= esc($logoUrl) ?>" alt="Platform logo" id="logoPreviewImg"<?= $logoUrl === '' ? ' hidden' : '' ?>>
                </div>
                <input class="platform-input" type="file" name="site_logo" id="inputSiteLogo" accept=".png,.jpg,.jpeg,.webp,.gif,image/png,image/jpeg,image/webp,image/gif">
                <div class="platform-asset-foot">
                    <span class="platform-help">PNG, JPG, or WEBP</span>
                    <?php if ($logoUrl !== ''): ?>
                        <label class="platform-remove"><input type="checkbox" name="remove_logo" value="1"> Remove</label>
                    <?php endif; ?>
                </div>
            </div>

            <div class="platform-asset">
                <div class="platform-asset-head">
                    <label class="platform-label" for="inputSiteFavicon">Browser favicon</label>
                    <span class="platform-asset-limit">Max 512KB</span>
                </div>
                <div class="platform-asset-preview is-dark">
                    <div class="platform-asset-tab">
                        <span id="favDefaultBadge" class="platform-asset-badge"<?= $faviconUrl !== '' ? ' hidden' : '' ?>><?= esc($brandInitial) ?></span>
                        <img src="<?= esc($faviconUrl) ?>" alt="Favicon" id="favPreviewImg"<?= $faviconUrl === '' ? ' hidden' : '' ?>>
                        <span>Browser tab</span>
                    </div>
                </div>
                <input class="platform-input" type="file" name="site_favicon" id="inputSiteFavicon" accept=".ico,.png,.jpg,.jpeg,.webp,image/png,image/jpeg,image/webp,image/x-icon">
                <div class="platform-asset-foot">
                    <span class="platform-help">Square PNG or ICO, about 32×32</span>
                    <?php if ($faviconUrl !== ''): ?>
                        <label class="platform-remove"><input type="checkbox" name="remove_favicon" value="1"> Remove</label>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </section>

    <section class="platform-card platform-tab-panel" data-panel="powered" hidden>
        <div class="platform-settings-head">
            <div>
                <h3 class="platform-section-title">Powered by</h3>
                <p class="platform-settings-lead">Footer credit in the sidebar and at the bottom of every console page.</p>
            </div>
            <label class="platform-switch">
                <input type="checkbox" name="powered_by_enabled" id="togglePoweredBy" value="1" <?= $poweredByEnabled ? 'checked' : '' ?>>
                <span>Show credit</span>
            </label>
        </div>
        <div id="poweredByFields" class="platform-settings-powered<?= $poweredByEnabled ? '' : ' is-off' ?>">
            <div class="platform-form-grid">
                <div>
                    <label class="platform-label" for="inputPoweredByName">Provider name</label>
                    <input class="platform-input" type="text" name="powered_by_name" id="inputPoweredByName" value="<?= esc($poweredByName) ?>" placeholder="Sateri Technologies">
                </div>
                <div>
                    <label class="platform-label" for="inputPoweredByUrl">Website</label>
                    <input class="platform-input" type="url" name="powered_by_url" id="inputPoweredByUrl" value="<?= esc($poweredByUrl) ?>" placeholder="https://example.com">
                </div>
            </div>
            <div class="platform-settings-assets" style="margin-top:0.85rem">
                <div class="platform-asset">
                    <div class="platform-asset-head">
                        <label class="platform-label" for="inputPoweredByLogo">Provider icon</label>
                        <span class="platform-asset-limit">Max 1MB</span>
                    </div>
                    <input class="platform-input" type="file" name="powered_by_logo" id="inputPoweredByLogo" accept=".png,.jpg,.jpeg,.webp,image/png,image/jpeg,image/webp,image/gif">
                    <div class="platform-asset-foot">
                        <span class="platform-help">Small mark, about 16–24px tall</span>
                        <?php if ($poweredByLogoUrl !== ''): ?>
                            <label class="platform-remove"><input type="checkbox" name="remove_powered_by_logo" value="1"> Remove</label>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="platform-asset">
                    <div class="platform-asset-head">
                        <span class="platform-label">Preview</span>
                    </div>
                    <div class="platform-powered-preview">
                        <span>Powered by</span>
                        <a href="<?= esc($poweredByUrl) ?>" id="previewPoweredByLink" target="_blank" rel="noopener noreferrer">
                            <?php if ($poweredByLogoUrl !== ''): ?>
                                <img src="<?= esc($poweredByLogoUrl) ?>" alt="" id="previewPoweredByLogo">
                            <?php else: ?>
                                <img src="" alt="" id="previewPoweredByLogo" hidden>
                            <?php endif; ?>
                            <span id="previewPoweredByName"><?= esc($poweredByName) ?></span>
                            <i class="fas fa-arrow-up-right-from-square" aria-hidden="true"></i>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <div class="platform-settings-save" data-settings-save>
        <span id="settingsSaveStatus">Save keeps you on this page.</span>
        <button type="submit" class="btn-pf btn-pf-primary" id="settingsSaveBtn"><i class="fas fa-save"></i> Save settings</button>
    </div>
</form>

<section class="platform-card platform-tab-panel" id="database-health" data-panel="database-health" hidden>
    <div class="platform-card-head">
        <div>
            <h3 class="platform-section-title">Database health</h3>
            <p class="platform-settings-lead">
                <?= $schemaTableCount ?> schema tables<?= $schemaSnapshotAt ? ' · snapshot ' . esc($schemaSnapshotAt) : '' ?>.
                Repair only adds missing tables, columns, and indexes.
            </p>
        </div>
        <div class="platform-settings-actions">
            <button type="button" class="btn-pf" id="schemaCheckAll"><i class="fas fa-stethoscope"></i> Check all</button>
            <button type="button" class="btn-pf btn-pf-primary" id="schemaRepairAll"><i class="fas fa-wrench"></i> Repair all</button>
        </div>
    </div>
    <div class="platform-settings-summary" id="schemaSummary"></div>
    <?php if ($schemaTableCount === 0): ?>
        <div class="platform-empty">
            <strong>Schema snapshot missing</strong>
            <p>Deploy <code>app/Database/Schema/tables.php</code> from <code>php spark schema:snapshot</code>.</p>
        </div>
    <?php elseif ($schemaClients === []): ?>
        <div class="platform-empty">
            <strong>No active clients</strong>
            <p>Create a client first.</p>
        </div>
    <?php else: ?>
        <div class="platform-table-wrap">
            <table class="platform-table">
                <thead>
                <tr>
                    <th>Client</th>
                    <th>Status</th>
                    <th>Details</th>
                    <th>Last checked</th>
                    <th class="is-actions">Actions</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($schemaClients as $c): ?>
                    <?php $key = (string) ($c['key'] ?? ''); ?>
                    <tr data-schema-row="<?= esc($key, 'attr') ?>">
                        <td>
                            <div class="platform-client-name"><?= esc((string) ($c['name'] ?? $key)) ?></div>
                            <div class="platform-client-meta"><?= esc($key) ?> · <?= esc((string) ($c['db_database'] ?? '')) ?></div>
                        </td>
                        <td class="js-schema-status"><span class="platform-badge">Not checked</span></td>
                        <td class="js-schema-detail">—</td>
                        <td class="js-schema-time">—</td>
                        <td class="is-actions">
                            <button type="button" class="btn-pf js-schema-action" data-action="check" data-key="<?= esc($key, 'attr') ?>"><i class="fas fa-stethoscope"></i> Check</button>
                            <button type="button" class="btn-pf btn-pf-primary js-schema-action" data-action="repair" data-key="<?= esc($key, 'attr') ?>"><i class="fas fa-wrench"></i> Repair</button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
(function () {
    var tabs = document.querySelectorAll('.platform-tab[data-tab]');
    var panels = document.querySelectorAll('.platform-tab-panel[data-panel]');
    var saveBar = document.querySelector('[data-settings-save]');
    var schemaStarted = false;

    function show(name, push) {
        var found = false;
        tabs.forEach(function (t) {
            var on = t.getAttribute('data-tab') === name;
            if (on) found = true;
            t.classList.toggle('is-active', on);
            t.setAttribute('aria-selected', on ? 'true' : 'false');
        });
        if (!found) return show('identity', push);
        panels.forEach(function (p) {
            p.hidden = p.getAttribute('data-panel') !== name;
        });
        if (saveBar) saveBar.hidden = name === 'database-health';
        if (push) history.replaceState(null, '', name === 'identity' ? location.pathname + location.search : '#' + name);
        if (name === 'database-health') startSchemaCheck();
    }

    tabs.forEach(function (t) {
        t.addEventListener('click', function () { show(t.getAttribute('data-tab'), true); });
    });

    function cacheBust(url) {
        if (!url) return '';
        return url + (url.indexOf('?') === -1 ? '?' : '&') + 'v=' + Date.now();
    }

    function applyBranding(b) {
        var name = b.site_name || 'Sateri Platform';
        var tag = b.site_tagline || '';
        var initial = (name.charAt(0) || 'S').toUpperCase();
        document.title = 'Platform Settings | ' + name;

        var footerName = document.getElementById('platformFooterName');
        if (footerName) footerName.textContent = name;
        var brandName = document.getElementById('platformBrandName');
        var brandTag = document.getElementById('platformBrandTag');
        var brandMark = document.getElementById('platformBrandMark');
        var brandText = document.getElementById('platformBrandText');
        var brandLogo = document.getElementById('platformBrandLogo');
        if (brandName) brandName.textContent = name;
        if (brandTag) brandTag.textContent = tag;
        if (brandMark) brandMark.textContent = initial;
        if (brandLogo) {
            var hasLogo = !!b.logo_url;
            brandLogo.hidden = !hasLogo;
            brandLogo.alt = name;
            if (hasLogo) brandLogo.src = cacheBust(b.logo_url);
            if (brandMark) brandMark.hidden = hasLogo;
            if (brandText) brandText.hidden = hasLogo;
        }

        var logoImg = document.getElementById('logoPreviewImg');
        var logoPh = document.getElementById('logoDefaultPlaceholder');
        if (logoImg) {
            logoImg.hidden = !b.logo_url;
            if (b.logo_url) logoImg.src = cacheBust(b.logo_url);
            if (logoPh) {
                logoPh.hidden = !!b.logo_url;
                var strong = logoPh.querySelector('strong');
                var sub = logoPh.querySelector('span');
                if (strong) strong.textContent = name;
                if (sub) sub.textContent = tag;
                var mark = logoPh.querySelector('.platform-rail-mark');
                if (mark) mark.textContent = initial;
            }
        }
        var favImg = document.getElementById('favPreviewImg');
        var favBadge = document.getElementById('favDefaultBadge');
        if (favImg) {
            favImg.hidden = !b.favicon_url;
            if (b.favicon_url) favImg.src = cacheBust(b.favicon_url);
        }
        if (favBadge) {
            favBadge.hidden = !!b.favicon_url;
            favBadge.textContent = initial;
        }
        if (b.favicon_url) {
            document.querySelectorAll('link[rel="icon"], link[rel="shortcut icon"], link[rel="apple-touch-icon"]').forEach(function (link) {
                link.href = cacheBust(b.favicon_url);
            });
        }

        var sideCredit = document.getElementById('platformSidePowered');
        var pageCredit = document.getElementById('platformPoweredBy');
        if (sideCredit) sideCredit.hidden = !b.powered_by_enabled;
        if (pageCredit) pageCredit.hidden = !b.powered_by_enabled;
        ['platformSidePoweredLink', 'platformPoweredByLink', 'previewPoweredByLink'].forEach(function (id) {
            var link = document.getElementById(id);
            if (link) link.href = b.powered_by_url || '#';
        });
        ['platformSidePoweredName', 'platformPoweredByName', 'previewPoweredByName'].forEach(function (id) {
            var node = document.getElementById(id);
            if (node) node.textContent = b.powered_by_name || '';
        });
        ['platformSidePoweredLogo', 'platformPoweredByLogo', 'previewPoweredByLogo'].forEach(function (id) {
            var img = document.getElementById(id);
            if (!img) return;
            img.hidden = !b.powered_by_logo_url;
            if (b.powered_by_logo_url) img.src = cacheBust(b.powered_by_logo_url);
        });

        ['inputSiteLogo', 'inputSiteFavicon', 'inputPoweredByLogo'].forEach(function (id) {
            var input = document.getElementById(id);
            if (input) input.value = '';
        });
        document.querySelectorAll('[name="remove_logo"], [name="remove_favicon"], [name="remove_powered_by_logo"]').forEach(function (box) {
            box.checked = false;
        });
    }

    var settingsForm = document.getElementById('platformSettingsForm');
    var saveBtn = document.getElementById('settingsSaveBtn');
    var saveStatus = document.getElementById('settingsSaveStatus');
    if (settingsForm) {
        settingsForm.addEventListener('submit', function (e) {
            e.preventDefault();
            if (saveBtn) saveBtn.disabled = true;
            if (saveStatus) {
                saveStatus.className = '';
                saveStatus.textContent = 'Saving…';
            }
            fetch(settingsForm.action, {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
                body: new FormData(settingsForm)
            }).then(function (res) {
                return res.json().catch(function () {
                    return { success: false, message: 'Could not save settings.' };
                });
            }).then(function (json) {
                if (saveStatus) {
                    saveStatus.textContent = (json && json.message) || (json && json.success ? 'Settings saved.' : 'Could not save settings.');
                    saveStatus.className = json && json.success ? 'is-ok' : 'is-err';
                }
                if (json && json.success && json.data && json.data.branding) applyBranding(json.data.branding);
            }).catch(function () {
                if (saveStatus) {
                    saveStatus.className = 'is-err';
                    saveStatus.textContent = 'Network error — please retry.';
                }
            }).then(function () {
                if (saveBtn) saveBtn.disabled = false;
            });
        });
    }

    var pNameInput = document.getElementById('inputPoweredByName');
    var pUrlInput = document.getElementById('inputPoweredByUrl');
    var pPreviewName = document.getElementById('previewPoweredByName');
    var pPreviewLink = document.getElementById('previewPoweredByLink');
    var pLogoInput = document.getElementById('inputPoweredByLogo');
    var pPreviewLogo = document.getElementById('previewPoweredByLogo');
    if (pNameInput && pPreviewName) {
        pNameInput.addEventListener('input', function () {
            pPreviewName.textContent = this.value.trim() || 'Sateri Technologies';
        });
    }
    if (pUrlInput && pPreviewLink) {
        pUrlInput.addEventListener('input', function () {
            pPreviewLink.href = this.value.trim() || '#';
        });
    }
    if (pLogoInput && pPreviewLogo) {
        pLogoInput.addEventListener('change', function (e) {
            if (!e.target.files || !e.target.files[0]) return;
            var reader = new FileReader();
            reader.onload = function (ev) {
                pPreviewLogo.src = ev.target.result;
                pPreviewLogo.hidden = false;
            };
            reader.readAsDataURL(e.target.files[0]);
        });
    }

    function previewFile(inputId, imgId, hideId) {
        var input = document.getElementById(inputId);
        var img = document.getElementById(imgId);
        var hide = hideId ? document.getElementById(hideId) : null;
        if (!input || !img) return;
        input.addEventListener('change', function (e) {
            if (!e.target.files || !e.target.files[0]) return;
            var reader = new FileReader();
            reader.onload = function (ev) {
                img.src = ev.target.result;
                img.hidden = false;
                img.style.display = '';
                if (hide) hide.hidden = true;
            };
            reader.readAsDataURL(e.target.files[0]);
        });
    }
    previewFile('inputSiteLogo', 'logoPreviewImg', 'logoDefaultPlaceholder');
    previewFile('inputSiteFavicon', 'favPreviewImg', 'favDefaultBadge');

    var togglePB = document.getElementById('togglePoweredBy');
    var pbFields = document.getElementById('poweredByFields');
    if (togglePB && pbFields) {
        togglePB.addEventListener('change', function () {
            pbFields.classList.toggle('is-off', !this.checked);
        });
    }

    var section = document.getElementById('database-health');
    var csrfName = <?= json_encode(csrf_token()) ?>;
    var csrfHash = <?= json_encode(csrf_hash()) ?>;
    var baseUrl = <?= json_encode(rtrim(site_url('platform/schema'), '/')) ?>;
    var results = {};
    var STATES = {
        ok: ['Up to date', 'platform-badge-ok'],
        needs_repair: ['Needs repair', 'platform-badge-warn'],
        attention: ['Attention', 'platform-badge-down'],
        error: ['Connect error', 'platform-badge-down'],
        working: ['Working…', '']
    };

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function row(key) {
        return section.querySelector('[data-schema-row="' + (window.CSS && CSS.escape ? CSS.escape(key) : key) + '"]');
    }
    function badge(state) {
        var s = STATES[state] || STATES.error;
        return '<span class="platform-badge ' + s[1] + '">' + (state === 'working' ? '<i class="fas fa-spinner fa-spin"></i> ' : '') + s[0] + '</span>';
    }
    function detailHtml(d, message) {
        var html = '<div style="color:var(--pf-ink)">' + esc(message || d.message || '') + '</div>';
        [['Repaired now', d.changes || {}], ['Still missing', d.issues || {}]].forEach(function (pair) {
            var tables = Object.keys(pair[1]);
            if (!tables.length) return;
            html += '<details style="margin-top:0.35rem"><summary style="cursor:pointer">' + pair[0] + ' (' + tables.length + ')</summary><ul style="margin:0.3rem 0 0;padding-left:1rem">';
            tables.forEach(function (t) {
                html += '<li><code>' + esc(t) + '</code>: ' + esc((pair[1][t] || []).join(', ')) + '</li>';
            });
            html += '</ul></details>';
        });
        return html;
    }
    function render(key, state, d, message) {
        var r = row(key);
        if (!r) return;
        r.querySelector('.js-schema-status').innerHTML = badge(state);
        if (d) {
            r.querySelector('.js-schema-detail').innerHTML = detailHtml(d, message);
            r.querySelector('.js-schema-time').textContent = d.checked_at || '';
        } else if (message) {
            r.querySelector('.js-schema-detail').innerHTML = '<span style="color:var(--pf-down)">' + esc(message) + '</span>';
        }
        r.querySelectorAll('.js-schema-action').forEach(function (b) { b.disabled = state === 'working'; });
    }
    function summary() {
        var box = document.getElementById('schemaSummary');
        if (!box) return;
        var counts = {};
        Object.keys(results).forEach(function (k) { counts[results[k]] = (counts[results[k]] || 0) + 1; });
        box.innerHTML = Object.keys(counts).map(function (s) {
            return badge(s).replace('</span>', ' · ' + counts[s] + '</span>');
        }).join('');
    }
    function run(key, action) {
        render(key, 'working');
        var body = new FormData();
        body.append(csrfName, csrfHash);
        return fetch(baseUrl + '/' + encodeURIComponent(key) + '/' + action, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: body
        }).then(function (res) {
            return res.json().catch(function () { return { success: false, message: 'Unexpected response (HTTP ' + res.status + ').' }; });
        }).then(function (json) {
            var d = json.data || null;
            results[key] = d ? d.state : 'error';
            render(key, results[key], d, json.message);
        }).catch(function () {
            results[key] = 'error';
            render(key, 'error', null, 'Network error — please retry.');
        }).then(summary);
    }
    function runAll(action) {
        var keys = Array.prototype.map.call(section.querySelectorAll('[data-schema-row]'), function (r) {
            return r.getAttribute('data-schema-row');
        });
        return keys.reduce(function (p, key) { return p.then(function () { return run(key, action); }); }, Promise.resolve());
    }
    function startSchemaCheck() {
        if (schemaStarted || !section || !section.querySelector('[data-schema-row]')) return;
        schemaStarted = true;
        runAll('check');
    }

    if (section) {
        section.addEventListener('click', function (e) {
            var btn = e.target.closest('.js-schema-action');
            if (!btn) return;
            var action = btn.getAttribute('data-action');
            if (action === 'repair' && !window.confirm('Create missing tables and columns for this client? Existing data is not changed.')) return;
            run(btn.getAttribute('data-key'), action);
        });
        var checkAll = document.getElementById('schemaCheckAll');
        var repairAll = document.getElementById('schemaRepairAll');
        if (checkAll) checkAll.addEventListener('click', function () { runAll('check'); });
        if (repairAll) repairAll.addEventListener('click', function () {
            if (window.confirm('Repair every client database? Only missing tables, columns, and indexes are created.')) runAll('repair');
        });
    }

    var initial = (location.hash || '#identity').slice(1);
    show(initial || 'identity', false);
})();
</script>
<?= $this->endSection() ?>
