<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>
<?php
$provider = $provider ?? 'cheerio';
$isMeta   = $provider === 'meta';
$emailProvider = $emailProvider ?? 'smtp';
$isSendGridEmail = $emailProvider === 'sendgrid';
$isCheerioEmail  = $emailProvider === 'cheerio';
$isSmtpEmail     = ! $isSendGridEmail && ! $isCheerioEmail;
$cheerio  = $cheerio ?? [];
$meta     = $meta ?? [];
$app      = $app ?? [];
$smtp     = $smtp ?? [];
$sendgrid = $sendgrid ?? [];
$cheerioEmail = $cheerioEmail ?? [];
$webhook  = $webhook ?? [];
$val = static function (array $source, string $key, string $default = '') {
    return esc(old($key) ?? ($source[$key] ?? $default));
};
$providerLabel = $isMeta ? 'Meta Cloud API' : 'Cheerio Direct API';
$emailProviderLabel = $isSendGridEmail ? 'SendGrid' : ($isCheerioEmail ? 'Cheerio Email API' : 'SMTP');
?>
<div class="settings-shell page-stack">
    <div class="settings-intro">
        <div class="settings-intro-meta mb-0">
            <span class="settings-pill">
                <span class="settings-pill-dot"></span>
                WhatsApp: <strong><?= esc($isMeta ? 'Meta' : 'Cheerio') ?></strong>
            </span>
            <span class="settings-pill settings-pill-muted">
                Email: <strong><?= esc($emailProviderLabel) ?></strong>
            </span>
            <span class="small text-muted ms-md-1">Pick a section on the left to edit.</span>
        </div>
    </div>

    <div class="settings-layout">
        <nav class="settings-nav" aria-label="Settings sections">
            <ul class="nav nav-pills" id="settingsTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tabProvider" type="button" role="tab" aria-controls="tabProvider" aria-selected="true">
                        <i class="fas fa-comments" aria-hidden="true"></i>
                        <span>
                            <span class="settings-nav-label">WhatsApp Provider</span>
                            <span class="settings-nav-hint">Cheerio or Meta API</span>
                        </span>
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabGoLive" type="button" role="tab" aria-controls="tabGoLive" aria-selected="false">
                        <i class="fas fa-rocket" aria-hidden="true"></i>
                        <span>
                            <span class="settings-nav-label">Go Live</span>
                            <span class="settings-nav-hint">Launch checklist</span>
                        </span>
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabApp" type="button" role="tab" aria-controls="tabApp" aria-selected="false">
                        <i class="fas fa-building" aria-hidden="true"></i>
                        <span>
                            <span class="settings-nav-label">Application</span>
                            <span class="settings-nav-hint">Name, logo, timezone</span>
                        </span>
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabEmail" type="button" role="tab" aria-controls="tabEmail" aria-selected="false">
                        <i class="fas fa-envelope" aria-hidden="true"></i>
                        <span>
                            <span class="settings-nav-label">Email Provider</span>
                            <span class="settings-nav-hint">SMTP · SendGrid · Cheerio</span>
                        </span>
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabWebhooks" type="button" role="tab" aria-controls="tabWebhooks" aria-selected="false">
                        <i class="fas fa-link" aria-hidden="true"></i>
                        <span>
                            <span class="settings-nav-label">Webhooks</span>
                            <span class="settings-nav-hint">Inbound Live Chat</span>
                        </span>
                    </button>
                </li>
            </ul>
        </nav>

        <div class="settings-main">
            <form action="<?= site_url('settings/save') ?>" method="post" id="settingsForm" enctype="multipart/form-data" class="settings-card">
                <?= csrf_field() ?>
                <input type="hidden" name="section" value="all">

                <div class="settings-body tab-content">
                    <div class="tab-pane fade show active" id="tabProvider" role="tabpanel">
                        <section class="wp-stage" data-provider="<?= esc($provider) ?>" id="wpStage">
                            <header class="wp-stage-head">
                                <div class="wp-stage-copy">
                                    <p class="wp-kicker">Message transport</p>
                                    <h2 class="wp-title">One pipe. Two providers.</h2>
                                    <p class="wp-lead">Chat, campaigns, templates, and queue all call the same facade. This flag only switches the HTTP driver — Cheerio Direct or Meta Graph. Both credential sets stay saved.</p>
                                </div>
                                <div class="wp-live" id="settingsActivePill" aria-live="polite">
                                    <span class="wp-live-dot"></span>
                                    <span class="wp-live-label">Live via</span>
                                    <strong id="wpLiveName"><?= esc($isMeta ? 'Meta' : 'Cheerio') ?></strong>
                                    <span class="wp-live-save d-none" id="wpProviderSaveState"></span>
                                </div>
                            </header>

                            <div class="wp-route" aria-hidden="true">
                                <div class="wp-route-node">
                                    <i class="fas fa-layer-group"></i>
                                    <span>This app</span>
                                </div>
                                <div class="wp-route-wire"><span></span></div>
                                <div class="wp-route-node is-hub" id="wpRouteHub">
                                    <i class="<?= $isMeta ? 'fab fa-meta' : 'fas fa-bolt' ?>" id="wpRouteHubIcon"></i>
                                    <span id="wpRouteHubLabel"><?= esc($isMeta ? 'Meta Graph' : 'Cheerio Direct') ?></span>
                                </div>
                                <div class="wp-route-wire"><span></span></div>
                                <div class="wp-route-node">
                                    <i class="fab fa-whatsapp"></i>
                                    <span>Customers</span>
                                </div>
                            </div>

                            <div class="alert alert-warning border-0 py-2 px-3 small mb-3" role="note">
                                <strong>Important:</strong> Keywords &amp; workflows reply <em>only</em> on the provider selected above.
                                If <strong>Cheerio</strong> is active, message your Cheerio WhatsApp number (not the Meta test number).
                                If <strong>Meta</strong> is active, use the Meta number. Mixed numbers will be saved in Chat but will not auto-reply.
                            </div>

                            <div class="wp-switch" role="radiogroup" aria-label="WhatsApp provider">
                                <label class="wp-option <?= ! $isMeta ? 'is-active' : '' ?>" data-tone="cheerio">
                                    <input type="radio" class="visually-hidden" name="whatsapp_provider" value="cheerio" <?= ! $isMeta ? 'checked' : '' ?> data-provider-toggle>
                                    <span class="wp-option-rail"></span>
                                    <span class="wp-option-body">
                                        <span class="wp-option-icon"><i class="fas fa-bolt"></i></span>
                                        <span class="wp-option-text">
                                            <span class="wp-option-name">Cheerio Direct API</span>
                                            <span class="wp-option-desc">x-api-key · contact &amp; workflow sync · Cheerio WABA</span>
                                        </span>
                                        <span class="wp-option-meta">
                                            <span class="wp-chip">x-api-key</span>
                                            <span class="wp-option-tick"><i class="fas fa-check"></i></span>
                                        </span>
                                    </span>
                                </label>
                                <label class="wp-option <?= $isMeta ? 'is-active' : '' ?>" data-tone="meta">
                                    <input type="radio" class="visually-hidden" name="whatsapp_provider" value="meta" <?= $isMeta ? 'checked' : '' ?> data-provider-toggle>
                                    <span class="wp-option-rail"></span>
                                    <span class="wp-option-body">
                                        <span class="wp-option-icon"><i class="fab fa-meta"></i></span>
                                        <span class="wp-option-text">
                                            <span class="wp-option-name">Meta Cloud API</span>
                                            <span class="wp-option-desc">Bearer token · Phone Number ID · official Graph webhooks</span>
                                        </span>
                                        <span class="wp-option-meta">
                                            <span class="wp-chip">Bearer</span>
                                            <span class="wp-option-tick"><i class="fas fa-check"></i></span>
                                        </span>
                                    </span>
                                </label>
                            </div>

                            <div id="panelCheerioCreds" class="wp-creds <?= $isMeta ? 'd-none' : '' ?>" data-tone="cheerio">
                                <div class="wp-creds-main">
                                    <div class="wp-creds-head">
                                        <div>
                                            <h3>Cheerio credentials</h3>
                                            <p>Encrypted at rest. Leave masked fields blank to keep the current value.</p>
                                        </div>
                                        <a class="wp-link" href="https://app.cheerio.in/settings/apikey" target="_blank" rel="noopener">Open API keys <i class="fas fa-arrow-up-right-from-square"></i></a>
                                    </div>
                                    <div class="wp-field">
                                        <label class="form-label" for="cheerio_api_key">API Key</label>
                                        <div class="input-group input-secret">
                                            <input type="password" id="cheerio_api_key" name="cheerio_api_key" class="form-control" value="<?= $val($cheerio, 'api_key') ?>" autocomplete="off" placeholder="x-api-key from app.cheerio.in">
                                            <button class="btn btn-outline-secondary toggle-secret" type="button" aria-label="Show API key"><i class="fas fa-eye"></i></button>
                                        </div>
                                    </div>
                                    <div class="wp-field">
                                        <label class="form-label" for="cheerio_display_phone">Cheerio / Elintom WhatsApp number</label>
                                        <input type="text" id="cheerio_display_phone" name="cheerio_display_phone" class="form-control" value="<?= esc($cheerio['display_phone'] ?? '') ?>" placeholder="919243959973" inputmode="tel">
                                        <div class="form-text">Customer must message this number when Cheerio is active (e.g. +91 92439 59973).</div>
                                    </div>
                                    <div class="wp-field-grid">
                                        <div class="wp-field">
                                            <label class="form-label">Webhook Verify Token</label>
                                            <div class="input-group input-secret">
                                                <input type="password" name="cheerio_webhook_verify_token" class="form-control" value="<?= $val($cheerio, 'verify_token') ?>" autocomplete="off">
                                                <button class="btn btn-outline-secondary toggle-secret" type="button"><i class="fas fa-eye"></i></button>
                                            </div>
                                        </div>
                                        <div class="wp-field">
                                            <label class="form-label">Webhook Secret</label>
                                            <div class="input-group input-secret">
                                                <input type="password" name="cheerio_webhook_secret" class="form-control" value="<?= $val($cheerio, 'webhook_secret') ?>" autocomplete="off">
                                                <button class="btn btn-outline-secondary toggle-secret" type="button"><i class="fas fa-eye"></i></button>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="wp-field">
                                        <label class="form-label">API Base URL</label>
                                        <div class="wp-endpoint"><?= esc($cheerio['base_url'] ?? 'https://newprod.api.cheerio.in/direct-apis') ?></div>
                                    </div>
                                </div>
                                <aside class="wp-creds-aside">
                                    <p class="wp-aside-kicker">Setup path</p>
                                    <ol class="wp-steps">
                                        <li><a href="https://app.cheerio.in/settings/apikey" target="_blank" rel="noopener">Create API key</a></li>
                                        <li>Connect a live WABA</li>
                                        <li>Approve at least one template</li>
                                        <li>Paste webhook URL under Webhooks</li>
                                    </ol>
                                    <?php if (function_exists('can') && can('settings.view')): ?>
                                        <button type="button" class="btn btn-wa w-100" id="btnTestCheerio">
                                            <i class="fas fa-plug me-1"></i> Test connection
                                        </button>
                                        <div id="cheerioTestResult" class="creds-test-result"></div>
                                    <?php endif; ?>
                                </aside>
                            </div>

                            <div id="panelMetaCreds" class="wp-creds <?= $isMeta ? '' : 'd-none' ?>" data-tone="meta">
                                <div class="wp-creds-main">
                                    <div class="wp-creds-head">
                                        <div>
                                            <h3>Connect WhatsApp</h3>
                                            <p>Meta Embedded Signup — customer authorizes, we store Access Token, WABA ID, and Phone Number ID.</p>
                                        </div>
                                        <a class="wp-link" href="https://developers.facebook.com/docs/whatsapp/embedded-signup/" target="_blank" rel="noopener">Meta docs <i class="fas fa-arrow-up-right-from-square"></i></a>
                                    </div>

                                    <?php
                                    $embedded = $embeddedSignup ?? ['app_id' => '', 'config_id' => '', 'api_version' => 'v21.0', 'ready' => false];
                                    $connected = trim((string) ($meta['phone_number_id'] ?? '')) !== ''
                                        && trim((string) ($meta['waba_id'] ?? '')) !== ''
                                        && trim((string) ($meta['access_token'] ?? '')) !== '';
                                    ?>
                                    <div class="wp-embed-card mb-3" id="metaEmbeddedSignupBox"
                                         data-app-id="<?= esc($embedded['app_id'] ?? '') ?>"
                                         data-config-id="<?= esc($embedded['config_id'] ?? '') ?>"
                                         data-api-version="<?= esc($embedded['api_version'] ?? 'v21.0') ?>"
                                         data-ready="<?= ! empty($embedded['ready']) ? '1' : '0' ?>">
                                        <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                                            <span class="badge <?= $connected ? 'text-bg-success' : 'text-bg-secondary' ?>" id="metaConnectStatus">
                                                <?= $connected ? 'Connected' : 'Not connected' ?>
                                            </span>
                                            <?php if ($connected): ?>
                                                <span class="small text-muted" id="metaConnectSummary">
                                                    WABA <?= esc((string) ($meta['waba_id'] ?? '')) ?> · Phone ID <?= esc((string) ($meta['phone_number_id'] ?? '')) ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="small text-muted" id="metaConnectSummary">Save App ID + Config ID + App Secret, then connect.</span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="d-flex flex-wrap gap-2">
                                            <button type="button" class="btn btn-wa" id="btnConnectWhatsApp" <?= empty($embedded['ready']) ? 'disabled' : '' ?>>
                                                <i class="fab fa-whatsapp me-1"></i> Connect WhatsApp
                                            </button>
                                            <button type="button" class="btn btn-outline-secondary btn-sm" id="btnReloadEmbeddedConfig" title="Refresh App ID / Config from saved settings">
                                                <i class="fas fa-sync"></i>
                                            </button>
                                        </div>
                                        <div id="metaEmbedResult" class="creds-test-result mt-2"></div>
                                        <div class="form-text mt-2">
                                            Domain must be listed under Meta App → Facebook Login for Business → Allowed domains / Valid OAuth redirect URIs (HTTPS).
                                        </div>
                                    </div>

                                    <div class="wp-creds-head">
                                        <div>
                                            <h3>Meta app (for Embedded Signup)</h3>
                                            <p>From Meta Developer App. Leave masked secrets blank to keep current values.</p>
                                        </div>
                                        <a class="wp-link" href="https://developers.facebook.com/apps/" target="_blank" rel="noopener">Open Meta Apps <i class="fas fa-arrow-up-right-from-square"></i></a>
                                    </div>
                                    <div class="wp-field-grid">
                                        <div class="wp-field">
                                            <label class="form-label">Meta App ID</label>
                                            <input type="text" name="meta_app_id" id="meta_app_id" class="form-control" value="<?= $val($meta, 'app_id') ?>" placeholder="App Dashboard → App ID" inputmode="numeric">
                                        </div>
                                        <div class="wp-field">
                                            <label class="form-label">Embedded Signup Config ID</label>
                                            <input type="text" name="meta_embedded_config_id" id="meta_embedded_config_id" class="form-control" value="<?= $val($meta, 'embedded_config_id') ?>" placeholder="Facebook Login for Business → Configurations" inputmode="numeric">
                                        </div>
                                    </div>
                                    <div class="wp-field-grid">
                                        <div class="wp-field">
                                            <label class="form-label">App Secret</label>
                                            <div class="input-group input-secret">
                                                <input type="password" name="meta_webhook_secret" class="form-control" value="<?= $val($meta, 'app_secret') ?>" autocomplete="off" placeholder="App settings → App secret">
                                                <button class="btn btn-outline-secondary toggle-secret" type="button"><i class="fas fa-eye"></i></button>
                                            </div>
                                        </div>
                                        <div class="wp-field">
                                            <label class="form-label">Two-step PIN (6 digits)</label>
                                            <div class="input-group input-secret">
                                                <input type="password" name="meta_two_step_pin" id="meta_two_step_pin" class="form-control" value="<?= $val($meta, 'two_step_pin') ?>" autocomplete="off" placeholder="••••••" maxlength="6" inputmode="numeric">
                                                <button class="btn btn-outline-secondary toggle-secret" type="button"><i class="fas fa-eye"></i></button>
                                            </div>
                                            <div class="form-text">Used when registering the phone for Cloud API after signup.</div>
                                        </div>
                                    </div>

                                    <hr class="my-3">
                                    <div class="wp-creds-head">
                                        <div>
                                            <h3>Manual credentials (optional)</h3>
                                            <p>Paste a System User token if you are not using Embedded Signup. Leave masked token blank to keep current value.</p>
                                        </div>
                                    </div>
                                    <div class="wp-field">
                                        <label class="form-label">Permanent Access Token</label>
                                        <div class="input-group input-secret">
                                            <input type="password" name="meta_access_token" class="form-control" value="<?= $val($meta, 'access_token') ?>" autocomplete="off" placeholder="EAAG…">
                                            <button class="btn btn-outline-secondary toggle-secret" type="button"><i class="fas fa-eye"></i></button>
                                        </div>
                                    </div>
                                    <div class="wp-field-grid">
                                        <div class="wp-field">
                                            <label class="form-label">Phone Number ID</label>
                                            <input type="text" name="meta_phone_number_id" id="meta_phone_number_id" class="form-control" value="<?= $val($meta, 'phone_number_id') ?>" placeholder="e.g. 1098…">
                                        </div>
                                        <div class="wp-field">
                                            <label class="form-label">WABA ID</label>
                                            <input type="text" name="meta_waba_id" id="meta_waba_id" class="form-control" value="<?= $val($meta, 'waba_id') ?>" placeholder="WhatsApp Business Account ID">
                                        </div>
                                    </div>
                                    <div class="wp-field-grid wp-field-grid-3">
                                        <div class="wp-field">
                                            <label class="form-label">Graph API Version</label>
                                            <input type="text" name="meta_api_version" id="meta_api_version" class="form-control" value="<?= $val($meta, 'api_version', 'v21.0') ?>" placeholder="v21.0">
                                        </div>
                                        <div class="wp-field">
                                            <label class="form-label">Webhook Verify Token</label>
                                            <div class="input-group input-secret">
                                                <input type="password" name="meta_webhook_verify_token" class="form-control" value="<?= $val($meta, 'verify_token') ?>" autocomplete="off">
                                                <button class="btn btn-outline-secondary toggle-secret" type="button"><i class="fas fa-eye"></i></button>
                                            </div>
                                        </div>
                                        <div class="wp-field">
                                            <label class="form-label">Graph Base URL</label>
                                            <div class="wp-endpoint"><?= esc($meta['graph_base_url'] ?? 'https://graph.facebook.com') ?></div>
                                        </div>
                                    </div>
                                    <hr class="my-3">
                                    <div class="wp-creds-head">
                                        <div>
                                            <h3>Team Inbox channels (Instagram / Messenger)</h3>
                                            <p>Uses Meta Page Access Token. Works even when WhatsApp provider is Cheerio.</p>
                                        </div>
                                    </div>
                                    <div class="wp-field-grid">
                                        <div class="wp-field">
                                            <label class="form-label">Facebook Page ID</label>
                                            <input type="text" name="meta_page_id" class="form-control" value="<?= $val($meta, 'page_id') ?>" placeholder="Page ID">
                                        </div>
                                        <div class="wp-field">
                                            <label class="form-label">Instagram Account ID</label>
                                            <input type="text" name="meta_instagram_account_id" class="form-control" value="<?= $val($meta, 'instagram_account_id') ?>" placeholder="Optional IG business account id">
                                        </div>
                                    </div>
                                    <div class="wp-field">
                                        <label class="form-label">Page Access Token</label>
                                        <div class="input-group input-secret">
                                            <input type="password" name="meta_page_access_token" class="form-control" value="<?= $val($meta, 'page_access_token') ?>" autocomplete="off" placeholder="Page PAT">
                                            <button class="btn btn-outline-secondary toggle-secret" type="button"><i class="fas fa-eye"></i></button>
                                        </div>
                                    </div>
                                    <div class="form-check form-switch mb-2">
                                        <input class="form-check-input" type="checkbox" role="switch" id="inboxInstagramEnabled" name="inbox_instagram_enabled" value="1" <?= ! empty($meta['inbox_instagram_enabled']) ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="inboxInstagramEnabled">Enable Instagram Inbox</label>
                                    </div>
                                    <div class="form-check form-switch mb-2">
                                        <input class="form-check-input" type="checkbox" role="switch" id="inboxMessengerEnabled" name="inbox_messenger_enabled" value="1" <?= ! empty($meta['inbox_messenger_enabled']) ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="inboxMessengerEnabled">Enable Messenger Inbox</label>
                                    </div>
                                    <button type="button" class="btn btn-outline-secondary btn-sm mt-1" id="btnTestPageMessaging">
                                        <i class="fas fa-plug me-1"></i> Test Page Messaging
                                    </button>
                                </div>
                                <aside class="wp-creds-aside">
                                    <p class="wp-aside-kicker">Setup path</p>
                                    <ol class="wp-steps">
                                        <li><a href="https://developers.facebook.com/apps/" target="_blank" rel="noopener">Create / open Meta App</a></li>
                                        <li>Become Tech Provider (or use own WABA Path A)</li>
                                        <li>Facebook Login for Business → create Embedded Signup config</li>
                                        <li>Save App ID + Config ID + App Secret here</li>
                                        <li>Click <strong>Connect WhatsApp</strong></li>
                                        <li>Public HTTPS webhook under Webhooks tab</li>
                                    </ol>
                                    <?php if (function_exists('can') && can('settings.view')): ?>
                                        <button type="button" class="btn btn-wa w-100" id="btnTestMeta">
                                            <i class="fas fa-plug me-1"></i> Test connection
                                        </button>
                                        <div id="metaTestResult" class="creds-test-result"></div>
                                    <?php endif; ?>
                                </aside>
                            </div>
                        </section>
                    </div>

                    <div class="tab-pane fade" id="tabGoLive" role="tabpanel">
                        <div class="settings-section-head">
                            <div>
                                <p class="settings-section-kicker">Launch readiness</p>
                                <h3 class="settings-section-title">Go Live checklist</h3>
                            </div>
                        </div>
                        <div class="alert alert-warning border settings-note">
                            <?php if ($isMeta): ?>
                                WABA go-live / template approval Meta Business Manager madhe hote.
                                Active provider: <strong>Meta Cloud API</strong>.
                            <?php else: ?>
                                WABA go-live / template approval <strong>Cheerio Dashboard</strong> madhe hote.
                                Active provider: <strong>Cheerio</strong>.
                            <?php endif; ?>
                        </div>
                        <div class="row g-3">
                            <div class="col-lg-7">
                                <div class="settings-panel">
                                    <div class="list-group list-group-flush settings-checklist" id="goLiveChecklist" data-provider="<?= esc($provider) ?>">
                                        <div class="list-group-item d-flex justify-content-between align-items-start">
                                            <div>
                                                <div class="fw-semibold">1. <?= esc($providerLabel) ?> credentials saved</div>
                                                <div class="small text-muted"><?= $isMeta ? 'Token + Phone Number ID + WABA ID' : 'x-api-key from Cheerio' ?></div>
                                            </div>
                                            <span class="badge text-bg-secondary" data-check="credentials">Check</span>
                                        </div>
                                        <div class="list-group-item d-flex justify-content-between align-items-start">
                                            <div>
                                                <div class="fw-semibold">2. API connection works</div>
                                                <div class="small text-muted"><?= $isMeta ? 'GET phone number info' : 'getAllTemplates responds' ?></div>
                                            </div>
                                            <span class="badge text-bg-secondary" data-check="api">Check</span>
                                        </div>
                                        <div class="list-group-item d-flex justify-content-between align-items-start">
                                            <div>
                                                <div class="fw-semibold">3. Live WABA</div>
                                                <div class="small text-muted"><?= $isMeta ? 'Business verification + live number on Meta' : 'Premium + live number on Cheerio' ?></div>
                                            </div>
                                            <span class="badge text-bg-secondary" data-check="production">Check</span>
                                        </div>
                                        <div class="list-group-item d-flex justify-content-between align-items-start">
                                            <div>
                                                <div class="fw-semibold">4. At least one APPROVED template</div>
                                                <div class="small text-muted">Needed for first/cold outbound messages</div>
                                            </div>
                                            <span class="badge text-bg-secondary" data-check="template">Check</span>
                                        </div>
                                        <?php if ($isMeta): ?>
                                        <div class="list-group-item d-flex justify-content-between align-items-start">
                                            <div>
                                                <div class="fw-semibold">4b. Webhook field <code>messages</code></div>
                                                <div class="small text-muted">Without this, customer replies never reach Live Chat</div>
                                            </div>
                                            <span class="badge text-bg-secondary" data-check="webhook_fields">Check</span>
                                        </div>
                                        <?php endif; ?>
                                        <div class="list-group-item">
                                            <div class="fw-semibold">5. Provider dashboard</div>
                                            <div class="small text-muted mb-2">Complete WABA setup outside this app</div>
                                            <?php if ($isMeta): ?>
                                                <a class="btn btn-sm btn-outline-secondary" href="https://business.facebook.com/" target="_blank" rel="noopener">Open Meta Business</a>
                                            <?php else: ?>
                                                <a class="btn btn-sm btn-outline-secondary" href="https://app.cheerio.in/" target="_blank" rel="noopener">Open Cheerio</a>
                                            <?php endif; ?>
                                        </div>
                                        <div class="list-group-item">
                                            <div class="fw-semibold">6. Public HTTPS webhook</div>
                                            <div class="small text-muted mb-2">Required for inbound replies &amp; delivery status</div>
                                            <code class="small"><?= esc($webhook['callback_url'] ?? site_url('webhooks')) ?></code>
                                        </div>
                                    </div>
                                    <div class="settings-panel-foot">
                                        <button type="button" class="btn btn-wa" id="btnRefreshGoLive"><i class="fas fa-sync"></i> Refresh checklist</button>
                                    </div>
                                </div>
                            </div>
                            <div class="col-lg-5">
                                <div class="settings-panel settings-panel-soft h-100">
                                    <h6 class="mb-2">Message anyone — rules</h6>
                                    <ul class="small mb-0 ps-3">
                                        <li>First message to a new contact must be an <strong>approved template</strong>.</li>
                                        <li>After the customer replies, free text works for ~24 hours.</li>
                                        <li>Templates: approve on <?= $isMeta ? 'Meta' : 'Cheerio' ?>, then Sync here.</li>
                                        <li><a href="<?= site_url('templates/create') ?>">Templates → Create</a></li>
                                    </ul>
                                    <pre class="small settings-code-block mt-3 mb-0" id="goLiveDetails">Click Refresh checklist</pre>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="tab-pane fade" id="tabApp" role="tabpanel">
                        <div class="settings-section-head">
                            <div>
                                <p class="settings-section-kicker">Brand &amp; app</p>
                                <h3 class="settings-section-title">Application</h3>
                            </div>
                        </div>
                        <?php
                        $logoUrl    = ! empty($app['site_logo']) ? base_url(ltrim((string) $app['site_logo'], '/')) : '';
                        $faviconUrl = ! empty($app['site_favicon']) ? base_url(ltrim((string) $app['site_favicon'], '/')) : '';
                        $effectiveFaviconUrl = $faviconUrl !== '' ? $faviconUrl : $logoUrl;
                        ?>
                        <div class="settings-panel mb-3">
                            <h6 class="settings-panel-label">Branding</h6>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Site Name</label>
                                    <input type="text" name="app_name" class="form-control" value="<?= $val($app, 'app_name', 'WhatsApp Automation Platform') ?>" placeholder="Your company or product name">
                                    <div class="form-text">Shown in browser title, sidebar, login, and emails.</div>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Tagline</label>
                                    <input type="text" name="app_tagline" class="form-control" value="<?= $val($app, 'app_tagline', 'Automation console') ?>" placeholder="Automation console">
                                    <div class="form-text">Short line under the site name.</div>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Site Logo</label>
                                    <input type="file" name="site_logo" class="form-control" accept=".png,.jpg,.jpeg,.webp,.gif,image/png,image/jpeg,image/webp,image/gif">
                                    <div class="form-text">PNG, JPG, WebP, SVG or GIF · max 2 MB. Used in sidebar and login.</div>
                                    <?php if ($logoUrl !== ''): ?>
                                        <div class="d-flex align-items-center gap-3 mt-2 branding-preview">
                                            <img src="<?= esc($logoUrl) ?>" alt="Logo preview" class="branding-preview-img">
                                            <div class="form-check mb-0">
                                                <input class="form-check-input" type="checkbox" name="remove_site_logo" value="1" id="removeSiteLogo">
                                                <label class="form-check-label" for="removeSiteLogo">Remove logo</label>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Favicon</label>
                                    <input type="file" name="site_favicon" class="form-control" accept=".ico,.png,.jpg,.jpeg,.webp,.gif,image/png,image/x-icon,image/jpeg,image/webp,image/gif">
                                    <div class="form-text">ICO or PNG · max 512 KB. Browser tab icon. If empty, site logo will be used automatically.</div>
                                    <?php if ($effectiveFaviconUrl !== ''): ?>
                                        <div class="d-flex align-items-center gap-3 mt-2 branding-preview">
                                            <img src="<?= esc($effectiveFaviconUrl) ?>" alt="Favicon preview" class="branding-preview-favicon">
                                            <?php if ($faviconUrl !== ''): ?>
                                                <div class="form-check mb-0">
                                                    <input class="form-check-input" type="checkbox" name="remove_site_favicon" value="1" id="removeSiteFavicon">
                                                    <label class="form-check-label" for="removeSiteFavicon">Remove favicon</label>
                                                </div>
                                            <?php else: ?>
                                                <span class="form-text mb-0">Using site logo as favicon.</span>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <div class="settings-panel">
                            <h6 class="settings-panel-label">Application</h6>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Timezone</label>
                                    <input type="text" name="app_timezone" class="form-control" value="<?= $val($app, 'app_timezone', 'UTC') ?>" placeholder="Asia/Kolkata">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Support / App Email</label>
                                    <input type="email" name="app_email" class="form-control" value="<?= $val($app, 'app_email') ?>">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">App URL</label>
                                    <input type="url" name="app_url" class="form-control" value="<?= $val($app, 'app_url') ?>">
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="tab-pane fade" id="tabEmail" role="tabpanel">
                        <section class="wp-stage" data-email-provider="<?= esc($emailProvider) ?>" id="emailStage">
                            <header class="wp-stage-head">
                                <div class="wp-stage-copy">
                                    <p class="wp-kicker">Outbound email</p>
                                    <h2 class="wp-title">One pipe. Many providers.</h2>
                                    <p class="wp-lead">Password resets, automation alerts, and future email campaigns all use <code>service('emailProvider')</code>. Switch SMTP, SendGrid, or Cheerio without changing app code.</p>
                                </div>
                                <div class="wp-live" aria-live="polite">
                                    <span class="wp-live-dot"></span>
                                    <span class="wp-live-label">Live via</span>
                                    <strong id="emailLiveName"><?= esc($emailProviderLabel) ?></strong>
                                </div>
                            </header>

                            <div class="wp-switch" role="radiogroup" aria-label="Email provider">
                                <label class="wp-option <?= $isSmtpEmail ? 'is-active' : '' ?>" data-tone="smtp">
                                    <input type="radio" class="visually-hidden" name="email_provider" value="smtp" <?= $isSmtpEmail ? 'checked' : '' ?> data-email-provider-toggle>
                                    <span class="wp-option-rail"></span>
                                    <span class="wp-option-body">
                                        <span class="wp-option-icon"><i class="fas fa-server"></i></span>
                                        <span class="wp-option-text">
                                            <span class="wp-option-name">SMTP</span>
                                            <span class="wp-option-desc">Gmail, Office365, custom mail server</span>
                                        </span>
                                        <span class="wp-option-meta">
                                            <span class="wp-chip">Classic</span>
                                            <span class="wp-option-tick"><i class="fas fa-check"></i></span>
                                        </span>
                                    </span>
                                </label>
                                <label class="wp-option <?= $isSendGridEmail ? 'is-active' : '' ?>" data-tone="sendgrid">
                                    <input type="radio" class="visually-hidden" name="email_provider" value="sendgrid" <?= $isSendGridEmail ? 'checked' : '' ?> data-email-provider-toggle>
                                    <span class="wp-option-rail"></span>
                                    <span class="wp-option-body">
                                        <span class="wp-option-icon"><i class="fas fa-paper-plane"></i></span>
                                        <span class="wp-option-text">
                                            <span class="wp-option-name">SendGrid</span>
                                            <span class="wp-option-desc">API v3 · high deliverability at scale</span>
                                        </span>
                                        <span class="wp-option-meta">
                                            <span class="wp-chip">Marketing</span>
                                            <span class="wp-option-tick"><i class="fas fa-check"></i></span>
                                        </span>
                                    </span>
                                </label>
                                <label class="wp-option <?= $isCheerioEmail ? 'is-active' : '' ?>" data-tone="cheerio">
                                    <input type="radio" class="visually-hidden" name="email_provider" value="cheerio" <?= $isCheerioEmail ? 'checked' : '' ?> data-email-provider-toggle>
                                    <span class="wp-option-rail"></span>
                                    <span class="wp-option-body">
                                        <span class="wp-option-icon"><i class="fas fa-bolt"></i></span>
                                        <span class="wp-option-text">
                                            <span class="wp-option-name">Cheerio Email API</span>
                                            <span class="wp-option-desc">Direct API · same x-api-key as WhatsApp</span>
                                        </span>
                                        <span class="wp-option-meta">
                                            <span class="wp-chip">Direct</span>
                                            <span class="wp-option-tick"><i class="fas fa-check"></i></span>
                                        </span>
                                    </span>
                                </label>
                            </div>

                            <div id="panelEmailSmtp" class="wp-creds mt-3 <?= ! $isSmtpEmail ? 'd-none' : '' ?>">
                                <h6 class="text-muted text-uppercase small mb-3">SMTP credentials</h6>
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">SMTP Host</label>
                                        <input type="text" name="smtp_host" class="form-control" value="<?= $val($smtp, 'smtp_host') ?>" placeholder="smtp.gmail.com">
                                    </div>
                                    <div class="col-md-3 mb-3">
                                        <label class="form-label">Port</label>
                                        <input type="number" name="smtp_port" class="form-control" value="<?= $val($smtp, 'smtp_port', '587') ?>">
                                    </div>
                                    <div class="col-md-3 mb-3">
                                        <label class="form-label">Encryption</label>
                                        <select name="smtp_encryption" class="form-select">
                                            <?php foreach (['tls', 'ssl', ''] as $enc): ?>
                                                <?php $label = $enc === '' ? 'NONE' : strtoupper($enc); ?>
                                                <option value="<?= esc($enc) ?>" <?= (($smtp['smtp_encryption'] ?? 'tls') === $enc) ? 'selected' : '' ?>><?= $label ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Username</label>
                                        <input type="text" name="smtp_user" class="form-control" value="<?= $val($smtp, 'smtp_user') ?>" autocomplete="off">
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Password</label>
                                        <div class="input-group input-secret">
                                            <input type="password" name="smtp_password" class="form-control" value="<?= $val($smtp, 'smtp_password') ?>" autocomplete="new-password">
                                            <button class="btn btn-outline-secondary toggle-secret" type="button"><i class="fas fa-eye"></i></button>
                                        </div>
                                        <div class="form-text">Leave masked/blank to keep the current password.</div>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">From Email</label>
                                        <input type="email" name="smtp_from_email" class="form-control" value="<?= $val($smtp, 'smtp_from_email') ?>">
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">From Name</label>
                                        <input type="text" name="smtp_from_name" class="form-control" value="<?= $val($smtp, 'smtp_from_name') ?>">
                                    </div>
                                </div>
                            </div>

                            <div id="panelEmailSendGrid" class="wp-creds mt-3 <?= ! $isSendGridEmail ? 'd-none' : '' ?>">
                                <h6 class="text-muted text-uppercase small mb-3">SendGrid credentials</h6>
                                <div class="alert alert-info border-0 py-2 px-3 small">
                                    Create an API key at <a href="https://app.sendgrid.com/settings/api_keys" target="_blank" rel="noopener">SendGrid Dashboard</a> with <strong>Mail Send</strong> and <strong>Marketing / Single Sends</strong> access.
                                    Promotional campaigns use SendGrid <strong>Single Sends</strong>, which require a verified sender and unsubscribe handling.
                                </div>
                                <div class="row">
                                    <div class="col-md-12 mb-3">
                                        <label class="form-label">API Key</label>
                                        <div class="input-group input-secret">
                                            <input type="password" name="sendgrid_api_key" class="form-control" value="<?= $val($sendgrid, 'api_key') ?>" autocomplete="off" placeholder="SG.xxxxx">
                                            <button class="btn btn-outline-secondary toggle-secret" type="button"><i class="fas fa-eye"></i></button>
                                        </div>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">From Email</label>
                                        <input type="email" name="sendgrid_from_email" class="form-control" value="<?= $val($sendgrid, 'from_email') ?>">
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">From Name</label>
                                        <input type="text" name="sendgrid_from_name" class="form-control" value="<?= $val($sendgrid, 'from_name') ?>">
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label">Verified Sender ID</label>
                                        <input type="number" name="sendgrid_sender_id" class="form-control" value="<?= $val($sendgrid, 'sender_id') ?>" placeholder="123456">
                                        <div class="form-text">Needed for marketing campaigns / Single Sends.</div>
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label">Suppression Group ID</label>
                                        <input type="number" name="sendgrid_suppression_group_id" class="form-control" value="<?= $val($sendgrid, 'suppression_group_id') ?>" placeholder="7890">
                                        <div class="form-text">Preferred unsubscribe group for promotions/newsletters.</div>
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label">IP Pool</label>
                                        <input type="text" name="sendgrid_ip_pool" class="form-control" value="<?= $val($sendgrid, 'ip_pool') ?>" placeholder="marketing-pool">
                                    </div>
                                    <div class="col-md-12 mb-3">
                                        <label class="form-label">Custom Unsubscribe URL</label>
                                        <input type="url" name="sendgrid_custom_unsubscribe_url" class="form-control" value="<?= $val($sendgrid, 'custom_unsubscribe_url') ?>" placeholder="https://example.com/unsubscribe">
                                        <div class="form-text">Use this only if you do not want to use a SendGrid suppression group.</div>
                                    </div>
                                </div>
                            </div>

                            <div id="panelEmailCheerio" class="wp-creds mt-3 <?= ! $isCheerioEmail ? 'd-none' : '' ?>">
                                <h6 class="text-muted text-uppercase small mb-3">Cheerio Email API</h6>
                                <div class="alert alert-warning border-0 py-2 px-3 small">
                                    Uses the same <strong>Cheerio API key</strong> as WhatsApp (Settings → Cheerio credentials).
                                    Create a verified <strong>Sender ID</strong> in Cheerio Dashboard → Email → Manage Sender ID before sending.
                                    Bulk marketing in Cheerio works best via <strong>label-based campaigns</strong> (`label-email/send`).
                                    <a href="https://www.cheerioai.com/getting-started-and-onboarding/how-to-set-up-email-channel-on-cheerio-ai" target="_blank" rel="noopener">Setup guide</a>
                                </div>
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <?php $selectedCheerioCampaign = (string) (old('cheerio_email_campaign_name') ?? ($cheerioEmail['default_campaign'] ?? 'app-direct')); ?>
                                        <label class="form-label">Default campaign</label>
                                        <select name="cheerio_email_campaign_name" class="form-select">
                                            <option value="">-- Select campaign --</option>
                                            <?php foreach (($campaigns ?? []) as $campaign): ?>
                                                <option value="<?= esc($campaign['name']) ?>" <?= $selectedCheerioCampaign === (string) $campaign['name'] ? 'selected' : '' ?>>
                                                    <?= esc($campaign['name']) ?><?= ! empty($campaign['status']) ? ' (' . esc($campaign['status']) . ')' : '' ?>
                                                </option>
                                            <?php endforeach; ?>
                                            <?php if ($selectedCheerioCampaign !== '' && ! in_array($selectedCheerioCampaign, array_map(static fn ($campaign) => (string) ($campaign['name'] ?? ''), $campaigns ?? []), true)): ?>
                                                <option value="<?= esc($selectedCheerioCampaign) ?>" selected>
                                                    <?= esc($selectedCheerioCampaign) ?> (saved)
                                                </option>
                                            <?php endif; ?>
                                        </select>
                                        <div class="form-text">Cheerio API send sathi campaign name analytics label mhanun vaparla jato.</div>
                                    </div>
                                </div>
                            </div>

                            <?php if (function_exists('can') && can('settings.edit')): ?>
                            <button type="button" class="btn btn-wa mt-2" id="btnTestEmail"><i class="fas fa-paper-plane"></i> Test Email Provider</button>
                            <?php endif; ?>
                        </section>
                    </div>

                    <div class="tab-pane fade" id="tabWebhooks" role="tabpanel">
                        <?php
                        $wh = $webhook ?? [];
                        $step1 = ! empty($wh['step1_done']);
                        $step2 = ! empty($wh['step2_done']);
                        $step3 = ! empty($wh['step3_ready']);
                        $whProvider = $wh['provider'] ?? $provider;
                        $isWhMeta = $whProvider === 'meta';
                        ?>
                        <div class="settings-section-head">
                            <div>
                                <p class="settings-section-kicker">Inbound delivery</p>
                                <h3 class="settings-section-title">Webhooks</h3>
                            </div>
                        </div>
                        <div class="alert alert-success border settings-note">
                            <strong>Live Chat inbound setup (3 steps)</strong><br>
                            Customer message → <strong><?= $isWhMeta ? 'Meta' : 'Cheerio' ?></strong> → this webhook URL → <a href="<?= site_url('chat') ?>">Live Chat</a>.
                            Localhost needs ngrok HTTPS. Active provider: <code><?= esc($whProvider) ?></code>
                        </div>

                        <div class="settings-steps" id="webhookSetupWizard"
                             data-setup-url="<?= site_url('settings/setup-webhook') ?>">
                            <div class="settings-step <?= $step1 ? 'is-done' : 'is-pending' ?>">
                                <div class="settings-step-head">
                                    <span class="settings-step-num">1</span>
                                    <div class="settings-step-copy">
                                        <strong>Verify Token save kara</strong>
                                        <span class="badge <?= $step1 ? 'text-bg-success' : 'text-bg-secondary' ?>" id="badgeStep1">
                                            <?= $step1 ? 'Done' : 'Pending' ?>
                                        </span>
                                    </div>
                                </div>
                                <div class="settings-step-body">
                                    <p class="small text-muted mb-2">
                                        Ha token <?= $isWhMeta ? 'Meta App → WhatsApp → Configuration' : 'Cheerio webhook form' ?> madhe paste karaycha.
                                        App + provider donhi thikani <strong>same</strong> asava.
                                    </p>
                                    <label class="form-label">Webhook Verify Token</label>
                                    <div class="input-group mb-2">
                                        <input type="text" class="form-control font-monospace" id="webhookVerifyToken"
                                               value="<?= esc($wh['verify_token'] ?? '') ?>" readonly>
                                        <button type="button" class="btn btn-outline-secondary" id="btnCopyVerifyToken" title="Copy">
                                            <i class="fas fa-copy"></i>
                                        </button>
                                        <button type="button" class="btn btn-wa" id="btnGenerateVerifyToken">
                                            <i class="fas fa-key me-1"></i> Generate + Save
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <div class="settings-step <?= $step2 ? 'is-done' : '' ?>">
                                <div class="settings-step-head">
                                    <span class="settings-step-num">2</span>
                                    <div class="settings-step-copy">
                                        <strong>Public HTTPS callback
                                            <?php
                                            $whMode = (string) ($wh['mode'] ?? 'local');
                                            $whSource = (string) ($wh['source'] ?? 'none');
                                            ?>
                                            <span class="badge <?= $whMode === 'live' ? 'text-bg-primary' : 'text-bg-warning' ?>" id="webhookModeBadge">
                                                <?= $whMode === 'live' ? 'Live' : 'Local' ?>
                                            </span>
                                            <span class="badge text-bg-secondary" id="webhookSourceBadge"><?= esc($whSource) ?></span>
                                        </strong>
                                        <span class="badge <?= $step2 ? 'text-bg-success' : 'text-bg-secondary' ?>" id="badgeStep2">
                                            <?= $step2 ? 'Done' : 'Pending' ?>
                                        </span>
                                    </div>
                                </div>
                                <div class="settings-step-body">
                                    <p class="small text-muted mb-2" id="webhookAutoHint"><?= esc($wh['hint'] ?? '') ?></p>
                                    <?php if ($whMode === 'local'): ?>
                                        <ol class="small text-muted mb-3 ps-3">
                                            <li>Start: <code>cloudflared tunnel --url http://127.0.0.1:80</code></li>
                                            <li>Browser मध्ये Settings <strong>tunnel HTTPS URL</strong> वरून उघडा</li>
                                            <li><strong>Auto-generate</strong> क्लिक → Save (किंवा paste)</li>
                                        </ol>
                                    <?php else: ?>
                                        <p class="small text-muted mb-3">
                                            Live domain वर Settings उघडताच callback <strong>auto-detect + save</strong> होते.
                                            <strong>Auto</strong> पुन्हा detect करण्यासाठी वापरा. Override फक्त गरज असल्यास.
                                        </p>
                                    <?php endif; ?>
                                    <label class="form-label" for="webhookPublicBase">Public HTTPS base</label>
                                    <div class="input-group mb-2">
                                        <input type="url" class="form-control font-monospace" id="webhookPublicBase"
                                               name="webhook_public_base"
                                               placeholder="<?= $whMode === 'live' ? 'https://your-domain.com' : 'https://xxxx.trycloudflare.com' ?>"
                                               value="<?= esc($wh['public_base'] ?? '') ?>"
                                               autocomplete="off">
                                        <button type="button" class="btn btn-outline-secondary" id="btnAutoPublicBase" title="Detect Local tunnel / Live domain">
                                            <i class="fas fa-magic me-1"></i> Auto
                                        </button>
                                        <button type="button" class="btn btn-wa" id="btnSavePublicBase">
                                            <i class="fas fa-save me-1"></i> Save
                                        </button>
                                    </div>
                                    <div class="form-text mb-2">
                                        Auto suggested:
                                        <code id="webhookAutoSuggested"><?= esc(($wh['auto_callback'] ?? '') !== '' ? (string) $wh['auto_callback'] : '—') ?></code>
                                        · Local path: <code><?= esc($wh['callback_url'] ?? site_url('webhooks')) ?></code>
                                    </div>
                                </div>
                            </div>

                            <div class="settings-step <?= $step3 ? 'is-done' : '' ?>">
                                <div class="settings-step-head">
                                    <span class="settings-step-num">3</span>
                                    <div class="settings-step-copy">
                                        <strong><?= $isWhMeta ? 'Meta' : 'Cheerio' ?> madhe paste + test</strong>
                                        <span class="badge <?= $step3 ? 'text-bg-success' : 'text-bg-secondary' ?>" id="badgeStep3">
                                            <?= $step3 ? 'Ready' : 'Waiting' ?>
                                        </span>
                                    </div>
                                </div>
                                <div class="settings-step-body">
                                    <label class="form-label" for="webhookPublicCallback">Callback URL (editable — provider madhe paste)</label>
                                    <div class="input-group mb-2">
                                        <input type="url" class="form-control font-monospace" id="webhookPublicCallback"
                                               name="webhook_public_callback"
                                               placeholder="https://xxxx.trycloudflare.com/…/webhooks"
                                               value="<?= esc($wh['public_callback'] ?? ($wh['callback_url'] ?? '')) ?>"
                                               autocomplete="off">
                                        <button type="button" class="btn btn-outline-secondary" id="btnCopyPublicCallback" title="Copy">
                                            <i class="fas fa-copy"></i> Copy URL
                                        </button>
                                        <button type="button" class="btn btn-wa" id="btnSavePublicCallback">
                                            <i class="fas fa-save me-1"></i> Save URL
                                        </button>
                                    </div>
                                    <div class="form-text mb-3">
                                        Full callback URL edit / paste karu shakta. Save kelya var host save hoto; path auto-correct hoto.
                                    </div>

                                    <div class="settings-guide mb-3 small">
                                        <?php if ($isWhMeta): ?>
                                            <div class="alert alert-warning border-0 py-2 px-3 small mb-3" role="note">
                                                <strong>Important:</strong> Callback URL verify पुरेसे नाही.
                                                Meta App → WhatsApp → Configuration → Webhook fields मध्ये
                                                <code>messages</code> <strong>Subscribe</strong> असावे.
                                                नसेल तर customer replies Live Chat मध्ये येणार नाहीत (24h lock राहील).
                                                <strong>Test connection</strong> हे auto-fix करण्याचा प्रयत्न करते.
                                            </div>
                                            <div class="fw-semibold mb-2">Meta App → WhatsApp → Configuration:</div>
                                            <ol class="mb-2 ps-3">
                                                <li>Open <a href="https://developers.facebook.com/apps/" target="_blank" rel="noopener">developers.facebook.com</a></li>
                                                <li><strong>Callback URL</strong> = Copy URL above</li>
                                                <li><strong>Verify Token</strong> = Step 1 token</li>
                                                <li>Subscribe fields: <code>messages</code>, <code>message_template_status_update</code>, <code>account_update</code></li>
                                                <li>Verify / Save · App Secret = Settings → Provider → Meta App Secret</li>
                                            </ol>
                                        <?php else: ?>
                                            <div class="fw-semibold mb-2">Cheerio / WABA webhook form:</div>
                                            <ol class="mb-2 ps-3">
                                                <li>Open <a href="https://app.cheerio.in/login" target="_blank" rel="noopener">app.cheerio.in</a></li>
                                                <li><strong>Callback / Webhook URL</strong> = Copy URL above</li>
                                                <li><strong>Verify Token</strong> = Step 1 token</li>
                                                <li>Subscribe: <code>messages</code></li>
                                                <li>Verify / Save</li>
                                            </ol>
                                        <?php endif; ?>
                                        <div class="text-muted">Phone varun business number la <code>Hi</code> pathava → <a href="<?= site_url('chat') ?>">Live Chat</a>.</div>
                                    </div>

                                    <div class="d-flex flex-wrap gap-2">
                                        <button type="button" class="btn btn-wa" id="btnTestWebhookChallenge">
                                            <i class="fas fa-vial me-1"></i> Test local verify
                                        </button>
                                        <a class="btn btn-outline-secondary" href="<?= site_url('chat') ?>">
                                            <i class="fas fa-comments me-1"></i> Open Live Chat
                                        </a>
                                    </div>
                                    <div id="webhookSetupResult" class="small mt-2"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <?php if (function_exists('can') && can('settings.edit')): ?>
                <div class="settings-footer">
                    <div class="settings-footer-copy">
                        <span class="settings-footer-hint">Changes apply after save</span>
                    </div>
                    <button type="submit" class="btn btn-wa"><i class="fas fa-save"></i> Save Settings</button>
                </div>
                <?php endif; ?>
            </form>
        </div>
    </div>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
