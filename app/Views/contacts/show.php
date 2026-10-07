<?= $this->extend('layouts/main') ?>

<?= $this->section('header_actions') ?>
<?php $contact = $contact ?? []; ?>
<a href="<?= site_url('contacts') ?>" class="btn btn-outline-secondary btn-sm"><i class="fas fa-arrow-left me-1"></i> Back</a>
<?php if (function_exists('can') && can('contacts.edit')): ?>
    <a href="<?= site_url('contacts/' . (int) ($contact['id'] ?? 0) . '/edit') ?>" class="btn btn-outline-secondary btn-sm"><i class="fas fa-edit me-1"></i> Edit</a>
<?php endif; ?>
<?php if (function_exists('can') && can('contacts.delete')): ?>
    <button type="button" class="btn btn-outline-danger btn-sm" data-confirm-delete data-url="<?= site_url('contacts/' . (int) ($contact['id'] ?? 0) . '/delete') ?>" data-title="Delete Contact?" data-text="Are you sure you want to delete this contact?">
        <i class="fas fa-trash-alt me-1"></i> Delete
    </button>
<?php endif; ?>
<?php if (function_exists('can') && can('chat.view')): ?>
    <a href="<?= site_url('chat?contact_id=' . (int) ($contact['id'] ?? 0)) ?>" class="btn btn-wa btn-sm"><i class="fab fa-whatsapp me-1"></i> Chat</a>
<?php endif; ?>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php
$contact       = $contact ?? [];
$contactId     = (int) ($contact['id'] ?? 0);
$attributeRows = $attributeRows ?? [];
$messages      = $messages ?? [];
$notes         = $notes ?? [];
$canChat       = function_exists('can') && can('chat.view');
$canEdit       = function_exists('can') && can('contacts.edit');
$displayName   = trim((string) ($contact['name'] ?? '')) ?: 'Unknown';
$initials      = strtoupper(mb_substr(trim((string) ($contact['name'] ?? '')) ?: (string) ($contact['mobile'] ?? '?'), 0, 2));
$dash          = static fn ($v): string => ($v === null || $v === '') ? '—' : (string) $v;

$consentSvc     = service('whatsAppConsent');
$isOptedOut     = $consentSvc->isOptedOut($contact);
$isSuppressed   = $consentSvc->isSuppressed($contact);
$hasOptIn       = $consentSvc->hasOptIn($contact);

$details = [
    ['icon' => 'fa-phone', 'label' => 'Mobile', 'value' => $contact['mobile'] ?? ''],
    ['icon' => 'fa-envelope', 'label' => 'Email', 'value' => $contact['email'] ?? ''],
    ['icon' => 'fa-globe', 'label' => 'Country', 'value' => $contact['country'] ?? ''],
    ['icon' => 'fa-birthday-cake', 'label' => 'Birthday', 'value' => $contact['birthday'] ?? ''],
    ['icon' => 'fa-user-tie', 'label' => 'Assigned to', 'value' => ($assignedName ?? '') ?: ($contact['assigned_to'] ?? '')],
    ['icon' => 'fa-signal', 'label' => 'Channel', 'value' => ucfirst((string) ($contact['channel'] ?? ''))],
    ['icon' => 'fa-fingerprint', 'label' => 'External ID', 'value' => $contact['external_id'] ?? ''],
    ['icon' => 'fa-calendar-plus', 'label' => 'Created', 'value' => format_app_datetime($contact['created_at'] ?? null)],
    ['icon' => 'fa-clock-rotate-left', 'label' => 'Updated', 'value' => format_app_datetime($contact['updated_at'] ?? null)],
];
?>

