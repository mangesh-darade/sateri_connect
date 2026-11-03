<?= $this->extend('layouts/main') ?>

<?= $this->section('header_actions') ?>
<?php if (function_exists('can') && can('social.create')): ?>
    <button type="button" class="btn btn-outline-secondary btn-sm me-1" data-bs-toggle="modal" data-bs-target="#socTextModal"><i class="fas fa-pen me-1"></i> New text post</button>
    <a href="<?= site_url('social/library') ?>" class="btn btn-wa btn-sm"><i class="fas fa-plus me-1"></i> Schedule media posts</a>
<?php endif; ?>
<?= $this->endSection() ?>

<?= $this->section('styles') ?>
<style>
    .soc-cal { display: grid; grid-template-columns: repeat(7, minmax(0, 1fr)); gap: 1px; background: var(--bs-border-color, #dee2e6); border: 1px solid var(--bs-border-color, #dee2e6); }
    .soc-cal .soc-dow { background: var(--bs-tertiary-bg, #f8f9fa); padding: 6px; font-size: 12px; font-weight: 600; text-align: center; }
    .soc-cal .soc-day { background: var(--bs-body-bg, #fff); min-height: 96px; padding: 4px; overflow: hidden; }
    .soc-cal .soc-day.is-out { opacity: .45; }
    .soc-cal .soc-day.is-today { box-shadow: inset 0 0 0 2px #25d366; }
    .soc-cal .soc-num { font-size: 12px; color: var(--bs-secondary-color, #6c757d); }
    .soc-chip { display: block; width: 100%; text-align: left; border: 0; border-radius: 4px; margin-top: 2px; padding: 1px 5px; font-size: 11px; line-height: 1.5; color: #fff; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; cursor: pointer; }
    .soc-chip.st-scheduled { background: #0d6efd; }
    .soc-chip.st-publishing { background: #fd7e14; }
    .soc-chip.st-published { background: #198754; }
    .soc-chip.st-failed { background: #dc3545; }
    .soc-chip.st-skipped { background: #6c757d; }
    @media (max-width: 767px) { .soc-cal .soc-day { min-height: 64px; } .soc-chip { font-size: 10px; } }
</style>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<div class="page-list" data-social-calendar data-can-edit="<?= function_exists('can') && can('social.create') ? '1' : '0' ?>">
    <div class="card">
        <div class="card-header d-flex flex-wrap align-items-center gap-2">
            <h2 class="card-title mb-0 me-auto" id="socCalTitle">Calendar</h2>
            <div class="d-flex flex-wrap gap-2 small align-items-center me-2">
                <span><span class="badge st-scheduled" style="background:#0d6efd">&nbsp;</span> Scheduled</span>
                <span><span class="badge" style="background:#198754">&nbsp;</span> Published</span>
                <span><span class="badge" style="background:#dc3545">&nbsp;</span> Failed</span>
                <span><span class="badge" style="background:#6c757d">&nbsp;</span> Cancelled</span>
            </div>
            <div class="btn-group btn-group-sm">
                <button class="btn btn-outline-secondary" id="socCalPrev" aria-label="Previous month"><i class="fas fa-chevron-left"></i></button>
                <button class="btn btn-outline-secondary" id="socCalToday">Today</button>
                <button class="btn btn-outline-secondary" id="socCalNext" aria-label="Next month"><i class="fas fa-chevron-right"></i></button>
            </div>
        </div>
        <div class="card-body p-2"><div class="soc-cal" id="socCal"></div></div>
    </div>
</div>

<div class="modal fade" id="socEventModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header"><h5 class="modal-title" id="socEventTitle">Post</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body">
                <dl class="row mb-2 small">
                    <dt class="col-4">Account</dt><dd class="col-8" id="socEvAccount"></dd>
                    <dt class="col-4">Type</dt><dd class="col-8" id="socEvType"></dd>
                    <dt class="col-4">Status</dt><dd class="col-8" id="socEvStatus"></dd>
                    <dt class="col-4 d-none" id="socEvProgLabel">Progress</dt>
                    <dd class="col-8 d-none" id="socEvProg">
                        <div class="progress" style="height:14px"><div class="progress-bar progress-bar-striped progress-bar-animated" id="socEvProgBar" role="progressbar" style="width:0%" aria-valuemin="0" aria-valuemax="100">0%</div></div>
                        <div class="small text-muted mt-1" id="socEvProgStage"></div>
                    </dd>
                    <dt class="col-4">When</dt><dd class="col-8" id="socEvWhen"></dd>
                    <dt class="col-4 d-none" id="socEvErrLabel">Error</dt><dd class="col-8 text-danger d-none" id="socEvErr"></dd>
                </dl>
                <a href="#" target="_blank" rel="noopener" class="small d-none" id="socEvLink">Open published post</a>
                <div class="mt-3 d-none" id="socEvEdit">
                    <label class="form-label small mb-1" for="socEvWhenInput">Reschedule to</label>
                    <input type="datetime-local" class="form-control form-control-sm" id="socEvWhenInput">
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-outline-danger btn-sm d-none" id="socEvCancel">Cancel post</button>
                <button class="btn btn-outline-secondary btn-sm d-none" id="socEvRetry">Retry now</button>
                <button class="btn btn-outline-danger btn-sm d-none" id="socEvDelete">Delete from calendar</button>
                <button class="btn btn-danger btn-sm d-none" id="socEvDeleteRemote">Delete everywhere</button>
                <button class="btn btn-wa btn-sm d-none" id="socEvSave">Save new time</button>
            </div>
        </div>
    </div>
</div>
<?php if (function_exists('can') && can('social.create')): ?>
<div class="modal fade" id="socTextModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" id="socTextForm">
            <div class="modal-header"><h5 class="modal-title">New text post</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label small mb-1">Post to</label>
                    <?php if ($accounts === []): ?>
                        <div class="text-muted small">No accounts connected. <a href="<?= site_url('social/accounts') ?>">Connect an account</a> first.</div>
                    <?php endif; ?>
                    <?php foreach ($accounts as $acc): ?>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="accounts[]" value="<?= (int) $acc['id'] ?>" id="socTpAcc<?= (int) $acc['id'] ?>" data-platform="<?= esc((string) $acc['platform'], 'attr') ?>"<?= in_array($acc['platform'], ['instagram', 'youtube'], true) ? ' disabled' : '' ?>>
                            <label class="form-check-label" for="socTpAcc<?= (int) $acc['id'] ?>"><i class="fab fa-<?= esc((string) $acc['platform']) ?> me-1"></i><?= esc((string) $acc['name']) ?> <span class="text-muted small">(<?= esc(ucfirst((string) $acc['platform'])) ?>)</span><?= in_array($acc['platform'], ['instagram', 'youtube'], true) ? ' <span class="text-muted small">needs a photo or video, use the Media Library</span>' : '' ?></label>
                        </div>
                    <?php endforeach; ?>
                    <div class="form-text">Instagram and YouTube need a photo or video, so use the Media Library for those.</div>
                </div>
                <div class="mb-3">
                    <label class="form-label small mb-1" for="socTpWhen">When</label>
                    <input type="datetime-local" class="form-control form-control-sm" id="socTpWhen" required>
                </div>
                <div class="mb-3">
                    <label class="form-label small mb-1" for="socTpCaption">Text</label>
                    <textarea class="form-control form-control-sm" id="socTpCaption" rows="4" maxlength="2000" required></textarea>
                </div>
                <div class="mb-3">
                    <label class="form-label small mb-1" for="socTpTags">Hashtags</label>
                    <input class="form-control form-control-sm" id="socTpTags" maxlength="500" placeholder="#brand">
                </div>
                <div class="border rounded p-2 d-none" id="socTpWaBox">
                    <div class="small fw-semibold mb-2"><i class="fab fa-whatsapp me-1"></i> WhatsApp broadcast</div>
                    <div class="row g-2">
                        <div class="col-12 col-md-6">
                            <label class="form-label small mb-1" for="socTpWaTemplate">Approved template</label>
                            <select class="form-select form-select-sm" id="socTpWaTemplate">
                                <option value="">Choose a template</option>
                                <?php foreach ($templates as $t): ?>
                                    <option value="<?= (int) $t['id'] ?>"><?= esc((string) $t['name']) ?> (<?= esc((string) $t['language']) ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label small mb-1" for="socTpWaTags">Customer groups</label>
                            <select class="form-select form-select-sm" id="socTpWaTags" multiple size="3">
                                <?php foreach ($tags as $tg): ?>
                                    <option value="<?= (int) $tg['id'] ?>"><?= esc((string) $tg['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="form-check mt-2"><input class="form-check-input" type="checkbox" id="socTpWaAll"><label class="form-check-label small" for="socTpWaAll">Send to all contacts instead</label></div>
                    <div class="form-check"><input class="form-check-input" type="checkbox" id="socTpWaCaption"><label class="form-check-label small" for="socTpWaCaption">Use the text as template variable {{1}}</label></div>
                </div>
            </div>
            <div class="modal-footer"><button class="btn btn-wa btn-sm" type="submit">Schedule</button></div>
        </form>
    </div>
</div>
<?php endif; ?>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= base_url('assets/js/social.js') ?>"></script>
<?= $this->endSection() ?>