$(function () {
    $('.toggle-secret').on('click', function () {
        var $input = $(this).closest('.input-group').find('input');
        var type = $input.attr('type') === 'password' ? 'text' : 'password';
        $input.attr('type', type);
        $(this).find('i').toggleClass('fa-eye fa-eye-slash');
    });

    $('#btnCopyWebhook').on('click', function () {
        var el = document.getElementById('webhookUrl');
        if (!el) return;
        el.select();
        navigator.clipboard.writeText(el.value).then(function () {
            APP.toast('Webhook URL copied');
        });
    });

    function copyText(value, label) {
        if (!value) {
            APP.toast('Nothing to copy', 'warning');
            return;
        }
        navigator.clipboard.writeText(value).then(function () {
            APP.toast((label || 'Copied') + ' copied');
        }).catch(function () {
            APP.toast('Copy failed', 'error');
        });
    }

    function setStepBadge(id, done, doneText) {
        var $b = $('#' + id);
        $b.removeClass('text-bg-secondary text-bg-success')
            .addClass(done ? 'text-bg-success' : 'text-bg-secondary')
            .text(done ? (doneText || 'Done') : 'Pending');
        var $step = $b.closest('.settings-step');
        if ($step.length) {
            $step.toggleClass('is-done', !!done).toggleClass('is-pending', !done);
        }
    }

    function webhookSetup(action, extra) {
        var url = $('#webhookSetupWizard').data('setup-url') || (APP.baseUrl + '/settings/setup-webhook');
        var payload = Object.assign({ action: action }, extra || {});
        return APP.post(url, payload);
    }

    $('#btnCopyVerifyToken').on('click', function () {
        copyText($('#webhookVerifyToken').val(), 'Verify token');
    });
    $('#btnCopyPublicCallback').on('click', function () {
        copyText($('#webhookPublicCallback').val(), 'Callback URL');
    });

    $('#btnGenerateVerifyToken').on('click', function () {
        var $btn = $(this).prop('disabled', true);
        webhookSetup('generate_token')
            .done(function (res) {
                var token = (res.data && res.data.verify_token) || '';
                $('#webhookVerifyToken').val(token);
                $('input[name="cheerio_webhook_verify_token"]').val(token);
                $('input[name="meta_webhook_verify_token"]').val(token);
                setStepBadge('badgeStep1', true);
                APP.toast(res.message || 'Token saved', 'success');
            })
            .fail(function (xhr) {
                APP.toast((xhr.responseJSON && xhr.responseJSON.message) || 'Token generate failed', 'error');
            })
            .always(function () { $btn.prop('disabled', false); });
    });

    function savePublicWebhookUrl(raw, $btn) {
        var value = $.trim(raw || '');
        $btn.prop('disabled', true);
        webhookSetup('save_public_url', { webhook_public_base: value })
            .done(function (res) {
                var data = res.data || {};
                if (data.public_base) $('#webhookPublicBase').val(data.public_base);
                if (data.public_callback) $('#webhookPublicCallback').val(data.public_callback);
                setStepBadge('badgeStep2', true);
                setStepBadge('badgeStep3', true, 'Ready');
                APP.toast(res.message || 'Public URL saved', 'success');
            })
            .fail(function (xhr) {
                APP.toast((xhr.responseJSON && xhr.responseJSON.message) || 'Save failed', 'error');
            })
            .always(function () { $btn.prop('disabled', false); });
    }

    $('#btnSavePublicBase').on('click', function () {
        savePublicWebhookUrl($('#webhookPublicBase').val(), $(this));
    });

    $('#btnAutoPublicBase').on('click', function () {
        var $btn = $(this).prop('disabled', true);
        webhookSetup('auto_public_url')
            .done(function (res) {
                var data = res.data || {};
                if (data.public_base) $('#webhookPublicBase').val(data.public_base);
                if (data.public_callback) {
                    $('#webhookPublicCallback').val(data.public_callback);
                    $('#webhookAutoSuggested').text(data.public_callback);
                }
                if (data.mode) {
                    $('#webhookModeBadge')
                        .text(data.mode === 'live' ? 'Live' : 'Local')
                        .toggleClass('text-bg-primary', data.mode === 'live')
                        .toggleClass('text-bg-warning', data.mode !== 'live');
                }
                $('#webhookSourceBadge').text(data.source || 'saved');
                setStepBadge('badgeStep2', true);
                setStepBadge('badgeStep3', true, 'Ready');
                APP.toast(res.message || 'Callback auto-saved', 'success');
            })
            .fail(function (xhr) {
                var data = (xhr.responseJSON && xhr.responseJSON.data) || {};
                if (data.hint) $('#webhookAutoHint').text(data.hint);
                if (data.auto_callback) $('#webhookAutoSuggested').text(data.auto_callback);
                APP.toast((xhr.responseJSON && xhr.responseJSON.message) || 'Auto-detect failed', 'warning');
            })
            .always(function () { $btn.prop('disabled', false); });
    });

    $('#btnSavePublicCallback').on('click', function () {
        savePublicWebhookUrl($('#webhookPublicCallback').val(), $(this));
    });

    $('#btnTestWebhookChallenge').on('click', function () {
        var $btn = $(this).prop('disabled', true);
        $('#webhookSetupResult').text('Testing…');
        webhookSetup('test_challenge')
            .done(function (res) {
                var ok = !!(res.success || res.data);
                $('#webhookSetupResult').html(
                    '<span class="' + (res.success !== false ? 'text-success' : 'text-danger') + '">' +
                    (res.message || 'Done') + '</span>'
                );
                APP.toast(res.message || 'Test done', res.success === false ? 'error' : 'success');
            })
            .fail(function (xhr) {
                var msg = (xhr.responseJSON && xhr.responseJSON.message) || 'Test failed';
                $('#webhookSetupResult').html('<span class="text-danger">' + msg + '</span>');
                APP.toast(msg, 'error');
            })
            .always(function () { $btn.prop('disabled', false); });
    });

    // Deep-link: /settings#tabWebhooks (and other tabs)
    (function openHashTab() {
        var hash = window.location.hash || '';
        if (!hash) return;
        var tabBtn = document.querySelector('[data-bs-target="' + hash + '"]');
        if (tabBtn && window.bootstrap) {
            new bootstrap.Tab(tabBtn).show();
        } else if (tabBtn) {
            $(tabBtn).click();
        }
    })();

    $('#settingsTabs button[data-bs-toggle="tab"]').on('shown.bs.tab', function (e) {
        var target = e.target.getAttribute('data-bs-target');
        if (target && history.replaceState) {
            history.replaceState(null, '', target);
        }
    });

    $('#btnTestEmail').on('click', function () {
        var to = prompt('Send test email to:');
        if (!to) return;
        var $btn = $(this).prop('disabled', true);
        APP.post(APP.baseUrl + '/settings/test-email', { to: to })
            .done(function (res) { APP.toast(res.message || 'Email OK'); })
            .fail(function (xhr) { APP.toast((xhr.responseJSON && xhr.responseJSON.message) || 'Email test failed', 'error'); })
            .always(function () { $btn.prop('disabled', false); });
    });

    function syncEmailProviderPanels() {
        var provider = $('input[name="email_provider"]:checked').val() || 'smtp';
        $('#panelEmailSmtp').toggleClass('d-none', provider !== 'smtp');
        $('#panelEmailSendGrid').toggleClass('d-none', provider !== 'sendgrid');
        $('#panelEmailCheerio').toggleClass('d-none', provider !== 'cheerio');
        $('#emailStage .wp-option').removeClass('is-active');
        $('input[name="email_provider"]:checked').closest('.wp-option').addClass('is-active');
        $('#emailStage').attr('data-email-provider', provider);
        var label = provider === 'sendgrid' ? 'SendGrid' : (provider === 'cheerio' ? 'Cheerio Email API' : 'SMTP');
        $('#emailLiveName').text(label);
    }

    $('input[data-email-provider-toggle]').on('change', syncEmailProviderPanels);
    syncEmailProviderPanels();

    function applyCheerioChecklist(data) {
        var items = (data && data.checklist) ? data.checklist : [];
        var byId = {};
        items.forEach(function (item) { byId[item.id] = item; });

        function setBadge(key, ok, detail) {
            var $b = $('[data-check="' + key + '"]');
            $b.removeClass('text-bg-secondary text-bg-success text-bg-danger text-bg-warning')
                .addClass(ok ? 'text-bg-success' : 'text-bg-danger')
                .text(ok ? 'OK' : 'Fix')
                .attr('title', detail || '');
        }

        setBadge('credentials', !!(byId.api_key && byId.api_key.ok), (byId.api_key && byId.api_key.detail) || '');
        setBadge('api', !!(byId.templates_api && byId.templates_api.ok), (byId.templates_api && byId.templates_api.detail) || '');
        setBadge('production', !!(data && data.ok), 'Live WABA required in provider dashboard');
        setBadge('template', !!(byId.approved_templates && byId.approved_templates.ok), (byId.approved_templates && byId.approved_templates.detail) || '');

        $('#goLiveDetails').text(JSON.stringify({
            ok: !!(data && data.ok),
            provider: (data && data.provider) || 'cheerio',
            templates_reachable: data && data.templates_reachable,
            template_count: data && data.template_count,
            approved_templates: data && data.approved_templates,
            checklist: items
        }, null, 2));
    }

    function applyMetaChecklist(data) {
        function setBadge(key, ok, detail) {
            var $b = $('[data-check="' + key + '"]');
            $b.removeClass('text-bg-secondary text-bg-success text-bg-danger text-bg-warning')
                .addClass(ok ? 'text-bg-success' : 'text-bg-danger')
                .text(ok ? 'OK' : 'Fix')
                .attr('title', detail || '');
        }
        var items = (data && data.checklist) ? data.checklist : [];
        var byId = {};
        items.forEach(function (item) { byId[item.id] = item; });
        var ok = !!(data && data.ok);
        setBadge('credentials', !!(byId.access_token && byId.access_token.ok && byId.phone_number_id && byId.phone_number_id.ok), (data && data.message) || '');
        setBadge('api', !!(byId.graph_api && byId.graph_api.ok), (byId.graph_api && byId.graph_api.detail) || (data && data.message) || '');
        setBadge('production', ok && !!(byId.webhook_fields && byId.webhook_fields.ok), 'Confirm live number + messages webhook field');
        setBadge('template', !!(byId.waba_id && byId.waba_id.ok), (byId.waba_id && byId.waba_id.detail) || 'WABA ID needed for template sync');
        if ($('[data-check="webhook_fields"]').length) {
            setBadge(
                'webhook_fields',
                !!(byId.webhook_fields && byId.webhook_fields.ok),
                (byId.webhook_fields && byId.webhook_fields.detail) || 'Subscribe messages in Meta App Dashboard'
            );
        }
        $('#goLiveDetails').text(JSON.stringify(data || {}, null, 2));
    }

    function runCheerioTest($btn, toast) {
        if ($btn) $btn.prop('disabled', true);
        $('#cheerioTestResult').text('Testing…');
        APP.post(APP.baseUrl + '/settings/test-cheerio', {})
            .done(function (res) {
                var data = res.data || {};
                applyCheerioChecklist(data);
                var msg = res.message || (data.ok ? 'Cheerio OK' : 'Issues found');
                $('#cheerioTestResult').html('<span class="' + (data.ok ? 'text-success' : 'text-warning') + '">' + msg + '</span>');
                if (toast !== false) APP.toast(msg, data.ok ? 'success' : 'warning');
            })
            .fail(function (xhr) {
                var msg = (xhr.responseJSON && xhr.responseJSON.message) || 'Cheerio test failed';
                $('#cheerioTestResult').html('<span class="text-danger">' + msg + '</span>');
                if (toast !== false) APP.toast(msg, 'error');
            })
            .always(function () { if ($btn) $btn.prop('disabled', false); });
    }

    function runMetaTest($btn, toast) {
        if ($btn) $btn.prop('disabled', true);
        $('#metaTestResult').text('Testing…');
        APP.post(APP.baseUrl + '/settings/test-meta', {})
            .done(function (res) {
                var data = res.data || {};
                applyMetaChecklist(data);
                var msg = (data && data.message) || res.message || (data.ok ? 'Meta OK' : 'Issues found');
                $('#metaTestResult').html('<span class="' + (data.ok ? 'text-success' : 'text-warning') + '">' + $('<div>').text(msg).html() + '</span>');
                if (toast !== false) APP.toast(msg, data.ok ? 'success' : 'warning');
            })
            .fail(function (xhr) {
                var msg = (xhr.responseJSON && xhr.responseJSON.message) || 'Meta test failed';
                $('#metaTestResult').html('<span class="text-danger">' + $('<div>').text(msg).html() + '</span>');
                if (toast !== false) APP.toast(msg, 'error');
            })
            .always(function () { if ($btn) $btn.prop('disabled', false); });
    }

    function syncProviderPanels() {
        var provider = $('input[name="whatsapp_provider"]:checked').val() || 'cheerio';
        var isMeta = provider === 'meta';
        $('#panelCheerioCreds').toggleClass('d-none', isMeta);
        $('#panelMetaCreds').toggleClass('d-none', !isMeta);
        $('#wpStage .wp-option').removeClass('is-active');
        $('input[name="whatsapp_provider"]:checked').closest('.wp-option').addClass('is-active');
        $('#wpStage').attr('data-provider', provider);
        $('#wpLiveName').text(isMeta ? 'Meta' : 'Cheerio');
        $('#wpRouteHubLabel').text(isMeta ? 'Meta Graph' : 'Cheerio Direct');
        $('#wpRouteHubIcon').attr('class', isMeta ? 'fab fa-meta' : 'fas fa-bolt');
        $('#goLiveChecklist').attr('data-provider', provider);
    }

    function updateProviderChrome(provider) {
        var isMeta = provider === 'meta';
        if (window.APP) {
            APP.whatsappProvider = provider;
            APP.whatsappProviderShort = isMeta ? 'Meta' : 'Cheerio';
            APP.whatsappProviderLabel = isMeta ? 'Meta Cloud API' : 'Cheerio Direct API';
        }
        var $chip = $('.provider-chip').first();
        if ($chip.length) {
            $chip
                .toggleClass('is-meta', isMeta)
                .toggleClass('is-cheerio', !isMeta)
                .attr('title', isMeta ? 'Meta Cloud API' : 'Cheerio Direct API')
                .html('<i class="' + (isMeta ? 'fab fa-meta' : 'fas fa-bolt') + '"></i> ' + (isMeta ? 'Meta' : 'Cheerio'));
        }
    }

    var providerSaveTimer = null;
    function persistActiveProvider(provider) {
        var $state = $('#wpProviderSaveState').removeClass('d-none text-success text-danger').text('Saving…');
        clearTimeout(providerSaveTimer);
        APP.post(APP.baseUrl + '/settings/save', {
            section: 'provider',
            whatsapp_provider: provider
        })
            .done(function (res) {
                var data = (res && res.data) || {};
                var saved = data.provider || provider;
                $('input[name="whatsapp_provider"][value="' + saved + '"]').prop('checked', true);
                syncProviderPanels();
                updateProviderChrome(saved);
                $state.addClass('text-success').text('Saved');
                if (window.APP && APP.toast) {
                    APP.toast((isMetaLabel(saved)) + ' is now active', 'success');
                }
                providerSaveTimer = setTimeout(function () { $state.addClass('d-none'); }, 2500);
            })
            .fail(function (xhr) {
                var msg = (xhr.responseJSON && xhr.responseJSON.message) || 'Could not save provider';
                $state.addClass('text-danger').text('Save failed');
                if (window.APP && APP.toast) APP.toast(msg, 'error');
            });
    }

    function isMetaLabel(provider) {
        return provider === 'meta' ? 'Meta Cloud API' : 'Cheerio Direct API';
    }

    $('input[data-provider-toggle]').on('change', function () {
        syncProviderPanels();
        persistActiveProvider($(this).val());
    });

    $('#btnTestCheerio').on('click', function () { runCheerioTest($(this), true); });
    $('#btnTestMeta').on('click', function () { runMetaTest($(this), true); });

    // ── Meta Embedded Signup (Connect WhatsApp) ──────────────────────────
    (function initMetaEmbeddedSignup() {
        var $box = $('#metaEmbeddedSignupBox');
        if (!$box.length) return;

        var pendingSession = null;
        var pendingCode = null;
        var completing = false;
        var sdkLoading = false;

        function embedCfg() {
            return {
                appId: ($('#meta_app_id').val() || $box.attr('data-app-id') || '').toString().trim(),
                configId: ($('#meta_embedded_config_id').val() || $box.attr('data-config-id') || '').toString().trim(),
                apiVersion: ($('#meta_api_version').val() || $box.attr('data-api-version') || 'v21.0').toString().trim() || 'v21.0'
            };
        }

        function setReady() {
            var c = embedCfg();
            var ready = !!(c.appId && c.configId);
            $box.attr('data-ready', ready ? '1' : '0');
            $('#btnConnectWhatsApp').prop('disabled', !ready);
            if (!ready) {
                $('#metaConnectSummary').text('Save App ID + Config ID + App Secret, then connect.');
            }
        }

        function setResult(html, ok) {
            $('#metaEmbedResult').html(
                '<span class="' + (ok ? 'text-success' : 'text-danger') + '">' + html + '</span>'
            );
        }

        function finishTypes() {
            return {
                FINISH: 1,
                FINISH_ONLY_WABA: 1,
                FINISH_WHATSAPP_BUSINESS_APP_ONBOARDING: 1,
                FINISH_OBO_MIGRATION: 1,
                FINISH_GRANT_ONLY_API_ACCESS: 1
            };
        }

        function tryComplete() {
            if (completing || !pendingCode || !pendingSession) return;
            completing = true;
            var code = pendingCode;
            var session = pendingSession;
            pendingCode = null;
            pendingSession = null;

            var pin = ($('#meta_two_step_pin').val() || '').toString().trim();
            if (pin.indexOf('•') !== -1) pin = '';

            setResult('Exchanging token &amp; saving credentials…', true);
            $('#btnConnectWhatsApp').prop('disabled', true);

            APP.post(APP.baseUrl + '/settings/embedded-signup', {
                code: code,
                waba_id: session.waba_id || '',
                phone_number_id: session.phone_number_id || '',
                business_id: session.business_id || '',
                pin: /^\d{6}$/.test(pin) ? pin : ''
            }).done(function (res) {
                var data = (res && res.data) || {};
                var msg = (res && res.message) || 'WhatsApp connected';
                setResult($('<div>').text(msg).html(), !!(res && res.success));
                APP.toast(msg, (res && res.success) ? 'success' : 'warning');

                if (data.waba_id) {
                    $('#meta_waba_id').val(data.waba_id);
                }
                if (data.phone_number_id) {
                    $('#meta_phone_number_id').val(data.phone_number_id);
                }
                $('#metaConnectStatus')
                    .removeClass('text-bg-secondary text-bg-danger')
                    .addClass('text-bg-success')
                    .text('Connected');
                $('#metaConnectSummary').text(
                    'WABA ' + (data.waba_id || '') + ' · Phone ID ' + (data.phone_number_id || '')
                    + (data.display_phone ? (' · ' + data.display_phone) : '')
                );
                $('input[name="whatsapp_provider"][value="meta"]').prop('checked', true).trigger('change');
                if (typeof runMetaTest === 'function') {
                    runMetaTest(null, false);
                }
            }).fail(function (xhr) {
                var msg = (xhr.responseJSON && xhr.responseJSON.message) || 'Embedded Signup failed';
                setResult($('<div>').text(msg).html(), false);
                APP.toast(msg, 'error');
            }).always(function () {
                completing = false;
                setReady();
            });
        }

        function ensureSdk(cb) {
            if (window.FB && typeof window.FB.login === 'function') {
                cb();
                return;
            }
            if (sdkLoading) {
                var tries = 0;
                var t = setInterval(function () {
                    tries++;
                    if (window.FB && typeof window.FB.login === 'function') {
                        clearInterval(t);
                        cb();
                    } else if (tries > 50) {
                        clearInterval(t);
                        setResult('Facebook SDK failed to load.', false);
                    }
                }, 100);
                return;
            }
            sdkLoading = true;
            var c = embedCfg();
            window.fbAsyncInit = function () {
                window.FB.init({
                    appId: c.appId,
                    autoLogAppEvents: true,
                    xfbml: true,
                    version: c.apiVersion
                });
                cb();
            };
            var s = document.createElement('script');
            s.async = true;
            s.defer = true;
            s.crossOrigin = 'anonymous';
            s.src = 'https://connect.facebook.net/en_US/sdk.js';
            s.onerror = function () {
                sdkLoading = false;
                setResult('Could not load Facebook SDK. Check network / HTTPS.', false);
            };
            document.body.appendChild(s);
        }

        window.addEventListener('message', function (event) {
            var origin = (event.origin || '').toString();
            if (origin.indexOf('facebook.com') === -1) return;
            var data = event.data;
            try {
                if (typeof data === 'string') data = JSON.parse(data);
            } catch (e) {
                return;
            }
            if (!data || data.type !== 'WA_EMBEDDED_SIGNUP') return;

            var ev = (data.event || '').toString();
            if (finishTypes()[ev]) {
                var d = data.data || {};
                pendingSession = {
                    phone_number_id: (d.phone_number_id || '').toString(),
                    waba_id: (d.waba_id || '').toString(),
                    business_id: (d.business_id || '').toString()
                };
                tryComplete();
                return;
            }
            if (ev === 'CANCEL') {
                var step = (data.data && data.data.current_step) || '';
                var err = (data.data && data.data.error_message) || '';
                setResult(
                    $('<div>').text(err || ('Signup cancelled' + (step ? (' at ' + step) : ''))).html(),
                    false
                );
            }
        });

        $('#btnConnectWhatsApp').on('click', function () {
            var c = embedCfg();
            if (!c.appId || !c.configId) {
                APP.toast('Enter Meta App ID and Embedded Signup Config ID first.', 'warning');
                return;
            }

            var $secret = $('input[name="meta_webhook_secret"]');
            var secretVal = ($secret.val() || '').toString().trim();
            var hasSecretTyped = secretVal !== '' && secretVal.indexOf('•') === -1;
            if ($box.attr('data-ready') !== '1' && !hasSecretTyped) {
                APP.toast('Enter App Secret (or Save Settings once), then Connect WhatsApp.', 'warning');
                return;
            }

            setResult('Saving Meta app settings…', true);
            $('#btnConnectWhatsApp').prop('disabled', true);

            var savePayload = {
                section: 'meta',
                meta_app_id: c.appId,
                meta_embedded_config_id: c.configId,
                meta_api_version: c.apiVersion,
                meta_phone_number_id: ($('#meta_phone_number_id').val() || '').toString(),
                meta_waba_id: ($('#meta_waba_id').val() || '').toString(),
                meta_webhook_verify_token: ($('input[name="meta_webhook_verify_token"]').val() || '').toString(),
                meta_page_id: ($('input[name="meta_page_id"]').val() || '').toString(),
                meta_instagram_account_id: ($('input[name="meta_instagram_account_id"]').val() || '').toString(),
                inbox_instagram_enabled: $('#inboxInstagramEnabled').is(':checked') ? '1' : '',
                inbox_messenger_enabled: $('#inboxMessengerEnabled').is(':checked') ? '1' : ''
            };
            if (hasSecretTyped) {
                savePayload.meta_webhook_secret = secretVal;
            }
            var pin = ($('#meta_two_step_pin').val() || '').toString().trim();
            if (/^\d{6}$/.test(pin)) {
                savePayload.meta_two_step_pin = pin;
            }

            APP.post(APP.baseUrl + '/settings/save', savePayload)
                .done(function () {
                    $box.attr('data-app-id', c.appId);
                    $box.attr('data-config-id', c.configId);
                    $box.attr('data-api-version', c.apiVersion);
                    $box.attr('data-ready', '1');
                    setResult('Opening Meta Embedded Signup…', true);
                    ensureSdk(function () {
                        if (window.FB && c.appId) {
                            try {
                                window.FB.init({
                                    appId: c.appId,
                                    autoLogAppEvents: true,
                                    xfbml: true,
                                    version: c.apiVersion
                                });
                            } catch (e) { /* already inited */ }
                        }
                        window.FB.login(function (response) {
                            if (response && response.authResponse && response.authResponse.code) {
                                pendingCode = response.authResponse.code;
                                tryComplete();
                            } else if (!pendingSession) {
                                setResult('Meta login did not return an auth code.', false);
                                setReady();
                            }
                        }, {
                            config_id: c.configId,
                            response_type: 'code',
                            override_default_response_type: true,
                            extras: { setup: {}, featureType: '', sessionInfoVersion: '3' }
                        });
                    });
                })
                .fail(function (xhr) {
                    var msg = (xhr.responseJSON && xhr.responseJSON.message) || 'Could not save Meta settings';
                    setResult($('<div>').text(msg).html(), false);
                    APP.toast(msg, 'error');
                    setReady();
                });
        });

        $('#meta_app_id, #meta_embedded_config_id, #meta_api_version').on('input change', setReady);
        $('#btnReloadEmbeddedConfig').on('click', setReady);
        setReady();
    })();

    $('#btnTestPageMessaging').on('click', function () {
        var $btn = $(this);
        $btn.prop('disabled', true);
        APP.post(APP.baseUrl + '/settings/test-page-messaging', {})
            .done(function (res) {
                APP.toast((res && res.message) || 'Page messaging OK', (res && res.success) ? 'success' : 'error');
            })
            .fail(function (xhr) {
                var msg = (xhr.responseJSON && xhr.responseJSON.message) || 'Page messaging test failed';
                APP.toast(msg, 'error');
            })
            .always(function () { $btn.prop('disabled', false); });
    });
    $('#btnRefreshGoLive').on('click', function () {
        var provider = $('input[name="whatsapp_provider"]:checked').val() || 'cheerio';
        if (provider === 'meta') runMetaTest($(this), true);
        else runCheerioTest($(this), true);
    });
});
</script>
<?= $this->endSection() ?>
