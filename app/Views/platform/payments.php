<?= $this->extend('layouts/platform') ?>

<?= $this->section('content') ?>
<?php
$cfg = $cfg ?? [];
$v   = static fn (string $k): string => (string) (old($k) ?? ($cfg[$k] ?? ''));
?>
<section class="platform-card">
    <div class="platform-card-head">
        <div>
            <h2>Online payments</h2>
            <p>Let clients recharge their WhatsApp wallet with Razorpay. Money goes to your Razorpay account, and the wallet is credited automatically.</p>
        </div>
        <span class="platform-badge <?= ($cfg['razorpay_enabled'] ?? '') === '1' && ! empty($cfg['razorpay_key_id']) ? 'platform-badge-ok' : 'platform-badge-warn' ?>">
            <?= ($cfg['razorpay_enabled'] ?? '') === '1' && ! empty($cfg['razorpay_key_id']) ? 'Enabled' : 'Off' ?>
        </span>
    </div>

    <form method="post" action="<?= site_url('platform/payments') ?>" class="platform-form-grid">
        <?= csrf_field() ?>
        <div class="full">
            <label><input type="checkbox" name="razorpay_enabled" value="1" <?= $v('razorpay_enabled') === '1' ? 'checked' : '' ?>> Enable online recharge for clients</label>
        </div>
        <div>
            <label class="platform-label" for="rzKey">Key id</label>
            <input class="platform-input" id="rzKey" name="razorpay_key_id" value="<?= esc($v('razorpay_key_id'), 'attr') ?>" placeholder="rzp_test_xxxxxxxx" autocomplete="off">
            <div class="platform-help">Razorpay Dashboard &rarr; Account &amp; Settings &rarr; API Keys. Start with the <strong>test</strong> key.</div>
        </div>
        <div>
            <label class="platform-label" for="rzSecret">Key secret</label>
            <input class="platform-input" id="rzSecret" type="password" name="razorpay_key_secret" value="" placeholder="<?= ! empty($hasSecret) ? '•••••••• saved (leave blank to keep)' : '' ?>" autocomplete="new-password">
        </div>
        <div class="full">
            <label class="platform-label" for="rzHook">Webhook secret</label>
            <input class="platform-input" id="rzHook" type="password" name="razorpay_webhook_secret" value="" placeholder="<?= ! empty($hasWebhook) ? '•••••••• saved (leave blank to keep)' : '' ?>" autocomplete="new-password">
            <div class="platform-help">
                In Razorpay &rarr; Webhooks add this URL and choose the events <strong>payment.captured</strong>, <strong>order.paid</strong> and <strong>payment_link.paid</strong>, then paste the secret you set there:
                <code><?= esc($webhookUrl) ?></code>. The webhook credits a wallet even if the client closes the browser after paying. It needs a public HTTPS address, so it will not reach <code>localhost</code>.
            </div>
        </div>
        <div class="full platform-help">
            <strong>GST on recharges:</strong> the GST % set on each client's wallet (Platform &rarr; client &rarr; Wallet) is added when the client pays online, and is shown on the invoice. Set it to 0 for no GST.
        </div>
        <div class="platform-form-row">
            <div>
                <label class="platform-label" for="rzMin">Minimum recharge</label>
                <input class="platform-input" id="rzMin" type="number" step="1" min="1" name="recharge_min" value="<?= esc($v('recharge_min'), 'attr') ?>">
            </div>
            <div>
                <label class="platform-label" for="rzMax">Maximum recharge</label>
                <input class="platform-input" id="rzMax" type="number" step="1" min="1" name="recharge_max" value="<?= esc($v('recharge_max'), 'attr') ?>">
            </div>
        </div>

        <div class="full"><h3 class="platform-section-title" style="margin-top:.5rem">Invoice details</h3></div>
        <div>
            <label class="platform-label" for="invCo">Company name</label>
            <input class="platform-input" id="invCo" name="invoice_company" maxlength="120" value="<?= esc($v('invoice_company'), 'attr') ?>">
        </div>
        <div>
            <label class="platform-label" for="invGst">GSTIN</label>
            <input class="platform-input" id="invGst" name="invoice_gstin" maxlength="20" value="<?= esc($v('invoice_gstin'), 'attr') ?>">
        </div>
        <div class="full">
            <label class="platform-label" for="invAddr">Address</label>
            <textarea class="platform-input" id="invAddr" name="invoice_address" rows="3" maxlength="400"><?= esc($v('invoice_address')) ?></textarea>
        </div>

        <div class="full" style="display:flex;gap:.6rem;flex-wrap:wrap">
            <button class="btn-pf btn-pf-primary" type="submit"><i class="fas fa-floppy-disk" aria-hidden="true"></i> Save</button>
        </div>
    </form>

    <form method="post" action="<?= site_url('platform/payments/test') ?>" style="margin-top:.8rem">
        <?= csrf_field() ?>
        <button class="btn-pf" type="submit"><i class="fas fa-plug" aria-hidden="true"></i> Test saved keys</button>
    </form>
