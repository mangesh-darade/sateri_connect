<?php
// Abandoned cart reminders tab (WooCommerce, Shopify). Variables come from Settings::index.
$canEdit   = ! empty($canEditSettings);
$crOn      = ! empty($cartCfg['enabled']);
$crTotal   = (int) array_sum($cartOverview['counts']);
$crPlats   = \App\Libraries\CartRecoveryService::PLATFORMS;
$crDisable = $canEdit ? '' : 'disabled';
?>
<div id="crPage" data-base="<?= esc($settingsBase . '/cart-reminders', 'attr') ?>">

<?php foreach (($cartHealth ?? []) as $h): ?>
    <div class="alert alert-<?= esc($h['level'], 'attr') ?> py-2 small mb-2" role="alert"><?= esc($h['message']) ?></div>
<?php endforeach; ?>

<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <div class="d-flex flex-wrap gap-3 small">
        <?php foreach (['open' => 'Waiting', 'reminded' => 'Reminded', 'recovered' => 'Recovered', 'skipped' => 'Skipped'] as $k => $label): ?>
            <span><span class="text-muted"><?= $label ?></span> <strong><?= (int) $cartOverview['counts'][$k] ?></strong></span>
        <?php endforeach; ?>
        <span><span class="text-muted">Recovered value</span> <strong><?= esc(number_format((float) $cartOverview['recovered_amount'], 2)) ?></strong></span>
    </div>
    <div class="btn-group btn-group-sm">
        <button type="button" class="btn btn-outline-secondary" id="crExpand"><i class="fas fa-angles-down me-1"></i> Expand all</button>
        <button type="button" class="btn btn-outline-secondary" id="crCollapse"><i class="fas fa-angles-up me-1"></i> Collapse all</button>
    </div>
</div>

