<?= $this->extend('layouts/main') ?>

<?= $this->section('header_actions') ?>
<a href="<?= site_url('contacts') ?>" class="btn btn-outline-secondary btn-sm"><i class="fas fa-arrow-left me-1"></i> Back</a>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php
$contact = $contact ?? [];
$isEdit = ! empty($contact['id']);
$action = $isEdit ? site_url('contacts/' . (int) $contact['id']) : site_url('contacts');
$val = static function (string $key, $default = '') use ($contact) {
    return esc(old($key) ?? ($contact[$key] ?? $default));
};
$selectedTags = old('tag_ids') ?? ($selectedTags ?? ($contact['tag_ids'] ?? []));
if (! is_array($selectedTags)) {
    $selectedTags = [];
}
?>
<div class="form-shell">
<div class="card form-card">
    <form action="<?= $action ?>" method="post">
        <?= csrf_field() ?>
        <div class="card-body">
            <div class="row g-2">
                <div class="col-md-6">
                    <label class="form-label">Name</label>
                    <input type="text" name="name" class="form-control" value="<?= $val('name') ?>" maxlength="150">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Mobile <span class="text-danger">*</span></label>
                    <input type="text" name="mobile" class="form-control" value="<?= $val('mobile') ?>" required maxlength="30" placeholder="9198XXXXXXXX" inputmode="tel">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Email</label>
                    <input type="email" name="email" class="form-control" value="<?= $val('email') ?>">
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label">Country</label>
                    <input type="text" name="country" class="form-control" value="<?= $val('country') ?>" maxlength="80">
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-select">
                        <?php foreach (['active', 'inactive', 'blocked'] as $st): ?>
                            <option value="<?= $st ?>" <?= ($contact['status'] ?? 'active') === $st ? 'selected' : '' ?>><?= ucfirst($st) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Birthday</label>
                    <input type="date" name="birthday" class="form-control" value="<?= $val('birthday') ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Assigned to</label>
                    <select name="assigned_to" class="form-select">
                        <option value="">— Unassigned —</option>
                        <?php foreach (($agents ?? []) as $agent): ?>
                            <option value="<?= (int) $agent['id'] ?>" <?= (string) ($contact['assigned_to'] ?? '') === (string) $agent['id'] ? 'selected' : '' ?>><?= esc($agent['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12">
                    <label class="form-label">Customer Groups</label>
                    <select name="tag_ids[]" class="form-select" multiple size="4">
                        <?php foreach (($tags ?? []) as $tag): ?>
                            <option value="<?= (int) $tag['id'] ?>" <?= in_array($tag['id'], $selectedTags, false) ? 'selected' : '' ?>><?= esc($tag['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text">Hold Ctrl to select multiple groups for campaigns.</div>
                </div>
                <div class="col-12">
                    <label class="form-label">Notes</label>
                    <textarea name="notes" class="form-control" rows="3"><?= $val('notes') ?></textarea>
                </div>
                <div class="col-12">
                    <div class="d-flex align-items-center justify-content-between mb-1">
                        <label class="form-label mb-0">Contact attributes</label>
                        <button type="button" class="btn btn-outline-secondary btn-sm" id="btnAddAttr"><i class="fas fa-plus me-1"></i> Add</button>
                    </div>
                    <p class="text-muted small mb-2">Custom fields used in workflow webhooks (e.g. source, business_name).</p>
                    <div id="attrRows">
                        <?php
                        $cf = $contact['custom_fields'] ?? [];
                        if (is_string($cf)) {
                            $decoded = json_decode($cf, true);
                            $cf = is_array($decoded) ? $decoded : [];
                        }
                        if (! is_array($cf)) {
                            $cf = [];
                        }
                        $oldKeys = old('attr_key');
                        $oldVals = old('attr_value');
                        if (is_array($oldKeys)) {
                            $cf = [];
                            foreach ($oldKeys as $i => $k) {
                                $cf[(string) $k] = is_array($oldVals) ? (string) ($oldVals[$i] ?? '') : '';
                            }
                        }
                        if ($cf === []):
                        ?>
                            <div class="row g-2 align-items-center mb-2 attr-row">
                                <div class="col-md-4">
                                    <input type="text" name="attr_key[]" class="form-control" list="attrKeyList" placeholder="Key (e.g. source)">
                                </div>
                                <div class="col-md-7">
                                    <input type="text" name="attr_value[]" class="form-control" placeholder="Value">
                                </div>
                                <div class="col-md-1">
                                    <button type="button" class="btn btn-outline-danger btn-sm btn-remove-attr" title="Remove">&times;</button>
                                </div>
                            </div>
                        <?php else: ?>
                            <?php foreach ($cf as $k => $v): ?>
                                <?php if (str_starts_with((string) $k, '_')) continue; ?>
                                <div class="row g-2 align-items-center mb-2 attr-row">
                                    <div class="col-md-4">
                                        <input type="text" name="attr_key[]" class="form-control" list="attrKeyList" value="<?= esc((string) $k) ?>" placeholder="Key">
                                    </div>
                                    <div class="col-md-7">
                                        <input type="text" name="attr_value[]" class="form-control" value="<?= esc(is_scalar($v) ? (string) $v : json_encode($v)) ?>" placeholder="Value">
                                    </div>
                                    <div class="col-md-1">
                                        <button type="button" class="btn btn-outline-danger btn-sm btn-remove-attr" title="Remove">&times;</button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    <datalist id="attrKeyList">
                        <?php foreach (($attributeKeys ?? []) as $ak): ?>
                            <option value="<?= esc($ak) ?>"></option>
                        <?php endforeach; ?>
                    </datalist>
                </div>
            </div>
        </div>
        <div class="card-footer d-flex flex-wrap gap-2">
            <button type="submit" class="btn btn-wa btn-sm"><i class="fas fa-save me-1"></i> Save</button>
            <a href="<?= site_url('contacts') ?>" class="btn btn-outline-secondary btn-sm">Cancel</a>
        </div>
    </form>
</div>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
(function ($) {
    function rowHtml() {
        return '<div class="row g-2 align-items-center mb-2 attr-row">' +
            '<div class="col-md-4"><input type="text" name="attr_key[]" class="form-control" list="attrKeyList" placeholder="Key (e.g. source)"></div>' +
            '<div class="col-md-7"><input type="text" name="attr_value[]" class="form-control" placeholder="Value"></div>' +
            '<div class="col-md-1"><button type="button" class="btn btn-outline-danger btn-sm btn-remove-attr" title="Remove">&times;</button></div>' +
            '</div>';
    }
    $('#btnAddAttr').on('click', function () { $('#attrRows').append(rowHtml()); });
    $('#attrRows').on('click', '.btn-remove-attr', function () {
        var $rows = $('#attrRows .attr-row');
        if ($rows.length <= 1) {
            $(this).closest('.attr-row').find('input').val('');
            return;
        }
        $(this).closest('.attr-row').remove();
    });
})(jQuery);
</script>
<?= $this->endSection() ?>
