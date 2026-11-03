<?= $this->extend('layouts/main') ?>

<?= $this->section('header_actions') ?>
<a href="<?= site_url('guide/social') ?>" class="btn btn-outline-secondary btn-sm"><i class="fas fa-book me-1"></i> Guide</a>
<a href="<?= site_url('social/accounts') ?>" class="btn btn-wa btn-sm"><i class="fas fa-plug me-1"></i> Connect accounts</a>
<?= $this->endSection() ?>

<?= $this->section('styles') ?>
<style>
    /* Social setup: uses the app's own colour tokens so it matches the rest of the product. */
    .soc-setup-side { position: sticky; top: 80px; }
    .soc-setup-nav { display: flex; flex-direction: column; gap: .15rem; }
    .soc-setup-nav a { display: flex; align-items: center; gap: .6rem; padding: .5rem .65rem; border-radius: var(--radius-sm, .5rem); color: var(--wa-ink); text-decoration: none; font-size: .8125rem; font-weight: 500; transition: background .15s ease; }
    .soc-setup-nav a:hover { background: var(--brand-50); color: var(--brand-600); }
    .soc-dot { width: .6rem; height: .6rem; border-radius: 50%; flex: 0 0 auto; background: #ced4da; box-shadow: 0 0 0 3px rgba(0, 0, 0, .04); }
    .soc-dot.is-ready { background: #198754; box-shadow: 0 0 0 3px rgba(25, 135, 84, .15); }
    .soc-dot.is-check { background: #f0ad00; box-shadow: 0 0 0 3px rgba(240, 173, 0, .18); }
    .soc-setup-card { scroll-margin-top: 90px; }
    .soc-setup-card .card-header { gap: .65rem; }
    .soc-setup-card .card-header .card-title { margin-right: auto; }
    .soc-brand { width: 2rem; height: 2rem; border-radius: .6rem; display: inline-flex; align-items: center; justify-content: center; background: var(--brand-50); color: var(--brand-500); flex: 0 0 auto; }
    .soc-setup-card .form-label { font-weight: 600; color: var(--wa-ink); }
    .soc-setup-card .form-text { margin-top: .35rem; }
    .soc-steps { padding-left: 1.1rem; margin-bottom: 0; }
    .soc-steps li { margin-bottom: .3rem; }
    .soc-help { display: inline-flex; align-items: center; gap: .35rem; margin-top: 1rem; font-size: .8125rem; font-weight: 500; text-decoration: none; }
    .soc-help-body { margin-top: .6rem; padding: .75rem .9rem; background: var(--surface-2, #fafafa); border: 1px solid var(--border); border-radius: var(--radius-sm, .5rem); }
    .soc-status-row { display: flex; align-items: flex-start; gap: .75rem; padding: .75rem 0; }
    .soc-status-row + .soc-status-row { border-top: 1px dashed var(--border); }
    .soc-status-row .badge { min-width: 4.5rem; }
    .soc-savebar { position: sticky; bottom: .75rem; z-index: 5; display: flex; flex-wrap: wrap; align-items: center; gap: .5rem; padding: .65rem .85rem; background: var(--surface, #fff); border: 1px solid var(--border); border-radius: var(--radius); box-shadow: var(--shadow); }
    .soc-secret-saved { color: #198754; font-weight: 500; }
    .soc-summary-icon { width: 2.75rem; height: 2.75rem; border-radius: .85rem; display: inline-flex; align-items: center; justify-content: center; background: var(--brand-50); color: var(--brand-500); font-size: 1.1rem; flex: 0 0 auto; }
    /* Compact density: tighter headers, padding and type so more fits on screen. */
    [data-social-setup] { font-size: .8125rem; }
    [data-social-setup] .card-header { padding: .5rem .85rem; min-height: 0; }
    [data-social-setup] .card-header .card-title { font-size: .875rem; font-weight: 600; }
    [data-social-setup] .card-body { padding: .75rem .85rem; }
    [data-social-setup] .card.mb-3 { margin-bottom: .75rem !important; }
    [data-social-setup] .form-label { font-size: .75rem; margin-bottom: .2rem; }
    [data-social-setup] .form-control, [data-social-setup] .form-select { font-size: .8125rem; }
    [data-social-setup] .form-text { font-size: .7rem; margin-top: .2rem; }
    [data-social-setup] .badge { font-size: .65rem; font-weight: 600; padding: .3em .55em; }
    [data-social-setup] .btn-sm { font-size: .75rem; padding: .25rem .6rem; }
    [data-social-setup] .row.g-3 { --bs-gutter-y: .6rem; --bs-gutter-x: .75rem; }
    .soc-brand { width: 1.6rem; height: 1.6rem; border-radius: .45rem; font-size: .8rem; }
    .soc-summary-icon { width: 2.1rem; height: 2.1rem; border-radius: .6rem; font-size: .9rem; }
    .soc-setup-nav a { padding: .35rem .55rem; font-size: .75rem; }
    .soc-help { margin-top: .6rem; font-size: .75rem; }
    .soc-help-body { padding: .5rem .7rem; }
    .soc-status-row { padding: .5rem 0; }
    .soc-savebar { padding: .45rem .7rem; }
    .soc-tile { display: flex; align-items: center; gap: .5rem; padding: .5rem .65rem; border: 1px solid var(--border); border-radius: var(--radius-sm, .5rem); color: var(--wa-ink); text-decoration: none; font-weight: 500; }
    .soc-tile:hover { background: var(--brand-50); border-color: var(--brand-500); }
    [data-soc-panel][hidden] { display: none !important; }
    @media (max-width: 991.98px) {
        .soc-setup-side { position: static; }
        .soc-setup-nav { flex-direction: row; flex-wrap: wrap; }
        .soc-setup-nav a { border: 1px solid var(--border); }
    }
</style>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php
$secretHint = static fn (bool $saved): string => $saved ? 'Saved. Leave blank to keep it.' : '';
$services   = ['facebook', 'youtube', 'linkedin', 'drive'];
$readyCount = count(array_filter($services, static fn (string $k): bool => ! empty($ready[$k])));
$percent    = (int) round($readyCount / count($services) * 100);
$pill       = static fn (bool $ok, string $yes = 'Ready', string $no = 'Not set'): string => $ok
    ? '<span class="badge bg-success"><i class="fas fa-check me-1"></i>' . esc($yes) . '</span>'
    : '<span class="badge bg-secondary">' . esc($no) . '</span>';
?>
<div class="page-list" data-social-setup>

<div class="card mb-3">
    <div class="card-body">
        <div class="d-flex flex-wrap align-items-center gap-3">
            <span class="soc-summary-icon"><i class="fas fa-share-nodes"></i></span>
            <div class="me-auto" style="min-width:240px;flex:1 1 320px">
                <h2 class="h6 mb-1">Connect your channels once, then schedule from the Calendar</h2>
                <div class="small text-muted">Fill in a section, press <strong>Test</strong> where it is offered, then <strong>Save setup</strong>. After that, connect the actual accounts on the Accounts page.</div>
            </div>
            <div class="text-end" style="min-width:180px">
                <div class="small mb-1"><strong><?= (int) $readyCount ?></strong> of <?= count($services) ?> services ready</div>
                <div class="progress" style="height:8px"><div class="progress-bar" role="progressbar" style="background:var(--brand-500);width:<?= $percent ?>%" aria-valuenow="<?= $percent ?>" aria-valuemin="0" aria-valuemax="100"></div></div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3">

<div class="col-12">
<div class="card mb-3">
    <div class="card-body d-flex flex-wrap align-items-center gap-2">
        <label class="form-label mb-0 fw-semibold" for="socPlatform">Platform</label>
        <select class="form-select form-select-sm" id="socPlatform" style="max-width:280px"><option value="soc-meta">Facebook &amp; Instagram</option><option value="soc-youtube">YouTube</option><option value="soc-linkedin">LinkedIn</option><option value="soc-drive">Google Drive media</option><option value="soc-ai">AI captions</option><option value="soc-publishing">Publishing</option><option value="soc-token">Connect with a token</option></select>
        <span class="small text-muted">Pick a platform to see its fields.</span>
    </div>
</div>


<form id="socSetupForm" autocomplete="off">

<div class="card mb-3 soc-setup-card" id="soc-meta" data-soc-panel="soc-meta">
    <div class="card-header d-flex align-items-center gap-2">
        <span class="soc-brand"><i class="fab fa-facebook"></i></span>
        <h2 class="card-title mb-0 me-auto">Facebook &amp; Instagram</h2>
        <?= $pill($ready['facebook']) ?>
        <button type="button" class="btn btn-outline-secondary btn-sm" data-soc-check="meta"><i class="fas fa-vial me-1"></i> Test</button>
    </div>
    <div class="card-body py-3">
        <div class="row g-3">
            <div class="col-12 col-md-6">
                <label class="form-label small mb-1" for="meta_app_id">App ID</label>
                <input class="form-control form-control-sm" id="meta_app_id" name="meta_app_id" value="<?= esc($values['meta_app_id']) ?>" maxlength="64" placeholder="e.g. 1234567890123456">
            </div>
            <div class="col-12 col-md-6">
                <label class="form-label small mb-1" for="meta_app_secret">App Secret <?php if ($saved['meta_app_secret']): ?><span class="soc-secret-saved small"><i class="fas fa-check-circle"></i> saved</span><?php endif; ?></label>
                <div class="input-group input-group-sm">
                    <input class="form-control" id="meta_app_secret" name="meta_app_secret" type="password" autocomplete="new-password" placeholder="<?= esc($secretHint($saved['meta_app_secret'])) ?>" maxlength="200">
                    <button class="btn btn-outline-secondary" type="button" data-soc-reveal aria-label="Show or hide"><i class="fas fa-eye"></i></button>
                </div>
            </div>
            <div class="col-12">
                <label class="form-label small mb-1">Redirect URL to add in the Meta app</label>
                <div class="input-group input-group-sm">
                    <input class="form-control" readonly value="<?= esc($redirectBase) ?>/facebook">
                    <button class="btn btn-outline-secondary" type="button" data-soc-copy><i class="far fa-copy me-1"></i> Copy</button>
                </div>
                <div class="form-text">Meta app → Facebook Login → Settings → Valid OAuth Redirect URIs.</div>
            </div>
        </div>
        <a class="soc-help" data-bs-toggle="collapse" href="#socHelpMeta" role="button" aria-expanded="false"><i class="fas fa-circle-question me-1"></i> Where do I find these?</a>
        <div class="collapse" id="socHelpMeta"><div class="soc-help-body">
            <ol class="soc-steps small text-muted">
                <li>Open <strong>developers.facebook.com → My Apps</strong> and choose your app (or create a Business app).</li>
                <li><strong>App settings → Basic</strong> shows the App ID and App Secret.</li>
                <li>Add the products <strong>Facebook Login</strong> and <strong>Instagram</strong>, and paste the redirect URL above.</li>
                <li>Instagram must be a Business or Creator account linked to your Facebook Page.</li>
            </ol>
        </div></div>
    </div>
</div>

<div class="card mb-3 soc-setup-card" id="soc-youtube" data-soc-panel="soc-youtube">
    <div class="card-header d-flex align-items-center gap-2">
        <span class="soc-brand"><i class="fab fa-youtube"></i></span>
        <h2 class="card-title mb-0 me-auto">YouTube</h2>
        <?= $pill($ready['youtube']) ?>
    </div>
    <div class="card-body py-3">
        <div class="row g-3">
            <div class="col-12 col-md-6">
                <label class="form-label small mb-1" for="google_client_id">OAuth Client ID</label>
                <input class="form-control form-control-sm" id="google_client_id" name="google_client_id" value="<?= esc($values['google_client_id']) ?>" maxlength="200" placeholder="xxxx.apps.googleusercontent.com">
            </div>
            <div class="col-12 col-md-6">
                <label class="form-label small mb-1" for="google_client_secret">OAuth Client Secret <?php if ($saved['google_client_secret']): ?><span class="soc-secret-saved small"><i class="fas fa-check-circle"></i> saved</span><?php endif; ?></label>
                <div class="input-group input-group-sm">
                    <input class="form-control" id="google_client_secret" name="google_client_secret" type="password" autocomplete="new-password" placeholder="<?= esc($secretHint($saved['google_client_secret'])) ?>" maxlength="200">
                    <button class="btn btn-outline-secondary" type="button" data-soc-reveal aria-label="Show or hide"><i class="fas fa-eye"></i></button>
                </div>
            </div>
            <div class="col-12">
                <label class="form-label small mb-1">Redirect URL to add in Google Cloud</label>
                <div class="input-group input-group-sm">
                    <input class="form-control" readonly value="<?= esc($redirectBase) ?>/youtube">
                    <button class="btn btn-outline-secondary" type="button" data-soc-copy><i class="far fa-copy me-1"></i> Copy</button>
                </div>
                <div class="form-text">Google Cloud → APIs &amp; Services → Credentials → your OAuth client → Authorized redirect URIs.</div>
            </div>
        </div>
        <a class="soc-help" data-bs-toggle="collapse" href="#socHelpYt" role="button" aria-expanded="false"><i class="fas fa-circle-question me-1"></i> Where do I find these?</a>
        <div class="collapse" id="socHelpYt"><div class="soc-help-body">
            <ol class="soc-steps small text-muted">
                <li>In Google Cloud, enable <strong>YouTube Data API v3</strong>.</li>
                <li><strong>Credentials → Create credentials → OAuth client ID</strong> (type: Web application).</li>
                <li>Paste the redirect URL above, then copy the Client ID and Client Secret here.</li>
                <li>Only videos can be posted to YouTube (a vertical video under 3 minutes becomes a Short).</li>
            </ol>
        </div></div>
    </div>
</div>

<div class="card mb-3 soc-setup-card" id="soc-linkedin" data-soc-panel="soc-linkedin">
    <div class="card-header d-flex align-items-center gap-2">
        <span class="soc-brand"><i class="fab fa-linkedin"></i></span>
        <h2 class="card-title mb-0 me-auto">LinkedIn</h2>
        <?= $pill($ready['linkedin']) ?>
    </div>
    <div class="card-body py-3">
        <div class="row g-3">
            <div class="col-12 col-md-6">
                <label class="form-label small mb-1" for="linkedin_client_id">Client ID</label>
                <input class="form-control form-control-sm" id="linkedin_client_id" name="linkedin_client_id" value="<?= esc($values['linkedin_client_id']) ?>" maxlength="200">
            </div>
            <div class="col-12 col-md-6">
                <label class="form-label small mb-1" for="linkedin_client_secret">Client Secret <?php if ($saved['linkedin_client_secret']): ?><span class="soc-secret-saved small"><i class="fas fa-check-circle"></i> saved</span><?php endif; ?></label>
                <div class="input-group input-group-sm">
                    <input class="form-control" id="linkedin_client_secret" name="linkedin_client_secret" type="password" autocomplete="new-password" placeholder="<?= esc($secretHint($saved['linkedin_client_secret'])) ?>" maxlength="200">
                    <button class="btn btn-outline-secondary" type="button" data-soc-reveal aria-label="Show or hide"><i class="fas fa-eye"></i></button>
                </div>
            </div>
            <div class="col-12">
                <label class="form-label small mb-1">Redirect URL to add in the LinkedIn app</label>
                <div class="input-group input-group-sm">
                    <input class="form-control" readonly value="<?= esc($redirectBase) ?>/linkedin">
                    <button class="btn btn-outline-secondary" type="button" data-soc-copy><i class="far fa-copy me-1"></i> Copy</button>
                </div>
                <div class="form-text">LinkedIn app → Auth → Authorized redirect URLs. Request the products <em>Share on LinkedIn</em> and <em>Sign In with OpenID Connect</em>.</div>
            </div>
        </div>
    </div>
</div>

<div class="card mb-3 soc-setup-card" id="soc-drive" data-soc-panel="soc-drive">
    <div class="card-header d-flex align-items-center gap-2">
        <span class="soc-brand"><i class="fab fa-google-drive"></i></span>
        <h2 class="card-title mb-0 me-auto">Google Drive media</h2>
        <?= $pill($ready['drive']) ?>
        <button type="button" class="btn btn-outline-secondary btn-sm" data-soc-check="drive"><i class="fas fa-vial me-1"></i> Test</button>
    </div>
    <div class="card-body py-3">
        <?php if ($serviceEmail !== ''): ?>
            <div class="alert alert-info py-2 small mb-3">
                <div class="mb-1"><strong>Share your Drive folder with this email</strong> (Viewer access), otherwise no files will show up in the Media Library:</div>
                <div class="input-group input-group-sm">
                    <input class="form-control" readonly value="<?= esc($serviceEmail) ?>">
                    <button class="btn btn-outline-secondary" type="button" data-soc-copy><i class="far fa-copy me-1"></i> Copy</button>
                </div>
            </div>
        <?php endif; ?>
        <label class="form-label small mb-1" for="drive_service_account">Service account key (JSON)</label>
        <textarea class="form-control form-control-sm font-monospace" id="drive_service_account" name="drive_service_account" rows="4" autocomplete="off" placeholder="<?= esc($saved['drive_service_account'] ? 'Saved. Paste a new key only to replace it.' : 'Paste the whole JSON file contents here') ?>"></textarea>
        <a class="soc-help" data-bs-toggle="collapse" href="#socHelpDrive" role="button" aria-expanded="false"><i class="fas fa-circle-question me-1"></i> How do I get this key?</a>
        <div class="collapse" id="socHelpDrive"><div class="soc-help-body">
            <ol class="soc-steps small text-muted">
                <li>Google Cloud → enable the <strong>Google Drive API</strong>.</li>
                <li><strong>IAM &amp; Admin → Service Accounts → Create service account.</strong></li>
                <li>Open it → <strong>Keys → Add key → Create new key → JSON</strong>, and paste the file contents above.</li>
                <li>Share each Drive folder with the service account email (shown here once saved).</li>
            </ol>
        </div></div>
    </div>
</div>

<div class="card mb-3 soc-setup-card" id="soc-ai" data-soc-panel="soc-ai">
    <div class="card-header d-flex align-items-center gap-2">
        <span class="soc-brand"><i class="fas fa-wand-magic-sparkles"></i></span>
        <h2 class="card-title mb-0 me-auto">AI captions</h2>
        <?= $pill($aiReady, 'Ready', 'Not set') ?>
        <button type="button" class="btn btn-outline-secondary btn-sm" data-soc-check="ai"><i class="fas fa-vial me-1"></i> Test</button>
    </div>
    <div class="card-body py-3">
        <p class="small text-muted mb-3">Used in the Media Library to write a caption and hashtags for every file, for each platform, in English, Hindi, Marathi or Hinglish. The AI looks at the file's preview picture, so each post gets its own text. It also plans Reel / video scripts and rewrites captions (shorter, translate, tone). Claude is used automatically once its key is saved here. Nothing is posted until you review it and press Schedule.</p>
        <div class="row g-3">
            <div class="col-12 col-md-4">
                <label class="form-label small mb-1" for="ai_caption_provider">Which AI writes the captions</label>
                <select class="form-select form-select-sm" id="ai_caption_provider" name="ai_caption_provider">
                    <?php foreach (\App\Libraries\SocialAiClient::PROVIDERS as $pk => $pl): ?>
                        <option value="<?= esc($pk, 'attr') ?>"<?= $aiProvider === $pk ? ' selected' : '' ?>><?= esc($pl) ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="form-text" data-ai-note="gemini">Uses the Gemini key in Settings &gt; AI (the same one as the chat assistant).</div>
            </div>
            <div class="col-12 col-md-8" data-ai-box="anthropic">
                <div class="row g-2">
                    <div class="col-12 col-md-7">
                        <label class="form-label small mb-1" for="anthropic_api_key">Claude API key <?php if ($saved['anthropic_api_key']): ?><span class="soc-secret-saved small"><i class="fas fa-check-circle"></i> saved</span><?php endif; ?></label>
                        <div class="input-group input-group-sm">
                            <input class="form-control" id="anthropic_api_key" name="anthropic_api_key" type="password" autocomplete="new-password" placeholder="<?= esc($secretHint($saved['anthropic_api_key'])) ?>" maxlength="300">
                            <button class="btn btn-outline-secondary" type="button" data-soc-reveal aria-label="Show or hide"><i class="fas fa-eye"></i></button>
                        </div>
                    </div>
                    <div class="col-12 col-md-5">
                        <label class="form-label small mb-1" for="anthropic_model">Model</label>
                        <?php
                        $claudeModels = \App\Libraries\SocialAiClient::CLAUDE_MODELS;
                        $claudeChosen = (string) ($values['anthropic_model'] ?: \App\Libraries\SocialAiClient::DEFAULT_MODELS['anthropic']);
                        if (! isset($claudeModels[$claudeChosen])) {
                            $claudeModels = [$claudeChosen => $claudeChosen . ' (saved)'] + $claudeModels;
                        }
                        ?>
                        <select class="form-select form-select-sm" id="anthropic_model" name="anthropic_model">
                            <?php foreach ($claudeModels as $mid => $mlabel): ?>
                                <option value="<?= esc($mid, 'attr') ?>"<?= $claudeChosen === $mid ? ' selected' : '' ?>><?= esc($mlabel) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12 form-text mt-1">Sonnet: best for Instagram, Facebook and YouTube captions, titles and scripts. Haiku: cheapest, for bulk captions. Opus and Fable: slower and costlier, only for important creative work.</div>
                </div>
            </div>
            <div class="col-12 col-md-8 offset-md-4" data-ai-box="openai">
                <div class="row g-2">
                    <div class="col-12 col-md-7">
                        <label class="form-label small mb-1" for="openai_api_key">OpenAI API key <?php if ($saved['openai_api_key']): ?><span class="soc-secret-saved small"><i class="fas fa-check-circle"></i> saved</span><?php endif; ?></label>
                        <div class="input-group input-group-sm">
                            <input class="form-control" id="openai_api_key" name="openai_api_key" type="password" autocomplete="new-password" placeholder="<?= esc($secretHint($saved['openai_api_key'])) ?>" maxlength="300">
                            <button class="btn btn-outline-secondary" type="button" data-soc-reveal aria-label="Show or hide"><i class="fas fa-eye"></i></button>
                        </div>
                    </div>
                    <div class="col-12 col-md-5">
                        <label class="form-label small mb-1" for="openai_model">Model</label>
                        <input class="form-control form-control-sm" id="openai_model" name="openai_model" value="<?= esc($values['openai_model']) ?>" maxlength="80" placeholder="<?= esc(\App\Libraries\SocialAiClient::DEFAULT_MODELS['openai'], 'attr') ?>">
                    </div>
                </div>
            </div>
        </div>
        <div class="form-text mt-2">Press <strong>Save setup</strong> before <strong>Test</strong>. Each caption is one small request, so 20 files means 20 requests to the AI you choose.</div>
    </div>
</div>

<div class="card mb-3 soc-setup-card" id="soc-publishing" data-soc-panel="soc-publishing">
    <div class="card-header d-flex align-items-center gap-2">
        <span class="soc-brand"><i class="fas fa-paper-plane"></i></span>
        <h2 class="card-title mb-0 me-auto">Publishing</h2>
    </div>
    <div class="card-body py-3">
        <div class="soc-status-row">
            <?= $cronOk ? '<span class="badge bg-success mt-1">Running</span>' : '<span class="badge bg-info text-dark mt-1">Automatic</span>' ?>
            <div class="small">
                <strong>Scheduled posts</strong>
                <div class="text-muted">
                    Posts go out on time automatically while anyone has the app open (checked every minute).
                    Last publisher run: <strong><?= esc($lastPublish ?: 'not yet') ?></strong>.
                </div>
            </div>
        </div>
        <div class="soc-status-row">
            <?= $publicUrl ? '<span class="badge bg-success mt-1">Ready</span>' : '<span class="badge bg-warning text-dark mt-1">Local</span>' ?>
            <div class="small">
                <strong>Public HTTPS address</strong>
                <div class="text-muted">
                    Facebook photos and videos are uploaded directly. <strong>Instagram</strong> and Facebook Reels fetch the media from your site, so they need a public https address.
                    <?php if (! $publicUrl): ?>
                        This site is running on a local or non-https address, so Instagram posts will fail until it is live (or opened through a tunnel such as ngrok).
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <a class="soc-help" data-bs-toggle="collapse" href="#socHelpCron" role="button" aria-expanded="false"><i class="fas fa-clock me-1"></i> Publish even when nobody has the app open</a>
        <div class="collapse" id="socHelpCron"><div class="soc-help-body">
            <div class="small text-muted mb-1">On the server, add a cron job that runs every minute:</div>
            <div class="input-group input-group-sm">
                <input class="form-control font-monospace" readonly value="<?= esc($cronCommand) ?>">
                <button class="btn btn-outline-secondary" type="button" data-soc-copy><i class="far fa-copy me-1"></i> Copy</button>
            </div>
        </div></div>
    </div>
</div>

<div class="soc-savebar">
    <button class="btn btn-wa btn-sm" type="submit" id="socSetupSave"><i class="fas fa-save me-1"></i> Save setup</button>
    <a class="btn btn-outline-secondary btn-sm" href="<?= site_url('social/accounts') ?>">Go to Accounts <i class="fas fa-arrow-right ms-1"></i></a>
    <span class="small text-muted ms-auto d-none" id="socSetupDirty"><i class="fas fa-circle text-warning me-1" style="font-size:.5rem"></i> Unsaved changes</span>
</div>

</form>

<div class="card mb-3 soc-setup-card" id="soc-token" data-soc-panel="soc-token">
    <div class="card-header d-flex align-items-center gap-2">
        <span class="soc-brand"><i class="fas fa-key"></i></span>
        <h2 class="card-title mb-0 me-auto">Connect with a token <span class="text-muted small">(quick test)</span></h2>
    </div>
    <div class="card-body py-3">
        <p class="small text-muted mb-3">For testing, or when the login buttons on the Accounts page cannot be used. For regular use prefer the Connect buttons on <a href="<?= site_url('social/accounts') ?>">Accounts</a>, because they keep the connection renewed.</p>

        <form id="socTokenForm" class="row g-2" autocomplete="off">
            <div class="col-12"><strong class="small"><i class="fab fa-facebook me-1"></i> Facebook Page (and the Instagram account linked to it)</strong></div>
            <div class="col-12 col-md-5">
                <label class="form-label small mb-1" for="socTokenPage">Facebook Page ID</label>
                <input class="form-control form-control-sm" id="socTokenPage" name="page_id" value="<?= esc($defaultPageId ?? '') ?>" maxlength="32">
            </div>
            <div class="col-12 col-md-7">
                <label class="form-label small mb-1" for="socTokenValue">Page access token</label>
                <div class="input-group input-group-sm">
                    <input class="form-control" id="socTokenValue" name="token" type="password" autocomplete="new-password" maxlength="1000">
                    <button class="btn btn-outline-secondary" type="button" data-soc-reveal aria-label="Show or hide"><i class="fas fa-eye"></i></button>
                </div>
            </div>
            <div class="col-12 d-flex flex-wrap align-items-center gap-3">
                <button class="btn btn-wa btn-sm" type="submit"><i class="fas fa-plug me-1"></i> Connect Page</button>
                <span class="small text-muted">Create it in Graph API Explorer with pages_show_list, pages_manage_posts, pages_read_engagement, instagram_basic and instagram_content_publish. A token from Explorer expires quickly unless you extend it.</span>
            </div>
        </form>

        <hr class="my-3">

        <form id="socYtTokenForm" class="row g-2" autocomplete="off">
            <div class="col-12"><strong class="small"><i class="fab fa-youtube me-1"></i> YouTube channel</strong></div>
            <div class="col-12 col-md-9">
                <label class="form-label small mb-1" for="socYtToken">YouTube refresh token</label>
                <div class="input-group input-group-sm">
                    <input class="form-control" id="socYtToken" name="refresh_token" type="password" autocomplete="new-password" maxlength="500" placeholder="<?= ! empty($hasSavedYouTubeToken) ? 'Leave blank to use the token saved on the server' : '' ?>">
                    <button class="btn btn-outline-secondary" type="button" data-soc-reveal aria-label="Show or hide"><i class="fas fa-eye"></i></button>
                </div>
            </div>
            <div class="col-12 col-md-3 d-flex align-items-end"><button class="btn btn-wa btn-sm w-100" type="submit"><i class="fas fa-plug me-1"></i> Connect YouTube</button></div>
            <div class="col-12 small text-muted">Needs the YouTube OAuth client saved above, and a refresh token issued to that same client with the youtube.upload, youtube.readonly and youtube scopes (the last one is only needed to delete videos from here).</div>
        </form>
    </div>
</div>
</div>

<div class="card mb-3">
    <div class="card-header"><h2 class="card-title mb-0">All platforms</h2></div>
    <div class="card-body"><div class="row g-2">
        <div class="col-6 col-md-4 col-xl-3"><a href="#soc-meta" class="soc-tile" data-soc-pick="soc-meta"><span class="soc-brand"><i class="fab fa-facebook"></i></span><span class="me-auto">Facebook &amp; Instagram</span><?= $ready['facebook'] ? '<span class="badge bg-success">Ready</span>' : '<span class="badge bg-secondary">Not set</span>' ?></a></div>
        <div class="col-6 col-md-4 col-xl-3"><a href="#soc-youtube" class="soc-tile" data-soc-pick="soc-youtube"><span class="soc-brand"><i class="fab fa-youtube"></i></span><span class="me-auto">YouTube</span><?= $ready['youtube'] ? '<span class="badge bg-success">Ready</span>' : '<span class="badge bg-secondary">Not set</span>' ?></a></div>
        <div class="col-6 col-md-4 col-xl-3"><a href="#soc-linkedin" class="soc-tile" data-soc-pick="soc-linkedin"><span class="soc-brand"><i class="fab fa-linkedin"></i></span><span class="me-auto">LinkedIn</span><?= $ready['linkedin'] ? '<span class="badge bg-success">Ready</span>' : '<span class="badge bg-secondary">Not set</span>' ?></a></div>
        <div class="col-6 col-md-4 col-xl-3"><a href="#soc-drive" class="soc-tile" data-soc-pick="soc-drive"><span class="soc-brand"><i class="fab fa-google-drive"></i></span><span class="me-auto">Google Drive media</span><?= $ready['drive'] ? '<span class="badge bg-success">Ready</span>' : '<span class="badge bg-secondary">Not set</span>' ?></a></div>
        <div class="col-6 col-md-4 col-xl-3"><a href="#soc-ai" class="soc-tile" data-soc-pick="soc-ai"><span class="soc-brand"><i class="fas fa-wand-magic-sparkles"></i></span><span class="me-auto">AI captions</span><?= $aiReady ? '<span class="badge bg-success">Ready</span>' : '<span class="badge bg-secondary">Not set</span>' ?></a></div>
        <div class="col-6 col-md-4 col-xl-3"><a href="#soc-publishing" class="soc-tile" data-soc-pick="soc-publishing"><span class="soc-brand"><i class="fas fa-paper-plane"></i></span><span class="me-auto">Publishing</span><?= ($publicUrl && $cronOk) ? '<span class="badge bg-success">Ready</span>' : '<span class="badge bg-secondary">Not set</span>' ?></a></div>
        <div class="col-6 col-md-4 col-xl-3"><a href="#soc-token" class="soc-tile" data-soc-pick="soc-token"><span class="soc-brand"><i class="fas fa-key"></i></span><span class="me-auto">Connect with a token</span></a></div>
    </div></div>
</div>
</div>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= base_url('assets/js/social.js') ?>?v=<?= (int) @filemtime(FCPATH . 'assets/js/social.js') ?>"></script>
<?= $this->endSection() ?>
