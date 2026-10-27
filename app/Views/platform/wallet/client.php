<?= $this->extend('layouts/platform') ?>

<?= $this->section('header_actions') ?>
<a class="btn-pf" href="<?= site_url('platform/clients/' . rawurlencode((string) ($tenant['key'] ?? ''))) ?>">← Client</a>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php
$wallet   = $wallet ?? [];
$tenant   = $tenant ?? [];
$enabled  = (int) ($wallet['enabled'] ?? 0) === 1;
$currency = (string) ($wallet['currency'] ?? \App\Libraries\Currency::base());
$symbol   = \App\Libraries\WhatsAppCountries::symbolOf((string) $currency);
$money    = static fn (float $n, int $dp = 2): string => number_format($n, $dp);
$action   = site_url('platform/clients/' . rawurlencode((string) ($tenant['key'] ?? '')) . '/wallet');
$typeLabel = ['credit' => 'Recharge', 'debit' => 'Message / deduction', 'refund' => 'Refund'];
?>

<section class="pfw-kpis">
    <div class="platform-card pfw-kpi pfw-kpi-main">
        <div class="pfw-kpi-top">
            <span class="pfw-eyebrow"><?= esc((string) ($tenant['name'] ?? '')) ?></span>
            <span class="pfw-kpi-ico"><i class="fas fa-wallet" aria-hidden="true"></i></span>
        </div>
        <div>
            <strong class="pfw-balance" id="pfwBalance"><?= esc($symbol) ?><?= $money((float) ($wallet['balance'] ?? 0)) ?></strong>
            <span class="pfw-sub">Available WhatsApp wallet balance · <?= esc($currency) ?></span>
        </div>
    </div>
    <div id="pfwPower" class="platform-card pfw-kpi pfw-power <?= $enabled ? 'is-on' : 'is-off' ?>">
        <div class="pfw-kpi-top">
            <span class="pfw-eyebrow">Wallet status</span>
            <span id="pfwStatusPill" class="pfw-status <?= $enabled ? 'is-on' : 'is-off' ?>"><i class="fas fa-circle" aria-hidden="true"></i> <?= $enabled ? 'Active' : 'Inactive' ?></span>
        </div>
        <form method="post" action="<?= $action ?>" class="pfw-power-row pfw-ajax" id="pfwPowerForm"
              data-confirm="<?= $enabled ? 'Turn this wallet off? Messages will no longer be charged or blocked by balance.' : '' ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="<?= $enabled ? 'disable' : 'enable' ?>">
            <button type="submit" class="pfw-switch" role="switch" aria-checked="<?= $enabled ? 'true' : 'false' ?>" aria-label="Wallet charging">
                <span class="pfw-switch-knob"></span>
            </button>
            <div>
                <strong id="pfwPowerTitle"><?= $enabled ? 'Charging is on' : 'Charging is off' ?></strong>
                <span class="pfw-sub" id="pfwPowerSub"><?= $enabled ? 'Messages are charged to this wallet. Sending stops at zero balance.' : 'Messages are free and not blocked. Add balance first, then switch on, or sending stops.' ?></span>
            </div>
        </form>
    </div>
    <div class="platform-card pfw-kpi">
        <?php $freePct = (int) $freeLimit > 0 ? min(100, (int) round(((int) $freeUsed / (int) $freeLimit) * 100)) : 0; ?>
        <div class="pfw-kpi-top">
            <span class="pfw-eyebrow">Free service replies</span>
            <span class="pfw-pct"><?= $freePct ?>%</span>
        </div>
        <strong class="pfw-num"><?= number_format((int) $freeUsed) ?><small> / <?= number_format((int) $freeLimit) ?></small></strong>
        <div>
            <div class="pfw-bar"><i style="width:<?= $freePct ?>%"></i></div>
            <span class="pfw-sub">Used this month</span>
        </div>
    </div>
</section>

