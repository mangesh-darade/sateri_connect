<?= $this->extend('layouts/main') ?>

<?= $this->section('header_actions') ?>
<a href="<?= site_url('smart-forms') ?>" class="btn btn-outline-secondary btn-sm">Back</a>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php
$id = (int) ($form['id'] ?? 0);
$action = $id > 0 ? site_url('smart-forms/' . $id) : site_url('smart-forms');
$share = $id > 0 ? \App\Libraries\SmartFormService::publicUrl((string) ($form['slug'] ?? '')) : '';
?>
<div class="page-list">
    <div class="card">
        <div class="card-header"><h2 class="card-title mb-0"><?= $id > 0 ? 'Edit form' : 'New form' ?></h2></div>
        <div class="card-body">
            <form method="post" action="<?= esc($action) ?>">
                <?= csrf_field() ?>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label" for="sfName">Name</label>
                        <input class="form-control" id="sfName" name="name" required maxlength="120" value="<?= esc(old('name', $form['name'] ?? '')) ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="sfSlug">Link slug</label>
                        <input class="form-control" id="sfSlug" name="slug" maxlength="80" value="<?= esc(old('slug', $form['slug'] ?? '')) ?>" placeholder="auto from name">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="sfStatus">Status</label>
                        <select class="form-select" id="sfStatus" name="status">
                            <?php $status = (string) old('status', $form['status'] ?? 'active'); ?>
                            <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Active</option>
                            <option value="paused" <?= $status === 'paused' ? 'selected' : '' ?>>Paused</option>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="sfThanks">Thank-you message</label>
                        <input class="form-control" id="sfThanks" name="success_message" maxlength="255" value="<?= esc(old('success_message', $form['success_message'] ?? 'Thank you. We have received your details.')) ?>">
                    </div>
                </div>

                <h3 class="h6 mt-4">Fields</h3>
                <p class="text-muted small">Mobile is always required. Extra text fields are saved on the contact as attributes.</p>
                <div id="sfFields">
                    <?php foreach ($fields as $i => $field): ?>
                        <div class="row g-2 align-items-end mb-2 sf-field">
                            <div class="col-md-4">
                                <label class="form-label">Label</label>
                                <input class="form-control" name="field_label[<?= (int) $i ?>]" value="<?= esc((string) ($field['label'] ?? '')) ?>" required>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Type</label>
                                <select class="form-select" name="field_type[<?= (int) $i ?>]">
                                    <?php foreach (['name' => 'Name', 'mobile' => 'Mobile', 'email' => 'Email', 'text' => 'Text'] as $value => $label): ?>
                                        <option value="<?= $value ?>" <?= ($field['type'] ?? '') === $value ? 'selected' : '' ?>><?= $label ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Key</label>
                                <input class="form-control" name="field_key[<?= (int) $i ?>]" value="<?= esc((string) ($field['key'] ?? '')) ?>" placeholder="auto">
                            </div>
                            <div class="col-md-2">
                                <input type="hidden" name="field_required[<?= (int) $i ?>]" value="0">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="field_required[<?= (int) $i ?>]" value="1" <?= ! empty($field['required']) ? 'checked' : '' ?>>
                                    <label class="form-check-label">Required</label>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <button type="button" class="btn btn-outline-secondary btn-sm" id="sfAddField">Add field</button>

                <div class="mt-4">
                    <button class="btn btn-wa" type="submit">Save</button>
                </div>
            </form>
            <?php if ($id > 0 && function_exists('can') && can('contacts.delete')): ?>
                <form method="post" action="<?= site_url('smart-forms/' . $id . '/delete') ?>" class="mt-2">
                    <?= csrf_field() ?>
                    <button class="btn btn-outline-danger btn-sm" type="submit" data-confirm="Delete this form?">Delete</button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($share !== ''): ?>
        <div class="card">
            <div class="card-header"><h2 class="card-title mb-0">Share</h2></div>
            <div class="card-body">
                <label class="form-label">Public link</label>
                <input class="form-control" readonly value="<?= esc($share) ?>">
                <label class="form-label mt-3">Embed</label>
                <textarea class="form-control" rows="2" readonly><?= esc('<iframe src="' . $share . '" width="100%" height="640" style="border:0"></iframe>') ?></textarea>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($responses !== []): ?>
        <div class="card">
            <div class="card-header"><h2 class="card-title mb-0">Recent responses</h2></div>
            <div class="card-body p-0">
                <table class="table mb-0">
                    <thead><tr><th>When</th><th>Contact</th><th>Answers</th></tr></thead>
                    <tbody>
                    <?php foreach ($responses as $response): ?>
                        <tr>
                            <td><?= esc((string) ($response['created_at'] ?? '')) ?></td>
                            <td>
                                <?php if (! empty($response['contact_id'])): ?>
                                    <a href="<?= site_url('contacts/' . (int) $response['contact_id']) ?>">#<?= (int) $response['contact_id'] ?></a>
                                <?php endif; ?>
                            </td>
                            <td class="small"><?= esc((string) ($response['payload'] ?? '')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
</div>
<script>
document.getElementById('sfAddField')?.addEventListener('click', function () {
    var box = document.getElementById('sfFields');
    var i = box.querySelectorAll('.sf-field').length;
    var row = document.createElement('div');
    row.className = 'row g-2 align-items-end mb-2 sf-field';
    row.innerHTML = ''
        + '<div class="col-md-4"><label class="form-label">Label</label><input class="form-control" name="field_label[' + i + ']" required></div>'
        + '<div class="col-md-3"><label class="form-label">Type</label><select class="form-select" name="field_type[' + i + ']"><option value="text" selected>Text</option><option value="name">Name</option><option value="mobile">Mobile</option><option value="email">Email</option></select></div>'
        + '<div class="col-md-3"><label class="form-label">Key</label><input class="form-control" name="field_key[' + i + ']" placeholder="city"></div>'
        + '<div class="col-md-2"><input type="hidden" name="field_required[' + i + ']" value="0"><div class="form-check"><input class="form-check-input" type="checkbox" name="field_required[' + i + ']" value="1"><label class="form-check-label">Required</label></div></div>';
    box.appendChild(row);
});
</script>
<?= $this->endSection() ?>
