<?= $this->extend('layouts/main') ?>

<?php $canEdit = function_exists('can') && can('contacts.edit'); ?>

<?= $this->section('header_actions') ?>
<?php if ($canEdit && $tableReady): ?>
    <button type="button" class="btn btn-wa btn-sm" data-crud-new>
        <i class="fas fa-plus me-1"></i> Add attribute
    </button>
<?php endif; ?>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<div class="page-list page-attributes" data-crud="/attributes" data-modal="#attributeModal">

<?php if (! $tableReady): ?>
    <div class="alert alert-warning">Attributes table is missing. Run <code>php spark migrate</code> to enable this page.</div>
<?php endif; ?>

<div class="card">
    <div class="card-header d-flex align-items-center justify-content-between gap-2">
        <h2 class="card-title mb-0">Contact attributes</h2>
        <span class="small text-muted">Used in contact profiles, inbox, filters, workflows (<code>{{key}}</code>) and imports</span>
    </div>
    <div class="card-body py-3">
        <table class="table table-sm table-hover align-middle w-100">
            <thead>
                <tr>
                    <th>Label</th>
                    <th>Key</th>
                    <th style="width:120px">Type</th>
                    <th>Options / default</th>
                    <th class="text-center" style="width:110px">Contacts</th>
                    <th>Used in</th>
                    <?php if ($canEdit): ?><th class="text-end" style="width:110px">Action</th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($attributes)): ?>
                <tr>
                    <td colspan="7" class="text-center text-muted py-4">
                        No attributes yet. Add one (e.g. <strong>City</strong> as a dropdown) so agents and workflows save clean, typed values.
                        <div class="small mt-1">Built-in fields (name, email, country, notes, status, birthday) are always available.</div>
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($attributes as $attr): ?>
                    <tr>
                        <td class="fw-medium"><?= esc($attr['label']) ?></td>
                        <td><code><?= esc($attr['attr_key']) ?></code></td>
                        <td><span class="badge bg-light text-dark border"><?= esc($types[$attr['type']] ?? $attr['type']) ?></span></td>
                        <td class="small text-muted">
                            <?= $attr['options'] !== [] ? esc(implode(', ', $attr['options'])) : '' ?>
                            <?php if (($attr['default_value'] ?? '') !== ''): ?>
                                <div>Default: <span class="text-dark"><?= esc($attr['default_value']) ?></span></div>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <?php if ((int) ($attr['usage'] ?? 0) > 0): ?>
                                <a href="<?= site_url('contacts?attr_key=' . rawurlencode($attr['attr_key']) . '&attr_op=not_empty') ?>" title="Show these contacts"><?= (int) $attr['usage'] ?></a>
                            <?php else: ?>0<?php endif; ?>
                        </td>
                        <?php
                        $used      = $attr['used_in'] ?? ['workflows' => [], 'keywords' => [], 'campaigns' => []];
                        $usedLinks = [
                            'workflows' => ['icon' => 'fa-diagram-project', 'label' => 'workflow', 'url' => static fn ($id) => site_url('automations/' . $id . '/builder')],
                            'keywords'  => ['icon' => 'fa-key', 'label' => 'keyword', 'url' => static fn ($id) => site_url('keywords/' . $id . '/edit')],
                            'campaigns' => ['icon' => 'fa-bullhorn', 'label' => 'campaign', 'url' => static fn ($id) => site_url('campaigns/' . $id)],
                        ];
                        $usedCount = count($used['workflows']) + count($used['keywords']) + count($used['campaigns']);
                        ?>
                        <td class="small">
                            <?php if ($usedCount === 0): ?>
                                <span class="text-muted">—</span>
                            <?php else: ?>
                                <?php foreach ($usedLinks as $type => $meta): ?>
                                    <?php foreach ($used[$type] as $ref): ?>
                                        <a href="<?= esc($meta['url']($ref['id']), 'attr') ?>" class="badge bg-light text-dark border text-decoration-none me-1 mb-1" title="<?= esc(ucfirst($meta['label']), 'attr') ?>">
                                            <i class="fas <?= $meta['icon'] ?> me-1 text-muted"></i><?= esc(mb_strimwidth($ref['name'] ?: ('#' . $ref['id']), 0, 28, '…')) ?>
                                        </a>
                                    <?php endforeach; ?>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </td>
                        <?php if ($canEdit): ?>
                        <td class="text-end">
                            <div class="table-actions justify-content-end">
                                <button type="button" class="btn btn-sm btn-outline-secondary" title="Edit"
                                        data-crud-edit data-row="<?= esc(json_encode($attr), 'attr') ?>">
                                    <i class="fas fa-pen"></i>
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-danger" title="Delete"
                                        data-crud-delete data-id="<?= (int) $attr['id'] ?>" data-name="<?= esc($attr['label'], 'attr') ?>"
                                        data-text="<?= esc($usedCount > 0
                                            ? 'Used in ' . $usedCount . ' workflow/keyword/campaign(s) — they keep working with the raw key but lose type checks. Values already saved on contacts are kept.'
                                            : 'Values already saved on contacts are kept.', 'attr') ?>">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </div>
                        </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
</div>

<?php if ($canEdit): ?>
<div class="modal fade" id="attributeModal" tabindex="-1" aria-hidden="true" data-title-new="Add attribute" data-title-edit="Edit attribute">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form autocomplete="off">
                <div class="modal-header">
                    <h5 class="modal-title">Add attribute</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Label</label>
                        <input type="text" class="form-control" name="label" maxlength="100" placeholder="City" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Key</label>
                        <input type="text" class="form-control font-monospace" name="attr_key" maxlength="50" placeholder="city"
                               pattern="[A-Za-z][A-Za-z0-9_]*" data-edit-lock required>
                        <div class="form-text">Used as <code>{{city}}</code> in messages. Letters, numbers and _ only; cannot change later.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Type</label>
                        <select class="form-select" name="type" id="attrType">
                            <?php foreach ($types as $value => $label): ?>
                                <option value="<?= esc($value) ?>"><?= esc($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3 d-none" id="attrOptionsWrap">
                        <label class="form-label">Dropdown options</label>
                        <textarea class="form-control" name="options" rows="4" placeholder="Pune&#10;Mumbai&#10;Nashik"></textarea>
                        <div class="form-text">One option per line.</div>
                    </div>
                    <div class="mb-1">
                        <label class="form-label">Default value <span class="text-muted small">(optional)</span></label>
                        <input type="text" class="form-control" name="default_value" maxlength="255">
                        <div class="form-text">Filled on every new contact that does not have a value.</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-wa">Save</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= base_url('assets/js/simple-crud.js') ?>"></script>
<script>
(function ($) {
    function toggleOptions() { $('#attrOptionsWrap').toggleClass('d-none', $('#attrType').val() !== 'dropdown'); }
    $('#attrType').on('change', toggleOptions);
    $('#attributeModal form').on('crud:open', toggleOptions);
    $('#attributeModal [name="label"]').on('input', function () {
        var $key = $('#attributeModal [name="attr_key"]');
        if (!$key.prop('readonly') && !$key.data('touched')) {
            $key.val($(this).val().trim().toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '').replace(/^(\d)/, 'a_$1'));
        }
    });
    $('#attributeModal [name="attr_key"]').on('input', function () { $(this).data('touched', true); });
    $('#attributeModal form').on('crud:open', function () { $('#attributeModal [name="attr_key"]').data('touched', false); });
})(jQuery);
</script>
<?= $this->endSection() ?>
