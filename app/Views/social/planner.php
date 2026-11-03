<?= $this->extend('layouts/main') ?>

<?= $this->section('styles') ?>
<style>
    .pl-item { display: grid; grid-template-columns: 220px 1fr; gap: 1rem; padding: .9rem; margin-bottom: .8rem; background: var(--surface, #fff); border: 1px solid var(--border); border-radius: var(--radius, .75rem); }
    .pl-pic { position: relative; aspect-ratio: 1 / 1; background: #0f172a; border-radius: .5rem; overflow: hidden; display: flex; align-items: center; justify-content: center; color: rgba(255, 255, 255, .6); font-size: .8rem; text-align: center; padding: .5rem; }
    .pl-pic img { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; }
    .pl-item.is-rejected, .pl-item.is-approved { opacity: .6; }
    @media (max-width: 767.98px) { .pl-item { grid-template-columns: 1fr; } .pl-pic { max-width: 260px; } }
</style>
<?= $this->endSection() ?>

<?= $this->section('header_actions') ?>
<a href="<?= site_url('social/setup') ?>#soc-ai" class="btn btn-outline-secondary btn-sm"><i class="fas fa-gear me-1"></i> AI setup</a>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<div class="page-list">

<?php if (empty($aiReady) || empty($imageReady)): ?>
    <div class="alert alert-warning small">
        <?php if (empty($aiReady)): ?>The text AI is not set up. <a href="<?= site_url('social/setup') ?>#soc-ai">Save a Claude key on Social Setup.</a> <?php endif; ?>
        <?php if (empty($imageReady)): ?>No image AI is set up. Save an OpenAI key on <a href="<?= site_url('social/setup') ?>#soc-ai">Social Setup</a>, or a Gemini key in Settings &gt; AI. <?php endif; ?>
    </div>
<?php endif; ?>

<?php if ($accounts === []): ?>
    <div class="alert alert-info small">No Instagram or Facebook account is connected. <a href="<?= site_url('social/accounts') ?>">Connect one</a> first.</div>
<?php endif; ?>

<?php if ($canEdit): ?>
<div class="card mb-3" id="plQuickCard">
    <div class="card-header"><h2 class="card-title mb-0 h6"><i class="fas fa-bolt text-warning me-1"></i> Post something today</h2></div>
    <div class="card-body">
        <div id="plChat" class="small mb-2"></div>
        <form class="input-group input-group-sm" id="plQuickForm">
            <input class="form-control" id="plQuickText" maxlength="500" autocomplete="off" placeholder="e.g. Aaj mala maza product chi Facebook ani Instagram var post karaychi aahe">
            <button class="btn btn-wa" type="submit" id="plQuickGo"<?= (empty($aiReady) || empty($imageReady) || $accounts === []) ? ' disabled' : '' ?>>Send</button>
        </form>
        <div class="form-text">I will ask which product, design the picture, write the caption and show you the preview. It goes out only after you approve. Accounts ticked in "New plan" below are used.</div>
    </div>
</div>
<?php endif; ?>

<?php if ($canEdit): ?>
<div class="card mb-4" id="plNewCard">
    <div class="card-header"><h2 class="card-title mb-0 h6">New plan <span class="text-muted fw-normal">(text: <?= esc($aiLabel) ?>, pictures: <?= esc($imageLabel) ?>)</span></h2></div>
    <form class="card-body" id="plForm">
        <div class="row g-3">
            <div class="col-12 col-md-4">
                <label class="form-label small mb-1" for="plCategory">Category</label>
                <input class="form-control form-control-sm" id="plCategory" name="category" maxlength="191" required placeholder="e.g. Real estate, Fitness studio, Sweet shop">
            </div>
            <div class="col-12 col-md-8">
                <label class="form-label small mb-1" for="plBrief">What should this week do? <span class="text-muted">(offers, products, goal, audience)</span></label>
                <textarea class="form-control form-control-sm" id="plBrief" name="brief" rows="2" maxlength="1500" placeholder="e.g. Promote our new 2BHK flats in Pune, aimed at first-time buyers. Site visits on weekends."></textarea>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small mb-1" for="plDays">Days</label>
                <input class="form-control form-control-sm" type="number" id="plDays" name="days" min="1" max="<?= (int) $maxDays ?>" value="7">
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label small mb-1" for="plStart">Start date</label>
                <input class="form-control form-control-sm" type="date" id="plStart" name="start_date" required>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small mb-1" for="plTime">Post time</label>
                <input class="form-control form-control-sm" type="time" id="plTime" name="post_time" value="10:00" required>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small mb-1" for="plLang">Language</label>
                <select class="form-select form-select-sm" id="plLang" name="language"><option>English</option><option>Hindi</option><option>Marathi</option><option>Hinglish</option></select>
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label small mb-1" for="plTone">Tone</label>
                <select class="form-select form-select-sm" id="plTone" name="tone"><option value="friendly">Friendly</option><option value="professional">Professional</option><option value="funny">Funny</option><option value="inspiring">Inspiring</option><option value="urgent">Urgent</option></select>
            </div>
            <div class="col-12">
                <label class="form-label small mb-1 d-block">Post to</label>
                <?php foreach ($accounts as $acc): ?>
                    <div class="form-check form-check-inline">
                        <input class="form-check-input" type="checkbox" name="accounts[]" value="<?= (int) $acc['id'] ?>" id="plAcc<?= (int) $acc['id'] ?>" checked>
                        <label class="form-check-label small" for="plAcc<?= (int) $acc['id'] ?>"><i class="fab fa-<?= esc($acc['platform']) ?> me-1"></i><?= esc((string) $acc['name']) ?></label>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="mt-3 d-flex align-items-center gap-2">
            <button class="btn btn-wa btn-sm" type="submit" id="plGo"<?= (empty($aiReady) || empty($imageReady) || $accounts === []) ? ' disabled' : '' ?>><i class="fas fa-wand-magic-sparkles me-1"></i> Plan and make pictures</button>
            <span class="small text-muted" id="plProgress">Nothing is posted until you review and approve.</span>
        </div>
    </form>
</div>
<?php endif; ?>

<div id="plReview" class="d-none">
    <div class="d-flex align-items-center flex-wrap gap-2 mb-2">
        <h2 class="h6 mb-0 me-auto" id="plTitle"></h2>
        <?php if ($canEdit): ?>
            <button class="btn btn-outline-secondary btn-sm" id="plMakeMissing" type="button"><i class="fas fa-image me-1"></i> Make missing pictures</button>
            <button class="btn btn-wa btn-sm" id="plApprove" type="button"><i class="fas fa-check me-1"></i> Approve and schedule</button>
        <?php endif; ?>
    </div>
    <div id="plItems"></div>
</div>

<?php if ($plans !== []): ?>
<div class="card mt-4">
    <div class="card-header"><h2 class="card-title mb-0 h6">Earlier plans</h2></div>
    <div class="list-group list-group-flush">
        <?php foreach ($plans as $p): ?>
            <a href="#" class="list-group-item list-group-item-action d-flex align-items-center gap-2 js-pl-open" data-id="<?= (int) $p['id'] ?>">
                <span class="me-auto"><?= esc((string) $p['title']) ?></span>
                <span class="small text-muted"><?= (int) $p['days'] ?> day(s)</span>
                <span class="badge bg-<?= $p['status'] === 'approved' ? 'success' : 'secondary' ?>"><?= esc(ucfirst((string) $p['status'])) ?></span>
            </a>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>window.PL_CAN_EDIT = <?= $canEdit ? 'true' : 'false' ?>;</script>
<script src="<?= base_url('assets/js/social-planner.js') ?>"></script>
<?= $this->endSection() ?>
