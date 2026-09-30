<?= $this->extend('layouts/main') ?>

<?php $canEdit = function_exists('can') && can('chat.send'); ?>

<?= $this->section('header_actions') ?>
<?php if ($canEdit && $tableReady): ?>
    <button type="button" class="btn btn-wa btn-sm" data-crud-new>
        <i class="fas fa-plus me-1"></i> Add quick reply
    </button>
<?php endif; ?>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<div class="page-list page-quick-replies" data-crud="/quick-replies" data-modal="#quickReplyModal">

<?php if (! $tableReady): ?>
    <div class="alert alert-warning">Quick replies table is missing. Run <code>php spark migrate</code> to enable this page.</div>
<?php endif; ?>

<div class="card">
    <div class="card-header d-flex align-items-center justify-content-between gap-2">
        <h2 class="card-title mb-0">Quick replies</h2>
        <span class="small text-muted">In the inbox, type <code>/</code> in the message box to insert one</span>
    </div>
    <div class="card-body py-3">
        <table class="table table-sm table-hover align-middle w-100">
            <thead>
                <tr>
                    <th style="width:160px">Shortcut</th>
                    <th style="width:200px">Title</th>
                    <th>Message</th>
                    <?php if ($canEdit): ?><th class="text-end" style="width:110px">Action</th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($quickReplies)): ?>
                <tr>
                    <td colspan="4" class="text-center text-muted py-4">
                        No quick replies yet. Add common answers (price, address, timings) so agents reply in one click.
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($quickReplies as $qr): ?>
                    <tr>
                        <td><code>/<?= esc($qr['shortcut']) ?></code></td>
                        <td class="fw-medium"><?= esc($qr['title']) ?></td>
                        <td class="small text-muted text-truncate" style="max-width:420px" title="<?= esc($qr['message'], 'attr') ?>"><?= esc($qr['message']) ?></td>
                        <?php if ($canEdit): ?>
                        <td class="text-end">
                            <div class="table-actions justify-content-end">
                                <button type="button" class="btn btn-sm btn-outline-secondary" title="Edit"
                                        data-crud-edit data-row="<?= esc(json_encode($qr), 'attr') ?>">
                                    <i class="fas fa-pen"></i>
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-danger" title="Delete"
                                        data-crud-delete data-id="<?= (int) $qr['id'] ?>" data-name="/<?= esc($qr['shortcut'], 'attr') ?>">
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
<div class="modal fade" id="quickReplyModal" tabindex="-1" aria-hidden="true" data-title-new="Add quick reply" data-title-edit="Edit quick reply">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form autocomplete="off">
                <div class="modal-header">
                    <h5 class="modal-title">Add quick reply</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Shortcut</label>
                        <div class="input-group">
                            <span class="input-group-text">/</span>
                            <input type="text" class="form-control font-monospace" name="shortcut" maxlength="50" placeholder="price"
                                   pattern="[A-Za-z0-9][A-Za-z0-9_\-]*" required>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Title <span class="text-muted small">(optional)</span></label>
                        <input type="text" class="form-control" name="title" maxlength="100" placeholder="Price list">
                    </div>
                    <div class="mb-1">
                        <label class="form-label">Message</label>
                        <textarea class="form-control" name="message" rows="5" maxlength="4096" required
                                  placeholder="Hi {{name}}, our plans start at ₹999/month."></textarea>
                        <div class="form-text">
                            Click to insert:
                            <?php foreach (array_merge(['name', 'mobile', 'email'], array_keys($attributeDefs ?? [])) as $ph): ?>
                                <code role="button" class="qr-placeholder me-1" data-ph="{{<?= esc($ph, 'attr') ?>}}" title="<?= esc(($attributeDefs[$ph]['label'] ?? ucfirst($ph)), 'attr') ?>">{{<?= esc($ph) ?>}}</code>
                            <?php endforeach; ?>
                        </div>
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
    $(document).on('click', '.qr-placeholder', function () {
        var $ta = $(this).closest('.mb-1').find('textarea[name="message"]');
        var el = $ta[0];
        if (!el) return;
        var ph = String($(this).data('ph'));
        var start = el.selectionStart || el.value.length;
        var end = el.selectionEnd || start;
        el.value = el.value.slice(0, start) + ph + el.value.slice(end);
        el.focus();
        el.selectionStart = el.selectionEnd = start + ph.length;
    });
})(jQuery);
</script>
<?= $this->endSection() ?>
