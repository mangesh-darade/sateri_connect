<?= $this->extend('layouts/main') ?>

<?= $this->section('header_actions') ?>
<?php if (function_exists('can') && can('settings.edit')): ?>
<button type="submit" form="settingsForm" class="btn btn-wa btn-sm"><i class="fas fa-save me-1"></i> Save settings</button>
<?php endif; ?>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php
$provider = $provider ?? 'cheerio';
$isMeta   = $provider === 'meta';
$emailProvider = $emailProvider ?? 'smtp';
$isSesEmail      = $emailProvider === 'ses';
$isSendGridEmail = $emailProvider === 'sendgrid';
$isCheerioEmail  = $emailProvider === 'cheerio';
$isSmtpEmail     = ! $isSendGridEmail && ! $isCheerioEmail && ! $isSesEmail;
$cheerio  = $cheerio ?? [];
$meta     = $meta ?? [];
$app      = $app ?? [];
$smtp     = $smtp ?? [];
$sendgrid = $sendgrid ?? [];
$ses      = $ses ?? [];
$cheerioEmail = $cheerioEmail ?? [];
$webhook  = $webhook ?? [];
$timezoneOptions = $timezoneOptions ?? [];
$val = static function (array $source, string $key, string $default = '') {
    return esc(old($key) ?? ($source[$key] ?? $default));
};
$providerLabel = $isMeta ? 'Meta Cloud API' : 'Cheerio Direct API';
$emailProviderLabel = $isSesEmail ? 'Amazon SES' : ($isSendGridEmail ? 'SendGrid' : ($isCheerioEmail ? 'Cheerio Email API' : 'SMTP'));
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
                <li class="nav-item" role="presentation">
                    <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabElintOm" type="button" role="tab" aria-controls="tabElintOm" aria-selected="false">
                        <i class="fas fa-cash-register" aria-hidden="true"></i>
                        <span>
                            <span class="settings-nav-label">ElintOm POS</span>
                            <span class="settings-nav-hint">Customer sync</span>
                        </span>
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabAi" type="button" role="tab" aria-controls="tabAi" aria-selected="false">
                        <i class="fas fa-robot" aria-hidden="true"></i>
                        <span>
                            <span class="settings-nav-label">AI Bot & Copilot</span>
                            <span class="settings-nav-hint">Gemini · Anti-Ban Rules</span>
                        </span>
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabApiKeys" type="button" role="tab" aria-controls="tabApiKeys" aria-selected="false">
                        <i class="fas fa-key" aria-hidden="true"></i>
                        <span>
                            <span class="settings-nav-label">Developer API Keys</span>
                            <span class="settings-nav-hint">REST API · Integrations</span>
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
                                    $embedded = $embeddedSignup ?? ['app_id' => '', 'config_id' => '', 'api_version' => 'v21.0', 'ready' => false, 'managed' => false];
                                    $connected = trim((string) ($meta['phone_number_id'] ?? '')) !== ''
                                        && trim((string) ($meta['waba_id'] ?? '')) !== ''
                                        && trim((string) ($meta['access_token'] ?? '')) !== '';
                                    ?>
                                    <div class="wp-embed-card mb-3" id="metaEmbeddedSignupBox"
                                         data-app-id="<?= esc($embedded['app_id'] ?? '') ?>"
                                         data-config-id="<?= esc($embedded['config_id'] ?? '') ?>"
                                         data-api-version="<?= esc($embedded['api_version'] ?? 'v21.0') ?>"
                                         data-ready="<?= ! empty($embedded['ready']) ? '1' : '0' ?>"
                                         data-managed="<?= ! empty($embedded['managed']) ? '1' : '0' ?>">
                                        <?php if (! empty($embedded['managed']) && ! empty($embedded['ready'])): ?>
                                            <div class="alert alert-success border py-2 px-3 small mb-2">
                                                <strong>Auto Embedded Signup ready.</strong>
                                                Platform Tech Provider credentials are loaded — client only clicks Connect WhatsApp (no Meta app create/publish).
                                            </div>
                                        <?php elseif (empty($embedded['ready'])): ?>
                                            <div class="alert alert-warning border py-2 px-3 small mb-2">
                                                Platform admin must save <strong>Embedded Signup</strong> App ID + Config ID + App Secret
                                                (Platform → Embedded Signup), <em>or</em> fill Meta app fields below.
                                            </div>
                                        <?php endif; ?>
                                        <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                                            <span class="badge <?= $connected ? 'text-bg-success' : 'text-bg-secondary' ?>" id="metaConnectStatus">
                                                <?= $connected ? 'Connected' : 'Not connected' ?>
                                            </span>
                                            <span class="badge text-bg-secondary" id="metaSdkStatus" title="Facebook JavaScript SDK">SDK: loading…</span>
                                            <?php if ($connected): ?>
                                                <span class="small text-muted" id="metaConnectSummary">
                                                    WABA <?= esc((string) ($meta['waba_id'] ?? '')) ?> · Phone ID <?= esc((string) ($meta['phone_number_id'] ?? '')) ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="small text-muted" id="metaConnectSummary">
                                                    <?= ! empty($embedded['ready'])
                                                        ? 'Click Connect WhatsApp to authorize this business number.'
                                                        : 'Save Tech Provider credentials (platform) or App ID + Config ID + App Secret, then connect.' ?>
                                                </span>
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
                                        <div class="alert alert-light border py-2 px-3 small mt-2 mb-0">
                                            <div class="d-flex flex-wrap align-items-center gap-2">
                                                <strong>JS SDK host domain:</strong>
                                                <code id="metaSdkOrigin">—</code>
                                                <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-2" id="btnCopySdkOrigin">Copy</button>
                                            </div>
                                            <div class="mt-1" id="metaSdkOriginHint">
                                                Meta checks this exact value. It must appear in Meta App → Facebook Login for Business → Settings →
                                                <strong>Allowed Domains for the JavaScript SDK</strong>. Subdomains are not covered by the parent domain.
                                            </div>
                                        </div>
                                        <div class="form-text mt-2">
                                            Uses Facebook JS SDK + Embedded Signup <code>config_id</code> (not a plain OAuth link).
                                        </div>
                                    </div>

                                    <div class="wp-creds-head <?= ! empty($embedded['managed']) ? 'd-none' : '' ?>" id="metaAppCredsHead">
                                        <div>
                                            <h3>Meta app (for Embedded Signup)</h3>
                                            <p>Only needed if platform Tech Provider is not configured. Leave masked secrets blank to keep current values.</p>
                                        </div>
                                        <a class="wp-link" href="https://developers.facebook.com/apps/" target="_blank" rel="noopener">Open Meta Apps <i class="fas fa-arrow-up-right-from-square"></i></a>
                                    </div>
                                    <div class="wp-field-grid <?= ! empty($embedded['managed']) ? 'd-none' : '' ?>" id="metaAppCredsFields">
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
                                        <div class="wp-field <?= ! empty($embedded['managed']) ? 'd-none' : '' ?>" id="metaAppSecretField">
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
                                    <?php if (! empty($embedded['managed']) && ! empty($embedded['ready'])): ?>
                                    <ol class="wp-steps">
                                        <li>Platform Tech Provider is already configured</li>
                                        <li>Have a Meta Business Portfolio + WhatsApp number ready</li>
                                        <li>Click <strong>Connect WhatsApp</strong> and authorize</li>
                                        <li>Public HTTPS webhook under Webhooks tab</li>
                                    </ol>
                                    <?php else: ?>
                                    <ol class="wp-steps">
                                        <li>Platform admin: <strong>Embedded Signup</strong> (preferred), or</li>
                                        <li><a href="https://developers.facebook.com/apps/" target="_blank" rel="noopener">Create / open Meta App</a></li>
                                        <li>Facebook Login for Business → Embedded Signup config</li>
                                        <li>Save App ID + Config ID + App Secret here</li>
                                        <li>Click <strong>Connect WhatsApp</strong></li>
                                        <li>Public HTTPS webhook under Webhooks tab</li>
                                    </ol>
                                    <?php endif; ?>
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
                                    <div class="form-text">ICO or PNG · max 512 KB. Browser tab icon. If empty, the first letter of the page title is shown.</div>
                                    <?php if ($faviconUrl !== ''): ?>
                                        <div class="d-flex align-items-center gap-3 mt-2 branding-preview">
                                            <img src="<?= esc($faviconUrl) ?>" alt="Favicon preview" class="branding-preview-favicon">
                                            <div class="form-check mb-0">
                                                <input class="form-check-input" type="checkbox" name="remove_site_favicon" value="1" id="removeSiteFavicon">
                                                <label class="form-check-label" for="removeSiteFavicon">Remove favicon</label>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <?php
                        $poweredByLogoUrl = ! empty($app['powered_by_logo']) ? base_url(ltrim((string) $app['powered_by_logo'], '/')) : '';
                        $isPbEnabled      = (string) ($app['powered_by_enabled'] ?? '1') === '1';
                        $pbName           = (string) ($app['powered_by_name'] ?? '');
                        $pbUrl            = (string) ($app['powered_by_url'] ?? '');
                        ?>
                        <div class="settings-panel mb-3">
                            <div class="d-flex align-items-center justify-content-between mb-3">
                                <div>
                                    <h6 class="settings-panel-label mb-0">&quot;Powered By&quot; Attribution</h6>
                                    <div class="form-text mt-0">Display developer, platform, or white-label attribution in footer and sidebar.</div>
                                </div>
                                <div class="form-check form-switch mb-0">
                                    <input class="form-check-input" type="checkbox" name="powered_by_enabled" value="1" id="tenantPoweredByToggle" <?= $isPbEnabled ? 'checked' : '' ?> style="cursor:pointer">
                                    <label class="form-check-label fw-bold" for="tenantPoweredByToggle">Enable attribution</label>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Brand / Provider Name</label>
                                    <input type="text" name="powered_by_name" id="tenantPoweredByName" class="form-control" value="<?= esc($pbName) ?>" placeholder="e.g. Sateri Technologies">
                                    <div class="form-text">Company or product name displayed after &quot;Powered by&quot;. Leave blank to use platform default.</div>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Destination Website Link</label>
                                    <input type="url" name="powered_by_url" id="tenantPoweredByUrl" class="form-control" value="<?= esc($pbUrl) ?>" placeholder="https://sateritechnologies.com">
                                    <div class="form-text">Clickable link for the powered by attribution badge.</div>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Provider Micro-Logo / Icon</label>
                                    <input type="file" name="powered_by_logo" id="tenantPoweredByLogoInput" class="form-control" accept=".png,.jpg,.jpeg,.webp,.gif,image/png,image/jpeg,image/webp,image/gif">
                                    <div class="form-text">Small brand logo (max 1 MB, PNG/JPG/WebP). Displayed next to the provider name.</div>
                                    <?php if ($poweredByLogoUrl !== ''): ?>
                                        <div class="d-flex align-items-center gap-3 mt-2 branding-preview">
                                            <img src="<?= esc($poweredByLogoUrl) ?>" alt="Logo preview" style="max-height:22px;width:auto;border-radius:2px" id="tenantPbLogoPreview">
                                            <div class="form-check mb-0">
                                                <input class="form-check-input" type="checkbox" name="remove_powered_by_logo" value="1" id="removePoweredByLogo">
                                                <label class="form-check-label" for="removePoweredByLogo">Remove logo</label>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Attribution Preview</label>
                                    <div class="p-2 border rounded bg-light d-flex align-items-center gap-2" style="min-height:38px">
                                        <span class="text-muted small">Powered by</span>
                                        <a href="<?= esc($pbUrl !== '' ? $pbUrl : '#') ?>" id="tenantPbPreviewLink" target="_blank" rel="noopener" style="font-weight:600;text-decoration:none">
                                            <img src="<?= esc($poweredByLogoUrl) ?>" alt="Logo" id="tenantPbPreviewImg" style="height:14px;width:auto;vertical-align:middle;<?= $poweredByLogoUrl !== '' ? '' : 'display:none;' ?>">
                                            <span id="tenantPbPreviewText"><?= esc($pbName !== '' ? $pbName : 'Sateri Technologies') ?></span>
                                            <i class="fas fa-external-link-alt ms-1" style="font-size:9px"></i>
                                        </a>
                                    </div>
                                    <div class="form-text">Real-time preview of how attribution appears across the console.</div>
                                </div>
                            </div>
                        </div>

                        <div class="settings-panel">
                            <h6 class="settings-panel-label">Application</h6>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Timezone</label>
                                    <?php $selectedTimezone = (string) (old('app_timezone') ?? ($app['app_timezone'] ?? 'UTC')); ?>
                                    <select name="app_timezone" class="form-select">
                                        <?php foreach ($timezoneOptions as $group => $options): ?>
                                            <optgroup label="<?= esc($group) ?>">
                                                <?php foreach ($options as $option): ?>
                                                    <option value="<?= esc($option['value']) ?>" <?= $selectedTimezone === $option['value'] ? 'selected' : '' ?>>
                                                        <?= esc($option['label']) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </optgroup>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="form-text">All dates/times across the app (chat, campaigns, reports, schedules) use this timezone.</div>
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

                        <div class="settings-panel mt-3">
                            <h6 class="settings-panel-label">WhatsApp quiet hours</h6>
                            <div class="row">
                                <div class="col-md-3 mb-3">
                                    <label class="form-label" for="waQuietStart">No marketing from</label>
                                    <input type="time" id="waQuietStart" name="wa_quiet_hours_start" class="form-control" value="<?= $val($app, 'wa_quiet_hours_start') ?>">
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label class="form-label" for="waQuietEnd">Until</label>
                                    <input type="time" id="waQuietEnd" name="wa_quiet_hours_end" class="form-control" value="<?= $val($app, 'wa_quiet_hours_end') ?>">
                                </div>
                                <div class="col-md-6 mb-3 d-flex align-items-end">
                                    <div class="form-text mb-2">
                                        Broadcast campaign messages are held during these hours (timezone above) and go out automatically when the window ends.
                                        Replies, chat and automations are not affected. Example: 21:00 → 09:00. Leave both empty to turn off.
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="settings-panel mt-3">
                            <h6 class="settings-panel-label">WhatsApp marketing limits</h6>
                            <div class="row">
                                <div class="col-md-3 mb-3">
                                    <label class="form-label" for="waMarketingCapDays">Max 1 marketing message per contact every</label>
                                    <div class="input-group">
                                        <input type="number" id="waMarketingCapDays" name="wa_marketing_frequency_cap_days" class="form-control"
                                               min="0" max="30" step="1" required value="<?= $val($app, 'wa_marketing_frequency_cap_days') ?>">
                                        <span class="input-group-text">day(s)</span>
                                    </div>
                                </div>
                                <div class="col-md-9 mb-3 d-flex align-items-end">
                                    <div class="form-text mb-2">
                                        Contacts who already got a MARKETING template in this period are skipped (campaigns, automations, API) and shown in the campaign's skipped summary.
                                        Utility templates, replies and chat are not affected. 0 turns the cap off.
                                    </div>
                                </div>
                                <div class="col-12">
                                    <input type="hidden" name="wa_auto_consent_request" value="0">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" role="switch" id="waAutoConsent" name="wa_auto_consent_request" value="1"
                                            <?= ($app['wa_auto_consent_request'] ?? '0') === '1' ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="waAutoConsent">Automatically ask new contacts for WhatsApp opt-in (once per contact)</label>
                                    </div>
                                    <div class="form-text">
                                        Off (recommended): ask only from a contact's page with "Request opt-in". Unrequested opt-in messages can lower your WhatsApp quality rating.
                                    </div>
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
                                    <p class="wp-lead">Password resets, automation alerts, and future email campaigns all use <code>service('emailProvider')</code>. Switch SMTP, Cheerio, or Amazon SES without changing app code.</p>
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
                                <label class="wp-option <?= $isSesEmail ? 'is-active' : '' ?>" data-tone="ses">
                                    <input type="radio" class="visually-hidden" name="email_provider" value="ses" <?= $isSesEmail ? 'checked' : '' ?> data-email-provider-toggle>
                                    <span class="wp-option-rail"></span>
                                    <span class="wp-option-body">
                                        <span class="wp-option-icon"><i class="fab fa-aws"></i></span>
                                        <span class="wp-option-text">
                                            <span class="wp-option-name">Amazon SES</span>
                                            <span class="wp-option-desc">API v2 · AWS cloud email delivery</span>
                                        </span>
                                        <span class="wp-option-meta">
                                            <span class="wp-chip">Cloud API</span>
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

                            <div id="panelEmailSes" class="wp-creds mt-3 <?= ! $isSesEmail ? 'd-none' : '' ?>">
                                <h6 class="text-muted text-uppercase small mb-3">Amazon SES Credentials (API v2)</h6>
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">AWS Access Key ID</label>
                                        <input type="text" name="ses_access_key" class="form-control" value="<?= $val($ses, 'access_key') ?>" placeholder="AKIAIOSFODNN7EXAMPLE">
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">AWS Secret Access Key</label>
                                        <div class="input-group input-secret">
                                            <input type="password" name="ses_secret_key" class="form-control" value="<?= $val($ses, 'secret_key') ?>" autocomplete="off" placeholder="wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY">
                                            <button class="btn btn-outline-secondary toggle-secret" type="button"><i class="fas fa-eye"></i></button>
                                        </div>
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label">AWS Region</label>
                                        <input type="text" name="ses_region" class="form-control" value="<?= $val($ses, 'region', 'ap-south-1') ?>" placeholder="ap-south-1">
                                        <div class="form-text">e.g. ap-south-1 (Mumbai), us-east-1, eu-west-1</div>
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label">From Email (Verified Identity)</label>
                                        <input type="email" name="ses_from_email" class="form-control" value="<?= $val($ses, 'from_email') ?>" placeholder="noreply@yourdomain.com">
                                        <div class="form-text">Must be verified in AWS SES console</div>
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label">From Name</label>
                                        <input type="text" name="ses_from_name" class="form-control" value="<?= $val($ses, 'from_name') ?>" placeholder="Sateri Connect">
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label">Configuration Set <span class="text-muted">(optional)</span></label>
                                        <input type="text" name="ses_configuration_set" class="form-control" value="<?= $val($ses, 'configuration_set') ?>" placeholder="sateri-campaigns">
                                        <div class="form-text">Enables SES event publishing &amp; per-campaign tags</div>
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label">Max Send Rate (emails/sec)</label>
                                        <input type="number" name="ses_max_send_rate" class="form-control" min="1" max="1000" step="1" value="<?= $val($ses, 'max_send_rate') ?>" placeholder="Auto (account quota)">
                                        <div class="form-text">Leave blank to use your SES account quota</div>
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label">SNS Topic ARN</label>
                                        <input type="text" name="ses_sns_topic_arn" class="form-control font-monospace" value="<?= $val($ses, 'sns_topic_arn') ?>" placeholder="Auto-saved on first subscription">
                                        <div class="form-text">Only this topic is accepted. Clear to re-link.</div>
                                    </div>
                                    <div class="col-12 mb-3">
                                        <label class="form-label" for="sesWebhookUrl">Bounce &amp; Complaint Webhook (SNS HTTPS subscription)</label>
                                        <div class="input-group">
                                            <input type="text" class="form-control font-monospace" id="sesWebhookUrl" value="<?= esc(\App\Libraries\EmailTracking::sesWebhookUrl()) ?>" readonly>
                                            <button class="btn btn-outline-secondary" type="button" id="btnCopySesWebhook"><i class="fas fa-copy"></i> Copy</button>
                                        </div>
                                        <div class="form-text">In AWS: create an SNS topic → add an HTTPS subscription with this URL → set it as the Bounce &amp; Complaint notification topic of your SES identity. Hard bounces and complaints are auto-suppressed.</div>
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
                             data-setup-url="<?= site_url('settings/setup-webhook') ?>"
                             data-provider="<?= esc($whProvider) ?>">
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
                                        <?php if ($isWhMeta): ?>
                                            This token syncs automatically to your configured Meta App via Graph API when you click Save URL or Save Settings.
                                        <?php else: ?>
                                            Paste this token into the Cheerio webhook form. It must be <strong>identical</strong> in both your App and provider settings.
                                        <?php endif; ?>
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
                                            <li>Start tunnel: <code>cloudflared tunnel --url http://127.0.0.1:80</code></li>
                                            <li>Open Settings in browser using the <strong>tunnel HTTPS URL</strong></li>
                                            <li>Click <strong>Auto</strong> → Save (or paste manually)</li>
                                        </ol>
                                    <?php else: ?>
                                        <p class="small text-muted mb-3">
                                            On a live domain, callback URL is <strong>auto-detected and saved</strong> when opening Settings.
                                            Use <strong>Auto</strong> to re-detect anytime. Override only if necessary.
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
                                        <strong><?= $isWhMeta ? 'Meta API sync & test' : 'Paste into provider & test' ?></strong>
                                        <span class="badge <?= $step3 ? 'text-bg-success' : 'text-bg-secondary' ?>" id="badgeStep3">
                                            <?= $step3 ? 'Ready' : 'Waiting' ?>
                                        </span>
                                    </div>
                                </div>
                                <div class="settings-step-body">
                                    <label class="form-label" for="webhookPublicCallback">Callback URL (editable — copy into provider)</label>
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
                                        You can edit or paste the full callback URL. Saving updates the host and auto-formats the webhook path.
                                    </div>

                                    <div class="settings-guide mb-3 small">
                                        <?php if ($isWhMeta): ?>
                                            <div class="alert alert-info border-0 py-2 px-3 small mb-3" role="note">
                                                <strong>Automatic Meta setup:</strong> Clicking <strong>Save URL</strong> or footer
                                                <strong>Save Settings</strong> syncs the Callback URL, Verify Token, <code>messages</code> fields,
                                                and WABA overrides directly to Meta via Graph API.
                                            </div>
                                            <div class="fw-semibold mb-2">Required Meta credentials:</div>
                                            <ol class="mb-2 ps-3">
                                                <li>Access Token, WABA ID, App ID, and App Secret must be saved under Settings → Provider.</li>
                                                <li>Public callback must be a valid HTTPS URL reachable by Meta verification requests.</li>
                                                <li>Use manual fallback in the <a href="https://developers.facebook.com/apps/" target="_blank" rel="noopener">Meta Dashboard</a> only if an API warning appears.</li>
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

                    <div class="tab-pane fade" id="tabElintOm" role="tabpanel">
                        <?php $elintom = $elintom ?? ['base_url' => '', 'private_key' => '']; ?>
                        <div class="settings-section-head">
                            <div>
                                <p class="settings-section-kicker">POS integration</p>
                                <h3 class="settings-section-title">ElintOm POS</h3>
                            </div>
                        </div>
                        <div class="alert alert-light border settings-note">
                            Pull customers from ElintOm (<code>sma_companies</code> via Api3 <code>sateri_contacts</code>) into
                            <a href="<?= site_url('contacts') ?>">Contacts</a>.
                            ElintOm madhe <strong>API access</strong> on asava ani <code>api_privatekey</code> match hova.
                        </div>
                        <div class="row g-3">
                            <div class="col-lg-8">
                                <div class="mb-3">
                                    <label class="form-label" for="elintom_base_url">ElintOm domain URL</label>
                                    <input type="url" name="elintom_base_url" id="elintom_base_url" class="form-control"
                                           value="<?= $val($elintom, 'base_url') ?>"
                                           placeholder="http://localhost/ElintOm">
                                    <div class="form-text">No trailing slash. Example: <code>https://pos.example.com</code></div>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label" for="elintom_api_private_key">Api3 private key</label>
                                    <div class="input-group input-secret">
                                        <input type="password" name="elintom_api_private_key" id="elintom_api_private_key"
                                               class="form-control" value="<?= $val($elintom, 'private_key') ?>"
                                               autocomplete="off" placeholder="Same as ElintOm sma_settings.api_privatekey">
                                        <button class="btn btn-outline-secondary toggle-secret" type="button"><i class="fas fa-eye"></i></button>
                                    </div>
                                    <div class="form-text">Leave masked blank to keep the current key.</div>
                                </div>
                                <div class="d-flex flex-wrap gap-2">
                                    <?php if (function_exists('can') && can('settings.edit')): ?>
                                        <button type="button" class="btn btn-outline-secondary" id="btnTestElintOm">
                                            <i class="fas fa-plug me-1"></i> Test connection
                                        </button>
                                    <?php endif; ?>
                                    <?php if (function_exists('can') && (can('contacts.import') || can('settings.edit'))): ?>
                                        <button type="button" class="btn btn-wa" id="btnSyncElintOmSettings">
                                            <i class="fas fa-cash-register me-1"></i> Sync customers now
                                        </button>
                                    <?php endif; ?>
                                    <a class="btn btn-outline-secondary" href="<?= site_url('contacts') ?>">
                                        Open Contacts
                                    </a>
                                </div>
                                <div id="elintomTestResult" class="creds-test-result mt-2"></div>
                            </div>
                            <div class="col-lg-4">
                                <aside class="wp-creds-aside">
                                    <p class="wp-aside-kicker">Setup path</p>
                                    <ol class="wp-steps">
                                        <li>ElintOm → enable API access + copy private key</li>
                                        <li>Paste domain URL + key here → Save Settings</li>
                                        <li>Test connection</li>
                                        <li>Sync customers (or use Contacts page button)</li>
                                    </ol>
                                </aside>
                            </div>
                        </div>
                    </div>

                    <div class="tab-pane fade" id="tabAi" role="tabpanel">
                        <div class="settings-pane-header">
                            <h2 class="settings-pane-title"><i class="fas fa-robot text-wa me-2"></i> AI Assistant & Gemini Auto-Bot</h2>
                            <p class="settings-pane-sub">Power your WhatsApp customer support with Google Gemini 1.5 Flash. Built-in anti-ban guardrails, rate limiting, and 24-hour service window awareness.</p>
                        </div>

                        <div class="row g-4">
                            <div class="col-lg-8">
                                <div class="card mb-4 border-0 shadow-sm">
                                    <div class="card-body">
                                        <div class="d-flex align-items-center justify-content-between mb-3 pb-3 border-bottom">
                                            <div>
                                                <h6 class="mb-1 fw-bold">Enable AI Auto-Bot</h6>
                                                <div class="text-muted small">When active, AI answers inbound customer queries when no keyword rules match.</div>
                                            </div>
                                            <div class="form-check form-switch fs-4 mb-0">
                                                <input class="form-check-input" type="checkbox" role="switch" name="ai_enabled" value="1" id="aiEnabled" <?= !empty($ai['enabled']) ? 'checked' : '' ?>>
                                            </div>
                                        </div>

                                        <div class="row g-3">
                                            <div class="col-md-6">
                                                <label class="form-label fw-semibold">AI Provider</label>
                                                <select name="ai_provider" class="form-select" id="aiProvider">
                                                    <option value="gemini" selected>Google Gemini (Recommended)</option>
                                                </select>
                                                <div class="form-text">Lowest latency & cost-effective for multi-lingual chats.</div>
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label fw-semibold">Model</label>
                                                <select name="ai_model" class="form-select" id="aiModel">
                                                    <option value="gemini-flash-latest" <?= in_array($ai['model'] ?? '', ['gemini-flash-latest', 'gemini-1.5-flash', ''], true) ? 'selected' : '' ?>>Gemini Flash (Latest & Recommended)</option>
                                                    <option value="gemini-2.5-flash" <?= ($ai['model'] ?? '') === 'gemini-2.5-flash' ? 'selected' : '' ?>>Gemini 2.5 Flash</option>
                                                </select>
                                            </div>
                                            <div class="col-12">
                                                <label class="form-label fw-semibold">Gemini API Key</label>
                                                <div class="input-group">
                                                    <input type="password" name="ai_api_key" id="aiApiKey" class="form-control font-monospace" value="<?= esc($ai['api_key'] ?? '') ?>" placeholder="Paste Gemini API Key (e.g. AIza... or AQ...)" autocomplete="new-password" data-lpignore="true" data-1p-ignore="true">
                                                    <button type="button" class="btn btn-outline-secondary toggle-secret"><i class="fas fa-eye"></i></button>
                                                    <button type="button" class="btn btn-outline-primary" id="btnTestAi"><i class="fas fa-plug me-1"></i> Test Connection</button>
                                                </div>
                                                <div id="aiTestResult" class="mt-2 small"></div>
                                                <div class="form-text">Get your free API key from <a href="https://aistudio.google.com/app/apikey" target="_blank" rel="noopener">Google AI Studio</a>.</div>
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label fw-semibold">Business / Brand Name</label>
                                                <input type="text" name="ai_business_name" class="form-control" value="<?= esc($ai['business_name'] ?? '') ?>" placeholder="e.g. Sateri Connect">
                                            </div>
                                            <div class="col-12">
                                                <label class="form-label fw-semibold">System Instructions & Business FAQ (Knowledge Base)</label>
                                                <textarea name="ai_system_prompt" class="form-control font-monospace small" rows="5" placeholder="Define your business details, products, working hours, pricing guidelines, etc."><?= esc($ai['system_prompt'] ?? '') ?></textarea>
                                                <div class="form-text">AI strictly follows this prompt to answer queries in Marathi, Hindi, and English.</div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="card border-0 shadow-sm">
                                    <div class="card-header bg-light-subtle py-3">
                                        <h6 class="mb-0 fw-bold"><i class="fas fa-shield-alt text-danger me-2"></i> WhatsApp Anti-Ban & Loop Prevention Guardrails</h6>
                                    </div>
                                    <div class="card-body">
                                        <div class="row g-3">
                                            <div class="col-md-6">
                                                <label class="form-label fw-semibold">Max Consecutive AI Replies</label>
                                                <input type="number" min="1" max="10" name="ai_max_consecutive_replies" class="form-control" value="<?= esc($ai['max_consecutive_replies'] ?? 3) ?>">
                                                <div class="form-text">Prevents endless bot-to-bot ping pong loops. Bot pauses and alerts staff after this limit.</div>
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label fw-semibold">Cooldown Between Replies (seconds)</label>
                                                <input type="number" min="2" max="60" name="ai_cooldown_seconds" class="form-control" value="<?= esc($ai['cooldown_seconds'] ?? 3) ?>">
                                                <div class="form-text">Enforces natural human response spacing. Meta blocks instant machine blasts.</div>
                                            </div>
                                            <div class="col-12">
                                                <label class="form-label fw-semibold">Human Takeover Keywords (comma separated)</label>
                                                <input type="text" name="ai_human_keywords" class="form-control" value="<?= esc($ai['human_keywords'] ?? '') ?>" placeholder="human,agent,support,representative,manus,madat">
                                                <div class="form-text">When customer types any of these, AI instantly halts and hands over the chat to a human agent.</div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="col-lg-4">
                                <aside class="wp-creds-aside">
                                    <p class="wp-aside-kicker">Built-in Meta Safety</p>
                                    <ul class="list-unstyled small mb-4">
                                        <li class="mb-2"><i class="fas fa-check-circle text-success me-2"></i><strong>24h Service Window:</strong> AI only replies within the active service window opened by customer.</li>
                                        <li class="mb-2"><i class="fas fa-check-circle text-success me-2"></i><strong>Opt-Out Compliance:</strong> STOP, UNSUBSCRIBE, and CANCEL automatically override and disable AI.</li>
                                        <li class="mb-2"><i class="fas fa-check-circle text-success me-2"></i><strong>Anti-Loop Guard:</strong> Cooldown & consecutive reply caps protect number quality rating.</li>
                                        <li class="mb-2"><i class="fas fa-check-circle text-success me-2"></i><strong>Content Safety:</strong> Guardrails prevent unauthorized discounts or policy-violating messages.</li>
                                    </ul>
                                    <p class="wp-aside-kicker">Live Chat Copilot</p>
                                    <p class="text-muted small">In Live Chat inbox, agents can click <strong>"AI Suggest"</strong> for 3 instant reply options or <strong>"Summarize Chat"</strong> for quick lead notes.</p>
                                </aside>
                            </div>
                        </div>
                    </div>

                    <!-- Developer API Keys Tab -->
                    <div class="tab-pane fade" id="tabApiKeys" role="tabpanel">
                        <div class="d-flex justify-content-between align-items-center mb-4 pb-2 border-bottom flex-wrap gap-2">
                            <div>
                                <h4 class="fw-bold mb-1"><i class="fas fa-key text-success me-2"></i>Developer REST API &amp; Secret Keys</h4>
                                <p class="text-muted small mb-0">Manage permanent API keys for external systems, WooCommerce, Shopify, CRM, and custom scripts.</p>
                            </div>
                            <a href="<?= site_url('api/docs') ?>" target="_blank" class="btn btn-sm btn-outline-success">
                                <i class="fas fa-book-open me-1"></i> Interactive API Docs
                            </a>
                        </div>

                        <!-- Live API Base URL Card -->
                        <div class="card p-3 mb-4 border-0 shadow-sm" style="background: linear-gradient(135deg, #0b1329 0%, #1e293b 100%); color: #fff; border-radius: 12px;">
                            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                                <div>
                                    <span class="badge bg-success mb-1" style="font-size: 0.72rem; letter-spacing: 0.05em;">LIVE API BASE ENDPOINT</span>
                                    <div class="font-monospace fw-bold fs-6 text-warning" id="liveApiBaseUrlText"><?= esc(site_url('api/v1')) ?></div>
                                    <p class="small text-light opacity-75 mb-0" style="font-size: 0.8rem;">Use this endpoint URL in your external CRM, billing system, or Postman environment.</p>
                                </div>
                                <div class="d-flex gap-2">
                                    <button class="btn btn-sm btn-outline-light" type="button" onclick="navigator.clipboard.writeText('<?= esc(site_url('api/v1')) ?>'); alert('API Base URL copied to clipboard: <?= esc(site_url('api/v1')) ?>');">
                                        <i class="fas fa-copy me-1"></i> Copy Base URL
                                    </button>
                                    <a href="<?= site_url('api/v1/postman') ?>" class="btn btn-sm btn-warning text-dark fw-semibold">
                                        <i class="fas fa-file-arrow-down me-1"></i> Postman Collection
                                    </a>
                                </div>
                            </div>
                        </div>

                        <!-- Generated Key Display Alert (Hidden by default) -->
                        <div id="newKeyAlert" class="alert alert-success d-none mb-4 shadow-sm border-2">
                            <div class="d-flex align-items-center justify-content-between mb-2">
                                <div class="fw-bold text-success"><i class="fas fa-check-circle me-1"></i> New API Key Generated!</div>
                                <button type="button" class="btn-close" id="btnCloseKeyAlert"></button>
                            </div>
                            <p class="small text-dark mb-2">Make sure to copy your API key now. <strong>You will not be able to see it again!</strong></p>
                            <div class="input-group">
                                <input type="text" id="newKeySecretInput" class="form-control font-monospace fw-bold bg-white" readonly>
                                <button class="btn btn-success" type="button" id="btnCopyNewKey">
                                    <i class="fas fa-copy me-1"></i> Copy Key
                                </button>
                            </div>
                        </div>

                        <!-- Generate Form Card -->
                        <div class="card p-3 mb-4 border bg-light">
                            <div class="row g-2 align-items-center">
                                <div class="col-md-7">
                                    <label class="form-label small fw-semibold mb-1" for="apiKeyNameInput">Key Description / Client Name</label>
                                    <input type="text" class="form-control form-control-sm" id="apiKeyNameInput" placeholder="e.g. WooCommerce Store, CRM Sync, Zapier" value="External API Client">
                                </div>
                                <div class="col-md-5 d-flex align-items-end">
                                    <button type="button" class="btn btn-sm btn-wa w-100" id="btnGenerateApiKey">
                                        <i class="fas fa-plus-circle me-1"></i> Generate Secret Key
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- Existing API Keys Table -->
                        <h6 class="fw-bold mb-2">Active API Keys</h6>
                        <div class="table-responsive bg-white border rounded">
                            <table class="table table-hover align-middle mb-0" style="font-size: 0.85rem;" id="apiTokensTable">
                                <thead class="table-light">
                                    <tr>
                                        <th>Name</th>
                                        <th>Token Identifier</th>
                                        <th>Created</th>
                                        <th>Last Used</th>
                                        <th>Calls (30 days)</th>
                                        <th class="text-end">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php $tokens = $apiTokens ?? []; ?>
                                    <?php if (empty($tokens)): ?>
                                        <tr id="noApiTokensRow">
                                            <td colspan="6" class="text-center py-4 text-muted">
                                                <i class="fas fa-key fs-4 d-block mb-1 text-secondary opacity-50"></i>
                                                No API keys generated yet. Click "Generate Secret Key" above.
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($tokens as $tk): ?>
                                            <tr id="tokenRow_<?= (int) $tk['id'] ?>">
                                                <td class="fw-semibold text-dark"><?= esc($tk['name']) ?></td>
                                                <td><code class="text-secondary">sc_live_••••<?= substr(hash('crc32', (string) $tk['id']), 0, 6) ?></code></td>
                                                <td class="text-muted"><?= esc($tk['created_at'] ?? '—') ?></td>
                                                <td>
                                                    <?= ! empty($tk['last_used_at']) ? '<span class="badge bg-success-subtle text-success">' . esc($tk['last_used_at']) . '</span>' : '<span class="text-muted">Never</span>' ?>
                                                </td>
                                                <td>
                                                    <?php $use = $apiUsage[(int) $tk['id']] ?? null; ?>
                                                    <?php if ($use): ?>
                                                        <span class="fw-semibold"><?= (int) $use['total'] ?></span>
                                                        <?php if ((int) $use['failed'] > 0): ?><span class="badge bg-danger-subtle text-danger ms-1"><?= (int) $use['failed'] ?> failed</span><?php endif; ?>
                                                        <div class="small text-muted"><?= (int) $use['ips'] ?> IP<?= (int) $use['ips'] === 1 ? '' : 's' ?></div>
                                                    <?php else: ?>
                                                        <span class="text-muted">0</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-end">
                                                    <button type="button" class="btn btn-xs btn-outline-danger js-delete-api-token" data-id="<?= (int) $tk['id'] ?>">
                                                        <i class="fas fa-trash-alt me-1"></i> Revoke
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <h6 class="fw-bold mt-4 mb-1">Recent API Calls</h6>
                        <p class="small text-muted mb-2">Last 50 requests made by external systems with an API key.</p>
                        <div class="table-responsive bg-white border rounded" style="max-height: 360px;">
                            <table class="table table-hover align-middle mb-0" style="font-size: 0.8rem;" id="apiRecentCallsTable">
                                <thead class="table-light">
                                    <tr>
                                        <th>Time</th>
                                        <th>API Key</th>
                                        <th>Request</th>
                                        <th>Status</th>
                                        <th>IP / Client</th>
                                        <th class="text-end">Time taken</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php $calls = $apiRecentCalls ?? []; ?>
                                    <?php if (empty($calls)): ?>
                                        <tr>
                                            <td colspan="6" class="text-center py-4 text-muted">No external API calls recorded yet.</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($calls as $call): ?>
                                            <?php $code = (int) $call['status_code']; ?>
                                            <tr>
                                                <td class="text-muted text-nowrap"><?= esc($call['created_at'] ?? '') ?></td>
                                                <td class="fw-semibold"><?= esc($call['token_name'] ?: ('#' . (int) $call['token_id'])) ?></td>
                                                <td><span class="badge bg-light text-dark border me-1"><?= esc($call['method']) ?></span><code class="text-secondary"><?= esc($call['endpoint']) ?></code></td>
                                                <td><span class="badge <?= $code >= 400 ? 'bg-danger-subtle text-danger' : 'bg-success-subtle text-success' ?>"><?= $code ?></span></td>
                                                <td>
                                                    <div><?= esc($call['ip_address'] ?? '') ?></div>
                                                    <div class="small text-muted text-truncate" style="max-width: 220px;" title="<?= esc($call['user_agent'] ?? '', 'attr') ?>"><?= esc($call['user_agent'] ?? '') ?></div>
                                                </td>
                                                <td class="text-end text-muted"><?= (int) $call['duration_ms'] ?> ms</td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <div class="mt-4 p-3 bg-white rounded border">
                            <h6 class="fw-bold text-dark mb-1"><i class="fas fa-terminal text-primary me-2"></i>Quick Example Header</h6>
                            <p class="small text-muted mb-2">Include this header in all HTTP requests to your Sateri Connect endpoints:</p>
                            <pre class="p-2 bg-dark text-white rounded small m-0 code-font">X-API-Key: sc_live_YOUR_API_KEY_HERE</pre>
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
<script src="<?= asset_url('assets/js/meta-embedded-signup.js') ?>"></script>
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
            .text(done ? (doneText || 'Done') : (doneText || 'Pending'));
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
    $('#btnCopySesWebhook').on('click', function () {
        copyText($('#sesWebhookUrl').val(), 'SES webhook URL');
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
                var remoteConfigured = data.remote_configured !== false;
                if (data.public_base) $('#webhookPublicBase').val(data.public_base);
                if (data.public_callback) $('#webhookPublicCallback').val(data.public_callback);
                setStepBadge('badgeStep2', true);
                setStepBadge('badgeStep3', remoteConfigured, remoteConfigured ? 'Synced' : 'Warning');
                APP.toast(res.message || 'Public URL saved', remoteConfigured ? 'success' : 'warning');
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
                var remoteConfigured = data.remote_configured !== false;
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
                setStepBadge('badgeStep3', remoteConfigured, remoteConfigured ? 'Synced' : 'Warning');
                APP.toast(res.message || 'Callback auto-saved', remoteConfigured ? 'success' : 'warning');
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
        $('#panelEmailCheerio').toggleClass('d-none', provider !== 'cheerio');
        $('#panelEmailSes').toggleClass('d-none', provider !== 'ses');
        $('#emailStage .wp-option').removeClass('is-active');
        $('input[name="email_provider"]:checked').closest('.wp-option').addClass('is-active');
        $('#emailStage').attr('data-email-provider', provider);
        var label = provider === 'ses' ? 'Amazon SES' : (provider === 'cheerio' ? 'Cheerio Email API' : 'SMTP');
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
        setBadge(
            'template',
            !!(byId.approved_templates && byId.approved_templates.ok),
            (byId.approved_templates && byId.approved_templates.detail)
                || (byId.templates_api && byId.templates_api.detail)
                || 'Sync approved templates from this WABA (do not rely on hello_world sample)'
        );
        if ($('[data-check="webhook_fields"]').length) {
            setBadge(
                'webhook_fields',
                !!(byId.webhook_fields && byId.webhook_fields.ok),
                (byId.webhook_fields && byId.webhook_fields.detail) || 'Subscribe messages in Meta App Dashboard'
            );
        }
        var conn = (data && data.connection) ? data.connection : {};
        var summary = [
            'WhatsApp: ' + (conn.whatsapp || (ok ? 'Connected' : 'Not connected')),
            'WABA: ' + (conn.waba || ((byId.waba_id && byId.waba_id.ok) ? 'Connected' : 'Not connected')),
            'Phone Number: ' + (conn.phone_number || ((byId.phone_number_id && byId.phone_number_id.ok && byId.graph_api && byId.graph_api.ok) ? 'Connected' : 'Not connected')),
            'Templates: ' + (data && data.template_count != null ? data.template_count : '-'),
            'Approved: ' + (data && data.approved_templates != null ? data.approved_templates : '-'),
            'Pending: ' + (data && data.pending_templates != null ? data.pending_templates : '-'),
            'Rejected: ' + (data && data.rejected_templates != null ? data.rejected_templates : '-'),
            'hello_world on WABA: ' + ((data && data.hello_world_exists) ? 'yes' : 'no')
        ].join('\n');
        $('#goLiveDetails').text(summary + '\n\n' + JSON.stringify(data || {}, null, 2));
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

    // ── Meta Embedded Signup (Connect WhatsApp) — Facebook JS SDK ────────
    (function initMetaEmbeddedSignup() {
        var $box = $('#metaEmbeddedSignupBox');
        if (!$box.length) return;

        function setResult(html, ok) {
            $('#metaEmbedResult').html(
                '<span class="' + (ok ? 'text-success' : 'text-danger') + '">' + html + '</span>'
            );
        }

        if (!APP.MetaEmbeddedSignup) {
            $('#metaSdkStatus').removeClass('text-bg-secondary text-bg-success').addClass('text-bg-danger').text('SDK: missing');
            setResult('meta-embedded-signup.js failed to load.', false);
            return;
        }

        var pendingSession = null;
        var pendingCode = null;
        var completing = false;

        // Meta validates the page origin against "Allowed Domains for the JavaScript SDK",
        // so show the exact string instead of making the admin guess it.
        (function showSdkOrigin() {
            var origin = window.location.origin || (window.location.protocol + '//' + window.location.host);
            $('#metaSdkOrigin').text(origin);

            if (window.location.protocol !== 'https:') {
                $('#metaSdkOriginHint')
                    .addClass('text-danger')
                    .html('<strong>This page is not HTTPS.</strong> Meta only allows the JavaScript SDK login on HTTPS pages. Open Settings over HTTPS before connecting.');
            }

            $('#btnCopySdkOrigin').on('click', function () {
                var value = $('#metaSdkOrigin').text();
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(value).then(function () {
                        APP.toast('Host domain copied. Paste it into Meta → Allowed Domains for the JavaScript SDK.', 'success');
                    });
                    return;
                }
                window.prompt('Copy this host domain into Meta:', value);
            });
        })();

        function isManaged() {
            return $box.attr('data-managed') === '1';
        }

        function embedCfg() {
            return {
                appId: ($('#meta_app_id').val() || $box.attr('data-app-id') || '').toString().trim(),
                configId: ($('#meta_embedded_config_id').val() || $box.attr('data-config-id') || '').toString().trim(),
                apiVersion: ($('#meta_api_version').val() || $box.attr('data-api-version') || 'v21.0').toString().trim() || 'v21.0'
            };
        }

        function setSdkStatus(state, label) {
            var $badge = $('#metaSdkStatus');
            $badge
                .removeClass('text-bg-secondary text-bg-success text-bg-danger text-bg-warning')
                .addClass(state)
                .text(label);
        }

        function setReady() {
            var c = embedCfg();
            var ready = !!(c.appId && c.configId);
            // Keep server "ready" (includes App Secret) when platform-managed.
            if (isManaged() && $box.attr('data-ready') === '1') {
                ready = true;
            }
            if (!isManaged()) {
                $box.attr('data-ready', ready ? '1' : '0');
            }
            $('#btnConnectWhatsApp').prop('disabled', !ready || !APP.MetaEmbeddedSignup.isSdkReady());
            if (!ready && !isManaged()) {
                $('#metaConnectSummary').text('Save App ID + Config ID + App Secret, then connect.');
            } else if (!ready && isManaged()) {
                $('#metaConnectSummary').text('Platform Tech Provider credentials are incomplete.');
            }
        }

        function applyConnectedIdentity(data) {
            if (!data) return;
            APP.whatsappProvider = 'meta';
            if (typeof APP.applyWaIdentity === 'function') {
                APP.applyWaIdentity({
                    display_name: data.verified_name || data.display_name || '',
                    phone: data.display_phone || data.phone || '',
                    profile_picture_url: data.profile_picture_url || ''
                });
            }
            if (typeof APP.refreshWaIdentity === 'function') {
                APP.refreshWaIdentity(true);
            }
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
                applyConnectedIdentity(data);
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

        function onEmbeddedSession(data) {
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
        }

        function preloadSdk() {
            var c = embedCfg();
            setSdkStatus('text-bg-warning', 'SDK: loading…');
            APP.MetaEmbeddedSignup.preload({ appId: c.appId, apiVersion: c.apiVersion })
                .then(function () {
                    setSdkStatus('text-bg-success', 'SDK: ready');
                    setReady();
                })
                .catch(function (err) {
                    setSdkStatus('text-bg-danger', 'SDK: failed');
                    setResult($('<div>').text((err && err.message) || 'Facebook SDK failed to load').html(), false);
                    setReady();
                });
        }

        function launchEmbeddedSignup(c) {
            setResult('Opening Meta Embedded Signup (Facebook SDK)…', true);
            APP.MetaEmbeddedSignup.launch({
                appId: c.appId,
                configId: c.configId,
                apiVersion: c.apiVersion,
                onSession: onEmbeddedSession,
                onCode: function (code) {
                    pendingCode = code;
                    tryComplete();
                },
                onCancel: function () {
                    if (!pendingSession) {
                        setResult('Meta login did not return an auth code. Check App Review / Advanced Access on Meta.', false);
                        setReady();
                    }
                }
            }).catch(function (err) {
                setResult($('<div>').text((err && err.message) || 'Could not launch Embedded Signup').html(), false);
                APP.toast((err && err.message) || 'Embedded Signup failed', 'error');
                setReady();
            });
        }

        $('#btnConnectWhatsApp').on('click', function () {
            var c = embedCfg();
            if (!c.appId || !c.configId) {
                APP.toast(
                    isManaged()
                        ? 'Platform Tech Provider App ID / Config ID missing. Ask platform admin.'
                        : 'Enter Meta App ID and Embedded Signup Config ID first.',
                    'warning'
                );
                return;
            }
            if (!APP.MetaEmbeddedSignup.isSdkReady()) {
                APP.toast('Facebook SDK is still loading. Wait a second and try again.', 'warning');
                preloadSdk();
                return;
            }

            // Platform-managed: token exchange uses platform App Secret — launch directly.
            if (isManaged()) {
                var pinOnly = ($('#meta_two_step_pin').val() || '').toString().trim();
                if (/^\d{6}$/.test(pinOnly)) {
                    APP.post(APP.baseUrl + '/settings/save', {
                        section: 'meta',
                        meta_two_step_pin: pinOnly,
                        meta_phone_number_id: ($('#meta_phone_number_id').val() || '').toString(),
                        meta_waba_id: ($('#meta_waba_id').val() || '').toString(),
                        meta_api_version: c.apiVersion
                    }).always(function () {
                        launchEmbeddedSignup(c);
                    });
                    return;
                }
                launchEmbeddedSignup(c);
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
                inbox_messenger_enabled: $('#inboxMessengerEnabled').is(':checked') ? '1' : '',
                meta_webhook_secret: secretVal
            };
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
                    launchEmbeddedSignup(c);
                })
                .fail(function (xhr) {
                    var msg = (xhr.responseJSON && xhr.responseJSON.message) || 'Could not save Meta settings';
                    setResult($('<div>').text(msg).html(), false);
                    APP.toast(msg, 'error');
                    setReady();
                });
        });

        $('#meta_app_id, #meta_embedded_config_id, #meta_api_version').on('input change', function () {
            setReady();
            var c = embedCfg();
            if (c.appId && APP.MetaEmbeddedSignup.isSdkReady()) {
                APP.MetaEmbeddedSignup.preload({ appId: c.appId, apiVersion: c.apiVersion }).catch(function () {});
            }
        });
        $('#btnReloadEmbeddedConfig').on('click', function () {
            setReady();
            preloadSdk();
        });

        setReady();
        preloadSdk();
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

    function elintomPayload() {
        return {
            elintom_base_url: ($('#elintom_base_url').val() || '').toString().trim(),
            elintom_api_private_key: ($('#elintom_api_private_key').val() || '').toString()
        };
    }

    $('#btnTestElintOm').on('click', function () {
        var $btn = $(this);
        var $out = $('#elintomTestResult');
        $btn.prop('disabled', true);
        $out.html('<span class="text-muted">Testing ElintOm…</span>');
        APP.post(APP.baseUrl + '/settings/test-elintom', elintomPayload())
            .done(function (res) {
                var ok = !!(res && res.success);
                var msg = (res && res.message) || (ok ? 'OK' : 'Failed');
                $out.html('<span class="' + (ok ? 'text-success' : 'text-danger') + '">' + $('<div>').text(msg).html() + '</span>');
                APP.toast(msg, ok ? 'success' : 'error');
            })
            .fail(function (xhr) {
                var msg = (xhr.responseJSON && xhr.responseJSON.message) || 'ElintOm test failed';
                $out.html('<span class="text-danger">' + $('<div>').text(msg).html() + '</span>');
                APP.toast(msg, 'error');
            })
            .always(function () { $btn.prop('disabled', false); });
    });

    $('#btnSyncElintOmSettings').on('click', function () {
        var $btn = $(this);
        var $out = $('#elintomTestResult');
        var html = $btn.html();
        $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin me-1"></i> Syncing…');
        $out.html('<span class="text-muted">Saving settings, then syncing…</span>');

        var saveData = $.extend({ section: 'elintom' }, elintomPayload());
        APP.post(APP.baseUrl + '/settings/save', saveData)
            .done(function () {
                APP.post(APP.baseUrl + '/settings/sync-elintom', {})
                    .done(function (res) {
                        var ok = !!(res && res.success);
                        var msg = (res && res.message) || 'Sync complete';
                        $out.html('<span class="' + (ok ? 'text-success' : 'text-danger') + '">' + $('<div>').text(msg).html() + '</span>');
                        APP.toast(msg, ok ? 'success' : 'error');
                    })
                    .fail(function (xhr) {
                        var msg = (xhr.responseJSON && xhr.responseJSON.message) || 'ElintOm sync failed';
                        $out.html('<span class="text-danger">' + $('<div>').text(msg).html() + '</span>');
                        APP.toast(msg, 'error');
                    })
                    .always(function () { $btn.prop('disabled', false).html(html); });
            })
            .fail(function (xhr) {
                var msg = (xhr.responseJSON && xhr.responseJSON.message) || 'Could not save ElintOm settings';
                $out.html('<span class="text-danger">' + $('<div>').text(msg).html() + '</span>');
                APP.toast(msg, 'error');
                $btn.prop('disabled', false).html(html);
            });
    });

    $('#btnTestAi').on('click', function () {
        var $btn = $(this);
        var $out = $('#aiTestResult');
        var apiKey = ($('#aiApiKey').val() || '').toString().trim();
        var model = ($('#aiModel').val() || '').toString().trim();

        $btn.prop('disabled', true);
        $out.html('<span class="text-muted"><i class="fas fa-spinner fa-spin me-1"></i> Testing connection to Gemini API…</span>');

        APP.post(APP.baseUrl + '/settings/test-ai', {
            api_key: apiKey,
            model: model
        })
            .done(function (res) {
                var ok = !!(res && res.success);
                var msg = (res && res.message) || (ok ? 'Connected' : 'Connection failed');
                $out.html('<span class="' + (ok ? 'text-success fw-semibold' : 'text-danger') + '">' + $('<div>').text(msg).html() + '</span>');
                APP.toast(msg, ok ? 'success' : 'error');
            })
            .fail(function (xhr) {
                var msg = (xhr.responseJSON && xhr.responseJSON.message) || 'Gemini API test failed';
                $out.html('<span class="text-danger">' + $('<div>').text(msg).html() + '</span>');
                APP.toast(msg, 'error');
            })
            .always(function () { $btn.prop('disabled', false); });
    });

    // Developer API Keys Handlers
    $('#btnGenerateApiKey').on('click', function () {
        var $btn = $(this);
        var name = $.trim($('#apiKeyNameInput').val()) || 'External API Client';
        $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin me-1"></i> Generating…');

        APP.post(APP.baseUrl + '/settings/api-tokens/generate', { name: name })
            .done(function (res) {
                if (res && res.data && res.data.plain_text) {
                    var token = res.data.plain_text;
                    $('#newKeySecretInput').val(token);
                    $('#newKeyAlert').removeClass('d-none');
                    APP.toast('API Key generated! Copy it now.', 'success');

                    // Add row to table
                    $('#noApiTokensRow').remove();
                    var newRow =
                        '<tr id="tokenRow_' + res.data.id + '">' +
                            '<td class="fw-semibold text-dark">' + APP.escapeHtml(name) + '</td>' +
                            '<td><code class="text-secondary">sc_live_••••' + token.slice(-6) + '</code></td>' +
                            '<td class="text-muted">Just now</td>' +
                            '<td><span class="text-muted">Never</span></td>' +
                            '<td><span class="text-muted">0</span></td>' +
                            '<td class="text-end">' +
                                '<button type="button" class="btn btn-xs btn-outline-danger js-delete-api-token" data-id="' + res.data.id + '">' +
                                    '<i class="fas fa-trash-alt me-1"></i> Revoke' +
                                '</button>' +
                            '</td>' +
                        '</tr>';
                    $('#apiTokensTable tbody').prepend(newRow);
                } else {
                    APP.toast((res && res.message) || 'Failed to generate key', 'error');
                }
            })
            .fail(function (xhr) {
                var msg = (xhr.responseJSON && xhr.responseJSON.message) || 'Key generation failed';
                APP.toast(msg, 'error');
            })
            .always(function () {
                $btn.prop('disabled', false).html('<i class="fas fa-plus-circle me-1"></i> Generate Secret Key');
            });
    });

    $('#btnCopyNewKey').on('click', function () {
        var el = document.getElementById('newKeySecretInput');
        if (el) {
            el.select();
            if (navigator.clipboard) {
                navigator.clipboard.writeText(el.value);
            }
            var $b = $(this);
            $b.html('<i class="fas fa-check me-1"></i> Copied!');
            setTimeout(function () {
                $b.html('<i class="fas fa-copy me-1"></i> Copy Key');
            }, 2000);
            APP.toast('API Key copied to clipboard!', 'success');
        }
    });

    $('#btnCloseKeyAlert').on('click', function () {
        $('#newKeyAlert').addClass('d-none');
    });

    $(document).on('click', '.js-delete-api-token', function () {
        var id = $(this).attr('data-id');
        if (!confirm('Are you sure you want to revoke this API Key? Any external systems using it will stop working immediately.')) {
            return;
        }
        var $row = $('#tokenRow_' + id);
        APP.post(APP.baseUrl + '/settings/api-tokens/' + id + '/delete', {})
            .done(function () {
                $row.fadeOut(300, function () { $(this).remove(); });
                APP.toast('API Key revoked', 'success');
            })
            .fail(function (xhr) {
                var msg = (xhr.responseJSON && xhr.responseJSON.message) || 'Failed to revoke key';
                APP.toast(msg, 'error');
            });
    });

    // Live update for Tenant Powered By Preview
    $('#tenantPoweredByName').on('input', function () {
        $('#tenantPbPreviewText').text($(this).val().trim() || 'Sateri Technologies');
    });
    $('#tenantPoweredByUrl').on('input', function () {
        $('#tenantPbPreviewLink').attr('href', $(this).val().trim() || '#');
    });
    $('#tenantPoweredByLogoInput').on('change', function (e) {
        if (e.target.files && e.target.files[0]) {
            var reader = new FileReader();
            reader.onload = function (ev) {
                $('#tenantPbPreviewImg').attr('src', ev.target.result).show();
            };
            reader.readAsDataURL(e.target.files[0]);
        }
    });
});
</script>
<?= $this->endSection() ?>
