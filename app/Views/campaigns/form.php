<?= $this->extend('layouts/main') ?>

<?= $this->section('header_actions') ?>
<a href="<?= site_url('campaigns') ?>" class="btn btn-outline-secondary btn-sm"><i class="fas fa-arrow-left me-1"></i> Back</a>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php
$campaign = $campaign ?? [];
$isEdit = ! empty($campaign['id']);
$action = $isEdit ? site_url('campaigns/' . (int) $campaign['id']) : site_url('campaigns');
$variablesJson = $campaign['variables'] ?? [];
if (is_string($variablesJson)) {
    $decoded = json_decode($variablesJson, true);
    $variablesJson = is_array($decoded) ? $decoded : [];
}
?>
<div class="form-shell form-shell-lg">
<div class="card form-card" id="campaignForm">
    <form action="<?= $action ?>" method="post">
        <?= csrf_field() ?>
        <input type="hidden" id="campaignId" value="<?= (int) ($campaign['id'] ?? 0) ?>">
        <div class="card-body">
            <div class="row g-2">
                <div class="col-md-6">
                    <label class="form-label">Campaign name <span class="text-danger">*</span></label>
                    <input type="text" name="name" id="campaignName" class="form-control" required value="<?= esc(old('name') ?? ($campaign['name'] ?? '')) ?>" placeholder="Summer promo">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Template <span class="text-danger">*</span></label>
                    <select name="template_id" id="templateId" class="form-select" required>
                        <option value="">Select template…</option>
                        <?php foreach (($templates ?? []) as $tpl): ?>
                            <option value="<?= (int) $tpl['id'] ?>" <?= (string) (old('template_id') ?? ($campaign['template_id'] ?? '')) === (string) $tpl['id'] ? 'selected' : '' ?>>
                                <?= esc($tpl['name']) ?> (<?= esc($tpl['language'] ?? 'en') ?>) — <?= esc($tpl['status'] ?? '') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="dash-panel mt-3 d-none" id="templatePreviewCard">
                <div class="panel-head"><h3>Template preview</h3></div>
                <div class="panel-body"><pre class="mb-0 small" id="templatePreviewBody" style="white-space:pre-wrap"></pre></div>
            </div>

            <h5 class="mt-4 mb-2" style="font-family:var(--font-display);font-size:0.95rem">Variable mapping</h5>
            <div id="variableMap" class="mb-3"></div>

            <h5 class="mb-2" style="font-family:var(--font-display);font-size:0.95rem">Audience</h5>
            <div class="form-check mb-3">
                <input class="form-check-input" type="checkbox" name="audience_all" id="audienceAll" value="1" <?= ! empty($campaign['audience_all']) ? 'checked' : '' ?>>
                <label class="form-check-label" for="audienceAll">All active contacts</label>
            </div>
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Contacts</label>
                    <select name="contact_ids[]" id="contactIds" class="form-select" multiple size="8">
                        <?php
                        $selectedContacts = old('contact_ids') ?? ($campaign['contact_ids'] ?? []);
                        if (! is_array($selectedContacts)) { $selectedContacts = []; }
                        foreach (($contacts ?? []) as $ct):
                        ?>
                            <option value="<?= (int) $ct['id'] ?>" <?= in_array($ct['id'], $selectedContacts, false) ? 'selected' : '' ?>>
                                <?= esc(($ct['name'] ?? '') . ' — ' . ($ct['mobile'] ?? '')) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Customer Groups</label>
                    <select name="tag_ids[]" id="tagIds" class="form-select" multiple size="8">
                        <?php
                        $selectedTags = old('tag_ids') ?? ($campaign['tag_ids'] ?? []);
                        if (! is_array($selectedTags)) { $selectedTags = []; }
                        foreach (($tags ?? []) as $tag):
                        ?>
                            <option value="<?= (int) $tag['id'] ?>" <?= in_array($tag['id'], $selectedTags, false) ? 'selected' : '' ?>><?= esc($tag['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text">Select groups created under Contacts → Customer Groups.</div>
                </div>
            </div>

            <div class="form-check form-switch mt-3 mb-2">
                <input class="form-check-input" type="checkbox" id="scheduleToggle" <?= ! empty($campaign['scheduled_at']) ? 'checked' : '' ?>>
                <label class="form-check-label" for="scheduleToggle">Schedule for later</label>
            </div>
            <div id="scheduleFields" class="row <?= empty($campaign['scheduled_at']) ? 'd-none' : '' ?>">
                <div class="col-md-4">
                    <label class="form-label">Scheduled at</label>
                    <input type="datetime-local" name="scheduled_at" class="form-control" value="<?= esc(old('scheduled_at') ?? (isset($campaign['scheduled_at']) ? date('Y-m-d\TH:i', strtotime($campaign['scheduled_at'])) : '')) ?>">
                </div>
            </div>
        </div>
        <div class="card-footer d-flex flex-wrap gap-2">
            <button type="submit" name="action" value="draft" class="btn btn-outline-secondary">Save draft</button>
            <button type="button" id="btnPreviewCampaign" class="btn btn-outline-secondary"><i class="fas fa-eye me-1"></i> Preview</button>
            <?php if (function_exists('can') && can('campaigns.start')): ?>
                <button type="submit" name="action" value="schedule" class="btn btn-outline-secondary">Schedule</button>
                <button type="submit" name="action" value="send_now" class="btn btn-wa" data-confirm="Send this campaign now?">Send now</button>
            <?php endif; ?>
            <a href="<?= site_url('campaigns') ?>" class="btn btn-link">Cancel</a>
        </div>
    </form>
</div>
</div>

<div class="modal fade" id="campaignPreviewModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Campaign preview</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="campaignPreviewContent"></div>
        </div>
    </div>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= base_url('assets/js/campaigns.js') ?>"></script>
<?= $this->endSection() ?>
