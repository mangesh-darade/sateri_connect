<?= $this->extend('layouts/main') ?>

<?= $this->section('header_actions') ?>
    <button type="button" class="btn btn-wa btn-sm" data-crud-new>
        <i class="fas fa-plus me-1"></i> Add entry
    </button>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<div class="page-list" data-crud="/knowledge-base" data-modal="#kbModal">

<div class="card">
    <div class="card-header d-flex align-items-center justify-content-between gap-2">
        <h2 class="card-title mb-0">Knowledge base</h2>
        <span class="small text-muted">The AI bot and the inbox "AI Suggest" button answer from these entries</span>
    </div>
    <div class="card-body py-3">
        <?php if (function_exists('is_ai_enabled') && ! is_ai_enabled()): ?>
            <div class="alert alert-warning py-2">AI is switched off. Turn it on in Settings → AI Bot &amp; Copilot for these entries to be used.</div>
        <?php endif; ?>
        <table class="table table-sm table-hover align-middle w-100" id="kbTable">
            <thead>
                <tr>
                    <th style="width:260px">Title</th>
                    <th>Answer</th>
                    <th style="width:90px">Status</th>
                    <th style="width:150px">Updated</th>
                    <th class="text-end" style="width:110px">Action</th>
                </tr>
            </thead>
            <tbody></tbody>
        </table>
    </div>
</div>
</div>

<div class="modal fade" id="kbModal" tabindex="-1" aria-hidden="true" data-title-new="Add entry" data-title-edit="Edit entry">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <form autocomplete="off">
                <div class="modal-header">
                    <h5 class="modal-title">Add entry</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Title</label>
                        <input type="text" class="form-control" name="title" maxlength="191" required placeholder="What are your delivery charges?">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Answer / details</label>
                        <textarea class="form-control" name="content" rows="7" maxlength="4000" required
                                  placeholder="Delivery is free above ₹499. Below that it is ₹40. Orders arrive in 2-4 working days."></textarea>
                        <div class="form-text">Write facts, not instructions. Include the words customers use, in any language, so matching works.</div>
                    </div>
                    <div class="mb-1">
                        <label class="form-label">Status</label>
                        <select class="form-select" name="is_active">
                            <option value="1">Active</option>
                            <option value="0">Inactive</option>
                        </select>
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
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= base_url('assets/js/simple-crud.js') ?>"></script>
<script>
(function ($) {
    function escAttr(v) { return AppDT.esc(v).replace(/"/g, '&quot;').replace(/'/g, '&#39;'); }

    AppDT.table('#kbTable', {
        url: <?= json_encode(site_url('knowledge-base/data')) ?>,
        order: [[3, 'desc']],
        emptyText: 'No entries yet. Add your prices, timings, delivery and return policy so the AI can answer from them.',
        columns: [
            { data: null, className: 'fw-medium', render: function (r) { return AppDT.esc(r.title); } },
            { data: null, className: 'small text-muted', render: function (r) {
                return '<div class="text-truncate" style="max-width:460px" title="' + escAttr(r.content) + '">' + AppDT.esc(r.content) + '</div>';
            } },
            { data: null, render: function (r) {
                return r.is_active ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Inactive</span>';
            } },
            { data: null, className: 'small text-muted', render: function (r) { return AppDT.esc(r.updated_at); } },
            { data: null, orderable: false, className: 'text-end', render: function (r) {
                return '<div class="table-actions justify-content-end">' +
                    '<button type="button" class="btn btn-sm btn-outline-secondary" title="Edit" data-crud-edit data-row="' + escAttr(JSON.stringify(r)) + '"><i class="fas fa-pen"></i></button> ' +
                    '<button type="button" class="btn btn-sm btn-outline-danger" title="Delete" data-crud-delete data-id="' + escAttr(r.id) + '" data-name="' + escAttr(r.title) + '"><i class="fas fa-trash"></i></button>' +
                    '</div>';
            } }
        ]
    });
})(jQuery);
</script>
<?= $this->endSection() ?>
