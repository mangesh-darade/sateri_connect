<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>
<div class="page-list" id="adsWizard" data-cap="<?= esc((string) $cap, 'attr') ?>" data-mode="<?= esc($mode, 'attr') ?>">

<?php if ($mode === 'mock'): ?>
    <div class="alert alert-info small">Test mode is on: nothing is sent to Meta and no money is spent.</div>
<?php endif; ?>
<?php if ($cap <= 0): ?>
    <div class="alert alert-warning small">You have not set a daily budget limit yet. You can build a draft, but you must set the limit in <a href="<?= site_url('ads/settings') ?>">Ad settings</a> before launching.</div>
<?php endif; ?>

<ol class="list-inline mb-3 fw-medium" id="adsSteps">
    <li class="list-inline-item" data-step="1"><span class="badge rounded-pill bg-primary">1</span> Brief</li>
    <li class="list-inline-item text-muted" data-step="2"><span class="badge rounded-pill bg-secondary">2</span> Generate</li>
    <li class="list-inline-item text-muted" data-step="3"><span class="badge rounded-pill bg-secondary">3</span> Launch</li>
</ol>

<!-- Step 1: brief -->
<div class="card mb-3" data-panel="1">
    <div class="card-header"><h2 class="card-title mb-0">Tell the AI about your business</h2></div>
    <div class="card-body py-3">
        <div class="mb-3" style="max-width: 640px;">
            <label class="form-label" for="adUrl">Your website or product link</label>
            <div class="input-group">
                <input type="text" class="form-control" id="adUrl" placeholder="https://www.example.com/diwali-offer" maxlength="500">
                <button type="button" class="btn btn-wa" id="adBtnBrief">Read my website</button>
            </div>
            <div class="form-text">The AI reads the page to understand what you sell. Ads will start WhatsApp chats with your customers.</div>
        </div>

        <div id="adBriefFields" class="d-none">
            <div class="alert alert-success small py-2" id="adBriefNote"></div>
            <div class="row g-3" style="max-width: 760px;">
                <div class="col-md-8">
                    <label class="form-label" for="adName">Campaign name</label>
                    <input type="text" class="form-control" id="adName" maxlength="150">
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="adBudget">Daily budget (INR)</label>
                    <input type="number" min="1" step="1" class="form-control" id="adBudget">
                    <div class="form-text" id="adBudgetNote"></div>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="adCity">City or area <span class="text-muted">(optional)</span></label>
                    <input type="text" class="form-control" id="adCity" maxlength="80" placeholder="Pune">
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="adLanguage">Ad language</label>
                    <select class="form-select" id="adLanguage">
                        <option>English</option><option>Hindi</option><option>Marathi</option><option>Hinglish</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="adStart">Start <span class="text-muted">(optional)</span></label>
                    <input type="date" class="form-control" id="adStart">
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="adEnd">End <span class="text-muted">(optional)</span></label>
                    <input type="date" class="form-control" id="adEnd">
                </div>
            </div>
            <button type="button" class="btn btn-wa mt-4" id="adBtnGenerate"><i class="fas fa-wand-magic-sparkles me-1"></i> Generate campaign</button>
        </div>
    </div>
</div>

<!-- Step 2: review and edit what the AI made -->
<div class="d-none" data-panel="2">
    <div class="row g-3">
        <div class="col-lg-7">
            <div class="card mb-3">
                <div class="card-header"><h2 class="card-title mb-0">Choose your ad</h2></div>
                <div class="card-body py-3">
                    <p class="small text-muted mb-2">Pick one. You can edit the text. Replace anything in [square brackets] with the real details.</p>
                    <div id="adVariants"></div>
                </div>
            </div>
            <div class="card">
                <div class="card-header"><h2 class="card-title mb-0">Who will see it</h2></div>
                <div class="card-body py-3">
                    <p class="small text-muted" id="adAudienceNote"></p>
                    <div class="row g-3">
                        <div class="col-md-6"><label class="form-label">Countries <span class="text-muted small">(codes or names, comma separated)</span></label><input type="text" class="form-control" id="audCountries"></div>
                        <div class="col-md-6"><label class="form-label">Cities <span class="text-muted small">(comma separated)</span></label><input type="text" class="form-control" id="audCities"></div>
                        <div class="col-md-3"><label class="form-label">Age from</label><input type="number" min="18" max="65" class="form-control" id="audAgeMin"></div>
                        <div class="col-md-3"><label class="form-label">Age to</label><input type="number" min="18" max="65" class="form-control" id="audAgeMax"></div>
                        <div class="col-md-6"><label class="form-label">Gender</label>
                            <select class="form-select" id="audGender"><option value="all">All</option><option value="male">Men</option><option value="female">Women</option></select>
                        </div>
                        <div class="col-12"><label class="form-label">Interests <span class="text-muted small">(comma separated)</span></label><input type="text" class="form-control" id="audInterests"></div>
                    </div>
                    <div class="alert alert-warning small mt-3 mb-0 d-none" id="adSpecialNote"></div>
                </div>
            </div>
        </div>
        <div class="col-lg-5">
            <div class="card">
                <div class="card-header"><h2 class="card-title mb-0">Preview</h2></div>
                <div class="card-body py-3">
                    <div class="border rounded p-3" id="adPreview">
                        <div class="small text-muted mb-2">Sponsored</div>
                        <div class="mb-2" id="adPrevText"></div>
                        <div class="bg-light rounded d-flex align-items-center justify-content-center text-muted small mb-2" style="height:140px;">Your photo or video</div>
                        <div class="fw-bold" id="adPrevHeadline"></div>
                        <div class="d-inline-block mt-2 px-3 py-1 rounded bg-success text-white small fw-medium"><i class="fab fa-whatsapp me-1"></i> Send WhatsApp message</div>
                    </div>
                    <p class="small text-muted mt-3 mb-0">The ad runs on Facebook and Instagram. The button opens a WhatsApp chat with you.</p>
                </div>
            </div>
            <div class="d-flex gap-2 mt-3">
                <button type="button" class="btn btn-outline-secondary" id="adBtnBack">Back</button>
                <button type="button" class="btn btn-wa flex-grow-1" id="adBtnDraft">Save and review</button>
            </div>
        </div>
    </div>
</div>

<!-- Step 3: launch -->
<div class="card d-none" data-panel="3">
    <div class="card-header"><h2 class="card-title mb-0">Review and launch</h2></div>
    <div class="card-body py-3">
        <dl class="row mb-3" id="adSummary"></dl>
        <div class="alert alert-warning small d-none" id="adProblems"></div>
        <div class="d-flex gap-2">
            <a href="<?= site_url('ads') ?>" class="btn btn-outline-secondary">Save as draft and exit</a>
            <?php if (function_exists('can') && can('ads.activate')): ?>
                <button type="button" class="btn btn-wa" id="adBtnLaunch"><i class="fas fa-rocket me-1"></i> Launch campaign</button>
            <?php else: ?>
                <span class="text-muted small align-self-center">You can save drafts. A person with launch access must start the campaign.</span>
            <?php endif; ?>
        </div>
    </div>
</div>

</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= asset_url('assets/js/ads.js') ?>"></script>
<?= $this->endSection() ?>