<div class="accordion" id="crAccordion">

    <div class="accordion-item border-0 shadow-sm rounded mb-3 overflow-hidden">
        <h2 class="accordion-header">
            <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#crAccSettings" aria-expanded="true" aria-controls="crAccSettings">
                <i class="fas fa-sliders fa-fw me-3 text-wa" aria-hidden="true"></i>
                <span class="me-auto"><span class="fw-bold d-block">Reminder settings</span><span class="small text-muted">Template, timing and consent</span></span>
                <span class="badge <?= $crOn ? 'bg-success' : 'bg-secondary' ?> ms-2 me-3"><?= $crOn ? 'Active' : 'Off' ?></span>
            </button>
        </h2>
        <div id="crAccSettings" class="accordion-collapse collapse show cr-acc-panel">
            <div class="accordion-body">
                <div id="crForm">
                    <div class="row g-3">
                        <div class="col-12">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" role="switch" id="crEnabled" data-cr="enabled" value="1" <?= $crOn ? 'checked' : '' ?> <?= $crDisable ?>>
                                <label class="form-check-label" for="crEnabled">Send WhatsApp reminders for abandoned carts</label>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small mb-1" for="crTemplate">Approved template</label>
                            <select class="form-select form-select-sm" id="crTemplate" data-cr="template_name" <?= $crDisable ?>>
                                <option value="">Choose a template</option>
                                <?php foreach ($cartTemplates as $t): ?>
                                    <option value="<?= esc((string) $t['name'], 'attr') ?>" data-lang="<?= esc((string) $t['language'], 'attr') ?>" <?= $t['name'] === $cartCfg['template_name'] ? 'selected' : '' ?>><?= esc((string) $t['name']) ?> (<?= esc((string) $t['language']) ?>)</option>
                                <?php endforeach; ?>
                            </select>
                            <input type="hidden" data-cr="template_language" id="crLang" value="<?= esc($cartCfg['template_language'], 'attr') ?>">
                            <div class="form-text">Template variables, in order: {{1}} customer name, {{2}} items, {{3}} total, {{4}} checkout link.</div>
                        </div>
                        <div class="col-6 col-md-2">
                            <label class="form-label small mb-1" for="crDelay">Send after (min)</label>
                            <input type="number" class="form-control form-control-sm" id="crDelay" data-cr="delay_minutes" min="2" max="10080" value="<?= (int) $cartCfg['delay_minutes'] ?>" <?= $crDisable ?>>
                        </div>
                        <div class="col-6 col-md-2">
                            <label class="form-label small mb-1" for="crMax">Max reminders</label>
                            <input type="number" class="form-control form-control-sm" id="crMax" data-cr="max_reminders" min="1" max="3" value="<?= (int) $cartCfg['max_reminders'] ?>" <?= $crDisable ?>>
                        </div>
                        <div class="col-6 col-md-2">
                            <label class="form-label small mb-1" for="crGap">Gap (hours)</label>
                            <input type="number" class="form-control form-control-sm" id="crGap" data-cr="gap_hours" min="1" max="168" value="<?= (int) $cartCfg['gap_hours'] ?>" <?= $crDisable ?>>
                        </div>
                        <div class="col-6 col-md-2">
                            <label class="form-label small mb-1" for="crCc">Country code</label>
                            <input type="text" class="form-control form-control-sm" id="crCc" data-cr="country_code" maxlength="4" value="<?= esc($cartCfg['country_code'], 'attr') ?>" <?= $crDisable ?>>
                            <div class="form-text">Added to 10 digit numbers.</div>
                        </div>
                        <div class="col-12">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="crOptin" data-cr="assume_optin" value="1" <?= $cartCfg['assume_optin'] ? 'checked' : '' ?> <?= $crDisable ?>>
                                <label class="form-check-label" for="crOptin">My checkout asks customers for WhatsApp consent (record it as opt-in)</label>
                            </div>
                            <div class="form-text">Leave off unless that is true. Without a recorded opt-in, reminders to that customer are skipped.</div>
                        </div>
                    </div>
                </div>
                <?php if ($canEdit): ?>
                    <div class="mt-3"><button type="button" class="btn btn-wa btn-sm js-cr-save"><i class="fas fa-save me-1"></i> Save cart reminder settings</button></div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="accordion-item border-0 shadow-sm rounded mb-3 overflow-hidden">
        <h2 class="accordion-header">
            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#crAccWoo" aria-expanded="false" aria-controls="crAccWoo">
                <i class="fab fa-wordpress fa-fw me-3 text-wa" aria-hidden="true"></i>
                <span class="me-auto"><span class="fw-bold d-block">WooCommerce</span><span class="small text-muted">Order created and order updated webhooks</span></span>
            </button>
        </h2>
        <div id="crAccWoo" class="accordion-collapse collapse cr-acc-panel">
            <div class="accordion-body small">
                <ol class="ps-3 mb-3">
                    <li>WordPress admin, WooCommerce, Settings, Advanced, Webhooks, Add webhook.</li>
                    <li>Topic <strong>Order created</strong>, status Active, API version <strong>WP REST API Integration v3</strong>. Use the URL and secret below.</li>
                    <li>Add a second webhook the same way with topic <strong>Order updated</strong>.</li>
                </ol>
                <label class="form-label small mb-1">Delivery URL</label>
                <input type="text" class="form-control form-control-sm font-monospace mb-2" value="<?= esc($cartUrls['woocommerce'], 'attr') ?>" readonly>
                <?php if ($canEdit): ?>
                    <label class="form-label small mb-1" for="crSecret">Secret</label>
                    <div class="input-group input-group-sm mb-2" style="max-width:560px">
                        <input type="text" class="form-control font-monospace" id="crSecret" value="<?= esc($cartSecret, 'attr') ?>" readonly>
                        <button type="button" class="btn btn-outline-secondary" id="crRotate">Create new secret</button>
                    </div>
                <?php endif; ?>
                <div class="text-muted">An unpaid order (pending or failed) counts as an abandoned cart. A paid order stops the reminders.</div>
            </div>
        </div>
    </div>

    <div class="accordion-item border-0 shadow-sm rounded mb-3 overflow-hidden">
        <h2 class="accordion-header">
            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#crAccShopify" aria-expanded="false" aria-controls="crAccShopify">
                <i class="fab fa-shopify fa-fw me-3 text-wa" aria-hidden="true"></i>
                <span class="me-auto"><span class="fw-bold d-block">Shopify</span><span class="small text-muted">Checkout and order webhooks</span></span>
                <span class="badge <?= ! empty($cartShopifySet) ? 'bg-success' : 'bg-secondary' ?> ms-2 me-3"><?= ! empty($cartShopifySet) ? 'Key saved' : 'Key not set' ?></span>
            </button>
        </h2>
        <div id="crAccShopify" class="accordion-collapse collapse cr-acc-panel">
            <div class="accordion-body small">
                <div class="fw-semibold mb-1">Before you start</div>
                <ul class="ps-3 mb-3">
                    <li>The webhook URL must be reachable from the internet (a live <code>https://</code> address, not <code>localhost</code>).</li>
                    <li>Turn on the reminders and choose the template in <strong>Reminder settings</strong> above.</li>
                </ul>
                <div class="fw-semibold mb-1">Create the 3 webhooks (repeat for each event)</div>
                <ol class="ps-3 mb-3">
                    <li>Shopify admin, <strong>Settings</strong> (bottom left), <strong>Notifications</strong>.</li>
                    <li>Scroll down to <strong>Webhooks</strong> and click <strong>Create webhook</strong>.</li>
                    <li><strong>Event</strong>: choose <em>Checkout creation</em>. <strong>Format</strong>: <em>JSON</em>. <strong>URL</strong>: the Webhook URL below. <strong>Webhook API version</strong>: the latest stable one.</li>
                    <li>Click <strong>Save</strong>. Create two more the same way with events <em>Checkout update</em> and <em>Order creation</em>.</li>
                </ol>
                <div class="fw-semibold mb-1">Connect the signing key</div>
                <ol class="ps-3 mb-3" start="5">
                    <li>On the same Webhooks list, below the table, Shopify shows <em>"Your webhooks will be signed with ..."</em> followed by a long key. Copy it.</li>
                    <li>Paste it in <strong>Shopify signing key</strong> below and click <strong>Save</strong>. The badge above changes to "Key saved".</li>
                </ol>
                <div class="fw-semibold mb-1">Test</div>
                <ol class="ps-3 mb-3" start="7">
                    <li>Open your store as a customer, add a product to the cart and go to checkout.</li>
                    <li>Enter an email, the <strong>phone number</strong> and the address, then close the tab without paying.</li>
                    <li>Within a minute the cart appears in the <strong>Carts</strong> section here with status <em>Waiting</em>. After the "Send after" delay the reminder is queued and the status becomes <em>Reminded</em>. If the customer pays, it becomes <em>Recovered</em>.</li>
                </ol>
                <div class="fw-semibold mb-1">If it does not work</div>
                <ul class="ps-3 mb-3">
                    <li>No cart appears: check the signing key and that the URL is public. Shopify shows failed deliveries on the webhook list.</li>
                    <li>Cart shows "No phone": the customer did not enter one, or your Shopify plan or app permissions hide customer data from checkout webhooks.</li>
                    <li>Cart shows "Skipped" with a consent message: the customer has no recorded WhatsApp opt-in (see the consent option in Reminder settings).</li>
                </ul>
                <label class="form-label small mb-1">Webhook URL</label>
                <input type="text" class="form-control form-control-sm font-monospace mb-2" value="<?= esc($cartUrls['shopify'], 'attr') ?>" readonly>
                <?php if ($canEdit): ?>
                    <label class="form-label small mb-1" for="crShopifySecret">Shopify signing key</label>
                    <input type="password" class="form-control form-control-sm font-monospace mb-2" style="max-width:560px" id="crShopifySecret" data-cr="shopify_secret" autocomplete="off" placeholder="<?= ! empty($cartShopifySet) ? 'Leave blank to keep the saved key' : 'Paste the key from Shopify' ?>">
                    <button type="button" class="btn btn-wa btn-sm mb-2 js-cr-save"><i class="fas fa-save me-1"></i> Save</button>
                <?php endif; ?>
                <div class="text-muted">Only checkouts where the customer entered a phone number can get a reminder.</div>
            </div>
        </div>
    </div>

    <div class="accordion-item border-0 shadow-sm rounded mb-3 overflow-hidden">
        <h2 class="accordion-header">
            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#crAccCarts" aria-expanded="false" aria-controls="crAccCarts">
                <i class="fas fa-shopping-cart fa-fw me-3 text-wa" aria-hidden="true"></i>
                <span class="me-auto"><span class="fw-bold d-block">Carts</span><span class="small text-muted">Latest carts received from your stores</span></span>
                <span class="badge bg-secondary ms-2 me-3"><?= $crTotal ?></span>
            </button>
        </h2>
        <div id="crAccCarts" class="accordion-collapse collapse cr-acc-panel">
            <div class="accordion-body">
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead><tr><th>Store</th><th>Customer</th><th>Items</th><th class="text-end">Total</th><th>Status</th><th class="text-end">Reminders</th><th>Last activity</th><?php if ($canEdit): ?><th class="text-end">Action</th><?php endif; ?></tr></thead>
                        <tbody>
                        <?php if (empty($cartOverview['rows'])): ?>
                            <tr><td colspan="8" class="text-center text-muted py-4">No carts yet. They appear when your store sends an event.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($cartOverview['rows'] as $c): ?>
                            <?php $badge = ['open' => 'warning', 'reminded' => 'info', 'recovered' => 'success', 'skipped' => 'secondary'][$c['status']] ?? 'secondary'; ?>
                            <tr>
                                <td><?= esc($crPlats[$c['platform']] ?? $c['platform']) ?></td>
                                <td><?= esc($c['customer_name'] !== '' ? $c['customer_name'] : '-') ?><div class="small text-muted"><?= esc($c['phone'] !== '' ? $c['phone'] : 'No phone') ?></div></td>
                                <td class="small"><?= esc($c['items_text']) ?></td>
                                <td class="text-end"><?= esc($c['total']) ?></td>
                                <td><span class="badge bg-<?= $badge ?>"><?= esc(ucfirst((string) $c['status'])) ?></span><?php if ($c['last_error'] !== ''): ?><div class="small text-muted"><?= esc($c['last_error']) ?></div><?php endif; ?>
                                    <?php if (! empty($c['delivery_status'])): ?>
                                        <?php $dl = ['sent' => ['success', 'Delivered to WhatsApp'], 'failed' => ['danger', 'Delivery failed'], 'cancelled' => ['secondary', 'Cancelled']][$c['delivery_status']] ?? ['info', 'Sending']; ?>
                                        <div class="small text-<?= $dl[0] ?>"><?= esc($dl[1]) ?><?= ($c['delivery_status'] === 'failed' && (string) $c['delivery_error'] !== '') ? ': ' . esc(mb_substr((string) $c['delivery_error'], 0, 160)) : '' ?></div>
                                    <?php endif; ?></td>
                                <td class="text-end"><?= (int) $c['reminders_sent'] ?></td>
                                <td class="small"><?= esc(format_app_datetime($c['last_activity_at'], 'd M Y, g:i A')) ?></td>
                                <?php if ($canEdit): ?>
                                    <td class="text-end text-nowrap">
                                        <?php if ($c['status'] !== 'recovered' && $c['phone'] !== ''): ?>
                                            <button type="button" class="btn btn-sm btn-outline-secondary js-cr-send" data-id="<?= (int) $c['id'] ?>" title="Send the reminder now"><i class="fab fa-whatsapp me-1"></i> Send now</button>
                                        <?php endif; ?>
                                    </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

</div>
</div>
