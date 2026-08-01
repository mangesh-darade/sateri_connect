<?= $this->extend('layouts/main') ?>

<?= $this->section('header_actions') ?>
<a href="<?= site_url('templates') ?>" class="btn btn-outline-secondary btn-sm">
    <i class="fas fa-arrow-left me-1"></i> Back
</a>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php
$providerShort = function_exists('whatsapp_provider_short') ? whatsapp_provider_short() : 'Cheerio';
$providerLabel = function_exists('whatsapp_provider_label') ? whatsapp_provider_label() : 'provider';
$dashUrl       = function_exists('whatsapp_provider_dashboard_url') ? whatsapp_provider_dashboard_url() : 'https://app.cheerio.in/';
$dashLabel     = function_exists('whatsapp_provider_dashboard_label') ? whatsapp_provider_dashboard_label() : 'provider dashboard';
$syncLabel     = function_exists('whatsapp_sync_label') ? whatsapp_sync_label() : 'Sync templates';
$isMeta        = function_exists('is_meta_provider') && is_meta_provider();
$selectedCategory = strtoupper((string) (old('category') ?? 'MARKETING'));
$selectedType = strtolower((string) (old('template_type') ?? 'default'));
$selectedLanguage = (string) (old('language') ?? 'en_US');
$languages = [
    'en_US' => 'English',
    'hi' => 'Hindi',
    'mr' => 'Marathi',
    'gu' => 'Gujarati',
    'kn' => 'Kannada',
    'ta' => 'Tamil',
    'te' => 'Telugu',
    'ml' => 'Malayalam',
    'bn' => 'Bengali',
];
$categories = [
    'MARKETING' => [
        'label' => 'Marketing',
        'icon' => 'fa-bullhorn',
        'copy' => 'Promotional or informational messages about your business, products or services.',
    ],
    'UTILITY' => [
        'label' => 'Utility',
        'icon' => 'fa-dollar-sign',
        'copy' => 'Messages about a particular account, transaction, order or customer request.',
    ],
    'AUTHENTICATION' => [
        'label' => 'Authentication',
        'icon' => 'fa-shield-halved',
        'copy' => 'One time passwords that your customers use to authenticate a transaction or login.',
    ],
];
?>
<div class="row g-3">
    <div class="col-xl-8">
        <div class="card template-create-card border-0">
            <div class="card-body p-4 p-lg-5">
                <div class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-4">
                    <p class="text-muted mb-0">Set up the basics first, then build the message and preview it before submitting.</p>
                    <div class="template-stepper" aria-label="Template steps">
                        <span class="template-step-pill is-active" data-step-pill="1">1. Basics</span>
                        <span class="template-step-pill" data-step-pill="2">2. Content</span>
                    </div>
                </div>

                <form action="<?= site_url('templates') ?>" method="post" id="templateCreateForm">
                    <?= csrf_field() ?>
                    <div class="template-step-panel" data-step-panel="1">
                        <div class="mb-4">
                            <label class="form-label fw-semibold d-block">Category</label>
                            <div class="row g-3">
                                <?php foreach ($categories as $value => $meta): ?>
                                    <div class="col-md-4">
                                        <button type="button"
                                                class="template-category-card<?= $selectedCategory === $value ? ' is-selected' : '' ?>"
                                                data-category-card
                                                data-value="<?= esc($value, 'attr') ?>">
                                            <span class="template-category-icon"><i class="fas <?= esc($meta['icon']) ?>"></i></span>
                                            <span class="template-category-title"><?= esc($meta['label']) ?></span>
                                            <span class="template-category-copy"><?= esc($meta['copy']) ?></span>
                                            <span class="template-category-check"><i class="fas fa-circle-check"></i></span>
                                        </button>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <input type="hidden" name="category" id="templateCategoryInput" value="<?= esc($selectedCategory, 'attr') ?>">
                        </div>

                        <div class="row g-3">
                            <div class="col-12">
                                <label class="form-label">Template Name</label>
                                <div class="d-flex justify-content-between align-items-center">
                                    <span class="small text-muted">Lowercase letters, numbers and underscores only.</span>
                                    <span class="small text-muted"><span id="templateNameCount">0</span>/512</span>
                                </div>
                                <input type="text" name="name" id="templateNameInput" class="form-control mt-2" required
                                       pattern="[a-z0-9_]+" maxlength="512" autocomplete="off"
                                       value="<?= esc(old('name') ?? '') ?>" placeholder="order_ready_update">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Template Type</label>
                                <select name="template_type" id="templateTypeInput" class="form-select">
                                    <option value="">Select template type</option>
                                    <option value="default" <?= $selectedType === 'default' ? 'selected' : '' ?>>Default</option>
                                    <option value="carousel" <?= $selectedType === 'carousel' ? 'selected' : '' ?>>Carousel</option>
                                </select>
                                <div class="form-text" id="templateTypeHelp">Use <strong>Default</strong> for standard templates, or <strong>Carousel</strong> for marketing media cards (2–10).</div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Language</label>
                                <select name="language" id="templateLanguageInput" class="form-select">
                                    <option value="">Select language</option>
                                    <?php foreach ($languages as $code => $label): ?>
                                        <option value="<?= esc($code, 'attr') ?>" <?= $selectedLanguage === $code ? 'selected' : '' ?>><?= esc($label) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div class="d-flex justify-content-end mt-4">
                            <button type="button" class="btn btn-wa px-4" id="templateNextBtn" disabled>Next</button>
                        </div>
                    </div>

                    <div class="template-step-panel d-none" data-step-panel="2">
                        <div class="row g-3">
                            <div class="col-12">
                                <div class="template-basics-summary mb-3">
                                    <div>
                                        <label class="form-label text-muted mb-1" for="templateSummaryCategory">Category</label>
                                        <select id="templateSummaryCategory" class="form-select form-select-sm">
                                            <?php foreach ($categories as $value => $meta): ?>
                                                <option value="<?= esc($value, 'attr') ?>" <?= $selectedCategory === $value ? 'selected' : '' ?>><?= esc($meta['label']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="form-label text-muted mb-1" for="templateSummaryType">Type</label>
                                        <select id="templateSummaryType" class="form-select form-select-sm">
                                            <option value="default" <?= $selectedType === 'default' ? 'selected' : '' ?>>Default</option>
                                            <option value="carousel" <?= $selectedType === 'carousel' ? 'selected' : '' ?>>Carousel</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="form-label text-muted mb-1" for="templateSummaryLanguage">Language</label>
                                        <select id="templateSummaryLanguage" class="form-select form-select-sm">
                                            <?php foreach ($languages as $code => $label): ?>
                                                <option value="<?= esc($code, 'attr') ?>" <?= $selectedLanguage === $code ? 'selected' : '' ?>><?= esc($label) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4" id="templateDefaultHeaderTypeWrap">
                                <label class="form-label">Header Type</label>
                                <select name="header_type" id="templateHeaderTypeInput" class="form-select">
                                    <option value="none" <?= (old('header_type') ?? 'text') === 'none' ? 'selected' : '' ?>>None</option>
                                    <option value="text" <?= (old('header_type') ?? 'text') === 'text' ? 'selected' : '' ?>>Text</option>
                                    <option value="image" <?= (old('header_type') ?? '') === 'image' ? 'selected' : '' ?>>Image</option>
                                    <option value="video" <?= (old('header_type') ?? '') === 'video' ? 'selected' : '' ?>>Video</option>
                                    <option value="document" <?= (old('header_type') ?? '') === 'document' ? 'selected' : '' ?>>Document</option>
                                </select>
                            </div>
                            <div class="col-md-8" id="templateHeaderTextWrap">
                                <label class="form-label">Header Text</label>
                                <input type="text" name="header" id="templateHeaderInput" class="form-control" maxlength="60"
                                       value="<?= esc(old('header') ?? '') ?>" placeholder="Order update">
                            </div>
                            <div class="col-12 d-none" id="templateHeaderMediaWrap">
                                <label class="form-label">Header Media</label>
                                <input type="hidden" name="header_media_source" id="templateHeaderMediaSourceInput" value="<?= esc(old('header_media_source') ?? '', 'attr') ?>">
                                <input type="hidden" name="header_media_preview_url" id="templateHeaderMediaPreviewUrlInput" value="<?= esc(old('header_media_preview_url') ?? '', 'attr') ?>">
                                <div class="template-upload-box" id="templateHeaderUploadBox">
                                    <input type="file" id="templateHeaderMediaFileInput" class="d-none" accept="image/*,video/*,.pdf">
                                    <div class="template-upload-icon"><i class="fas fa-upload"></i></div>
                                    <div class="fw-semibold">Upload <?= esc((old('header_type') ?? '') !== '' ? (string) old('header_type') : 'media') ?> here</div>
                                    <div class="small text-muted mb-2" id="templateHeaderUploadHelp">Media size limit 16MB.</div>
                                    <button type="button" class="btn btn-outline-secondary btn-sm" id="templateHeaderChooseFileBtn">Choose File</button>
                                    <button type="button" class="btn btn-link btn-sm" id="templateHeaderToggleManualBtn">Use media URL instead</button>
                                    <div class="small text-success mt-2 d-none" id="templateHeaderUploadStatus"></div>
                                </div>
                                <div class="mt-3 d-none" id="templateHeaderManualUrlWrap">
                                    <label class="form-label">Sample Media URL</label>
                                    <input type="url" id="templateHeaderMediaManualUrlInput" class="form-control"
                                           value="<?= esc(old('header_media_preview_url') ?? '') ?>" placeholder="https://example.com/sample-image.jpg">
                                    <div class="form-text">Use a public URL if you do not want to upload a file now.</div>
                                </div>
                            </div>
                            <div class="col-12">
                                <div class="d-flex align-items-center justify-content-between gap-2 flex-wrap mb-2">
                                    <label class="form-label mb-0">Body</label>
                                    <button type="button" class="btn btn-outline-secondary btn-sm" id="templateAddVariableBtn">Add variable</button>
                                </div>
                                <div class="template-body-editor">
                                    <textarea name="body" id="templateBodyInput" class="form-control border-0 shadow-none" rows="5" required maxlength="1024"
                                              placeholder="Your body text goes here…"><?= esc(old('body') ?? '') ?></textarea>
                                    <div class="template-body-toolbar">
                                        <div class="d-flex align-items-center gap-1">
                                            <button type="button" class="btn btn-sm btn-light js-body-format" data-format="bold" title="Bold">B</button>
                                            <button type="button" class="btn btn-sm btn-light js-body-format" data-format="italic" title="Italic"><em>I</em></button>
                                            <button type="button" class="btn btn-sm btn-light js-body-format" data-format="strike" title="Strikethrough"><s>S</s></button>
                                        </div>
                                        <div class="small text-muted"><span id="templateBodyCount">0</span>/1024</div>
                                    </div>
                                </div>
                                <div class="form-text mt-2">
                                    Use <code>{{1}}</code>, <code>{{2}}</code> for variables. <?= esc($providerShort) ?> must approve before you can send.
                                    WhatsApp needs at least <strong>(3 &times; variables) + 1</strong> words in the body, and the body cannot start or end with a variable.
                                </div>
                                <div class="alert alert-warning py-2 px-3 small mt-2 mb-0 d-none" id="templateVarRatioWarning"></div>
                            </div>
                            <div class="col-12 d-none" id="templateExamplesWrap">
                                <input type="hidden" name="body_examples" id="templateExamplesInput" value="<?= esc(old('body_examples') ?? '', 'attr') ?>">
                                <div class="template-var-examples" id="templateVarExamplesList"></div>
                                <div class="form-text mt-2">Provide sample values for each variable so Meta/WABA can review the template.</div>
                            </div>
                            <div class="col-12" id="templateDefaultFooterWrap">
                                <div class="d-flex align-items-center justify-content-between gap-2">
                                    <label class="form-label mb-0">Footer</label>
                                    <div class="small text-muted"><span id="templateFooterCount">0</span>/60</div>
                                </div>
                                <input type="text" name="footer" id="templateFooterInput" class="form-control" maxlength="60"
                                       value="<?= esc(old('footer') ?? '') ?>" placeholder="Thank you">
                            </div>
                            <div class="col-12" id="templateDefaultCtaWrap">
                                <hr class="my-1">
                                <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                                    <div>
                                        <label class="form-label mb-0">Buttons</label>
                                        <div class="small text-muted">Add Quick Reply, website, or phone buttons (max 10). Up to 2 website and 1 phone.</div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-12" id="templateDefaultCtaBuilderWrap">
                                <input type="hidden" name="template_buttons" id="templateButtonsInput" value="">
                                <div class="d-flex flex-wrap gap-2 align-items-center" id="templateCtaActions">
                                    <button type="button" class="btn btn-outline-secondary btn-sm" id="templateAddButtonBtn">Add Button</button>
                                    <span class="small text-muted" id="templateButtonsHint">Click Add Button to add another button.</span>
                                </div>
                                <div class="d-flex flex-column gap-3 mt-3" id="templateButtonsList"></div>
                            </div>

                            <div class="col-12 d-none" id="templateCarouselWrap">
                                <hr class="my-1">
                                <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-2">
                                    <div>
                                        <label class="form-label mb-0">Carousel Cards</label>
                                        <div class="small text-muted">Add 2–10 cards. All cards must use the same media type and CTA structure.</div>
                                    </div>
                                    <button type="button" class="btn btn-outline-secondary btn-sm" id="templateAddCarouselCardBtn">Add Card</button>
                                </div>
                                <input type="hidden" name="carousel_cards" id="templateCarouselCardsInput" value="">
                                <div id="templateCarouselCardsList" class="d-grid gap-3"></div>
                            </div>
                        </div>

                        <div class="d-flex flex-wrap justify-content-between gap-2 mt-4">
                            <button type="button" class="btn btn-outline-secondary" id="templateBackBtn">Back</button>
                            <button type="submit" class="btn btn-wa" id="templateSubmitBtn">
                                <i class="fas fa-paper-plane me-1"></i> Submit to <?= esc($providerLabel) ?>
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-xl-4">
        <div class="dash-panel h-100 template-preview-panel">
            <div class="panel-head d-flex align-items-center justify-content-between">
                <h3 class="mb-0">Message Preview</h3>
                <span class="template-live-pill">Live</span>
            </div>
            <div class="panel-body">
                <div class="template-phone-frame">
                    <div class="template-phone-status">
                        <span>9:41</span>
                        <span><i class="fas fa-signal"></i> <i class="fas fa-wifi"></i> <i class="fas fa-battery-full"></i></span>
                    </div>
                    <div class="template-phone-header">
                        <div class="d-flex align-items-center gap-2">
                            <div class="template-phone-avatar">YB</div>
                            <div>
                                <div class="fw-semibold">Your Business</div>
                                <div class="small text-muted">Business Account</div>
                            </div>
                        </div>
                    </div>
                    <div class="template-preview-shell">
                        <div class="template-preview-bubble">
                            <div class="small text-muted mb-2 d-none" id="templatePreviewHeaderMeta"></div>
                            <div class="fw-semibold mb-1" id="templatePreviewHeader"></div>
                            <div id="templatePreviewBody" style="white-space:pre-wrap">Your message preview will appear here.</div>
                            <div class="small text-muted mt-2" id="templatePreviewFooter"></div>
                            <div class="d-flex flex-wrap gap-2 mt-3 d-none" id="templatePreviewButtons"></div>
                            <div class="template-preview-time">10:15 <i class="fas fa-check-double"></i></div>
                        </div>
                    </div>
                </div>

                <hr>

                <ol class="small ps-3 mb-3 text-muted">
                    <li class="mb-2">Template is submitted to <?= esc($providerShort) ?> for review.</li>
                    <li class="mb-2">Status starts as <em>PENDING</em>.</li>
                    <li class="mb-2">After approval, click <strong><?= esc($syncLabel) ?></strong>.</li>
                    <li>Use it in Live Chat or Campaigns.</li>
                </ol>
                <p class="small mb-0 text-muted">
                    WABA / business verification is handled in the <a href="<?= esc($dashUrl) ?>" target="_blank" rel="noopener"><?= esc($dashLabel) ?></a>.
                    This app submits the template through <?= esc($providerLabel) ?><?= $isMeta ? ' and needs a valid WABA ID in Settings.' : '.' ?>
                </p>
            </div>
        </div>
    </div>
</div>
<?= $this->endSection() ?>

<?= $this->section('styles') ?>
<style>
.template-create-card {
    border-radius: 1.5rem;
    box-shadow: 0 20px 60px rgba(36, 39, 46, .08);
}
.template-stepper {
    display: inline-flex;
    gap: .75rem;
    flex-wrap: wrap;
}
.template-step-pill {
    border-radius: 999px;
    padding: .45rem .85rem;
    background: #f3f1fb;
    color: #6f64a7;
    font-size: .85rem;
    font-weight: 700;
}
.template-step-pill.is-active {
    background: rgba(116, 82, 211, .14);
    color: #6a44d3;
}
.template-category-card {
    width: 100%;
    height: 100%;
    border: 1px solid #e3dcf8;
    border-radius: 1.1rem;
    background: #fff;
    padding: 1.1rem;
    text-align: left;
    display: flex;
    flex-direction: column;
    gap: .7rem;
    position: relative;
    transition: .18s ease;
}
.template-category-card:hover,
.template-category-card:focus {
    border-color: #8f6bf0;
    box-shadow: 0 12px 30px rgba(106, 68, 211, .08);
    outline: none;
}
.template-category-card.is-selected {
    border-color: #8f6bf0;
    box-shadow: 0 14px 32px rgba(106, 68, 211, .12);
}
.template-category-icon {
    width: 2.25rem;
    height: 2.25rem;
    border-radius: .75rem;
    background: #f3efff;
    color: #7d5ce0;
    display: inline-flex;
    align-items: center;
    justify-content: center;
}
.template-category-title {
    font-weight: 700;
    color: #251f41;
}
.template-category-copy {
    font-size: .92rem;
    color: #6b7280;
    line-height: 1.5;
}
.template-category-check {
    position: absolute;
    top: .9rem;
    right: .9rem;
    color: #6a44d3;
    opacity: 0;
}
.template-category-card.is-selected .template-category-check {
    opacity: 1;
}
.template-basics-summary {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: .75rem;
    background: #f8f8fc;
    border: 1px solid #ece8fb;
    border-radius: 1rem;
    padding: .95rem 1rem;
}
.template-basics-summary .form-label {
    font-size: .78rem;
    font-weight: 500;
}
.template-basics-summary .form-select {
    background-color: #fff;
}
.template-body-editor {
    border: 1px solid #e5e0f5;
    border-radius: 1rem;
    background: #fff;
    overflow: hidden;
}
.template-body-editor textarea {
    resize: vertical;
    min-height: 140px;
}
.template-body-toolbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: .75rem;
    padding: .55rem .85rem;
    border-top: 1px solid #efeafb;
    background: #fbfaff;
}
.template-body-toolbar .js-body-format {
    width: 2rem;
    height: 2rem;
    font-weight: 700;
}
.template-var-examples {
    display: grid;
    gap: .65rem;
}
.template-var-row {
    display: grid;
    grid-template-columns: auto 1fr;
    gap: .65rem;
    align-items: center;
}
.template-var-chip {
    min-width: 2.6rem;
    height: 2.2rem;
    border-radius: 999px;
    background: #f1edfb;
    color: #6a44d3;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: .85rem;
    font-weight: 700;
}
.template-live-pill {
    display: inline-flex;
    align-items: center;
    border-radius: 999px;
    padding: .2rem .65rem;
    background: rgba(116, 82, 211, .12);
    color: #6a44d3;
    font-size: .75rem;
    font-weight: 700;
}
.template-phone-frame {
    border: 1px solid #d9d3ea;
    border-radius: 1.4rem;
    overflow: hidden;
    background: #f7f4ee;
}
.template-phone-status,
.template-phone-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: .65rem .9rem;
    background: #fff;
}
.template-phone-status {
    font-size: .75rem;
    color: #6b7280;
}
.template-phone-avatar {
    width: 2rem;
    height: 2rem;
    border-radius: 999px;
    background: #6a44d3;
    color: #fff;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: .75rem;
    font-weight: 700;
}
.template-preview-shell {
    background: #ece5dd;
    padding: 1rem;
    min-height: 220px;
}
.template-preview-bubble {
    background: #dcf8c6;
    border-radius: 1rem 1rem .35rem 1rem;
    padding: .9rem 1rem 1.35rem;
    box-shadow: 0 10px 24px rgba(0, 0, 0, .06);
    position: relative;
}
.template-preview-time {
    position: absolute;
    right: .75rem;
    bottom: .35rem;
    font-size: .7rem;
    color: #667781;
}
.template-preview-button {
    border: 1px solid rgba(106, 68, 211, .18);
    background: rgba(255, 255, 255, .7);
    color: #5b43b4;
    border-radius: 999px;
    padding: .35rem .75rem;
    font-size: .85rem;
    font-weight: 600;
}
.template-upload-box {
    border: 1px dashed #c9b8f5;
    border-radius: 1rem;
    padding: 1.25rem;
    text-align: center;
    background: #faf8ff;
}
.template-upload-icon {
    width: 3rem;
    height: 3rem;
    border-radius: 999px;
    background: #f0e9ff;
    color: #6a44d3;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto .75rem;
    font-size: 1.1rem;
}
.template-cta-card {
    border: 1px solid #ece8fb;
    border-radius: 1rem;
    padding: 1rem;
    background: #fbfbfe;
}
@media (max-width: 767.98px) {
    .template-basics-summary {
        grid-template-columns: 1fr;
    }
}
</style>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
$(function () {
    var $form = $('#templateCreateForm');
    var $name = $('#templateNameInput');
    var $category = $('#templateCategoryInput');
    var $type = $('#templateTypeInput');
    var $language = $('#templateLanguageInput');
    var $body = $('#templateBodyInput');
    var $headerType = $('#templateHeaderTypeInput');
    var $header = $('#templateHeaderInput');
    var $headerMediaSource = $('#templateHeaderMediaSourceInput');
    var $headerMediaPreviewUrl = $('#templateHeaderMediaPreviewUrlInput');
    var $headerMediaManualUrl = $('#templateHeaderMediaManualUrlInput');
    var $footer = $('#templateFooterInput');
    var $examples = $('#templateExamplesInput');
    var $buttonsInput = $('#templateButtonsInput');
    var $buttonsList = $('#templateButtonsList');
    var $carouselCardsInput = $('#templateCarouselCardsInput');
    var $carouselList = $('#templateCarouselCardsList');
    var currentStep = 1;
    var MAX_TEMPLATE_BUTTONS = 10;
    var MAX_URL_BUTTONS = 2;
    var MAX_PHONE_BUTTONS = 1;
    var templateButtons = [];
    var carouselCards = [
        emptyCarouselCard(),
        emptyCarouselCard()
    ];
    var activeCardUploadIndex = null;

    var $summaryCategory = $('#templateSummaryCategory');
    var $summaryType = $('#templateSummaryType');
    var $summaryLanguage = $('#templateSummaryLanguage');

    var languageLabels = <?= json_encode($languages) ?>;

    function isPublicMediaUrl(value) {
        var url = String(value || '').trim();
        if (!/^https?:\/\//i.test(url)) {
            return false;
        }
        if (/^https?:\/\/(localhost|127\.0\.0\.1)(:|\/|$)/i.test(url)) {
            return false;
        }
        if (url.indexOf('/media/serve/') !== -1) {
            return false;
        }
        return true;
    }
    var categoryLabels = {
        MARKETING: 'Marketing',
        UTILITY: 'Utility',
        AUTHENTICATION: 'Authentication'
    };

    function emptyTemplateButton() {
        return {
            type: 'quick_reply',
            text: '',
            url: '',
            url_example: '',
            phone_number: ''
        };
    }

    function emptyCarouselCard() {
        return {
            media_type: 'image',
            media_source: '',
            media_preview_url: '',
            body: '',
            body_examples: '',
            cta_type: 'url',
            cta_button_text: '',
            cta_url: '',
            cta_url_example: '',
            cta_phone_number: ''
        };
    }

    function isCarousel() {
        return $type.val() === 'carousel';
    }

    function setStep(step) {
        currentStep = step === 2 ? 2 : 1;
        $('[data-step-panel]').addClass('d-none');
        $('[data-step-panel="' + currentStep + '"]').removeClass('d-none');
        $('[data-step-pill]').removeClass('is-active');
        $('[data-step-pill="' + currentStep + '"]').addClass('is-active');
        if (currentStep === 2) {
            updateModeUi();
            updateSummary();
            updatePreview();
        }
    }

    // Live typing must keep trailing `_` (e.g. "order_" while typing "order_ready").
    // Edge trimming only on blur / submit / init.
    function sanitizeName(value, trimEdges) {
        var cleaned = String(value || '')
            .toLowerCase()
            .replace(/[^a-z0-9_]+/g, '_')
            .replace(/_+/g, '_');
        if (trimEdges) {
            cleaned = cleaned.replace(/^_+|_+$/g, '');
        }
        return cleaned;
    }

    function hasBodyVariables() {
        return getBodyPlaceholders().length > 0;
    }

    function getBodyPlaceholders() {
        var body = String($body.val() || '');
        var matches = body.match(/\{\{\s*(\d+)\s*\}\}/g) || [];
        var nums = [];
        matches.forEach(function (token) {
            var match = token.match(/\d+/);
            if (match) {
                var n = parseInt(match[0], 10);
                if (nums.indexOf(n) === -1) {
                    nums.push(n);
                }
            }
        });
        nums.sort(function (a, b) { return a - b; });
        return nums;
    }

    // WhatsApp counts whitespace-separated tokens and needs words + variables >= (3 x variables) + 1.
    function getBodyRatioState() {
        var placeholders = getBodyPlaceholders();
        var text = String($body.val() || '').replace(/\s+/g, ' ').trim();
        var tokens = text ? text.split(' ') : [];

        return {
            variables: placeholders.length,
            words: tokens.length,
            required: placeholders.length ? (placeholders.length * 3) + 1 : 0
        };
    }

    function isBodyVariableRatioTooHigh() {
        var state = getBodyRatioState();
        return state.variables > 0 && state.words < state.required;
    }

    function getBodyVariablePlacementError() {
        var text = String($body.val() || '').trim();
        if (!text) {
            return '';
        }
        if (/^\{\{\s*\d+\s*\}\}/.test(text)) {
            return 'The message body cannot start with a variable. Add some text before it.';
        }
        if (/\{\{\s*\d+\s*\}\}$/.test(text)) {
            return 'The message body cannot end with a variable. Add some text after it.';
        }
        return '';
    }

    function updateVarRatioWarning() {
        var $warn = $('#templateVarRatioWarning');
        if (!$warn.length) {
            return;
        }

        var placement = getBodyVariablePlacementError();
        if (placement) {
            $warn.text(placement).removeClass('d-none');
            return;
        }

        var state = getBodyRatioState();
        if (state.variables > 0 && state.words < state.required) {
            $warn.text(
                'WhatsApp needs at least ' + state.required + ' words for ' + state.variables
                + ' variable' + (state.variables === 1 ? '' : 's') + '. This body has ' + state.words
                + '. Add more text or use fewer variables.'
            ).removeClass('d-none');
            return;
        }

        $warn.addClass('d-none');
    }

    function getExampleMap() {
        var map = {};
        $('#templateVarExamplesList .js-var-example').each(function () {
            var num = parseInt($(this).attr('data-var'), 10);
            map[num] = String($(this).val() || '').trim();
        });
        return map;
    }

    var lastPlaceholderKey = '';

    function syncExamplesCsv() {
        var placeholders = getBodyPlaceholders();
        var map = getExampleMap();
        var values = placeholders.map(function (n) {
            return map[n] || '';
        });
        $examples.val(values.join(', '));
    }

    function renderVarExamples(force) {
        var placeholders = getBodyPlaceholders();
        var key = placeholders.join(',');
        if (!force && key === lastPlaceholderKey) {
            syncExamplesCsv();
            return;
        }
        lastPlaceholderKey = key;

        var existing = getExampleMap();
        if (Object.keys(existing).length === 0) {
            var seed = String($examples.val() || '').split(',').map(function (v) { return v.trim(); });
            placeholders.forEach(function (n, idx) {
                if (seed[idx]) {
                    existing[n] = seed[idx];
                }
            });
        }

        if (!placeholders.length) {
            $('#templateVarExamplesList').empty();
            $('#templateExamplesWrap').addClass('d-none');
            $examples.val('');
            return;
        }

        var html = placeholders.map(function (n) {
            var value = existing[n] || '';
            return ''
                + '<div class="template-var-row">'
                +   '<span class="template-var-chip">{{' + n + '}}</span>'
                +   '<input type="text" class="form-control js-var-example" data-var="' + n + '" maxlength="60" placeholder="Enter text" value="' + $('<div>').text(value).html() + '">'
                + '</div>';
        }).join('');

        $('#templateVarExamplesList').html(html);
        $('#templateExamplesWrap').removeClass('d-none');
        syncExamplesCsv();
    }

    function updateExamplesVisibility() {
        renderVarExamples(false);
    }

    function getExampleValues() {
        var map = getExampleMap();
        return getBodyPlaceholders().map(function (n) {
            return map[n] || '';
        });
    }

    function applyBodyFormat(format) {
        var el = $body.get(0);
        if (!el) {
            return;
        }
        var start = el.selectionStart || 0;
        var end = el.selectionEnd || 0;
        var value = String($body.val() || '');
        var selected = value.substring(start, end) || 'text';
        var wrapped = selected;
        if (format === 'bold') {
            wrapped = '*' + selected + '*';
        } else if (format === 'italic') {
            wrapped = '_' + selected + '_';
        } else if (format === 'strike') {
            wrapped = '~' + selected + '~';
        }
        $body.val(value.substring(0, start) + wrapped + value.substring(end));
        $body.trigger('input');
        el.focus();
        var cursor = start + wrapped.length;
        el.setSelectionRange(cursor, cursor);
    }

    function updateHeaderFields() {
        var type = $headerType.val() || 'none';
        var isText = type === 'text';
        var isMedia = ['image', 'video', 'document'].indexOf(type) !== -1;

        $('#templateHeaderTextWrap').toggleClass('d-none', !isText || isCarousel());
        $('#templateHeaderMediaWrap').toggleClass('d-none', !isMedia || isCarousel());
        $('#templateHeaderUploadHelp').text('Upload ' + type + ' here. Media size limit 16MB.');
    }

    function buttonTypeLabel(type) {
        if (type === 'url') {
            return 'Visit Website';
        }
        if (type === 'phone_number') {
            return 'Call Phone Number';
        }
        return 'Quick Reply';
    }

    function buttonTypeIcon(type) {
        if (type === 'url') {
            return 'fas fa-arrow-up-right-from-square';
        }
        if (type === 'phone_number') {
            return 'fas fa-phone';
        }
        return 'fas fa-reply';
    }

    function countButtonsByType(type) {
        return templateButtons.filter(function (btn) {
            return (btn.type || '') === type;
        }).length;
    }

    function escAttr(value) {
        return $('<div>').text(value == null ? '' : String(value)).html();
    }

    function syncTemplateButtonsInput() {
        $buttonsInput.val(JSON.stringify(templateButtons));
    }

    function readTemplateButtonsFromDom() {
        $buttonsList.find('[data-button-index]').each(function () {
            var $card = $(this);
            var index = parseInt($card.attr('data-button-index'), 10);
            if (!templateButtons[index]) {
                return;
            }

            // Fields absent for the current type keep their stored value, so switching
            // type back and forth does not lose what the user already typed.
            function pick(selector, current) {
                var $field = $card.find(selector);
                return $field.length ? ($field.val() || '').trim() : (current || '');
            }

            templateButtons[index].type = $card.find('.js-btn-type').val() || 'quick_reply';
            templateButtons[index].text = pick('.js-btn-text', templateButtons[index].text);
            templateButtons[index].url = pick('.js-btn-url', templateButtons[index].url);
            templateButtons[index].url_example = pick('.js-btn-url-example', templateButtons[index].url_example);
            templateButtons[index].phone_number = pick('.js-btn-phone', templateButtons[index].phone_number);
        });
        syncTemplateButtonsInput();
    }

    function canAddTemplateButton() {
        return templateButtons.length < MAX_TEMPLATE_BUTTONS;
    }

    function renderTemplateButtons() {
        var html = '';
        templateButtons.forEach(function (btn, index) {
            var type = btn.type || 'quick_reply';
            var needsExample = /\{\{\s*\d+\s*\}\}/.test(btn.url || '');

            // Only the fields the selected type actually needs are rendered.
            var typeFields = '';
            if (type === 'url') {
                typeFields = ''
                    + '<div class="col-12 js-btn-url-wrap"><label class="form-label">CTA URL</label>'
                    +   '<input type="text" class="form-control js-btn-url" value="' + escAttr(btn.url) + '" placeholder="https://example.com/orders/{{1}}">'
                    +   '<div class="form-text">You can use one dynamic placeholder like <code>{{1}}</code> in the URL.</div></div>'
                    + '<div class="col-12' + (needsExample ? '' : ' d-none') + ' js-btn-url-example-wrap"><label class="form-label">CTA URL Example</label>'
                    +   '<input type="url" class="form-control js-btn-url-example" value="' + escAttr(btn.url_example) + '" placeholder="https://example.com/orders/ORD-1001">'
                    +   '<div class="form-text">Required when CTA URL contains a placeholder.</div></div>';
            } else if (type === 'phone_number') {
                typeFields = ''
                    + '<div class="col-12 js-btn-phone-wrap"><label class="form-label">CTA Phone Number</label>'
                    +   '<input type="text" class="form-control js-btn-phone" value="' + escAttr(btn.phone_number) + '" placeholder="+919876543210"></div>';
            } else {
                typeFields = '<div class="col-12"><div class="form-text mt-0">Quick Reply needs button text only. The reply text comes back as an inbound message.</div></div>';
            }

            html += ''
                + '<div class="template-cta-card" data-button-index="' + index + '">'
                +   '<div class="d-flex justify-content-between align-items-center gap-2 mb-3">'
                +     '<strong>' + buttonTypeLabel(type) + ' Button</strong>'
                +     '<button type="button" class="btn btn-link btn-sm text-danger p-0 js-remove-template-button">Remove</button>'
                +   '</div>'
                +   '<div class="row g-3">'
                +     '<div class="col-md-4"><label class="form-label">Button Type</label>'
                +       '<select class="form-select js-btn-type">'
                +         '<option value="quick_reply"' + (type === 'quick_reply' ? ' selected' : '') + '>Quick Reply</option>'
                +         '<option value="url"' + (type === 'url' ? ' selected' : '') + '>Visit Website</option>'
                +         '<option value="phone_number"' + (type === 'phone_number' ? ' selected' : '') + '>Call Phone Number</option>'
                +       '</select></div>'
                +     '<div class="col-md-8"><label class="form-label">Button Text</label>'
                +       '<input type="text" class="form-control js-btn-text" maxlength="25" value="' + escAttr(btn.text) + '" placeholder="' + (type === 'quick_reply' ? 'Yes' : 'Track order') + '"></div>'
                +     typeFields
                +   '</div>'
                + '</div>';
        });
        $buttonsList.html(html);
        syncTemplateButtonsInput();
        $('#templateAddButtonBtn').prop('disabled', !canAddTemplateButton());
        $('#templateButtonsHint').text(
            templateButtons.length
                ? (templateButtons.length + ' / ' + MAX_TEMPLATE_BUTTONS + ' buttons · up to ' + MAX_URL_BUTTONS + ' website, ' + MAX_PHONE_BUTTONS + ' phone')
                : 'Click Add Button to add Quick Reply, website, or phone.'
        );
    }

    function updateCtaFields() {
        renderTemplateButtons();
    }

    function syncCarouselCardsInput() {
        $carouselCardsInput.val(JSON.stringify(carouselCards));
    }

    function renderCarouselCards() {
        var html = '';
        carouselCards.forEach(function (card, index) {
            var needsExample = /\{\{\s*\d+\s*\}\}/.test(card.cta_url || '');
            html += ''
                + '<div class="template-cta-card" data-card-index="' + index + '">'
                +   '<div class="d-flex justify-content-between align-items-center gap-2 mb-3">'
                +     '<strong>Card ' + (index + 1) + '</strong>'
                +     '<button type="button" class="btn btn-link btn-sm text-danger p-0 js-remove-carousel-card"' + (carouselCards.length <= 2 ? ' disabled' : '') + '>Remove</button>'
                +   '</div>'
                +   '<div class="row g-3">'
                +     '<div class="col-md-4"><label class="form-label">Media Type</label>'
                +       '<select class="form-select js-card-media-type">'
                +         '<option value="image"' + (card.media_type === 'image' ? ' selected' : '') + '>Image</option>'
                +         '<option value="video"' + (card.media_type === 'video' ? ' selected' : '') + '>Video</option>'
                +       '</select></div>'
                +     '<div class="col-md-8"><label class="form-label">Media Sample</label>'
                +       '<div class="input-group">'
                +         '<input type="text" class="form-control js-card-media-url" value="'
                +           $('<div>').text(isPublicMediaUrl(card.media_preview_url || card.media_source || '') ? (card.media_preview_url || card.media_source || '') : '').html()
                +         '" placeholder="https://example.com/product.jpg">'
                +         '<button type="button" class="btn btn-outline-secondary js-card-upload-btn">Upload</button>'
                +         '<input type="file" class="d-none js-card-file" accept="image/*,video/*">'
                +       '</div>'
                +       '<div class="small ' + ((card.media_source || card.media_preview_url) ? 'text-success' : 'text-muted') + ' mt-1 js-card-media-status">'
                +         ((card.media_source || card.media_preview_url) ? 'Uploaded ✓' : 'Public URL or uploaded sample for review.')
                +       '</div></div>'
                +     '<div class="col-12"><label class="form-label">Card Body (optional)</label>'
                +       '<input type="text" class="form-control js-card-body" maxlength="160" value="' + $('<div>').text(card.body || '').html() + '" placeholder="Product title or offer text">'
                +     '</div>'
                +     '<div class="col-md-4"><label class="form-label">CTA Type</label>'
                +       '<select class="form-select js-card-cta-type">'
                +         '<option value=""' + (!card.cta_type ? ' selected' : '') + '>No CTA</option>'
                +         '<option value="url"' + (card.cta_type === 'url' ? ' selected' : '') + '>Visit Website</option>'
                +         '<option value="phone_number"' + (card.cta_type === 'phone_number' ? ' selected' : '') + '>Call Phone</option>'
                +       '</select></div>'
                +     '<div class="col-md-8"><label class="form-label">CTA Text</label>'
                +       '<input type="text" class="form-control js-card-cta-text" maxlength="25" value="' + $('<div>').text(card.cta_button_text || '').html() + '" placeholder="Buy now"></div>'
                +     '<div class="col-12' + (card.cta_type === 'url' ? '' : ' d-none') + ' js-card-url-wrap"><label class="form-label">CTA URL</label>'
                +       '<input type="text" class="form-control js-card-cta-url" value="' + $('<div>').text(card.cta_url || '').html() + '" placeholder="https://example.com/p/{{1}}"></div>'
                +     '<div class="col-12' + (card.cta_type === 'url' && needsExample ? '' : ' d-none') + ' js-card-url-example-wrap"><label class="form-label">CTA URL Example</label>'
                +       '<input type="url" class="form-control js-card-cta-url-example" value="' + $('<div>').text(card.cta_url_example || '').html() + '" placeholder="https://example.com/p/123"></div>'
                +     '<div class="col-12' + (card.cta_type === 'phone_number' ? '' : ' d-none') + ' js-card-phone-wrap"><label class="form-label">CTA Phone</label>'
                +       '<input type="text" class="form-control js-card-cta-phone" value="' + $('<div>').text(card.cta_phone_number || '').html() + '" placeholder="+919876543210"></div>'
                +   '</div>'
                + '</div>';
        });
        $carouselList.html(html);
        syncCarouselCardsInput();
        $('#templateAddCarouselCardBtn').prop('disabled', carouselCards.length >= 10);
    }

    function readCarouselCardsFromDom() {
        $carouselList.find('[data-card-index]').each(function () {
            var index = parseInt($(this).attr('data-card-index'), 10);
            if (!carouselCards[index]) {
                return;
            }
            carouselCards[index].media_type = $(this).find('.js-card-media-type').val() || 'image';
            var typedUrl = ($(this).find('.js-card-media-url').val() || '').trim();
            if (typedUrl !== '') {
                carouselCards[index].media_preview_url = typedUrl;
                if (!carouselCards[index].media_source || String(carouselCards[index].media_source).indexOf('http') === 0) {
                    carouselCards[index].media_source = typedUrl;
                }
            }
            // When the visible URL is empty after a local upload, keep stored media_source / preview.
            carouselCards[index].body = ($(this).find('.js-card-body').val() || '').trim();
            carouselCards[index].cta_type = $(this).find('.js-card-cta-type').val() || '';
            carouselCards[index].cta_button_text = ($(this).find('.js-card-cta-text').val() || '').trim();
            carouselCards[index].cta_url = ($(this).find('.js-card-cta-url').val() || '').trim();
            carouselCards[index].cta_url_example = ($(this).find('.js-card-cta-url-example').val() || '').trim();
            carouselCards[index].cta_phone_number = ($(this).find('.js-card-cta-phone').val() || '').trim();
        });
        syncCarouselCardsInput();
    }

    function updateModeUi() {
        var carousel = isCarousel();
        $('#templateDefaultHeaderTypeWrap, #templateHeaderTextWrap, #templateHeaderMediaWrap, #templateDefaultFooterWrap, #templateDefaultCtaWrap, #templateDefaultCtaBuilderWrap')
            .toggleClass('d-none', carousel);
        $('#templateCarouselWrap').toggleClass('d-none', !carousel);
        if (carousel) {
            applyCategory('MARKETING');
            renderCarouselCards();
        } else {
            updateHeaderFields();
            updateCtaFields();
        }
    }

    function updatePreview() {
        var previewBody = String($body.val() || '').trim();
        var exampleMap = getExampleMap();
        var $previewHeader = $('#templatePreviewHeader');
        var $previewHeaderMeta = $('#templatePreviewHeaderMeta');
        var $previewButtons = $('#templatePreviewButtons');

        previewBody = previewBody.replace(/\{\{\s*(\d+)\s*\}\}/g, function (match, num) {
            var key = parseInt(num, 10);
            var replacement = (exampleMap[key] || '').trim();
            return replacement !== '' ? replacement : ('{{' + key + '}}');
        });

        // Convert simple WA formatting markers for preview readability.
        var previewHtml = $('<div>').text(previewBody || 'Your message preview will appear here.').html()
            .replace(/\*(.*?)\*/g, '<strong>$1</strong>')
            .replace(/_(.*?)_/g, '<em>$1</em>')
            .replace(/~(.*?)~/g, '<s>$1</s>');
        $('#templatePreviewBody').html(previewHtml.replace(/\n/g, '<br>'));

        if (isCarousel()) {
            readCarouselCardsFromDom();
            $previewHeader.text('');
            $previewHeaderMeta
                .text('Carousel · ' + carouselCards.length + ' cards')
                .removeClass('d-none');
            $('#templatePreviewFooter').text('');
            var buttonsHtml = carouselCards.map(function (card, i) {
                var label = card.cta_button_text || ('Card ' + (i + 1));
                return '<span class="template-preview-button">' + $('<div>').text(label).html() + '</span>';
            }).join('');
            $previewButtons.html(buttonsHtml).toggleClass('d-none', buttonsHtml === '');
            return;
        }

        var headerType = $headerType.val() || 'none';
        if (headerType === 'text') {
            $previewHeader.text(($header.val() || '').trim());
            $previewHeaderMeta.text('').addClass('d-none');
        } else if (['image', 'video', 'document'].indexOf(headerType) !== -1) {
            var sampleUrl = ($headerMediaPreviewUrl.val() || '').trim()
                || ($headerMediaManualUrl.val() || '').trim();
            $previewHeader.html(APP.templateHeaderPreviewHtml(headerType, sampleUrl));
            $previewHeaderMeta
                .text(headerType.charAt(0).toUpperCase() + headerType.slice(1)
                    + ' header sample: ' + (sampleUrl ? 'uploaded' : 'Not set'))
                .removeClass('d-none');
        } else {
            $previewHeader.text('');
            $previewHeaderMeta.text('').addClass('d-none');
        }

        $('#templatePreviewFooter').text(($footer.val() || '').trim());
        readTemplateButtonsFromDom();
        if (templateButtons.length) {
            var buttonsHtml = templateButtons.map(function (btn) {
                var label = (btn.text || '').trim() || buttonTypeLabel(btn.type || 'quick_reply');
                return '<span class="template-preview-button"><i class="' + buttonTypeIcon(btn.type || 'quick_reply') + ' me-1"></i>'
                    + $('<div>').text(label).html() + '</span>';
            }).join('');
            $previewButtons.html(buttonsHtml).removeClass('d-none');
        } else {
            $previewButtons.html('').addClass('d-none');
        }
    }

    function applyCategory(value, fromSummary) {
        value = String(value || '').toUpperCase();
        if (!categoryLabels[value]) {
            return;
        }
        if (isCarousel() && value !== 'MARKETING') {
            APP.toast('Carousel templates must use Marketing category.', 'info');
            value = 'MARKETING';
        }
        $category.val(value);
        $summaryCategory.val(value);
        $('[data-category-card]').removeClass('is-selected');
        $('[data-category-card][data-value="' + value + '"]').addClass('is-selected');
        updateNextButton();
        if (!fromSummary) {
            updateSummary();
        }
    }

    function updateSummary() {
        $summaryCategory.val($category.val() || 'MARKETING');
        $summaryType.val($type.val() || 'default');
        $summaryLanguage.val($language.val() || 'en_US');
    }

    function updateNextButton() {
        var nameValue = sanitizeName($name.val(), true);
        var isValid = Boolean($category.val() && nameValue && $type.val() && $language.val());
        if (isCarousel() && $category.val() !== 'MARKETING') {
            isValid = false;
        }
        $('#templateNextBtn').prop('disabled', !isValid);

        if (isCarousel()) {
            $('#templateTypeHelp').html('Carousel is for <strong>Marketing</strong> templates with 2–10 media cards.');
        } else {
            $('#templateTypeHelp').html('Use <strong>Default</strong> for standard templates, or <strong>Carousel</strong> for marketing media cards (2–10).');
        }
    }

    function uploadMediaFile(file, onDone) {
        var formData = new FormData();
        formData.append('file', file);
        return $.ajax({
            url: APP.baseUrl + '/templates/header-media',
            method: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            dataType: 'json'
        }).done(function (response) {
            var data = response && response.data ? response.data : null;
            if (!response || response.success === false || !data) {
                APP.toast((response && response.message) || 'Media upload failed.', 'error');
                return;
            }
            onDone(data, file);
        }).fail(function (xhr) {
            var message = 'Media upload failed.';
            if (xhr.responseJSON && xhr.responseJSON.message) {
                message = xhr.responseJSON.message;
            }
            APP.toast(message, 'error');
        });
    }

    $('[data-category-card]').on('click', function () {
        applyCategory($(this).data('value'));
    });

    $name.on('input', function () {
        var cleaned = sanitizeName($(this).val());
        $(this).val(cleaned);
        $('#templateNameCount').text(cleaned.length);
        updateNextButton();
    });

    $name.on('blur', function () {
        var cleaned = sanitizeName($(this).val(), true);
        $(this).val(cleaned);
        $('#templateNameCount').text(cleaned.length);
        updateNextButton();
    });

    $type.add($language).on('change', function () {
        if (isCarousel()) {
            applyCategory('MARKETING');
        }
        updateModeUi();
        updateNextButton();
        updateSummary();
        updatePreview();
    });

    $summaryCategory.on('change', function () {
        applyCategory($(this).val(), true);
        updateModeUi();
        updatePreview();
    });

    $summaryType.on('change', function () {
        $type.val($(this).val()).trigger('change');
    });

    $summaryLanguage.on('change', function () {
        $language.val($(this).val()).trigger('change');
    });

    $('#templateNextBtn').on('click', function () {
        if (isCarousel() && $category.val() !== 'MARKETING') {
            APP.toast('Carousel templates must use Marketing category.', 'info');
            return;
        }
        setStep(2);
    });

    $('#templateBackBtn').on('click', function () {
        setStep(1);
    });

    $headerType.on('change', function () {
        updateHeaderFields();
        updatePreview();
    });

    $('#templateAddVariableBtn').on('click', function () {
        var current = String($body.val() || '');
        var placeholders = getBodyPlaceholders();
        var nextNumber = placeholders.length ? (placeholders[placeholders.length - 1] + 1) : 1;
        var addition = (current && !/\s$/.test(current) ? ' ' : '') + '{{' + nextNumber + '}}';
        $body.val(current + addition).trigger('input');
    });

    $(document).on('click', '.js-body-format', function () {
        applyBodyFormat($(this).data('format'));
    });

    $(document).on('input', '.js-var-example', function () {
        syncExamplesCsv();
        updatePreview();
    });

    $('#templateAddButtonBtn').on('click', function () {
        readTemplateButtonsFromDom();
        if (!canAddTemplateButton()) {
            APP.toast('You can add up to ' + MAX_TEMPLATE_BUTTONS + ' buttons.', 'info');
            return;
        }
        var nextType = 'quick_reply';
        if (countButtonsByType('url') < MAX_URL_BUTTONS && templateButtons.length === 0) {
            nextType = 'url';
        }
        var btn = emptyTemplateButton();
        btn.type = nextType;
        templateButtons.push(btn);
        renderTemplateButtons();
        updatePreview();
    });

    $buttonsList.on('click', '.js-remove-template-button', function () {
        readTemplateButtonsFromDom();
        var index = parseInt($(this).closest('[data-button-index]').attr('data-button-index'), 10);
        if (isNaN(index)) {
            return;
        }
        templateButtons.splice(index, 1);
        renderTemplateButtons();
        updatePreview();
    });

    $buttonsList.on('change', '.js-btn-type', function () {
        readTemplateButtonsFromDom();
        var index = parseInt($(this).closest('[data-button-index]').attr('data-button-index'), 10);
        var type = $(this).val() || 'quick_reply';
        if (type === 'url' && countButtonsByType('url') > MAX_URL_BUTTONS) {
            APP.toast('WhatsApp allows max ' + MAX_URL_BUTTONS + ' website buttons.', 'info');
            templateButtons[index].type = 'quick_reply';
        } else if (type === 'phone_number' && countButtonsByType('phone_number') > MAX_PHONE_BUTTONS) {
            APP.toast('WhatsApp allows max ' + MAX_PHONE_BUTTONS + ' phone button.', 'info');
            templateButtons[index].type = 'quick_reply';
        }
        renderTemplateButtons();
        updatePreview();
    });

    $buttonsList.on('input', 'input', function () {
        readTemplateButtonsFromDom();
        var $card = $(this).closest('[data-button-index]');
        var btn = templateButtons[parseInt($card.attr('data-button-index'), 10)] || {};
        var needsExample = btn.type === 'url' && /\{\{\s*\d+\s*\}\}/.test(btn.url || '');
        $card.find('.js-btn-url-example-wrap').toggleClass('d-none', !needsExample);
        updatePreview();
    });

    $('#templateAddCarouselCardBtn').on('click', function () {
        if (carouselCards.length >= 10) {
            return;
        }
        readCarouselCardsFromDom();
        carouselCards.push(emptyCarouselCard());
        renderCarouselCards();
        updatePreview();
    });

    $carouselList.on('click', '.js-remove-carousel-card', function () {
        if (carouselCards.length <= 2) {
            return;
        }
        readCarouselCardsFromDom();
        var index = parseInt($(this).closest('[data-card-index]').attr('data-card-index'), 10);
        carouselCards.splice(index, 1);
        renderCarouselCards();
        updatePreview();
    });

    $carouselList.on('input change', 'input, select', function () {
        var $card = $(this).closest('[data-card-index]');
        readCarouselCardsFromDom();
        var card = carouselCards[parseInt($card.attr('data-card-index'), 10)] || {};
        var needsExample = /\{\{\s*\d+\s*\}\}/.test(card.cta_url || '');
        $card.find('.js-card-url-wrap').toggleClass('d-none', card.cta_type !== 'url');
        $card.find('.js-card-url-example-wrap').toggleClass('d-none', !(card.cta_type === 'url' && needsExample));
        $card.find('.js-card-phone-wrap').toggleClass('d-none', card.cta_type !== 'phone_number');
        updatePreview();
    });

    $carouselList.on('click', '.js-card-upload-btn', function () {
        activeCardUploadIndex = parseInt($(this).closest('[data-card-index]').attr('data-card-index'), 10);
        $(this).closest('[data-card-index]').find('.js-card-file').trigger('click');
    });

    $carouselList.on('change', '.js-card-file', function () {
        var file = this.files && this.files[0] ? this.files[0] : null;
        var index = activeCardUploadIndex;
        if (!file || index === null || !carouselCards[index]) {
            return;
        }
        uploadMediaFile(file, function (data) {
            carouselCards[index].media_source = data.source || data.url || '';
            carouselCards[index].media_preview_url = data.preview_url || data.url || '';
            renderCarouselCards();
            updatePreview();
            APP.toast('Card media uploaded.');
        });
    });

    APP.bindUploadBox({
        box: '#templateHeaderUploadBox',
        input: '#templateHeaderMediaFileInput',
        chooseBtn: '#templateHeaderChooseFileBtn',
        ignore: '#templateHeaderToggleManualBtn',
        onFile: uploadHeaderMediaFile
    });

    $('#templateHeaderToggleManualBtn').on('click', function () {
        $('#templateHeaderManualUrlWrap').toggleClass('d-none');
    });

    $headerMediaManualUrl.on('input', function () {
        var url = ($(this).val() || '').trim();
        $headerMediaSource.val(url);
        $headerMediaPreviewUrl.val(url);
        $('#templateHeaderUploadStatus').toggleClass('d-none', url === '').text(url === '' ? '' : 'Using manual media URL.');
        updatePreview();
    });

    function uploadHeaderMediaFile(file) {
        if (!file) {
            return;
        }
        var $status = $('#templateHeaderUploadStatus');
        $status.removeClass('d-none text-success text-danger').addClass('text-muted').text('Uploading ' + file.name + '...');
        uploadMediaFile(file, function (data) {
            $headerMediaSource.val(data.source || data.url || '');
            $headerMediaPreviewUrl.val(data.preview_url || data.url || '');
            // Keep the visible URL field empty for local/serve paths — only show success status.
            $headerMediaManualUrl.val('');
            $status.removeClass('text-muted').addClass('text-success').text('Uploaded ✓');
            updatePreview();
        }).fail(function () {
            $status.removeClass('text-muted').addClass('text-danger').text('Upload failed. Try again or use a media URL.');
        });
    }

    $header.add($body).add($footer).add($examples).on('input', function () {
        updateExamplesVisibility();
        $('#templateBodyCount').text(String($body.val() || '').length);
        $('#templateFooterCount').text(String($footer.val() || '').length);
        updateVarRatioWarning();
        updatePreview();
    });

    $form.on('submit', function (e) {
        e.preventDefault();
        $name.val(sanitizeName($name.val(), true));
        $('#templateNameCount').text($name.val().length);
        syncExamplesCsv();
        readTemplateButtonsFromDom();
        syncTemplateButtonsInput();
        if (isCarousel()) {
            readCarouselCardsFromDom();
            syncCarouselCardsInput();
        }

        var placeholders = getBodyPlaceholders();
        if (placeholders.length) {
            var map = getExampleMap();
            for (var i = 0; i < placeholders.length; i++) {
                if (!map[placeholders[i]]) {
                    APP.toast('Please enter sample text for {{' + placeholders[i] + '}}.', 'error');
                    return;
                }
            }
            var placementError = getBodyVariablePlacementError();
            if (placementError) {
                updateVarRatioWarning();
                APP.toast(placementError, 'error');
                return;
            }
            if (isBodyVariableRatioTooHigh()) {
                var state = getBodyRatioState();
                updateVarRatioWarning();
                APP.toast(
                    'WhatsApp needs at least ' + state.required + ' words for ' + state.variables
                    + ' variable' + (state.variables === 1 ? '' : 's') + '. This body has ' + state.words + '.',
                    'error'
                );
                return;
            }
        }

        var $submit = $('#templateSubmitBtn');
        var originalHtml = $submit.html();
        $submit.prop('disabled', true).html('<i class="fas fa-spinner fa-spin me-1"></i>Submitting...');

        APP.post($form.attr('action'), $form.serialize())
            .done(function (response) {
                if (!response || response.success === false || response.status === false) {
                    APP.toast((response && response.message) || 'Template could not be created.', 'error');
                    return;
                }
                APP.toast(response.message || 'Template created successfully.');
                var redirect = response.data && response.data.redirect ? response.data.redirect : null;
                if (redirect) {
                    window.location.href = redirect;
                }
            })
            .fail(function (xhr) {
                var message = 'Template could not be created.';
                if (xhr.responseJSON && xhr.responseJSON.message) {
                    message = xhr.responseJSON.message;
                }
                APP.toast(message, 'error');
            })
            .always(function () {
                $submit.prop('disabled', false).html(originalHtml);
            });
    });

    $name.val(sanitizeName($name.val(), true));
    $('#templateNameCount').text($name.val().length);
    $('#templateBodyCount').text(String($body.val() || '').length);
    $('#templateFooterCount').text(String($footer.val() || '').length);
    updateNextButton();
    updateSummary();
    updateModeUi();
    updateHeaderFields();
    updateExamplesVisibility();
    renderTemplateButtons();
    updateVarRatioWarning();
    updatePreview();
});
</script>
<?= $this->endSection() ?>
