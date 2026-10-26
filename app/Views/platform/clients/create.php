<?= $this->extend('layouts/platform') ?>

<?= $this->section('content') ?>
<div class="pfn-head">
    <div>
        <a class="pfn-back" href="<?= site_url('platform/clients') ?>"><i class="fas fa-arrow-left" aria-hidden="true"></i> Dashboard</a>
        <h2>Create client</h2>
        <p>Set up a new workspace: its own database, an admin login and routing, in one step.</p>
    </div>
    <div class="pfn-actions">
        <a href="<?= site_url('platform/clients') ?>" class="btn-pf">Cancel</a>
        <button type="submit" form="createClientForm" class="btn-pf btn-pf-accent"><i class="fas fa-plus" aria-hidden="true"></i> Create client</button>
    </div>
</div>
<form method="post" action="<?= site_url('platform/clients') ?>" class="pfn-layout" id="createClientForm">
    <?= csrf_field() ?>
    <div class="pfn-main">
        <section class="platform-card pfn-section">
            <div class="pfn-section-head">
                <span class="pfn-ico"><i class="fas fa-building" aria-hidden="true"></i></span>
                <div><h3>Client &amp; admin</h3><p>The workspace and the first user who signs in to it.</p></div>
            </div>
            <div class="pfn-grid">
        <div class="pfn-c4">
            <label class="platform-label">Client key<span class="req">*</span></label>
            <input class="platform-input" type="text" name="key" required pattern="[a-z0-9][a-z0-9_-]{1,62}" placeholder="shop2" value="<?= esc(old('key') ?? '') ?>">
            <div class="platform-help">a-z, 0-9, _ and -</div>
        </div>
        <div class="pfn-c4">
            <label class="platform-label">Display name<span class="req">*</span></label>
            <input class="platform-input" type="text" name="name" required placeholder="Shop 2" value="<?= esc(old('name') ?? '') ?>">
        </div>
        <div class="pfn-c4">
                    <label class="platform-label">Admin name</label>
                    <input class="platform-input" type="text" name="admin_name" value="<?= esc(old('admin_name') ?? 'Admin') ?>">
                </div>
                <div class="pfn-c4">
            <label class="platform-label" for="adminPhone">Country &amp; mobile number<span class="req">*</span></label>
            <div class="pf-phone">
            <select class="platform-input" aria-label="Country" id="clientCountry" name="country_code" required>
                <?php
                $pickedCountry = (string) (old('country_code') ?: '91');
                $markets = \App\Libraries\WhatsAppCountries::MARKETS;
                $others  = \App\Libraries\WhatsAppCountries::OTHERS;
                asort($markets);
                asort($others);
                ?>
                <optgroup label="Main markets">
                    <?php foreach ($markets as $code => $cname): ?>
                        <option value="<?= esc((string) $code) ?>" data-cur="<?= esc(\App\Libraries\WhatsAppCountries::currencyOf((string) $code)) ?>" <?= $pickedCountry === (string) $code ? 'selected' : '' ?>><?= esc($cname) ?> (+<?= esc((string) $code) ?>)</option>
                    <?php endforeach; ?>
                </optgroup>
                <optgroup label="Other countries">
                    <?php foreach ($others as $code => $cname): ?>
                        <option value="<?= esc((string) $code) ?>" data-cur="<?= esc(\App\Libraries\WhatsAppCountries::currencyOf((string) $code)) ?>" <?= $pickedCountry === (string) $code ? 'selected' : '' ?>><?= esc($cname) ?> (+<?= esc((string) $code) ?>)</option>
                    <?php endforeach; ?>
                </optgroup>
            </select>
                <input class="platform-input" id="adminPhone" type="tel" name="admin_phone" required inputmode="numeric" autocomplete="tel-national"
                       placeholder="9876543210" value="<?= esc(old('admin_phone') ?? '') ?>" aria-describedby="adminPhoneHint">
            </div>
            <div class="platform-help" id="adminPhoneHint">Enter the number without the country code.</div>
            <div class="platform-help" id="clientCountryHint">Sets wallet currency and timezone.</div>
        </div>
                <div class="pfn-c4">
                    <label class="platform-label">Admin email<span class="req">*</span></label>
                    <input class="platform-input" type="email" name="admin_email" required placeholder="admin@company.com" value="<?= esc(old('admin_email') ?? '') ?>">
                </div>
                <div class="pfn-c4">
                    <label class="platform-label" for="adminPassword">Admin password<span class="req">*</span></label>
                    <div class="pfn-pass">
                        <input class="platform-input" id="adminPassword" type="password" name="admin_password" required minlength="8" autocomplete="new-password" value="">
                        <button type="button" class="btn-pf" id="pwToggle" aria-label="Show or hide password"><i class="fas fa-eye" aria-hidden="true"></i></button>
                        <button type="button" class="btn-pf" id="pwGenerate" title="Generate a strong password" aria-label="Generate a strong password"><i class="fas fa-wand-magic-sparkles" aria-hidden="true"></i></button>
                    </div>
                    <div class="platform-help">At least 8 characters.</div>
                </div>
            </div>
        </section>

        <details class="platform-card pfn-section pfn-adv"<?= old('database') ? ' open' : '' ?>>
            <summary>
                <span class="pfn-ico"><i class="fas fa-database" aria-hidden="true"></i></span>
                <div><h3>Database <small>Advanced</small></h3><p>Leave blank to use the defaults. Only change this for a dedicated MySQL server.</p></div>
                <i class="fas fa-chevron-down pfn-chev" aria-hidden="true"></i>
            </summary>
            <div class="pfn-grid">
                <div class="pfn-c4">
                    <label class="platform-label">MySQL database</label>
                    <input class="platform-input" type="text" name="database" placeholder="sateri_shop2" value="<?= esc(old('database') ?? '') ?>">
                </div>
                <div class="pfn-c4">
                    <label class="platform-label">DB host</label>
                    <input class="platform-input" type="text" name="hostname" value="<?= esc(old('hostname') ?? 'localhost') ?>">
                </div>
                <div class="pfn-c4">
                    <label class="platform-label">DB user</label>
                    <input class="platform-input" type="text" name="username" value="<?= esc(old('username') ?? 'root') ?>">
                </div>
                <div class="pfn-c4">
                    <label class="platform-label">DB password</label>
                    <input class="platform-input" type="password" name="db_password" value="" autocomplete="new-password">
                </div>
                <div class="pfn-c4">
                    <label class="platform-label">Port</label>
                    <input class="platform-input" type="number" name="port" value="<?= esc(old('port') ?? '3306') ?>">
                </div>
            </div>
        </details>
    </div>
