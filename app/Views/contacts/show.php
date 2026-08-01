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
                        <span class="badge me-1 mb-1 rounded-pill" style="background:<?= esc($tag['color'] ?? '#8e53f7'); ?>;color:#042f2a"><?= esc($tag['name'] ?? $tag) ?></span>
                    <?php endforeach; ?>
                <?php else: ?>
                    <span class="text-muted">No groups</span>
                <?php endif; ?>
            </div>
        </div>

        <div class="dash-panel mt-3">
            <div class="panel-head"><h3>Contact columns</h3></div>
            <div class="panel-body">
                <dl class="row mb-0 small">
                    <dt class="col-5 text-muted">ID</dt>
                    <dd class="col-7"><?= esc((string) ($contact['id'] ?? '')) ?></dd>
                    <dt class="col-5 text-muted">Mobile</dt>
                    <dd class="col-7"><?= esc($contact['mobile'] ?? '—') ?></dd>
                    <dt class="col-5 text-muted">Email</dt>
                    <dd class="col-7"><?= esc($contact['email'] ?? '—') ?></dd>
                    <dt class="col-5 text-muted">Country</dt>
                    <dd class="col-7"><?= esc($contact['country'] ?? '—') ?></dd>
                    <dt class="col-5 text-muted">Birthday</dt>
                    <dd class="col-7"><?= esc($contact['birthday'] ?? '—') ?></dd>
                    <dt class="col-5 text-muted">Status</dt>
                    <dd class="col-7"><?= esc($contact['status'] ?? '—') ?></dd>
                    <dt class="col-5 text-muted">Channel</dt>
                    <dd class="col-7"><?= esc($contact['channel'] ?? '—') ?></dd>
                    <dt class="col-5 text-muted">External ID</dt>
                    <dd class="col-7"><?= esc($contact['external_id'] ?? '—') ?></dd>
                    <dt class="col-5 text-muted">Assigned to</dt>
                    <dd class="col-7"><?= esc((string) ($contact['assigned_to'] ?? '—')) ?></dd>
                    <dt class="col-5 text-muted">Last message</dt>
                    <dd class="col-7"><?= esc(format_app_datetime($contact['last_message_at'] ?? null) ?: '—') ?></dd>
                    <dt class="col-5 text-muted">Last reply</dt>
                    <dd class="col-7"><?= esc(format_app_datetime($contact['last_reply_at'] ?? null) ?: '—') ?></dd>
                    <dt class="col-5 text-muted">Created</dt>
                    <dd class="col-7"><?= esc(format_app_datetime($contact['created_at'] ?? null) ?: '—') ?></dd>
                    <dt class="col-5 text-muted">Updated</dt>
                    <dd class="col-7"><?= esc(format_app_datetime($contact['updated_at'] ?? null) ?: '—') ?></dd>
                </dl>
            </div>
        </div>

        <div class="dash-panel mt-3">
            <div class="panel-head"><h3>Custom fields</h3></div>
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
                ksort($cf, SORT_NATURAL | SORT_FLAG_CASE);
                ?>
                <?php if ($cf === []): ?>
                    <span class="text-muted">No custom fields</span>
                <?php else: ?>
                    <dl class="row mb-0 small">
                        <?php foreach ($cf as $k => $v): ?>
                            <dt class="col-5 text-muted"><?= esc((string) $k) ?></dt>
                            <dd class="col-7"><?= esc(is_scalar($v) || $v === null ? (string) $v : json_encode($v)) ?></dd>
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
                            <small class="text-muted"><?= esc(format_app_datetime($note['created_at'] ?? null)) ?> · <?= esc($note['user_name'] ?? '') ?></small>
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
            <div class="panel-head d-flex flex-wrap align-items-center justify-content-between gap-2">
                <h3 class="mb-0">Message history</h3>
                <div class="d-flex align-items-center gap-2">
                    <?php if ((int) ($messages_total ?? 0) > 0): ?>
                        <span class="small text-muted"><?= (int) ($messages_total ?? 0) ?> total<?= count($messages ?? []) < (int) ($messages_total ?? 0) ? ' · latest ' . count($messages ?? []) : '' ?></span>
                    <?php endif; ?>
                    <?php if (function_exists('can') && can('chat.view')): ?>
                        <a href="<?= site_url('chat?contact_id=' . (int) ($contact['id'] ?? 0)) ?>" class="btn btn-sm btn-wa">
                            <i class="fab fa-whatsapp me-1"></i> Open in chat
                        </a>
                    <?php endif; ?>
                </div>
            </div>
            <div class="panel-body p-0">
                <div class="table-responsive" style="max-height:560px;overflow:auto">
                    <table class="table table-hover mb-0 align-middle">
                        <thead class="sticky-top bg-white">
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
                                    <?php
                                    $direction = strtolower((string) ($msg['direction'] ?? ''));
                                    $type      = (string) ($msg['message_type'] ?? $msg['type'] ?? 'text');
                                    $body      = (string) ($msg['content'] ?? $msg['body'] ?? '');
                                    if ($body === '' && ! empty($msg['media_url'])) {
                                        $body = '[' . $type . ' media]';
                                    }
                                    if ($body === '' && ! empty($msg['campaign_id'])) {
                                        $body = '[Campaign template #' . (int) $msg['campaign_id'] . ']';
                                    }
                                    ?>
                                    <tr>
                                        <td class="text-muted small text-nowrap"><?= esc(format_app_datetime($msg['created_at'] ?? null) ?: '—') ?></td>
                                        <td>
                                            <?php if ($direction === 'inbound'): ?>
                                                <span class="badge bg-info-subtle text-info-emphasis">Inbound</span>
                                            <?php elseif ($direction === 'outbound'): ?>
                                                <span class="badge bg-primary-subtle text-primary-emphasis">Outbound</span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary"><?= esc($direction !== '' ? $direction : '—') ?></span>
                                            <?php endif; ?>
                                            <?php if (! empty($msg['campaign_id'])): ?>
                                                <div class="small text-muted mt-1">Campaign #<?= (int) $msg['campaign_id'] ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="small"><?= esc($type) ?></td>
                                        <td><?= esc(mb_strimwidth($body, 0, 120, '…')) ?></td>
                                        <td><?= view('partials/status_badge', ['status' => $msg['status'] ?? '']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="5">
                                        <div class="activity-empty py-4">
                                            No WhatsApp messages for this contact yet.
                                            <?php if (function_exists('can') && can('chat.view')): ?>
                                                <div class="mt-2">
                                                    <a href="<?= site_url('chat?contact_id=' . (int) ($contact['id'] ?? 0)) ?>">Open Team Inbox</a>
                                                    to start a conversation.
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
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
        var $btn = $(this).find('button[type="submit"]').prop('disabled', true);
        APP.post(APP.baseUrl + '/chat/note', { contact_id: contactId, note: note })
            .done(function (res) {
                var n = (res && res.data) ? res.data : {};
                var text = n.note || note;
                var when = 'Just now';
                $('#contactNotesLive').prepend(
                    '<div class="border-bottom py-2">' +
                    '<small class="text-muted"></small>' +
                    '<div></div></div>'
                );
                var $row = $('#contactNotesLive .border-bottom').first();
                $row.find('small').text(when);
                $row.find('div').text(text);
                $('#contactNoteInput').val('');
                APP.toast(res.message || 'Note added');
            })
            .fail(function (xhr) {
                APP.toast((xhr.responseJSON && xhr.responseJSON.message) || 'Failed to add note', 'error');
            })
            .always(function () {
                $btn.prop('disabled', false);
            });
    });
})(jQuery);
</script>
<?= $this->endSection() ?>