<section class="pfw-layout">
    <div class="platform-card pfw-card">
        <div class="pfw-head"><span class="pfw-ico"><i class="fas fa-money-bill-transfer" aria-hidden="true"></i></span>
            <div><h3>Add or deduct money</h3><p>Manual adjustment, recorded in the transactions below.</p></div></div>
        <form method="post" action="<?= $action ?>" class="pfw-form pfw-ajax" id="pfwMoneyForm">
            <?= csrf_field() ?>
            <div>
                <span class="platform-label">Action</span>
                <div class="pfw-seg" role="radiogroup" aria-label="Action">
                    <label><input type="radio" name="action" value="credit" checked><span><i class="fas fa-plus" aria-hidden="true"></i> Add money</span></label>
                    <label><input type="radio" name="action" value="debit"><span><i class="fas fa-minus" aria-hidden="true"></i> Deduct</span></label>
                </div>
            </div>
            <div>
                <label class="platform-label" for="pfwAmount">Amount (<?= esc($currency) ?>)</label>
                <div class="pfw-money"><span><?= esc(trim($symbol)) ?></span><input class="platform-input" id="pfwAmount" type="number" name="amount" step="0.01" min="0.01" required placeholder="0.00"></div>
                <div class="pfw-chips" aria-label="Quick amounts">
                    <?php foreach ([500, 1000, 5000, 10000] as $amt): ?>
                        <button type="button" data-amount="<?= $amt ?>"><?= esc(trim($symbol)) ?><?= number_format($amt) ?></button>
                    <?php endforeach; ?>
                </div>
            </div>
            <div>
                <label class="platform-label" for="pfwNote">Note</label>
                <input class="platform-input" id="pfwNote" type="text" name="note" maxlength="255" placeholder="Payment reference, reason…">
                <div class="platform-help">Required when deducting.</div>
            </div>
            <button class="btn-pf btn-pf-primary pfw-save" type="submit">Save</button>
        </form>
    </div>

    <div class="platform-card pfw-card">
        <form method="post" action="<?= $action ?>" class="pfw-ajax" id="pfwSettingsForm">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="settings">
            <div class="pfw-head"><span class="pfw-ico"><i class="fas fa-sliders" aria-hidden="true"></i></span>
                <div><h3>Pricing, limits and alerts</h3><p>Applies to this client's wallet only.</p></div>
                <button class="btn-pf btn-pf-primary pfw-save pfw-head-save" type="submit"><i class="fas fa-floppy-disk" aria-hidden="true"></i> Save changes</button>
            </div>
            <div class="pfw-groups">
            <div class="pfw-group">
                <h4>Pricing</h4>
                <div class="pfw-grid">
                    <div>
                        <label class="platform-label" for="markupPct">Platform markup (%)</label>
                        <input class="platform-input" id="markupPct" type="number" name="markup_percent" step="0.01" min="0" max="1000" value="<?= esc((string) ($wallet['markup_percent'] ?? 0)) ?>">
                        <div class="platform-help">Added on the Meta price before GST. Your margin. The client sees only the final price. 0 = pass Meta's price through.</div>
                    </div>
                    <div>
                        <label class="platform-label" for="taxPct">GST / tax (%)</label>
                        <input class="platform-input" id="taxPct" type="number" name="tax_percent" step="0.01" min="0" max="100" value="<?= esc((string) ($wallet['tax_percent'] ?? 0)) ?>">
                        <div class="platform-help">Added on top of the rate card price for every message (rates are before tax), and also added when this client recharges online (Razorpay or Mswipe). 0 = none.</div>
                    </div>
                </div>
            </div>
            <div class="pfw-group">
                <h4>Spending limits</h4>
                <div class="pfw-grid">
                    <div>
                        <label class="platform-label" for="dailyCap">Daily limit (<?= esc($currency) ?>)</label>
                        <input class="platform-input" id="dailyCap" type="number" name="daily_cap" step="0.01" min="0" value="<?= esc((string) ($wallet['daily_cap'] ?? 0)) ?>">
                        <div class="platform-help">Sending stops for the day once this much is spent. 0 = no limit.</div>
                    </div>
                    <div>
                        <label class="platform-label" for="monthlyCap">Monthly limit (<?= esc($currency) ?>)</label>
                        <input class="platform-input" id="monthlyCap" type="number" name="monthly_cap" step="0.01" min="0" value="<?= esc((string) ($wallet['monthly_cap'] ?? 0)) ?>">
                        <div class="platform-help">Same, for the calendar month. 0 = no limit.</div>
                    </div>
                </div>
            </div>
            <div class="pfw-group">
                <h4>Low-balance alert</h4>
                <div class="pfw-grid">
                    <div>
                        <label class="platform-label" for="lowAlert">Alert below (<?= esc($currency) ?>)</label>
                        <input class="platform-input" id="lowAlert" type="number" name="low_balance_alert" step="0.01" min="0" value="<?= esc((string) ($wallet['low_balance_alert'] ?? 0)) ?>">
                        <div class="platform-help">Workspace admins get a notification when the balance falls below this. 0 = off.</div>
                    </div>
                    <div>
                        <label class="platform-label" for="topup">Top-up amount (<?= esc($currency) ?>)</label>
                        <input class="platform-input" id="topup" type="number" name="auto_topup_amount" step="0.01" min="0" value="<?= esc((string) ($wallet['auto_topup_amount'] ?? 0)) ?>">
                        <div class="platform-help">When the low-balance alert fires, a payment link for this amount is added to the alert. Needs online payments to be set up. 0 = off.</div>
                    </div>
                </div>
            </div>
            </div>
        </form>
    </div>