<div class="dash-panel contact-hero mb-3">
    <div class="panel-body">
        <div class="d-flex flex-wrap align-items-center gap-3">
            <div class="chat-avatar contact-hero-avatar"><?= esc($initials) ?></div>
            <div class="flex-grow-1 min-w-0">
                <h4 class="contact-hero-name mb-1"><?= esc($displayName) ?></h4>
                <div class="text-muted small mb-2">
                    <i class="fas fa-phone me-1"></i><?= esc($contact['mobile'] ?? '') ?>
                    <?php if (! empty($contact['email'])): ?>
                        <span class="mx-2">·</span><i class="fas fa-envelope me-1"></i><?= esc($contact['email']) ?>
                    <?php endif; ?>
                </div>
                <div class="d-flex flex-wrap align-items-center gap-1">
                    <?= view('partials/status_badge', ['status' => $contact['status'] ?? 'active']) ?>
                    <?= view('partials/wa_consent_badge', ['contact' => $contact]) ?>
                    <?php foreach (($contact['tags'] ?? []) as $tag): ?>
                        <span class="badge rounded-pill" style="background:<?= esc($tag['color'] ?? '#8e53f7') ?>;color:#042f2a"><?= esc($tag['name'] ?? $tag) ?></span>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="contact-hero-stats">
                <div><span><?= (int) ($messages_total ?? 0) ?></span><small>Messages</small></div>
                <div><span><?= esc(format_app_datetime($contact['last_message_at'] ?? null) ?: '—') ?></span><small>Last message</small></div>
                <div><span><?= esc(format_app_datetime($contact['last_reply_at'] ?? null) ?: '—') ?></span><small>Last reply</small></div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 contact-page align-items-start">
    <div class="col-lg-4">
        <div class="dash-panel">
            <div class="panel-head"><h3>Details</h3></div>
            <div class="panel-body">
                <ul class="contact-detail-list">
                    <?php foreach ($details as $row): ?>
                        <li>
                            <i class="fas <?= esc($row['icon']) ?>"></i>
                            <span class="label"><?= esc($row['label']) ?></span>
                            <span class="value"><?= esc($dash($row['value'])) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>

        <div class="dash-panel mt-3">
            <div class="panel-head d-flex align-items-center justify-content-between">
                <h3 class="mb-0">Attributes</h3>
                <?php if ($canEdit): ?>
                    <a href="<?= site_url('contacts/' . $contactId . '/edit') ?>" class="small">Edit</a>
                <?php endif; ?>
            </div>
            <div class="panel-body">
                <?php if ($attributeRows === []): ?>
                    <div class="text-muted small">No attributes yet.
                        <?php if (function_exists('can') && can('contacts.view')): ?>
                            Define them in <a href="<?= site_url('attributes') ?>">Contacts → Attributes</a>.
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <ul class="contact-detail-list">
                        <?php foreach ($attributeRows as $row): ?>
                            <li>
                                <span class="label"><?= esc($row['label']) ?>
                                    <?php if (! $row['defined']): ?><small class="text-muted d-block"><?= esc($row['key']) ?></small><?php endif; ?>
                                </span>
                                <span class="value<?= $row['value'] === '' ? ' text-muted' : '' ?>"><?= esc($dash($row['value'])) ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>

        <div class="dash-panel mt-3" id="waConsentPanel" data-contact-id="<?= $contactId ?>">
            <div class="panel-head"><h3>WhatsApp consent</h3></div>
            <div class="panel-body small">
                <ul class="contact-detail-list mb-2">
                    <li><span class="label">Status</span><span class="value"><?= view('partials/wa_consent_badge', ['contact' => $contact]) ?></span></li>
                    <li><span class="label">Opt-in source</span><span class="value"><?= esc(\App\Libraries\WhatsAppConsentService::OPT_IN_SOURCES[$contact['wa_opt_in_source'] ?? ''] ?? $dash($contact['wa_opt_in_source'] ?? '')) ?></span></li>
                    <li><span class="label">Opt-in date</span><span class="value"><?= esc(format_app_datetime($contact['wa_opt_in_at'] ?? null) ?: '—') ?></span></li>
                    <?php if (! $hasOptIn && ! empty($contact['wa_consent_requested_at'])): ?>
                        <li><span class="label">Opt-in asked</span><span class="value"><?= esc(format_app_datetime($contact['wa_consent_requested_at'])) ?></span></li>
                    <?php endif; ?>
                    <?php if ($isOptedOut): ?>
                        <li><span class="label">Opted out</span><span class="value text-danger"><?= esc(format_app_datetime($contact['wa_opted_out_at'])) ?></span></li>
                    <?php endif; ?>
                    <?php if ($isSuppressed): ?>
                        <li><span class="label">Paused until</span><span class="value text-warning"><?= esc(format_app_datetime($contact['wa_suppressed_until'])) ?>
                            <?php if (! empty($contact['wa_suppress_reason'])): ?><small class="d-block text-muted"><?= esc($contact['wa_suppress_reason']) ?></small><?php endif; ?>
                        </span></li>
                    <?php endif; ?>
                </ul>
                <?php if ($canEdit): ?>
                    <?php if ($isOptedOut): ?>
                        <p class="text-muted mb-0">Customer sent STOP. They can opt back in by sending START on WhatsApp.</p>
                    <?php else: ?>
                        <div class="d-flex flex-wrap gap-2 align-items-center">
                            <?php if (! $hasOptIn): ?>
                                <select class="form-select form-select-sm" id="waConsentSource">
                                    <option value="">How was consent given?</option>
                                    <?php foreach (\App\Libraries\WhatsAppConsentService::OPT_IN_SOURCES as $key => $label): ?>
                                        <option value="<?= esc($key) ?>"><?= esc($label) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="button" class="btn btn-sm btn-wa" data-wa-consent="opt_in"><i class="fas fa-check me-1"></i> Record opt-in</button>
                                <button type="button" class="btn btn-sm btn-outline-success" data-wa-consent="request_opt_in"
                                        title="Send the Agree / Stop opt-in message on WhatsApp"><i class="fab fa-whatsapp me-1"></i> Request opt-in</button>
                            <?php endif; ?>
                            <button type="button" class="btn btn-sm btn-outline-danger" data-wa-consent="opt_out"><i class="fas fa-ban me-1"></i> Opt out</button>
                            <?php if ($isSuppressed): ?>
                                <button type="button" class="btn btn-sm btn-outline-secondary" data-wa-consent="clear_suppression">Clear pause</button>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
                <?php if (function_exists('can') && can('contacts.delete')): ?>
                    <div class="border-top pt-2 mt-3">
                        <button type="button" class="btn btn-sm btn-link text-danger p-0" id="btnEraseContactData">
                            <i class="fas fa-user-slash me-1"></i> Erase customer data (deletion request)
                        </button>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-8">
        <div class="dash-panel">
            <div class="panel-head d-flex flex-wrap align-items-center justify-content-between gap-2">
                <ul class="nav nav-pills contact-tabs" role="tablist">
                    <li class="nav-item"><button class="nav-link active" data-bs-toggle="pill" data-bs-target="#tabMessages" type="button">
                        <i class="fas fa-comments me-1"></i> Messages <span class="badge bg-light text-dark ms-1"><?= (int) ($messages_total ?? 0) ?></span>
                    </button></li>
                    <li class="nav-item"><button class="nav-link" data-bs-toggle="pill" data-bs-target="#tabNotes" type="button">
                        <i class="fas fa-sticky-note me-1"></i> Notes <span class="badge bg-light text-dark ms-1" id="contactNotesCount"><?= count($notes) ?></span>
                    </button></li>
                </ul>
                <?php if ((int) ($messages_total ?? 0) > count($messages)): ?>
                    <span class="small text-muted">Showing latest <?= count($messages) ?> of <?= (int) $messages_total ?></span>
                <?php endif; ?>
            </div>
            <div class="panel-body p-0">
                <div class="tab-content">
                    <div class="tab-pane fade show active" id="tabMessages">
                        <div class="contact-history" id="contactHistory">
                            <?php if ($messages === []): ?>
                                <div class="activity-empty py-5 text-center">
                                    No WhatsApp messages for this contact yet.
                                    <?php if ($canChat): ?>
                                        <div class="mt-2"><a href="<?= site_url('chat?contact_id=' . $contactId) ?>">Open Team Inbox</a> to start a conversation.</div>
                                    <?php endif; ?>
                                </div>
                            <?php else: ?>
                                <?php $lastDay = ''; ?>
                                <?php foreach ($messages as $msg): ?>
                                    <?php
                                    $direction = strtolower((string) ($msg['direction'] ?? '')) === 'inbound' ? 'inbound' : 'outbound';
                                    $type      = (string) ($msg['message_type'] ?? $msg['type'] ?? 'text');
                                    $body      = (string) ($msg['content'] ?? $msg['body'] ?? '');
                                    if ($body === '' && ! empty($msg['media_url'])) {
                                        $body = '[' . $type . ' media]';
                                    }
                                    if ($body === '' && ! empty($msg['campaign_id'])) {
                                        $body = '[Campaign template #' . (int) $msg['campaign_id'] . ']';
                                    }
                                    $ts  = strtotime((string) ($msg['created_at'] ?? '')) ?: null;
                                    $day = $ts ? date('d M Y', $ts) : '';
                                    ?>
                                    <?php if ($day !== '' && $day !== $lastDay): $lastDay = $day; ?>
                                        <div class="chat-day-sep"><span><?= esc($day) ?></span></div>
                                    <?php endif; ?>
                                    <div class="msg-row <?= $direction ?>">
                                        <div class="msg-bubble">
                                            <?php if ($type !== 'text' || ! empty($msg['campaign_id'])): ?>
                                                <div class="small text-muted mb-1">
                                                    <?= esc(ucfirst($type)) ?><?= ! empty($msg['campaign_id']) ? ' · Campaign #' . (int) $msg['campaign_id'] : '' ?>
                                                </div>
                                            <?php endif; ?>
                                            <div class="contact-msg-text"><?= nl2br(esc(mb_strimwidth($body, 0, 600, '…'))) ?></div>
                                            <span class="msg-time">
                                                <?= esc($ts ? date('h:i A', $ts) : '') ?>
                                                <?php if ($direction === 'outbound' && ! empty($msg['status'])): ?>
                                                    <span class="msg-status"><?= view('partials/status_badge', ['status' => $msg['status']]) ?></span>
                                                <?php endif; ?>
                                            </span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="tab-pane fade" id="tabNotes">
                        <div class="p-3">
                            <?php if (! empty($contact['notes'])): ?>
                                <div class="contact-note-profile mb-3">
                                    <small class="text-muted d-block mb-1">Contact notes</small>
                                    <?= nl2br(esc($contact['notes'])) ?>
                                </div>
                            <?php endif; ?>
                            <?php if ($canChat): ?>
                                <form id="contactAddNoteForm" class="mb-3">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="contact_id" value="<?= $contactId ?>">
                                    <textarea name="note" id="contactNoteInput" class="form-control mb-2" rows="2" placeholder="Add internal note…" required></textarea>
                                    <div class="text-end"><button type="submit" class="btn btn-sm btn-wa"><i class="fas fa-plus me-1"></i> Add note</button></div>
                                </form>
                            <?php endif; ?>
                            <div id="contactNotesLive"></div>
                            <div id="contactNotesExisting">
                                <?php foreach ($notes as $note): ?>
                                    <div class="contact-note-item">
                                        <small class="text-muted"><?= esc(format_app_datetime($note['created_at'] ?? null)) ?> · <?= esc($note['user_name'] ?? '') ?></small>
                                        <div><?= esc($note['note'] ?? $note['content'] ?? '') ?></div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <?php if ($notes === [] && empty($contact['notes'])): ?>
                                <div class="text-muted small" id="contactNotesEmpty">No notes yet.</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
