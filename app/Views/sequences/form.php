<?= $this->extend('layouts/main') ?>

<?= $this->section('header_actions') ?>
<a href="<?= site_url('sequences') ?>" class="btn btn-outline-secondary btn-sm">Back</a>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php
$isEdit = ! empty($sequence);
$action = $isEdit ? site_url('sequences/' . (int) $sequence['id']) : site_url('sequences');
?>
<div class="page-list">
<form method="post" action="<?= esc($action) ?>" class="card">
    <?= csrf_field() ?>
    <div class="card-header"><h2 class="card-title mb-0"><?= $isEdit ? 'Edit sequence' : 'Create sequence' ?></h2></div>
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label">Name</label>
                <input type="text" name="name" class="form-control" required value="<?= esc($sequence['name'] ?? '') ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label d-block">Active</label>
                <div class="form-check form-switch mt-2">
                    <input class="form-check-input" type="checkbox" name="is_active" value="1" id="seqActive" <?= ! isset($sequence['is_active']) || ! empty($sequence['is_active']) ? 'checked' : '' ?>>
                    <label class="form-check-label" for="seqActive">On</label>
                </div>
            </div>
            <div class="col-md-3">
                <label class="form-label d-block">Exit on reply</label>
                <div class="form-check form-switch mt-2">
                    <input class="form-check-input" type="checkbox" name="exit_on_reply" value="1" id="seqExit" <?= ! isset($sequence['exit_on_reply']) || ! empty($sequence['exit_on_reply']) ? 'checked' : '' ?>>
                    <label class="form-check-label" for="seqExit">Stop when contact replies</label>
                </div>
            </div>
        </div>

        <h3 class="h6 mt-4 mb-2">Steps</h3>
        <div id="seqSteps">
            <?php foreach (($steps ?? []) as $i => $step): ?>
            <div class="border rounded p-3 mb-2 seq-step">
                <div class="row g-2 align-items-end">
                    <div class="col-md-2">
                        <label class="form-label small">Delay (min)</label>
                        <input type="number" min="0" name="step_delay[]" class="form-control form-control-sm" value="<?= (int) ($step['delay_minutes'] ?? 0) ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small">Type</label>
                        <select name="step_type[]" class="form-select form-select-sm">
                            <option value="text" <?= ($step['message_type'] ?? '') !== 'template' ? 'selected' : '' ?>>Text</option>
                            <option value="template" <?= ($step['message_type'] ?? '') === 'template' ? 'selected' : '' ?>>Template</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small">Template name</label>
                        <input type="text" name="step_template[]" class="form-control form-control-sm" value="<?= esc($step['template_name'] ?? '') ?>" placeholder="hello_world">
                    </div>
                    <div class="col-md-5">
                        <label class="form-label small">Body text</label>
                        <input type="text" name="step_body[]" class="form-control form-control-sm" value="<?= esc($step['body_text'] ?? '') ?>" placeholder="Hi {{contact.name}}">
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <button type="button" class="btn btn-sm btn-outline-secondary" id="btnAddSeqStep">Add step</button>
    </div>
    <div class="card-footer d-flex flex-wrap gap-2">
        <button type="submit" class="btn btn-wa btn-sm"><i class="fas fa-save me-1"></i> Save sequence</button>
        <a href="<?= site_url('sequences') ?>" class="btn btn-outline-secondary btn-sm">Cancel</a>
    </div>
</form>

<?php if ($isEdit): ?>
<div class="card mt-3">
    <div class="card-header"><h2 class="card-title mb-0">Enroll contact</h2></div>
    <div class="card-body">
        <form method="post" action="<?= site_url('sequences/' . (int) $sequence['id'] . '/enroll') ?>" class="row g-2 align-items-end">
            <?= csrf_field() ?>
            <div class="col-md-4">
                <label class="form-label">Contact ID</label>
                <input type="number" name="contact_id" class="form-control" required min="1" placeholder="e.g. demo contact id">
            </div>
            <div class="col-md-3">
                <button type="submit" class="btn btn-wa btn-sm">Enroll</button>
            </div>
        </form>
        <p class="small text-muted mt-2 mb-0">Worker: <code>php spark automations:process</code> advances due steps.</p>
    </div>
</div>
<?php endif; ?>
</div>
<script>
document.getElementById('btnAddSeqStep')?.addEventListener('click', function () {
    var wrap = document.getElementById('seqSteps');
    var div = document.createElement('div');
    div.className = 'border rounded p-3 mb-2 seq-step';
    div.innerHTML = '<div class="row g-2 align-items-end">' +
        '<div class="col-md-2"><label class="form-label small">Delay (min)</label><input type="number" min="0" name="step_delay[]" class="form-control form-control-sm" value="60"></div>' +
        '<div class="col-md-2"><label class="form-label small">Type</label><select name="step_type[]" class="form-select form-select-sm"><option value="text">Text</option><option value="template">Template</option></select></div>' +
        '<div class="col-md-3"><label class="form-label small">Template name</label><input type="text" name="step_template[]" class="form-control form-control-sm"></div>' +
        '<div class="col-md-5"><label class="form-label small">Body text</label><input type="text" name="step_body[]" class="form-control form-control-sm" placeholder="Hi {{contact.name}}"></div>' +
        '</div>';
    wrap.appendChild(div);
});
</script>
<?= $this->endSection() ?>
