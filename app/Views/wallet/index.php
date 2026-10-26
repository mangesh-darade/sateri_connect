<?= $this->extend('layouts/main') ?>

<?= $this->section('header_actions') ?>
<?php if (function_exists('can') && can('reports.view')): ?>
    <a class="btn btn-wa btn-sm" href="<?= site_url('reports/transactions') ?>"><i class="fas fa-receipt me-1"></i> Transaction report</a>
<?php endif; ?>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php
$wallet   = $wallet ?? [];
$enabled  = (int) ($wallet['enabled'] ?? 0) === 1;
$balance  = (float) ($wallet['balance'] ?? 0);
$currency = (string) ($wallet['currency'] ?? 'INR');
$symbol   = $currency === 'INR' ? '₹' : $currency . ' ';
$money    = static fn (float $n, int $dp = 2): string => number_format($n, $dp);
$typeLabel = ['credit' => 'Recharge', 'debit' => 'Message / deduction', 'refund' => 'Refund'];
?>
<div class="page-list">

<?php $hState = (string) (($health ?? [])['state'] ?? 'off'); ?>
<?php if ($hState === 'empty'): ?>
    <div class="alert alert-danger d-flex align-items-start gap-2" role="alert">
        <i class="fas fa-circle-exclamation mt-1"></i>
        <div><strong>Your WhatsApp wallet is empty.</strong> Messages and campaigns are blocked until your platform admin adds money.</div>
    </div>
<?php elseif ($hState === 'low'): ?>
    <div class="alert alert-warning d-flex align-items-start gap-2" role="alert">
        <i class="fas fa-triangle-exclamation mt-1"></i>
        <div><strong>Your WhatsApp wallet balance is low.</strong> It is below <?= esc($symbol) ?><?= $money((float) $health['threshold']) ?>. Ask your platform admin to add money before you run out.</div>
    </div>
<?php endif; ?>

<?php
$lim = $limits ?? ['daily' => ['cap' => 0, 'spent' => 0], 'monthly' => ['cap' => 0, 'spent' => 0]];
$hasLimit = $lim['daily']['cap'] > 0 || $lim['monthly']['cap'] > 0;
$rc  = $recharge ?? ['available' => false];
$gws = $rc['gateways'] ?? [];
$gstTxt = rtrim(rtrim(number_format((float) ($wallet['tax_percent'] ?? 0), 2, '.', ''), '0'), '.');
?>

<?php foreach (($pendingPayments ?? []) as $pp): ?>
    <div class="wallet-pending" role="status" data-pending-order="<?= esc((string) $pp['order_id'], 'attr') ?>">
        <i class="fas fa-hourglass-half"></i>
        <div class="wallet-pending-text">
            <strong>You started a payment of <?= esc($symbol) ?><?= $money((float) $pp['total_amount']) ?> that is not complete yet</strong>
            <span>Started <?= esc(\App\Libraries\AppDateTime::format($pp['created_at'] ?? null, 'd M Y, h:i A', (string) $pp['created_at'])) ?>. If you already paid on Mswipe, your wallet is credited automatically within a few minutes. Use "Check now" to refresh. If you did not pay, you can dismiss this; unpaid links expire on their own.</span>
        </div>
        <div class="wallet-pending-actions">
            <a class="btn btn-sm btn-wa" href="<?= site_url('wallet/recharge/return?order=' . rawurlencode((string) $pp['order_id'])) ?>"><i class="fas fa-rotate me-1"></i> Check now</a>
            <button type="button" class="btn btn-sm btn-outline-secondary" data-pending-dismiss>Dismiss</button>
        </div>
    </div>
<?php endforeach; ?>

<script>
(function () {
    // Hide a notice for good (this browser only). The payment itself is still checked in the background.
    var KEY = 'walletPendingDismissed';
    var done = [];
    try { done = JSON.parse(localStorage.getItem(KEY) || '[]'); } catch (e) { done = []; }
    document.querySelectorAll('[data-pending-order]').forEach(function (box) {
        var id = box.getAttribute('data-pending-order');
        if (done.indexOf(id) !== -1) { box.remove(); return; }
        box.querySelector('[data-pending-dismiss]').addEventListener('click', function () {
            done.push(id);
            try { localStorage.setItem(KEY, JSON.stringify(done.slice(-50))); } catch (e) { /* private mode */ }
            box.remove();
        });
    });
})();
</script>