(function ($) {
    var $history = $('#contactHistory');
    if ($history.length) $history.scrollTop($history[0].scrollHeight);

    $('#contactAddNoteForm').on('submit', function (e) {
        e.preventDefault();
        var note = ($('#contactNoteInput').val() || '').trim();
        var contactId = $(this).find('[name="contact_id"]').val();
        if (!note) return;
        var $btn = $(this).find('button[type="submit"]').prop('disabled', true);
        APP.post(APP.baseUrl + '/chat/note', { contact_id: contactId, note: note })
            .done(function (res) {
                var n = (res && res.data) ? res.data : {};
                var $row = $('<div class="contact-note-item"><small class="text-muted"></small><div></div></div>');
                $row.find('small').text('Just now');
                $row.find('div').text(n.note || note);
                $('#contactNotesLive').prepend($row);
                $('#contactNotesEmpty').remove();
                $('#contactNotesCount').text((parseInt($('#contactNotesCount').text(), 10) || 0) + 1);
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

    $('#waConsentPanel').on('click', '[data-wa-consent]', function () {
        var action = $(this).data('wa-consent');
        var contactId = $('#waConsentPanel').data('contact-id');
        var source = $('#waConsentSource').val() || '';
        if (action === 'opt_in' && !source) {
            APP.toast('Choose how the customer gave WhatsApp consent.', 'warning');
            return;
        }
        var send = function () {
            APP.post(APP.baseUrl + '/contacts/' + contactId + '/consent', { action: action, source: source })
                .done(function (res) {
                    APP.toast(res.message || 'Saved');
                    setTimeout(function () { window.location.reload(); }, 600);
                })
                .fail(function (xhr) {
                    APP.toast((xhr.responseJSON && xhr.responseJSON.message) || 'Consent update failed', 'error');
                });
        };
        if (action === 'opt_out') {
            APP.confirm({ title: 'Opt out this contact?', text: 'Campaigns, automations and sequences will stop for this number.', confirmText: 'Opt out' })
                .then(function (r) { if (r.isConfirmed) send(); });
            return;
        }
        if (action === 'request_opt_in') {
            APP.confirm({ title: 'Ask for WhatsApp opt-in?', text: 'The customer gets one Agree / Stop message. Only ask people who expect to hear from you — unwanted messages lower your WhatsApp quality rating.', confirmText: 'Send request' })
                .then(function (r) { if (r.isConfirmed) send(); });
            return;
        }
        send();
    });

    $('#btnEraseContactData').on('click', function () {
        var contactId = $('#waConsentPanel').data('contact-id');
        APP.confirm({
            title: 'Erase all data for this customer?',
            text: 'Messages, chats, notes, tags and history are permanently deleted. Only the mobile number is kept as opted-out so they are never messaged again. This cannot be undone.',
            confirmText: 'Erase data'
        }).then(function (r) {
            if (!r.isConfirmed) return;
            APP.post(APP.baseUrl + '/contacts/' + contactId + '/erase', {})
                .done(function (res) {
                    APP.toast(res.message || 'Customer data erased');
                    setTimeout(function () { window.location.href = APP.baseUrl + '/contacts'; }, 800);
                })
                .fail(function (xhr) {
                    APP.toast((xhr.responseJSON && xhr.responseJSON.message) || 'Erase failed', 'error');
                });
        });
    });
})(jQuery);
</script>
<?= $this->endSection() ?>