</section>

<section class="platform-card pfw-card">
    <div class="pfw-head"><span class="pfw-ico"><i class="fas fa-receipt" aria-hidden="true"></i></span>
        <div><h3>Transactions</h3><p>Search, filter and sort the full wallet history.</p></div>
        <div class="pfw-tools">
            <select id="pfwType" class="platform-input" aria-label="Filter by type">
                <option value="">All types</option>
                <option value="credit">Recharge</option>
                <option value="debit">Message / deduction</option>
                <option value="refund">Refund</option>
            </select>
        </div>
    </div>
    <div class="platform-table-wrap">
        <table class="platform-table pfw-table" id="pfwTx" data-url="<?= site_url('platform/clients/' . rawurlencode((string) ($tenant['key'] ?? '')) . '/wallet/transactions') ?>" style="width:100%">
            <thead><tr><th>Date</th><th>Type</th><th>Details</th><th class="num">Amount</th><th class="num">Balance</th></tr></thead>
            <tbody></tbody>
        </table>
    </div>
</section>
<script>window.PFW_SYMBOL = <?= json_encode($symbol) ?>;</script>
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/jquery.dataTables.min.css">
<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script>
(function ($) {
    'use strict';
    var $t = $('#pfwTx');
    if (!$t.length) return;
    var sym = window.PFW_SYMBOL || '';
    var $type = $('#pfwType');

    function esc(s) { return $('<div>').text(s == null ? '' : String(s)).html(); }

    var table = window.PFW_TABLE = $t.DataTable({
        serverSide: true,
        processing: true,
        searchDelay: 350,
        order: [[0, 'desc']],
        pageLength: 10,
        lengthMenu: [10, 25, 50, 100],
        pagingType: 'simple_numbers',
        autoWidth: false,
        ajax: {
            url: $t.data('url'),
            data: function (d) { d.type = $type.val(); },
            error: function () { $('#pfwTxError').remove(); $t.before('<div id="pfwTxError" class="pfw-tx-error">Could not load transactions. Reload the page and try again.</div>'); }
        },
        columns: [
            { data: null, render: function (r, t) { return t === 'display' ? '<strong>' + esc(r.date) + '</strong><small>' + esc(r.time) + '</small>' : r.date; } },
            { data: null, render: function (r, t) {
                if (t !== 'display') return r.label;
                return '<span class="pfw-tag is-' + esc(r.type) + '"><i class="fas fa-arrow-' + (r['in'] ? 'down' : 'up') + '"></i> ' + esc(r.label) + '</span>';
            } },
            { data: 'details', orderable: true, render: function (v, t) { return t === 'display' ? esc(v) : v; } },
            { data: null, className: 'num', render: function (r, t) {
                if (t !== 'display') return r.amount;
                return '<span class="' + (r['in'] ? 'is-in' : 'is-out') + '">' + (r['in'] ? '+' : '−') + esc(r.symbol || sym) + esc(r.amount) + '</span>';
            } },
            { data: null, className: 'num', render: function (r, t) { return t === 'display' ? esc(r.symbol || sym) + esc(r.balance) : r.balance; } }
        ],
        columnDefs: [{ targets: 0, className: 'nowrap' }],
        language: {
            search: '',
            searchPlaceholder: 'Search note, reference or amount',
            lengthMenu: 'Show _MENU_',
            info: 'Showing _START_ to _END_ of _TOTAL_ transactions',
            infoEmpty: 'No transactions',
            infoFiltered: '(filtered from _MAX_)',
            emptyTable: 'No transactions yet.',
            zeroRecords: 'No transactions match your search.',
            processing: 'Loading…',
            paginate: { previous: '‹', next: '›' }
        },
        drawCallback: function () { $('#pfwTxError').remove(); }
    });

    $type.on('change', function () { table.ajax.reload(); });
})(jQuery);


