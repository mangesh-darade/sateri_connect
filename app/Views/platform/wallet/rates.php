<?= $this->extend('layouts/platform') ?>

<?= $this->section('content') ?>
<?php
$rates = $rates ?? [];
$have  = [];
$priced = 0;
$unset  = 0;
$latest = '';
foreach ($rates as $r) {
    $have[(string) $r['country_code']] = true;
    \App\Libraries\WalletRateCard::isUnset($r) ? $unset++ : $priced++;
    $latest = max($latest, (string) ($r['updated_at'] ?? ''));
}
// "Other countries" last, the rest by name.
usort($rates, static function (array $a, array $b): int {
    $ao = $a['country_code'] === 'other' ? 1 : 0;
    $bo = $b['country_code'] === 'other' ? 1 : 0;

    return [$ao, strtolower((string) $a['country_name'])] <=> [$bo, strtolower((string) $b['country_name'])];
});
$fx       = $fx ?? ['INR' => 1.0];
$allCur   = \App\Libraries\WhatsAppCountries::CURRENCIES;
$inUse    = [];
foreach ($rates as $r) {
    if (! \App\Libraries\WalletRateCard::isUnset($r)) {
        $inUse[strtoupper((string) $r['currency'])] = true;
    }
}
$missingFx = array_values(array_filter(array_keys($inUse), static fn (string $c): bool => $c !== 'INR' && empty($fx[$c])));
$cats = ['marketing' => 'Marketing', 'utility' => 'Utility', 'authentication' => 'Authentication', 'service' => 'Service*'];
?>
<style>
    .rg-kpis { display:grid; grid-template-columns:repeat(auto-fit,minmax(150px,1fr)); gap:.75rem; margin-bottom:1rem }
    .rg-kpi { background:#fff; border:1px solid #e7e9ef; border-radius:12px; padding:.8rem 1rem }
    .rg-kpi span { display:block; font-size:.74rem; color:#6b7280; text-transform:uppercase; letter-spacing:.04em }
    .rg-kpi strong { font-size:1.35rem }
    .rg-toolbar { display:flex; flex-wrap:wrap; gap:.6rem; align-items:center; justify-content:space-between; margin-bottom:.75rem }
    .rg-toolbar-left, .rg-toolbar-right { display:flex; flex-wrap:wrap; gap:.5rem; align-items:center }
    .rg-search { min-width:220px }
    .rg-chip { border:1px solid #e1e4ec; background:#fff; border-radius:999px; padding:.3rem .85rem; font-size:.82rem; cursor:pointer }
    .rg-chip.is-on { background:#111827; color:#fff; border-color:#111827 }
    .rg-wrap { max-height:62vh; overflow:auto; border:1px solid #e7e9ef; border-radius:12px }
    .rg-table { width:100%; border-collapse:separate; border-spacing:0; font-size:.88rem }
    .rg-table th { position:sticky; top:0; z-index:1; background:#f8f9fc; text-align:left; padding:.6rem .7rem; font-size:.72rem; text-transform:uppercase; letter-spacing:.04em; color:#6b7280; border-bottom:1px solid #e7e9ef; white-space:nowrap }
    .rg-table td { padding:.4rem .7rem; border-bottom:1px solid #f0f1f5; vertical-align:middle }
    .rg-table tr:hover td { background:#fafbff }
    .rg-table tr.is-unset td:first-child { box-shadow:inset 3px 0 0 #f59e0b }
    .rg-table tr.is-dirty td { background:#fffbeb }
    .rg-country { font-weight:600 }
    .rg-code { display:inline-block; margin-left:.4rem; padding:.05rem .45rem; border-radius:6px; background:#eef0f6; color:#4b5563; font-size:.75rem; font-weight:600 }
    .rg-num { width:6.4rem; text-align:right; padding:.3rem .5rem }
    .rg-cur { width:5.2rem; padding:.3rem .4rem }
    .rg-x { border:0; background:none; color:#9ca3af; cursor:pointer; font-size:1rem }
    .rg-x:hover { color:#dc2626 }
    .rg-empty { padding:1.5rem; text-align:center; color:#6b7280 }
    .rg-savebar { position:sticky; bottom:0; display:flex; align-items:center; justify-content:space-between; gap:.75rem; margin-top:.75rem; padding:.7rem 1rem; background:#111827; color:#fff; border-radius:12px }
    .rg-savebar[hidden] { display:none }
    .rg-split { display:grid; grid-template-columns:repeat(auto-fit,minmax(320px,1fr)); gap:1rem }
    .rg-details summary { cursor:pointer; font-weight:600 }
    .rg-fx { display:grid; grid-template-columns:repeat(auto-fill,minmax(190px,1fr)); gap:.6rem }
    .rg-fx-item { display:flex; align-items:center; gap:.45rem; border:1px solid #e7e9ef; border-radius:10px; padding:.4rem .65rem; background:#fff }
    .rg-fx-item.is-missing { border-color:#f59e0b; background:#fffbeb }
    .rg-fx-cur { font-weight:700; white-space:nowrap; min-width:3.4rem }
    .rg-fx-item .platform-input { min-width:0; width:100%; padding:.3rem .45rem }
</style>

<section class="platform-card">
    <div class="platform-card-head">
        <div>
            <h2>WhatsApp rates</h2>
            <p>Price per message by receiver country, used by every client wallet. Meta bills by category and country and changes prices only on 1 Jan, Apr, Jul and Oct.</p>
        </div>
    </div>

    <div class="rg-kpis">
        <div class="rg-kpi"><span>Countries</span><strong><?= count($rates) ?></strong></div>
        <div class="rg-kpi"><span>With a rate</span><strong><?= $priced ?></strong></div>
        <div class="rg-kpi"><span>Not set</span><strong style="color:<?= $unset > 0 ? '#b45309' : 'inherit' ?>"><?= $unset ?></strong></div>
        <div class="rg-kpi"><span>Last updated</span><strong style="font-size:1rem"><?= $latest !== '' ? esc($latest) : '—' ?></strong></div>
    </div>

    <?php if ($unset > 0): ?>
        <div class="platform-help" style="margin-bottom:.75rem">Countries marked <span class="platform-badge platform-badge-warn">Not set</span> have no rate yet. While a client's wallet is on, messages to them are refused. Enter Meta's rate, or import Meta's rate card.</div>
    <?php endif; ?>

    <form method="post" action="<?= site_url('platform/wa-rates/bulk') ?>" id="rateGridForm">
        <?= csrf_field() ?>

        <div class="rg-toolbar">
            <div class="rg-toolbar-left">
                <input type="hidden" id="rgStatus" value="">
                <button type="button" class="rg-chip is-on" data-filter="">All</button>
                <button type="button" class="rg-chip" data-filter="priced">With a rate</button>
                <button type="button" class="rg-chip" data-filter="unset">Not set</button>
            </div>
            <div class="rg-toolbar-right">
                <span class="platform-help">Use the search box to find a country or calling code. Edits on other pages are kept until you save.</span>
            </div>
        </div>

        <div class="rg-wrap">
            <table class="rg-table" id="rgTable" style="width:100%">
                <thead>
                <tr>
                    <th>Country</th><th>Status</th><th>Cur.</th>
                    <?php foreach ($cats as $label): ?><th style="text-align:right"><?= esc($label) ?></th><?php endforeach; ?>
                    <th></th>
                </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>

        <div class="rg-savebar" id="rgSavebar" hidden>
            <span><strong id="rgDirty">0</strong> unsaved <span id="rgDirtyWord">changes</span></span>
            <span style="display:flex;gap:.5rem">
                <button type="button" class="btn-pf" id="rgReset">Discard</button>
                <button type="submit" class="btn-pf btn-pf-primary">Save changes</button>
            </span>
        </div>
    </form>
    <form id="rateDeleteForm" method="post" style="display:none"><?= csrf_field() ?></form>

    <div class="platform-help" style="margin-top:.75rem">Rates are per message, before tax. *Service = free-form replies after the 1,000 free messages per phone number each month.</div>
</section>

<section class="platform-card">
    <div class="platform-card-head">
        <div>
            <h2>Exchange rates</h2>
            <p>Each country is priced in its own currency, and each client's wallet is in its own currency, so every message is converted at these rates when it is charged. Enter what <strong>1 unit</strong> of the currency is worth in INR.</p>
            <p style="margin-top:.25rem">Last updated: <strong><?= ($fxUpdated ?? '') !== '' ? esc((string) $fxUpdated) : 'never' ?></strong></p>
        </div>
        <form method="post" action="<?= site_url('platform/wa-rates/fx/refresh') ?>" style="margin:0">
            <?= csrf_field() ?>
            <button type="submit" class="btn-pf"><i class="fas fa-rotate" aria-hidden="true"></i> Fetch latest rates</button>
        </form>
    </div>
    <?php if ($missingFx !== []): ?>
        <div class="platform-help" style="margin-bottom:.75rem;color:#b45309"><strong>Missing:</strong> <?= esc(implode(', ', $missingFx)) ?>. Messages to countries priced in these currencies are refused until you add the rate.</div>
    <?php endif; ?>
    <form method="post" action="<?= site_url('platform/wa-rates/fx') ?>">
        <?= csrf_field() ?>
        <div class="rg-fx">
            <?php foreach ($allCur as $cur): ?>
                <?php if ($cur === 'INR') { continue; } ?>
                <label class="rg-fx-item<?= isset($inUse[$cur]) && empty($fx[$cur]) ? ' is-missing' : '' ?>">
                    <span class="rg-fx-cur">1 <?= $cur ?></span>
                    <span style="color:#6b7280">₹</span>
                    <input class="platform-input" style="padding:.25rem .4rem" type="number" step="0.000001" min="0" name="fx[<?= $cur ?>]" value="<?= ! empty($fx[$cur]) ? esc(rtrim(rtrim(number_format((float) $fx[$cur], 6, '.', ''), '0'), '.'), 'attr') : '' ?>" placeholder="—" aria-label="1 <?= $cur ?> in INR">
                </label>
            <?php endforeach; ?>
        </div>
        <div style="margin-top:.75rem"><button class="btn-pf btn-pf-primary" type="submit">Save exchange rates</button></div>
        <div class="platform-help" style="margin-top:.5rem">"Fetch latest rates" fills every currency from a public daily mid-market feed (not Meta's own conversion). Edit any value by hand if you bill at a different rate. Rates are not refreshed automatically.</div>
    </form>
</section>

<section class="rg-split">
    <div class="platform-card">
        <details class="rg-details" <?= $unset === count($rates) ? 'open' : '' ?>>
            <summary>Add a country</summary>
            <form method="post" action="<?= site_url('platform/wa-rates') ?>" class="platform-form-grid" style="margin-top:.75rem">
                <?= csrf_field() ?>
                <div class="full">
                    <label class="platform-label" for="rateCountry">Country</label>
                    <select class="platform-input" id="rateCountry" name="country_code" required>
                        <option value="">Choose a country…</option>
                        <?php if (! isset($have['other'])): ?><option value="other">Other countries (fallback rate)</option><?php endif; ?>
                        <optgroup label="Meta markets">
                            <?php foreach (\App\Libraries\WhatsAppCountries::MARKETS as $code => $name): ?>
                                <?php if (! isset($have[(string) $code])): ?><option value="<?= esc((string) $code) ?>" data-cur="<?= esc(\App\Libraries\WhatsAppCountries::currencyOf((string) $code)) ?>"><?= esc($name) ?> (+<?= esc((string) $code) ?>)</option><?php endif; ?>
                            <?php endforeach; ?>
                        </optgroup>
                        <optgroup label="Other countries (regional rates)">
                            <?php foreach (\App\Libraries\WhatsAppCountries::OTHERS as $code => $name): ?>
                                <?php if (! isset($have[(string) $code])): ?><option value="<?= esc((string) $code) ?>" data-cur="<?= esc(\App\Libraries\WhatsAppCountries::currencyOf((string) $code)) ?>"><?= esc($name) ?> (+<?= esc((string) $code) ?>)</option><?php endif; ?>
                            <?php endforeach; ?>
                        </optgroup>
                    </select>
                </div>
                <div>
                    <label class="platform-label" for="rateCurrency">Currency</label>
                    <select class="platform-input" id="rateCurrency" name="currency">
                        <?php foreach ($allCur as $cur): ?><option value="<?= $cur ?>"><?= $cur ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div><label class="platform-label">Marketing</label><input class="platform-input" type="number" step="0.0001" min="0" name="marketing" value="0"></div>
                <div><label class="platform-label">Utility</label><input class="platform-input" type="number" step="0.0001" min="0" name="utility" value="0"></div>
                <div><label class="platform-label">Authentication</label><input class="platform-input" type="number" step="0.0001" min="0" name="authentication" value="0"></div>
                <div><label class="platform-label">Service</label><input class="platform-input" type="number" step="0.0001" min="0" name="service" value="0"></div>
                <div class="full"><button class="btn-pf btn-pf-primary" type="submit">Add country</button></div>
            </form>
        </details>
    </div>

    <div class="platform-card">
        <details class="rg-details">
            <summary>Import Meta rate card (CSV)</summary>
            <p class="platform-help" style="margin-top:.75rem">Download the list-rates CSV for your billing currency from Meta's <a href="https://developers.facebook.com/docs/whatsapp/pricing" target="_blank" rel="noopener">pricing page</a> (Rate cards and volume tiers) and upload it. Countries are matched by name and existing rates are updated. Regional rows such as "Rest of Africa" are skipped; add those countries separately.</p>
            <form method="post" action="<?= site_url('platform/wa-rates/import') ?>" enctype="multipart/form-data" class="platform-form-grid">
                <?= csrf_field() ?>
                <div class="full"><label class="platform-label" for="rateCard">CSV file</label><input class="platform-input" id="rateCard" type="file" name="rate_card" accept=".csv,text/csv" required></div>
                <div><label class="platform-label">Currency</label><input class="platform-input" name="currency" value="INR" maxlength="3"></div>
                <div class="full"><button class="btn-pf btn-pf-primary" type="submit">Import</button></div>
            </form>
        </details>
    </div>
</section>

<?= view('partials/platform_dt_assets') ?>
<script>
(function () {
    var cats = <?= json_encode(array_keys($cats)) ?>;
    var allCur = <?= json_encode(array_values($allCur)) ?>;
    var bar = document.getElementById('rgSavebar');
    var dirtyN = document.getElementById('rgDirty');
    var dirtyWord = document.getElementById('rgDirtyWord');
    var form = document.getElementById('rateGridForm');
    var origs = {};   // code => values as loaded from the server
    var edits = {};   // code => current values (rows the admin touched, across pages)

    function isDirty(code) {
        var o = origs[code], e = edits[code];
        if (!o || !e) return false;
        if (String(e.currency).toUpperCase() !== String(o.currency).toUpperCase()) return true;
        return cats.some(function (c) { return parseFloat(e[c] || '0') !== parseFloat(o[c] || '0'); });
    }
    function dirtyCount() { return Object.keys(edits).filter(isDirty).length; }
    function refreshBar() {
        var n = dirtyCount();
        dirtyN.textContent = n;
        dirtyWord.textContent = n === 1 ? 'change' : 'changes';
        bar.hidden = n === 0;
    }

    var dt = AppDT.table('#rgTable', {
        url: <?= json_encode(site_url('platform/wa-rates/data')) ?>,
        order: [[0, 'asc']],
        pageLength: 25,
        emptyText: 'No countries match.',
        filters: { status: '#rgStatus' },
        createdRow: function (tr, r) {
            tr.className = r.unset ? 'is-unset' : '';
            tr.setAttribute('data-code', r.code);
        },
        columns: [
            { data: null, render: function (r) {
                return '<span class="rg-country">' + AppDT.esc(r.name) + '</span>' + (r.code !== 'other' ? '<span class="rg-code">+' + AppDT.esc(r.code) + '</span>' : '');
            } },
            { data: null, orderable: false, render: function (r) {
                return '<span class="platform-badge ' + (r.unset ? 'platform-badge-warn' : 'platform-badge-ok') + '">' + (r.unset ? 'Not set' : 'Active') + '</span>';
            } },
            { data: null, orderable: false, render: function (r) {
                origs[r.code] = origs[r.code] || { currency: r.currency, marketing: r.marketing, utility: r.utility, authentication: r.authentication, service: r.service };
                var cur = String((edits[r.code] || r).currency).toUpperCase();
                var opts = allCur.map(function (c) { return '<option value="' + AppDT.esc(c) + '"' + (c === cur ? ' selected' : '') + '>' + AppDT.esc(c) + '</option>'; }).join('');
                return '<select class="platform-input rg-cur" data-code="' + AppDT.esc(r.code) + '" data-field="currency" aria-label="Currency for ' + AppDT.esc(r.name) + '">' + opts + '</select>';
            } }
        ].concat(cats.map(function (c) {
            return { data: null, orderable: false, render: function (r) {
                var v = (edits[r.code] || r)[c];
                return '<input class="platform-input rg-num" type="number" step="0.0001" min="0" inputmode="decimal" data-code="' + AppDT.esc(r.code) + '" data-field="' + c + '" value="' + AppDT.esc(v) + '" aria-label="' + AppDT.esc(c + ' rate for ' + r.name) + '">';
            } };
        })).concat([
            { data: null, orderable: false, render: function (r) {
                return '<button type="submit" class="rg-x js-rate-del" form="rateDeleteForm" formaction="' + AppDT.esc(r.delete_url) + '" data-name="' + AppDT.esc(r.name) + '" title="Remove ' + AppDT.esc(r.name) + '" aria-label="Remove ' + AppDT.esc(r.name) + '">&#10005;</button>';
            } }
        ]),
        drawCallback: function () {
            $('#rgTable tbody tr').each(function () {
                $(this).toggleClass('is-dirty', isDirty(this.getAttribute('data-code')));
            });
        }
    });

    $('.rg-chip').on('click', function () {
        $('.rg-chip').removeClass('is-on');
        $(this).addClass('is-on');
        $('#rgStatus').val(this.getAttribute('data-filter')).trigger('change');
    });

    $('#rgTable').on('input change', '[data-field]', function () {
        var code = this.getAttribute('data-code');
        var tr = $(this).closest('tr');
        var cur = {};
        tr.find('[data-field]').each(function () { cur[this.getAttribute('data-field')] = this.value.trim(); });
        edits[code] = cur;
        tr.toggleClass('is-dirty', isDirty(code));
        refreshBar();
    });

    $('#rgTable').on('click', '.js-rate-del', function (e) {
        if (!confirm('Remove the rate for ' + this.getAttribute('data-name') + '?')) e.preventDefault();
    });

    document.getElementById('rgReset').addEventListener('click', function () {
        edits = {};
        refreshBar();
        dt.draw(false);
    });

    // Send only the rows that were edited (on any page).
    form.addEventListener('submit', function () {
        Object.keys(edits).forEach(function (code) {
            if (!isDirty(code)) return;
            ['currency'].concat(cats).forEach(function (f) {
                var i = document.createElement('input');
                i.type = 'hidden';
                i.name = 'rates[' + code + '][' + f + ']';
                i.value = edits[code][f];
                form.appendChild(i);
            });
        });
        bar.hidden = true;
        edits = {};
    });

    window.addEventListener('beforeunload', function (e) {
        if (!bar.hidden) { e.preventDefault(); e.returnValue = ''; }
    });

    // Pick the country's own currency when adding a country.
    var addCountry = document.getElementById('rateCountry');
    var addCurrency = document.getElementById('rateCurrency');
    if (addCountry && addCurrency) {
        addCountry.addEventListener('change', function () {
            var opt = addCountry.options[addCountry.selectedIndex];
            var cur = opt ? opt.getAttribute('data-cur') : '';
            if (cur) addCurrency.value = cur;
        });
    }

})();
</script>
<?= $this->endSection() ?>