</section>

<?php $m = static fn (string $k): string => (string) (old($k) ?? ($msw[$k] ?? '')); ?>
<section class="platform-card">
    <div class="platform-card-head">
        <div>
            <h2>Mswipe (Pay By Link)</h2>
            <p>A second way for clients to add money. The client is sent to a Mswipe payment page and the wallet is credited once Mswipe confirms the payment.</p>
        </div>
        <span class="platform-badge <?= ($msw['mswipe_enabled'] ?? '') === '1' && ! empty($msw['mswipe_user_id']) ? 'platform-badge-ok' : 'platform-badge-warn' ?>">
            <?= ($msw['mswipe_enabled'] ?? '') === '1' && ! empty($msw['mswipe_user_id']) ? 'Enabled (' . strtoupper((string) ($msw['mswipe_env'] ?? 'uat')) . ')' : 'Off' ?>
        </span>
    </div>

    <form method="post" action="<?= site_url('platform/payments/mswipe') ?>" class="platform-form-grid">
        <?= csrf_field() ?>
        <div class="full">
            <label><input type="checkbox" name="mswipe_enabled" value="1" <?= $m('mswipe_enabled') === '1' ? 'checked' : '' ?>> Enable Mswipe for client recharges</label>
        </div>
        <div>
            <label class="platform-label" for="mswEnv">Environment</label>
            <select class="platform-input" id="mswEnv" name="mswipe_env">
                <option value="uat" <?= $m('mswipe_env') !== 'live' ? 'selected' : '' ?>>UAT (testing)</option>
                <option value="live" <?= $m('mswipe_env') === 'live' ? 'selected' : '' ?>>Live</option>
            </select>
            <div class="platform-help">Use UAT with the test credentials Mswipe gave you, then switch to Live with the live ones.</div>
        </div>
        <div>
            <label class="platform-label" for="mswCust">Customer code (custcode)</label>
            <input class="platform-input" id="mswCust" name="mswipe_custcode" value="<?= esc($m('mswipe_custcode'), 'attr') ?>" autocomplete="off">
        </div>
        <div>
            <label class="platform-label" for="mswUser">User id</label>
            <input class="platform-input" id="mswUser" name="mswipe_user_id" value="<?= esc($m('mswipe_user_id'), 'attr') ?>" autocomplete="off">
        </div>
        <div>
            <label class="platform-label" for="mswClient">Client id</label>
            <input class="platform-input" id="mswClient" name="mswipe_client_id" value="<?= esc($m('mswipe_client_id'), 'attr') ?>" autocomplete="off">
        </div>
        <div>
            <label class="platform-label" for="mswPass">Password</label>
            <input class="platform-input" id="mswPass" type="password" name="mswipe_password" value="" placeholder="<?= ! empty($mswHasPassword) ? '•••••••• saved (leave blank to keep)' : '' ?>" autocomplete="new-password">
        </div>
        <div>
            <label class="platform-label" for="mswMobile">Fallback mobile (10 digits)</label>
            <input class="platform-input" id="mswMobile" name="mswipe_fallback_mobile" maxlength="15" value="<?= esc($m('mswipe_fallback_mobile'), 'attr') ?>" inputmode="numeric">
            <div class="platform-help">Mswipe needs a customer mobile number. The paying user's own number is used; this is used when the user has none.</div>
        </div>
        <div class="full platform-help">
            Ask Mswipe to register this <strong>back-posting URL</strong> for your account:
            <code><?= esc($mswBackPostUrl) ?></code> (public HTTPS). Mswipe does not sign it, so it is only a trigger: money is credited only after Mswipe's status API confirms the payment.
            Add <code>php spark payments:reconcile</code> to cron (every 10 minutes) so payments are credited even if the customer closes the Mswipe page and no back-post arrives.
        </div>
        <div class="full" style="display:flex;gap:.6rem;flex-wrap:wrap">
            <button class="btn-pf btn-pf-primary" type="submit"><i class="fas fa-floppy-disk" aria-hidden="true"></i> Save Mswipe</button>
        </div>
    </form>
    <form method="post" action="<?= site_url('platform/payments/mswipe/test') ?>" style="margin-top:.8rem">
        <?= csrf_field() ?>
        <button class="btn-pf" type="submit"><i class="fas fa-plug" aria-hidden="true"></i> Test saved credentials</button>
    </form>
</section>
<?= $this->endSection() ?>