(function () {
    'use strict';
    var sym = window.PFW_SYMBOL || '';
    var POWER = {
        on:  { pill: 'Active',   title: 'Charging is on',  sub: 'Messages are charged to this wallet. Sending stops at zero balance.', action: 'disable' },
        off: { pill: 'Inactive', title: 'Charging is off', sub: 'Messages are free and not blocked. Add balance first, then switch on, or sending stops.', action: 'enable' }
    };
    var CONFIRM_OFF = 'Turn this wallet off? Messages will no longer be charged or blocked by balance.';

    function byId(id) { return document.getElementById(id); }

    function toast(kind, text) {
        var box = byId('pfwToasts');
        if (!box) { box = document.createElement('div'); box.id = 'pfwToasts'; box.setAttribute('aria-live', 'polite'); document.body.appendChild(box); }
        var t = document.createElement('div');
        t.className = 'pfw-toast is-' + kind;
        var ic = document.createElement('i');
        ic.className = 'fas fa-' + (kind === 'success' ? 'circle-check' : 'circle-exclamation');
        var sp = document.createElement('span');
        sp.textContent = text;
        t.appendChild(ic);
        t.appendChild(sp);
        box.appendChild(t);
        setTimeout(function () { t.classList.add('is-out'); setTimeout(function () { t.remove(); }, 300); }, kind === 'success' ? 3500 : 6500);
    }

    function applyPower(on) {
        var d = on ? POWER.on : POWER.off;
        byId('pfwPower').classList.toggle('is-on', on);
        byId('pfwPower').classList.toggle('is-off', !on);
        var pill = byId('pfwStatusPill');
        pill.classList.toggle('is-on', on);
        pill.classList.toggle('is-off', !on);
        pill.lastChild.textContent = ' ' + d.pill;
        byId('pfwPowerTitle').textContent = d.title;
        byId('pfwPowerSub').textContent = d.sub;
        var f = byId('pfwPowerForm');
        f.querySelector('[name=action]').value = d.action;
        f.querySelector('.pfw-switch').setAttribute('aria-checked', on ? 'true' : 'false');
        f.setAttribute('data-confirm', on ? CONFIRM_OFF : '');
    }

    function applyWallet(w) {
        if (!w) return;
        byId('pfwBalance').textContent = sym + w.balance;
        applyPower(!!w.enabled);
        var sf = byId('pfwSettingsForm');
        ['markup_percent', 'tax_percent', 'daily_cap', 'monthly_cap', 'low_balance_alert', 'auto_topup_amount'].forEach(function (n) {
            var el = sf.querySelector('[name=' + n + ']');
            if (el && w[n] !== undefined) el.value = w[n];
        });
    }

    function busy(form, on) {
        form.classList.toggle('is-busy', on);
        form.querySelectorAll('button').forEach(function (b) {
            if (on) { b.setAttribute('data-was', b.disabled ? '1' : '0'); b.disabled = true; } else { b.disabled = b.getAttribute('data-was') === '1'; }
        });
        var save = form.querySelector('.pfw-save');
        if (save) {
            if (on) { save.setAttribute('data-label', save.innerHTML); save.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving…'; }
            else if (save.getAttribute('data-label')) { save.innerHTML = save.getAttribute('data-label'); }
        }
    }

    document.querySelectorAll('form.pfw-ajax').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            if (form.classList.contains('is-busy')) return;
            var msg = form.getAttribute('data-confirm');
            if (msg && !window.confirm(msg)) return;
            if (!form.checkValidity()) { form.reportValidity(); return; }

            busy(form, true);
            fetch(form.getAttribute('action'), {
                method: 'POST',
                body: new FormData(form),
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
                credentials: 'same-origin'
            }).then(function (r) {
                return r.json().catch(function () { return {}; }).then(function (j) { return { status: r.status, body: j }; });
            }).then(function (res) {
                busy(form, false);
                if (res.body && res.body.ok) {
                    toast('success', res.body.message || 'Saved.');
                    applyWallet(res.body.wallet);
                    if (form.id === 'pfwMoneyForm') {
                        form.querySelector('[name=amount]').value = '';
                        form.querySelector('[name=note]').value = '';
                    }
                    if (window.PFW_TABLE) window.PFW_TABLE.ajax.reload(null, false);
                    return;
                }
                if (res.status === 419 || res.status === 403) {
                    toast('error', 'Your session has expired. Reload the page and try again.');
                } else {
                    toast('error', (res.body && res.body.message) || 'Could not save. Please try again.');
                }
            }).catch(function () {
                busy(form, false);
                toast('error', 'Connection problem. Nothing was changed. Check your internet and try again.');
            });
        });
    });
})();

document.querySelectorAll('.pfw-chips [data-amount]').forEach(function (b) {
    b.addEventListener('click', function () {
        var i = document.getElementById('pfwAmount');
        i.value = b.getAttribute('data-amount');
        i.focus();
    });
});
</script>

