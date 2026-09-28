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
<div class="page-stack">
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
                <?php
                $optedOut     = ! empty($contact['wa_opted_out_at']);
                $optInChecked = old('wa_opt_in') !== null ? (bool) old('wa_opt_in') : ((int) ($contact['wa_opt_in'] ?? 0) === 1);
                $optInSource  = (string) (old('wa_opt_in_source') ?? ($contact['wa_opt_in_source'] ?? ''));
                ?>
                <div class="col-12">
                    <div class="border rounded p-2">
                        <div class="d-flex align-items-center justify-content-between mb-1">
                            <label class="form-label mb-0">WhatsApp consent</label>
                            <?php if ($isEdit): ?>
                                <?= view('partials/wa_consent_badge', ['contact' => $contact]) ?>
                            <?php endif; ?>
                        </div>
                        <?php if ($optedOut): ?>
                            <p class="small text-danger mb-0">
                                This customer opted out on <?= esc(format_app_datetime($contact['wa_opted_out_at'])) ?>.
                                Only they can opt back in by sending <strong>START</strong> on WhatsApp.
                            </p>
                        <?php else: ?>
                            <div class="row g-2 align-items-center">
                                <div class="col-md-6">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="wa_opt_in" value="1" id="waOptIn" <?= $optInChecked ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="waOptIn">Customer agreed to receive WhatsApp messages from us</label>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <select name="wa_opt_in_source" id="waOptInSource" class="form-select form-select-sm" <?= $optInChecked ? '' : 'disabled' ?>>
                                        <option value="">How was consent given?</option>
                                        <?php foreach (\App\Libraries\WhatsAppConsentService::OPT_IN_SOURCES as $key => $label): ?>
                                            <option value="<?= esc($key) ?>" <?= $optInSource === $key ? 'selected' : '' ?>><?= esc($label) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="form-text">Meta allows campaigns only to people who opted in. Keep proof (form, message, signed sheet) for every opt-in.</div>
                        <?php endif; ?>
                    </div>
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
    $('#waOptIn').on('change', function () {
        $('#waOptInSource').prop('disabled', !this.checked).prop('required', this.checked);
    }).trigger('change');
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
