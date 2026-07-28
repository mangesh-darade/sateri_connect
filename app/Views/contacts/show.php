<?= $this->extend('layouts/main') ?>

<?= $this->section('header_actions') ?>
<?php $contact = $contact ?? []; ?>
<a href="<?= site_url('contacts') ?>" class="btn btn-outline-secondary btn-sm"><i class="fas fa-arrow-left me-1"></i> Back</a>
<?php if (function_exists('can') && can('contacts.edit')): ?>
    <a href="<?= site_url('contacts/' . (int) ($contact['id'] ?? 0) . '/edit') ?>" class="btn btn-outline-secondary btn-sm"><i class="fas fa-edit me-1"></i> Edit</a>
<?php endif; ?>
<?php if (function_exists('can') && can('chat.view')): ?>
    <a href="<?= site_url('chat?contact_id=' . (int) ($contact['id'] ?? 0)) ?>" class="btn btn-wa btn-sm"><i class="fab fa-whatsapp me-1"></i> Chat</a>
<?php endif; ?>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php $contact = $contact ?? []; ?>
<div class="row g-2">
    <div class="col-lg-4">
        <div class="dash-panel text-center">
            <div class="panel-body pt-3">
                <div class="chat-avatar mx-auto mb-2" style="width:64px;height:64px;font-size:1.35rem">
                    <?= esc(strtoupper(substr($contact['name'] ?? $contact['mobile'] ?? '?', 0, 2))) ?>
                </div>
                <h4 class="mb-1" style="font-family:var(--font-display);font-size:1.15rem"><?= esc($contact['name'] ?? 'Unknown') ?></h4>
                <p class="text-muted mb-2"><?= esc($contact['mobile'] ?? '') ?></p>
                <?= view('partials/status_badge', ['status' => $contact['status'] ?? 'active']) ?>
                <hr>
                <p class="mb-1 small"><i class="fas fa-envelope me-1 text-muted"></i> <?= esc($contact['email'] ?? '—') ?></p>
                <p class="mb-1 small"><i class="fas fa-globe me-1 text-muted"></i> <?= esc($contact['country'] ?? '—') ?></p>
                <p class="mb-0 small"><i class="fas fa-birthday-cake me-1 text-muted"></i> <?= esc($contact['birthday'] ?? '—') ?></p>
            </div>
        </div>

        <div class="dash-panel mt-3">
            <div class="panel-head"><h3>Customer Groups</h3></div>
            <div class="panel-body">
                <?php if (! empty($contact['tags'])): ?>
                    <?php foreach ($contact['tags'] as $tag): ?>
                        <span class="badge me-1 mb-1 rounded-pill" style="background:<?= esc($tag['color'] ?? '#25D366'); ?>;color:#042f2a"><?= esc($tag['name'] ?? $tag) ?></span>
                    <?php endforeach; ?>
                <?php else: ?>
                    <span class="text-muted">No groups</span>
                <?php endif; ?>
            </div>
        </div>

        <div class="dash-panel mt-3">
            <div class="panel-head"><h3>Attributes</h3></div>
            <div class="panel-body">
                <?php
                $cf = $contact['custom_fields'] ?? [];
                if (is_string($cf)) {
                    $decoded = json_decode($cf, true);
                    $cf = is_array($decoded) ? $decoded : [];
                }
                if (! is_array($cf)) {
                    $cf = [];
                }
                $cf = array_filter($cf, static fn ($v, $k) => ! str_starts_with((string) $k, '_'), ARRAY_FILTER_USE_BOTH);
                ?>
                <?php if ($cf === []): ?>
                    <span class="text-muted">No custom attributes</span>
                <?php else: ?>
                    <dl class="row mb-0 small">
                        <?php foreach ($cf as $k => $v): ?>
                            <dt class="col-5 text-muted"><?= esc((string) $k) ?></dt>
                            <dd class="col-7"><?= esc(is_scalar($v) ? (string) $v : json_encode($v)) ?></dd>
                        <?php endforeach; ?>
                    </dl>
                <?php endif; ?>
            </div>
        </div>

        <div class="dash-panel mt-3">
            <div class="panel-head"><h3>Notes</h3></div>
            <div class="panel-body">
                <p class="mb-3"><?= nl2br(esc($contact['notes'] ?? '—')) ?></p>
                <?php if (function_exists('can') && can('chat.view')): ?>
                <form id="contactAddNoteForm" class="mb-2">
                    <?= csrf_field() ?>
                    <input type="hidden" name="contact_id" value="<?= (int) ($contact['id'] ?? 0) ?>">
                    <textarea name="note" id="contactNoteInput" class="form-control mb-2" rows="2" placeholder="Add internal note…" required></textarea>
                    <button type="submit" class="btn btn-sm btn-outline-secondary">Add note</button>
                </form>
                <div id="contactNotesLive"></div>
                <?php endif; ?>
                <?php if (! empty($notes)): ?>
                    <hr>
                    <div id="contactNotesExisting">
                    <?php foreach ($notes as $note): ?>
                        <div class="border-bottom py-2">
                            <small class="text-muted"><?= esc($note['created_at'] ?? '') ?> · <?= esc($note['user_name'] ?? '') ?></small>
                            <div><?= esc($note['note'] ?? $note['content'] ?? '') ?></div>
                        </div>
                    <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-8">
        <div class="dash-panel">
            <div class="panel-head"><h3>Message history</h3></div>
            <div class="panel-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Time</th>
                                <th>Direction</th>
                                <th>Type</th>
                                <th>Content</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (! empty($messages)): ?>
                                <?php foreach ($messages as $msg): ?>
                                    <tr>
                                        <td class="text-muted small"><?= esc($msg['created_at'] ?? '') ?></td>
                                        <td><?= esc($msg['direction'] ?? '') ?></td>
                                        <td><?= esc($msg['message_type'] ?? $msg['type'] ?? '') ?></td>
                                        <td><?= esc(mb_strimwidth($msg['body'] ?? $msg['content'] ?? '', 0, 80, '…')) ?></td>
                                        <td><?= view('partials/status_badge', ['status' => $msg['status'] ?? '']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="5"><div class="activity-empty">No messages yet</div></td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
(function ($) {
    $('#contactAddNoteForm').on('submit', function (e) {
        e.preventDefault();
        var note = ($('#contactNoteInput').val() || '').trim();
        var contactId = $(this).find('[name="contact_id"]').val();
        if (!note) return;
        APP.post(APP.baseUrl + '/chat/note', { contact_id: contactId, note: note })
            .done(function (res) {
                var n = (res && res.data) ? res.data : {};
                $('#contactNotesLive').prepend(
                    '<div class="border-bottom py-2">' +
                    '<small class="text-muted">Just now</small>' +
                    '<div></div></div>'
                );
                $('#contactNotesLive .border-bottom').first().find('div').text(n.note || note);
                $('#contactNoteInput').val('');
                APP.toast('Note added');
            })
            .fail(function (xhr) {
                APP.toast((xhr.responseJSON && xhr.responseJSON.message) || 'Failed to add note', 'error');
            });
    });
})(jQuery);
</script>
<?= $this->endSection() ?>