<style>
.pfw-kpis { display: grid; gap: 0.8rem; grid-template-columns: 1fr; margin-bottom: 0.8rem; }
@media (min-width: 768px) { .pfw-kpis { grid-template-columns: 1.3fr 1.3fr 1fr; } }
.pfw-kpi { margin: 0; display: flex; flex-direction: column; align-items: flex-start; gap: 0.45rem; padding: 1rem 1.15rem; }
.pfw-kpi-main { background: linear-gradient(135deg, #152029, #2a3642); border: 0; color: #fff; }
.pfw-eyebrow { font-size: 0.68rem; font-weight: 750; letter-spacing: 0.07em; text-transform: uppercase; color: var(--pf-muted); }
.pfw-kpi-main .pfw-eyebrow, .pfw-kpi-main .pfw-sub { color: rgba(255, 255, 255, 0.7); }
.pfw-balance { font-family: var(--pf-display); font-size: 2rem; font-weight: 800; letter-spacing: -0.02em; font-variant-numeric: tabular-nums; line-height: 1.1; }
.pfw-num { font-family: var(--pf-display); font-size: 1.6rem; font-weight: 800; font-variant-numeric: tabular-nums; }
.pfw-num small { font-size: 0.9rem; color: var(--pf-muted); font-weight: 600; }
.pfw-sub { font-size: 0.74rem; color: var(--pf-muted); line-height: 1.4; }
.pfw-status { display: inline-flex; align-items: center; gap: 0.4rem; font-weight: 800; font-size: 1rem; }
.pfw-status i { font-size: 0.5rem; }
.pfw-status.is-on { color: var(--pf-ok); }
.pfw-status.is-off { color: var(--pf-muted); }
.pfw-bar { width: 100%; height: 6px; border-radius: 99px; background: var(--pf-paper); overflow: hidden; }
.pfw-bar i { display: block; height: 100%; background: var(--pf-teal); border-radius: 99px; }
.pfw-layout { display: grid; gap: 0.8rem; grid-template-columns: 1fr; margin-bottom: 0.8rem; align-items: start; }
@media (min-width: 992px) { .pfw-layout { grid-template-columns: minmax(0, 0.8fr) minmax(0, 1.4fr); } }
.pfw-card { padding: 1.1rem 1.25rem; }
.pfw-layout .pfw-card { margin: 0; }
.pfw-head { display: flex; gap: 0.75rem; align-items: center; margin-bottom: 1rem; }
.pfw-head h3 { margin: 0; font-family: var(--pf-display); font-size: 0.98rem; font-weight: 800; letter-spacing: -0.015em; }
.pfw-head p { margin: 0.1rem 0 0; color: var(--pf-muted); font-size: 0.78rem; }
.pfw-ico { display: inline-grid; place-items: center; flex: none; width: 2.3rem; height: 2.3rem; border-radius: 10px; background: rgba(225, 29, 72, 0.08); color: var(--pf-teal-deep); }
.pfw-form { display: grid; gap: 0.85rem; }
.pfw-card .platform-input { height: 2.4rem; padding: 0.45rem 0.7rem; }
.pfw-seg { display: grid; grid-template-columns: 1fr 1fr; padding: 3px; gap: 3px; background: var(--pf-paper); border-radius: 10px; }
.pfw-seg label { position: relative; margin: 0; }
.pfw-seg input { position: absolute; opacity: 0; pointer-events: none; }
.pfw-seg span { display: flex; align-items: center; justify-content: center; gap: 0.4rem; padding: 0.45rem; border-radius: 8px; font-size: 0.82rem; font-weight: 700; color: var(--pf-muted); cursor: pointer; }
.pfw-seg input:checked + span { background: #fff; color: var(--pf-ink); box-shadow: 0 1px 3px rgba(21, 32, 41, 0.12); }
.pfw-seg input:focus-visible + span { outline: 2px solid var(--pf-teal); }
.pfw-money { display: flex; }
.pfw-money span { display: inline-flex; align-items: center; padding: 0 0.75rem; border: 1px solid #d4dcd6; border-right: 0; border-radius: 8px 0 0 8px; background: var(--pf-paper-2); font-weight: 700; color: var(--pf-ink-soft); }
.pfw-money .platform-input { border-radius: 0 8px 8px 0; flex: 1; min-width: 0; }
.pfw-save { justify-content: center; padding: 0.55rem 1.2rem; font-size: 0.84rem; }
.pfw-form .pfw-save { width: 100%; }
.pfw-group { padding-bottom: 1rem; margin-bottom: 1rem; border-bottom: 1px solid var(--pf-line); }
.pfw-group h4 { margin: 0 0 0.6rem; font-size: 0.7rem; font-weight: 800; letter-spacing: 0.07em; text-transform: uppercase; color: var(--pf-muted); }
.pfw-grid { display: grid; gap: 0.85rem; grid-template-columns: 1fr; }
@media (min-width: 700px) { .pfw-grid { grid-template-columns: 1fr 1fr; } }
.pfw-foot { display: flex; justify-content: flex-end; }
.pfw-table .num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
.pfw-table .nowrap { white-space: nowrap; color: var(--pf-muted); }
.pfw-table .is-in { color: var(--pf-ok); font-weight: 700; }
.pfw-table .is-out { color: var(--pf-down); font-weight: 700; }
.pfw-tag { display: inline-block; padding: 0.12rem 0.55rem; border-radius: 999px; font-size: 0.7rem; font-weight: 700; background: var(--pf-paper); color: var(--pf-ink-soft); }
.pfw-tag.is-credit { background: #e8f6ef; color: var(--pf-ok); }
.pfw-tag.is-refund { background: #eaf2fe; color: #1d4ed8; }
.pfw-tag.is-debit { background: #fdecea; color: var(--pf-down); }
.pfw-empty { text-align: center; color: var(--pf-muted); padding: 1.5rem; }

/* polish */
.pfw-kpi { justify-content: space-between; min-height: 9.5rem; padding: 1.15rem 1.3rem; gap: 0.75rem; }
.pfw-kpi-top { display: flex; justify-content: space-between; align-items: center; gap: 0.6rem; width: 100%; }
.pfw-kpi-ico { display: inline-grid; place-items: center; width: 2.2rem; height: 2.2rem; border-radius: 10px; background: rgba(255, 255, 255, 0.12); }
.pfw-balance { display: block; font-size: 2.3rem; margin-bottom: 0.25rem; }
.pfw-kpi-main .pfw-eyebrow { font-size: 0.74rem; color: rgba(255, 255, 255, 0.85); }
.pfw-sub { display: block; font-size: 0.78rem; }
.pfw-num { font-size: 1.9rem; line-height: 1; }
.pfw-pct { font-size: 0.74rem; font-weight: 800; color: var(--pf-muted); }
.pfw-bar { height: 8px; margin-bottom: 0.4rem; }
.pfw-status { padding: 0.18rem 0.65rem; border-radius: 999px; font-size: 0.76rem; }
.pfw-status.is-on { background: #e8f6ef; }
.pfw-status.is-off { background: var(--pf-paper); }
.pfw-card { padding: 1.3rem 1.4rem; }
.pfw-head { margin-bottom: 1.2rem; }
.pfw-head h3 { font-size: 1.05rem; }
.pfw-head p { font-size: 0.82rem; }
.pfw-card .platform-label { font-size: 0.8rem; margin-bottom: 0.35rem; }
.pfw-card .platform-help { font-size: 0.76rem; line-height: 1.45; margin-top: 0.35rem; }
.pfw-card .platform-input { height: 2.6rem; font-size: 0.92rem; }
.pfw-card .platform-input:focus { border-color: var(--pf-teal); }
.pfw-seg { padding: 4px; }
.pfw-seg span { padding: 0.55rem; font-size: 0.86rem; }
.pfw-seg label:first-child input:checked + span { color: var(--pf-ok); }
.pfw-seg label:last-child input:checked + span { color: var(--pf-down); }
.pfw-chips { display: flex; flex-wrap: wrap; gap: 0.4rem; margin-top: 0.55rem; }
.pfw-chips button { border: 1px solid var(--pf-line); background: #fff; border-radius: 999px; padding: 0.2rem 0.75rem; font-size: 0.76rem; font-weight: 700; color: var(--pf-ink-soft); cursor: pointer; }
.pfw-chips button:hover { border-color: var(--pf-teal); color: var(--pf-teal-deep); }
.pfw-save { padding: 0.7rem 1.4rem; font-size: 0.9rem; border-radius: 10px; }
.pfw-group h4 { font-size: 0.74rem; margin-bottom: 0.8rem; }
.pfw-group { padding-bottom: 1.2rem; margin-bottom: 1.2rem; }
.pfw-table th { font-size: 0.7rem; letter-spacing: 0.06em; text-transform: uppercase; color: var(--pf-muted); font-weight: 800; padding: 0.55rem 0.75rem; background: var(--pf-paper-2); }
.pfw-table td { padding: 0.8rem 0.75rem; font-size: 0.88rem; vertical-align: middle; }
.pfw-table .nowrap strong { display: block; color: var(--pf-ink); font-weight: 700; font-size: 0.86rem; }
.pfw-table .nowrap small { color: var(--pf-muted); font-size: 0.74rem; }
.pfw-table .num { font-size: 0.9rem; }
.pfw-tag { padding: 0.2rem 0.65rem; font-size: 0.74rem; }
.pfw-tag i { font-size: 0.65rem; margin-right: 0.15rem; }
.pfw-tag.is-credit i, .pfw-tag.is-refund i { transform: none; }
@media (max-width: 767px) { .pfw-table .num, .pfw-table td { font-size: 0.82rem; } .pfw-balance { font-size: 2rem; } }

/* layout v3: three setting panels side by side */
.pfw-kpis { align-items: stretch; }
.pfw-kpi { height: 100%; }
@media (min-width: 992px) { .pfw-layout { grid-template-columns: minmax(0, 0.75fr) minmax(0, 1.75fr); } }
.pfw-groups { display: grid; gap: 0.8rem; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); }
.pfw-groups .pfw-group { margin: 0; padding: 1rem; border: 1px solid var(--pf-line); border-radius: 12px; background: var(--pf-paper-2); display: flex; flex-direction: column; }
.pfw-groups .pfw-grid { grid-template-columns: 1fr; gap: 1rem; }
.pfw-groups .pfw-group h4 { color: var(--pf-ink-soft); }
.pfw-foot { margin-top: 1rem; padding-top: 1rem; border-top: 1px solid var(--pf-line); }
.pfw-table th.num { color: var(--pf-muted); }

/* DataTables skin */
.pfw-head .pfw-tools { margin-left: auto; }
.pfw-tools .platform-input { width: auto; min-width: 11rem; }
.pfw-tx-error { margin-bottom: 0.6rem; padding: 0.6rem 0.8rem; border-radius: 10px; background: #fef3f2; border: 1px solid #fecdca; color: #b42318; font-size: 0.84rem; }
.pfw-card .dataTables_wrapper { font-size: 0.84rem; color: var(--pf-ink-soft); }
.pfw-card .dataTables_wrapper .dataTables_filter { float: left; margin-bottom: 0.7rem; }
.pfw-card .dataTables_wrapper .dataTables_length { float: right; margin-bottom: 0.7rem; }
.pfw-card .dataTables_filter input { font-size: 0.86rem; margin: 0; width: 18rem; max-width: 100%; height: 2.4rem; border: 1px solid #d4dcd6; border-radius: 8px; padding: 0 0.75rem; font-family: var(--pf-font); }
.pfw-card .dataTables_filter input:focus, .pfw-card .dataTables_length select:focus { outline: none; border-color: var(--pf-teal); box-shadow: 0 0 0 3px rgba(225, 29, 72, 0.12); }
.pfw-card .dataTables_length select { height: 2.4rem; border: 1px solid #d4dcd6; border-radius: 8px; padding: 0 0.5rem; margin-left: 0.3rem; background: #fff; }
.pfw-card .dataTables_filter label { font-size: 0; }
.pfw-card .dataTables_length label { display: inline-flex; align-items: center; gap: 0.35rem; margin: 0; }
.pfw-table.dataTable { border-collapse: collapse !important; clear: both; margin: 0 !important; }
.pfw-table.dataTable thead th { border-bottom: 1px solid var(--pf-line); }
.pfw-table.dataTable.no-footer { border-bottom: 1px solid var(--pf-line); }
.pfw-table.dataTable tbody tr { background: transparent; }
.pfw-table.dataTable tbody td { border-bottom: 1px solid var(--pf-line); }
.pfw-table.dataTable tbody tr:hover { background: #f5f8f4; }
.pfw-table.dataTable thead .sorting, .pfw-table.dataTable thead .sorting_asc, .pfw-table.dataTable thead .sorting_desc { padding-right: 1.6rem; }
.pfw-table.dataTable thead th.num { text-align: right; }
.pfw-table.dataTable thead .sorting_asc, .pfw-table.dataTable thead .sorting_desc { color: var(--pf-ink); }
.pfw-card .dataTables_wrapper .dataTables_info { padding-top: 0.9rem; color: var(--pf-muted); font-size: 0.8rem; }
.pfw-card .dataTables_wrapper .dataTables_paginate { padding-top: 0.7rem; }
.pfw-card .dataTables_wrapper .dataTables_paginate .paginate_button { min-width: 2.1rem; height: 2.1rem; line-height: 2.1rem; padding: 0 0.5rem; margin-left: 0.25rem; border: 1px solid var(--pf-line) !important; border-radius: 8px !important; background: #fff !important; color: var(--pf-ink-soft) !important; font-weight: 700; box-sizing: border-box; }
.pfw-card .dataTables_wrapper .dataTables_paginate .paginate_button:hover { background: var(--pf-paper) !important; color: var(--pf-ink) !important; }
.pfw-card .dataTables_wrapper .dataTables_paginate .paginate_button.current, .pfw-card .dataTables_wrapper .dataTables_paginate .paginate_button.current:hover { background: var(--pf-teal) !important; border-color: var(--pf-teal) !important; color: #fff !important; }
.pfw-card .dataTables_wrapper .dataTables_paginate .paginate_button.disabled, .pfw-card .dataTables_wrapper .dataTables_paginate .paginate_button.disabled:hover { opacity: 0.45; background: #fff !important; color: var(--pf-muted) !important; cursor: default; }
.pfw-card .dataTables_wrapper .dataTables_processing { background: rgba(255, 255, 255, 0.9); border: 1px solid var(--pf-line); border-radius: 10px; color: var(--pf-ink-soft); font-weight: 700; padding: 0.6rem 1rem; height: auto; margin-top: -1.2rem; }
@media (max-width: 767px) { .pfw-card .dataTables_filter, .pfw-card .dataTables_length { float: none; } .pfw-card .dataTables_filter input { width: 100%; } .pfw-head .pfw-tools { margin-left: 0; width: 100%; } }

/* wallet power switch */
.pfw-power-row { display: flex; align-items: center; gap: 0.9rem; margin: 0; }
.pfw-power-row strong { display: block; font-size: 0.95rem; color: var(--pf-ink); }
.pfw-power-row .pfw-sub { margin-top: 0.15rem; }
.pfw-switch { flex: none; position: relative; width: 3.4rem; height: 1.9rem; border: 0; border-radius: 999px; background: #c9d1cc; cursor: pointer; transition: background 0.18s; padding: 0; }
.pfw-switch-knob { position: absolute; top: 3px; left: 3px; width: 1.5rem; height: 1.5rem; border-radius: 50%; background: #fff; box-shadow: 0 1px 4px rgba(21, 32, 41, 0.3); transition: transform 0.18s; }
.pfw-switch[aria-checked="true"] { background: var(--pf-ok); }
.pfw-switch[aria-checked="true"] .pfw-switch-knob { transform: translateX(1.5rem); }
.pfw-switch:focus-visible { outline: 3px solid rgba(225, 29, 72, 0.3); outline-offset: 2px; }
.pfw-switch:hover { filter: brightness(0.96); }
.pfw-power.is-on { border-color: rgba(27, 127, 90, 0.35); background: linear-gradient(180deg, #f3fbf7, #fff 65%); }
.pfw-power.is-off { background: linear-gradient(180deg, #f7f8f7, #fff 65%); }
.pfw-power.is-on .pfw-status i { animation: pfwPulse 1.8s ease-in-out infinite; }
@keyframes pfwPulse { 50% { opacity: 0.35; } }
@media (prefers-reduced-motion: reduce) { .pfw-power.is-on .pfw-status i { animation: none; } .pfw-switch, .pfw-switch-knob { transition: none; } }

/* toasts */
#pfwToasts { position: fixed; top: 1rem; right: 1rem; z-index: 2000; display: grid; gap: 0.5rem; max-width: min(24rem, calc(100vw - 2rem)); }
.pfw-toast { display: flex; gap: 0.6rem; align-items: flex-start; padding: 0.75rem 0.9rem; border-radius: 12px; background: #fff; border: 1px solid var(--pf-line); box-shadow: 0 10px 30px rgba(21, 32, 41, 0.16); font-size: 0.86rem; font-weight: 600; color: var(--pf-ink); animation: pfwIn 0.2s ease-out; transition: opacity 0.3s, transform 0.3s; }
.pfw-toast i { margin-top: 0.1rem; }
.pfw-toast.is-success { border-left: 4px solid var(--pf-ok); }
.pfw-toast.is-success i { color: var(--pf-ok); }
.pfw-toast.is-error { border-left: 4px solid var(--pf-down); }
.pfw-toast.is-error i { color: var(--pf-down); }
.pfw-toast.is-out { opacity: 0; transform: translateX(12px); }
@keyframes pfwIn { from { opacity: 0; transform: translateY(-8px); } }
form.is-busy { opacity: 0.75; }
form.is-busy .pfw-switch { cursor: progress; }
.pfw-head-save { margin-left: auto; white-space: nowrap; padding: 0.55rem 1.1rem; font-size: 0.86rem; }
@media (max-width: 575px) { .pfw-head { flex-wrap: wrap; } .pfw-head-save { width: 100%; margin-left: 0; justify-content: center; } }
</style>
<?= $this->endSection() ?>