<div class="row g-3 mb-3">
    <!-- Balance -->
    <div class="col-lg-4">
        <div class="card h-100 wallet-card">
            <div class="card-body">
                <div class="wallet-eyebrow">Available balance</div>
                <div class="wallet-balance"><?= esc($symbol) ?><?= $money($balance) ?></div>
                <div class="mt-1">
                    <?php if ($enabled): ?>
                        <span class="badge bg-success">Wallet on</span>
                    <?php else: ?>
                        <span class="badge bg-secondary">Wallet off</span>
                        <span class="small text-muted ms-1">Messages are not charged yet.</span>
                    <?php endif; ?>
                </div>
                <dl class="wallet-facts">
                    <?php if ((float) ($wallet['low_balance_alert'] ?? 0) > 0): ?>
                        <div><dt>Low-balance alert</dt><dd>below <?= esc($symbol) ?><?= $money((float) $wallet['low_balance_alert']) ?></dd></div>
                    <?php endif; ?>
                    <?php if ((float) ($wallet['tax_percent'] ?? 0) > 0): ?>
                        <div><dt>GST on charges</dt><dd><?= esc($gstTxt) ?>%</dd></div>
                    <?php endif; ?>
                    <div><dt>Free service replies</dt><dd><?= number_format((int) $freeUsed) ?> / <?= number_format((int) $freeLimit) ?> this month</dd></div>
                </dl>
            </div>
        </div>
    </div>

    <!-- Add money -->
    <div class="col-lg-8">
        <div class="card h-100 wallet-card">
            <div class="card-body">
                <h2 class="wallet-title"><i class="fas fa-circle-plus"></i> Add money</h2>
                <?php if (empty($rc['available'])): ?>
                    <div class="alert alert-light border small mb-0"><i class="fas fa-circle-info me-1"></i> <?= esc((string) ($rc['reason'] ?? 'Online payment is not available.')) ?></div>
                <?php elseif (! empty($rc['canPay'])): ?>
                    <form id="walletRechargeForm" class="wallet-recharge" autocomplete="off"
                          data-order-url="<?= site_url('wallet/recharge/order') ?>" data-verify-url="<?= site_url('wallet/recharge/verify') ?>"
                          data-symbol="<?= esc($symbol, 'attr') ?>" data-gst="<?= esc((string) $rc['gst'], 'attr') ?>" data-min="<?= esc((string) $rc['min'], 'attr') ?>" data-max="<?= esc((string) $rc['max'], 'attr') ?>">

                        <div class="wallet-label">Amount</div>
                        <div class="wallet-quick" role="group" aria-label="Quick amounts">
                            <?php foreach ([500, 1000, 2000, 5000, 10000] as $amt): ?>
                                <?php if ($amt >= $rc['min'] && $amt <= $rc['max']): ?><button type="button" class="wallet-chip-btn" data-amount="<?= $amt ?>"><?= esc($symbol) ?><?= number_format($amt) ?></button><?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                        <div class="input-group wallet-amount">
                            <span class="input-group-text"><?= esc($symbol) ?></span>
                            <input type="number" class="form-control" id="walletRechargeAmount" min="<?= esc((string) $rc['min'], 'attr') ?>" max="<?= esc((string) $rc['max'], 'attr') ?>" step="1" placeholder="Enter amount" aria-label="Amount to add">
                        </div>
                        <div class="wallet-hint">Between <?= esc($symbol) ?><?= number_format((float) $rc['min']) ?> and <?= esc($symbol) ?><?= number_format((float) $rc['max']) ?>.</div>

                        <?php if (count($gws) > 1): ?>
                            <div class="wallet-label mt-3">Pay with</div>
                            <div class="wallet-gateways" role="radiogroup" aria-label="Payment method">
                                <?php $first = true; foreach ($gws as $gk => $gl): ?>
                                    <label class="wallet-gw"><input type="radio" name="gateway" value="<?= esc($gk, 'attr') ?>" <?= $first ? 'checked' : '' ?>><span><?= esc($gl) ?></span></label>
                                <?php $first = false; endforeach; ?>
                            </div>
                        <?php else: ?>
                            <input type="hidden" name="gateway" value="<?= esc((string) array_key_first($gws), 'attr') ?>">
                        <?php endif; ?>

                        <div class="wallet-alert d-none" id="walletRechargeAlert" role="alert"></div>
                        <div class="wallet-summary" id="walletRechargeSummary" aria-live="polite">
                            <?= (float) $rc['gst'] > 0 ? 'GST ' . esc(rtrim(rtrim(number_format((float) $rc['gst'], 2, '.', ''), '0'), '.')) . '% is added at payment.' : 'No GST is added.' ?>
                        </div>
                        <button type="submit" class="btn btn-wa wallet-pay" id="walletRechargeBtn"><i class="fas fa-lock me-1"></i> Pay securely</button>
                    </form>
                <?php else: ?>
                    <p class="small text-muted mb-0">You can view the wallet, but adding money needs the settings permission.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mb-3">
    <?php if ($hasLimit): ?>
    <div class="col-lg-5">
        <div class="card h-100 wallet-card">
            <div class="card-body">
                <h2 class="wallet-title"><i class="fas fa-gauge-high"></i> Spending limits</h2>
                <?php foreach (['daily' => 'Today', 'monthly' => 'This month'] as $k => $label): ?>
                    <?php $c = $lim[$k]; if ($c['cap'] <= 0) { continue; } $pct = min(100, ($c['spent'] / $c['cap']) * 100); ?>
                    <div class="mb-3">
                        <div class="d-flex justify-content-between small mb-1"><span><?= esc($label) ?></span><span><?= esc($symbol) ?><?= $money($c['spent']) ?> / <?= esc($symbol) ?><?= $money($c['cap']) ?></span></div>
                        <div class="progress" style="height:7px"><div class="progress-bar <?= $pct >= 100 ? 'bg-danger' : ($pct >= 80 ? 'bg-warning' : 'bg-success') ?>" style="width:<?= number_format($pct, 1, '.', '') ?>%"></div></div>
                    </div>
                <?php endforeach; ?>
                <p class="small text-muted mb-0">Sending pauses when a limit is reached. Your platform admin can change the limits.</p>
            </div>
        </div>
    </div>
    <?php endif; ?>
    <div class="col-lg-<?= $hasLimit ? '7' : '12' ?>">
        <div class="card h-100 wallet-card">
            <div class="card-body">
                <h2 class="wallet-title"><i class="fas fa-circle-question"></i> How WhatsApp billing works</h2>
                <ul class="small text-muted mb-0 ps-3 wallet-list">
                    <li>Meta charges per delivered message, by category and the receiver's country.</li>
                    <li>Marketing, utility and authentication templates are charged per message.</li>
                    <li>Free-form replies inside the 24-hour window are free for the first <?= number_format((int) $freeLimit) ?> each month (<?= number_format((int) $freeUsed) ?> used), then charged.</li>
                    <li>Messages that fail, or that Meta does not bill (for example Click-to-WhatsApp ad replies), are refunded.</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header d-flex align-items-center justify-content-between">
        <h2 class="card-title mb-0">Latest transactions</h2>
        <?php if (function_exists('can') && can('reports.view')): ?>
            <a class="small" href="<?= site_url('reports/transactions') ?>">Full report</a>
        <?php endif; ?>
    </div>
    <div class="card-body py-3">
        <div class="wallet-table-tools">
            <select id="walletTxType" class="form-select form-select-sm" aria-label="Filter by type">
                <option value="">All types</option>
                <option value="credit">Recharge</option>
                <option value="debit">Message / deduction</option>
                <option value="refund">Refund</option>
            </select>
        </div>
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0 wallet-dt" id="walletTx" data-url="<?= site_url('wallet/transactions') ?>" data-symbol="<?= esc($symbol, 'attr') ?>" style="width:100%">
                <thead>
                    <tr><th>Date</th><th>Type</th><th>Details</th><th class="text-end">Amount</th><th class="text-end">Balance</th></tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
    </div>
</div>
</div>

<?php if (! empty($hasPayments)): ?>
<div class="card mt-3">
    <div class="card-header"><h2 class="card-title mb-0">Online recharges and invoices</h2></div>
    <div class="card-body py-3">
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0 wallet-dt" id="walletPay" data-url="<?= site_url('wallet/payments') ?>" data-symbol="<?= esc($symbol, 'attr') ?>" style="width:100%">
                <thead><tr><th>Date</th><th>Invoice</th><th class="text-end">Added to wallet</th><th class="text-end">GST</th><th class="text-end">Paid</th><th></th></tr></thead>
                <tbody></tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<?php if (! empty($recharge['available']) && ! empty($recharge['canPay'])): ?>
<?php if (isset($recharge['gateways']['razorpay'])): ?><script src="https://checkout.razorpay.com/v1/checkout.js"></script><?php endif; ?>
<script src="<?= base_url('assets/js/wallet-recharge.js') ?>?v=3"></script>
<?php endif; ?>
<script src="<?= base_url('assets/js/wallet-tables.js') ?>?v=1"></script>
<?= $this->endSection() ?>
