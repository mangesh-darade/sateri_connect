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
        <table class="table table-sm table-hover align-middle w-100" id="attributesTable">
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
            <tbody></tbody>
        </table>
    </div>
</div>

<?php if (! empty($undefinedKeys)): ?>
<div class="card mt-3">
    <div class="card-header">
        <h2 class="card-title mb-0">Found on contacts — not added yet</h2>
        <div class="small text-muted">These fields came from imports, workflows or webhooks. Add them here to show them as columns in the contact list and as fields on the contact form.</div>
    </div>
    <div class="card-body py-3 d-flex flex-wrap gap-2">
        <?php foreach ($undefinedKeys as $key => $count): ?>
            <span class="badge bg-light text-dark border d-inline-flex align-items-center gap-2 py-2 px-2 fw-normal">
                <code><?= esc($key) ?></code>
                <a href="<?= site_url('contacts?attr_key=' . rawurlencode($key) . '&attr_op=not_empty') ?>" class="text-muted text-decoration-none" title="Show these contacts"><?= (int) $count ?> contact<?= $count === 1 ? '' : 's' ?></a>
                <?php if ($canEdit): ?>
                    <button type="button" class="btn btn-sm btn-outline-success py-0 px-2" data-crud-new
                            data-prefill="<?= esc(json_encode(['attr_key' => $key, 'label' => ucwords(str_replace('_', ' ', $key)), 'type' => 'text']), 'attr') ?>">
                        <i class="fas fa-plus me-1"></i>Add
                    </button>
                <?php endif; ?>
            </span>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>
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
    var canEdit = <?= $canEdit ? 'true' : 'false' ?>;
    var siteBase = <?= json_encode(rtrim(site_url(), '/')) ?>;
    function escAttr(v) { return AppDT.esc(v).replace(/"/g, '&quot;').replace(/'/g, '&#39;'); }
    var usedLinks = {
        workflows: { icon: 'fa-diagram-project', label: 'Workflow', url: function (id) { return siteBase + '/automations/' + id + '/builder'; } },
        keywords:  { icon: 'fa-key', label: 'Keyword', url: function (id) { return siteBase + '/keywords/' + id + '/edit'; } },
        campaigns: { icon: 'fa-bullhorn', label: 'Campaign', url: function (id) { return siteBase + '/campaigns/' + id; } }
    };

    if ($('#attributesTable').length && <?= $tableReady ? 'true' : 'false' ?>) {
        var cols = [
            { data: null, className: 'fw-medium', render: function (r) { return AppDT.esc(r.label); } },
            { data: null, render: function (r) { return '<code>' + AppDT.esc(r.attr_key) + '</code>'; } },
            { data: null, render: function (r) { return '<span class="badge bg-light text-dark border">' + AppDT.esc(r.type_label) + '</span>'; } },
            { data: null, orderable: false, className: 'small text-muted', render: function (r) {
                var h = (r.options && r.options.length) ? AppDT.esc(r.options.join(', ')) : '';
                if (r.default_value) h += '<div>Default: <span class="text-dark">' + AppDT.esc(r.default_value) + '</span></div>';
                return h;
            } },
            { data: null, orderable: false, className: 'text-center', render: function (r) {
                if (!(r.usage > 0)) return '0';
                return '<a href="' + escAttr(siteBase + '/contacts?attr_key=' + encodeURIComponent(r.attr_key) + '&attr_op=not_empty') + '" title="Show these contacts">' + AppDT.esc(r.usage) + '</a>';
            } },
            { data: null, orderable: false, className: 'small', render: function (r) {
                var h = '', n = 0;
                $.each(usedLinks, function (type, meta) {
                    $.each((r.used_in && r.used_in[type]) || [], function (_, ref) {
                        n++;
                        var name = ref.name || ('#' + ref.id);
                        if (name.length > 28) name = name.slice(0, 27) + '\u2026';
                        h += '<a href="' + escAttr(meta.url(ref.id)) + '" class="badge bg-light text-dark border text-decoration-none me-1 mb-1" title="' + escAttr(meta.label) + '">' +
                            '<i class="fas ' + meta.icon + ' me-1 text-muted"></i>' + AppDT.esc(name) + '</a>';
                    });
                });
                return n ? h : '<span class="text-muted">\u2014</span>';
            } }
        ];
        if (canEdit) {
            cols.push({ data: null, orderable: false, className: 'text-end', render: function (r) {
                var n = 0;
                $.each(r.used_in || {}, function (_, list) { n += list.length; });
                var text = n > 0
                    ? 'Used in ' + n + ' workflow/keyword/campaign(s) \u2014 they keep working with the raw key but lose type checks. Values already saved on contacts are kept.'
                    : 'Values already saved on contacts are kept.';
                return '<div class="table-actions justify-content-end">' +
                    '<button type="button" class="btn btn-sm btn-outline-secondary" title="Edit" data-crud-edit data-row="' + escAttr(JSON.stringify(r)) + '"><i class="fas fa-pen"></i></button> ' +
                    '<button type="button" class="btn btn-sm btn-outline-danger" title="Delete" data-crud-delete data-id="' + escAttr(r.id) + '" data-name="' + escAttr(r.label) + '" data-text="' + escAttr(text) + '"><i class="fas fa-trash"></i></button>' +
                    '</div>';
            } });
        }
        AppDT.table('#attributesTable', {
            url: <?= json_encode(site_url('attributes/data')) ?>,
            order: [[0, 'asc']],
            emptyText: 'No attributes yet. Add one (e.g. City as a dropdown) so agents and workflows save clean, typed values.',
            columns: cols
        });
    }

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