</form>
<style>
.pfn-actions { display: flex; gap: 0.4rem; align-items: center; }
.pfn-head { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: flex-end; gap: 0.8rem; margin-bottom: 0.9rem; }
.pfn-head h2 { margin: 0.25rem 0 0; font-family: var(--pf-display); font-size: 1.35rem; font-weight: 800; letter-spacing: -0.02em; }
.pfn-head p { margin: 0.2rem 0 0; color: var(--pf-muted); font-size: 0.84rem; }
.pfn-back { font-size: 0.76rem; font-weight: 700; color: var(--pf-muted); text-decoration: none; }
.pfn-back:hover { color: var(--pf-teal-deep); }
.pfn-steps { display: flex; gap: 0.5rem; list-style: none; margin: 0; padding: 0; font-size: 0.76rem; font-weight: 700; color: var(--pf-muted); }
.pfn-steps li { display: flex; align-items: center; gap: 0.4rem; padding: 0.3rem 0.7rem 0.3rem 0.35rem; background: var(--pf-card); border: 1px solid var(--pf-line); border-radius: 999px; }
.pfn-steps span { display: inline-grid; place-items: center; width: 1.3rem; height: 1.3rem; border-radius: 50%; background: var(--pf-teal); color: #fff; font-size: 0.7rem; }
.pfn-layout { display: grid; gap: 0.9rem; grid-template-columns: minmax(0, 1fr); align-items: start; }
.pfn-main { display: grid; gap: 0.9rem; }
.pfn-section { margin: 0; padding: 1.1rem 1.25rem 1.25rem; }
.pfn-section-head, .pfn-adv summary { display: flex; align-items: center; gap: 0.8rem; margin-bottom: 1rem; }
.pfn-section h3 { margin: 0; font-family: var(--pf-display); font-size: 0.98rem; font-weight: 800; letter-spacing: -0.015em; }
.pfn-section h3 small { margin-left: 0.4rem; padding: 0.1rem 0.45rem; border-radius: 999px; background: var(--pf-paper); color: var(--pf-muted); font-size: 0.64rem; font-weight: 700; vertical-align: middle; }
.pfn-section p { margin: 0.1rem 0 0; color: var(--pf-muted); font-size: 0.78rem; }
.pfn-ico { display: inline-grid; place-items: center; flex: none; width: 2.3rem; height: 2.3rem; border-radius: 10px; background: rgba(225, 29, 72, 0.08); color: var(--pf-teal-deep); font-size: 0.95rem; }
.pfn-adv summary { cursor: pointer; list-style: none; margin-bottom: 0; }
.pfn-adv summary::-webkit-details-marker { display: none; }
.pfn-adv[open] summary { margin-bottom: 1rem; }
.pfn-chev { margin-left: auto; color: var(--pf-muted); transition: transform 0.15s; }
.pfn-adv[open] .pfn-chev { transform: rotate(180deg); }
.pfn-pass { display: flex; gap: 0.4rem; }
.pfn-pass .platform-input { flex: 1; min-width: 0; }
.pfn-section .platform-input { padding: 0.62rem 0.8rem; }
.pf-phone { display: flex; align-items: stretch; }
.pf-phone select.platform-input { flex: 0 0 7.6rem; width: 7.6rem; padding-right: 0.3rem; text-overflow: ellipsis; border-radius: 10px 0 0 10px; border-right: 0; background-color: var(--pf-paper-2); font-weight: 600; }
.pf-phone input.platform-input { flex: 1; min-width: 0; border-radius: 0 10px 10px 0; }
.pf-phone .platform-input:focus { position: relative; z-index: 1; }
.platform-input.is-invalid { border-color: var(--pf-down); background: #fff5f4; }
.platform-help.is-error { color: var(--pf-down); font-weight: 600; }

/* compact density */
.pfn-head { margin-bottom: 0.6rem; align-items: center; }
.pfn-head h2 { margin: 0.1rem 0 0; font-size: 1.15rem; }
.pfn-head p { display: none; }
.pfn-layout { gap: 0.7rem; }
.pfn-main { gap: 0.7rem; }
.pfn-section { padding: 0.8rem 1rem 0.95rem; }
.pfn-section-head, .pfn-adv summary { gap: 0.6rem; margin-bottom: 0.7rem; }
.pfn-ico { width: 1.85rem; height: 1.85rem; border-radius: 8px; font-size: 0.8rem; }
.pfn-section h3 { font-size: 0.92rem; }
.pfn-section p { font-size: 0.74rem; margin: 0; }
.pfn-adv[open] summary { margin-bottom: 0.7rem; }
.pfn-grid { display: grid; gap: 0.6rem 0.8rem; grid-template-columns: minmax(0, 1fr); }
@media (min-width: 700px) { .pfn-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
@media (min-width: 1200px) { .pfn-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
.pfn-span2 { grid-column: 1 / -1; }
@media (min-width: 1200px) { .pfn-span2 { grid-column: span 2; } }
.pfn-grid .platform-label { margin-bottom: 0.2rem; font-size: 0.74rem; }
.pfn-grid .platform-help { margin-top: 0.2rem; font-size: 0.7rem; line-height: 1.3; }
.pfn-section .platform-input { padding: 0.45rem 0.65rem; font-size: 0.86rem; }
.pfn-pass .btn-pf { padding: 0.4rem 0.7rem; font-size: 0.78rem; }

/* 12-column row layout on wide screens: key | name | admin name, then country | mobile | email | password */
@media (min-width: 1100px) {
    .pfn-grid { grid-template-columns: repeat(12, minmax(0, 1fr)); }
    .pfn-c4 { grid-column: span 4; }
    .pfn-c3 { grid-column: span 3; }
    .pfn-c2 { grid-column: span 2; }
    .pfn-c5 { grid-column: span 5; }
}
.pfn-pass { gap: 0.3rem; }
.pfn-pass .btn-pf { padding: 0.4rem 0.55rem; }
.pfn-c2 .platform-help, .pfn-c3 .platform-help { font-size: 0.68rem; }
.pfn-pass { align-items: stretch; }
.pfn-pass .btn-pf { justify-content: center; width: 2.4rem; padding: 0; border-radius: 8px; }
.pf-phone select.platform-input { border-radius: 8px 0 0 8px; }
.pf-phone input.platform-input { border-radius: 0 8px 8px 0; }
.pfn-grid .platform-input { height: 2.4rem; }
</style>
<script>
(function () {
    // Mobile number rules per country (same as the server's WhatsAppCountries::validatePhone).
    var RULES = <?= json_encode(\App\Libraries\WhatsAppCountries::phoneRules(), JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var sel = document.getElementById('clientCountry');
    var phone = document.getElementById('adminPhone');
    var phoneHint = document.getElementById('adminPhoneHint');

    function describe(c) {
        var r = RULES[c] || { min: 6, max: 14, first: '' };
        var len = r.min === r.max ? r.min + ' digits' : r.min + ' to ' + r.max + ' digits';
        return len + (r.first ? ', starting with ' + r.first.replace(/[\[\]]/g, '') : '');
    }

    // Returns '' when valid, otherwise the message. Mirrors the server: accepts +code / 00 / trunk 0.
    function check() {
        var c = sel.value, r = RULES[c] || { min: 6, max: 14, first: '' };
        var d = (phone.value || '').replace(/\D+/g, '');
        if (d === '') return 'Enter the mobile number.';
        if (d.indexOf('00') === 0) d = d.slice(2);
        if (d.length > r.max && d.indexOf(c) === 0) d = d.slice(c.length);
        d = d.replace(/^0+/, '');
        var ok = d.length >= r.min && d.length <= r.max && (!r.first || new RegExp('^' + r.first).test(d));
        return ok ? '' : 'Enter a valid mobile number for ' + sel.options[sel.selectedIndex].text.replace(/\s*\(\+\d+\)$/, '') + ' (' + describe(c) + ', without the +' + c + ').';
    }

    function showPhone(force) {
        var msg = phone.value === '' && !force ? '' : check();
        phone.classList.toggle('is-invalid', msg !== '');
        phoneHint.classList.toggle('is-error', msg !== '');
        phoneHint.textContent = msg !== '' ? msg : 'Mobile number of the admin: ' + describe(sel.value) + ', without the +' + sel.value + '.';
        phone.setCustomValidity(phone.value === '' ? '' : msg);
    }
    if (sel && phone) {
        sel.addEventListener('change', function () { showPhone(false); });
        phone.addEventListener('input', function () { showPhone(false); });
        phone.addEventListener('blur', function () { showPhone(phone.value !== ''); });
        phone.form.addEventListener('submit', function (e) {
            var msg = check();
            phone.setCustomValidity(msg);
            if (msg !== '') { showPhone(true); phone.reportValidity(); e.preventDefault(); }
        });
        showPhone(false);
    }

    var pw = document.getElementById('adminPassword');
    var pwToggle = document.getElementById('pwToggle');
    var pwGen = document.getElementById('pwGenerate');
    if (pw && pwToggle) {
        pwToggle.addEventListener('click', function () {
            var show = pw.type === 'password';
            pw.type = show ? 'text' : 'password';
            pwToggle.firstElementChild.className = show ? 'fas fa-eye-slash' : 'fas fa-eye';
        });
    }
    if (pw && pwGen) {
        pwGen.addEventListener('click', function () {
            var chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789!@#$%';
            var buf = new Uint32Array(14), out = '';
            (window.crypto || window.msCrypto).getRandomValues(buf);
            for (var i = 0; i < buf.length; i++) out += chars.charAt(buf[i] % chars.length);
            pw.value = out;
            pw.type = 'text';
            pwToggle.firstElementChild.className = 'fas fa-eye-slash';
        });
    }

    var sel = document.getElementById('clientCountry');
    var hint = document.getElementById('clientCountryHint');
    if (!sel || !hint) return;
    function update() {
        var opt = sel.options[sel.selectedIndex];
        var cur = opt ? opt.getAttribute('data-cur') : '';
        hint.textContent = cur ? 'Wallet currency: ' + cur + '. The starting timezone is set from the country.' : 'Sets the wallet currency and the starting timezone.';
    }
    sel.addEventListener('change', update);
    update();
})();
</script>
<?= $this->endSection() ?>
