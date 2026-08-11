<?= $this->extend('layouts/main') ?>

<?= $this->section('header_actions') ?>
<?php $t = $template ?? []; ?>
<a href="<?= site_url('templates') ?>" class="btn btn-outline-secondary btn-sm">
    <i class="fas fa-arrow-left me-1"></i> Back
</a>
<?php if (strtoupper((string) ($t['status'] ?? '')) === 'APPROVED' && function_exists('can') && (can('chat.send') || can('templates.sync') || can('templates.create'))): ?>
    <button type="button" class="btn btn-wa btn-sm" id="btnShowSendTest"
        data-id="<?= (int) ($t['id'] ?? 0) ?>"
        data-name="<?= esc($t['name'] ?? '', 'attr') ?>"
        data-language="<?= esc($t['language'] ?? '', 'attr') ?>">
        <i class="fas fa-paper-plane me-1"></i> Send Test
    </button>
<?php endif; ?>
<?php if (function_exists('can') && can('templates.delete')): ?>
    <form action="<?= site_url('templates/' . (int) ($t['id'] ?? 0) . '/delete') ?>" method="post"
          onsubmit="return confirm('Delete this template from <?= esc(function_exists('whatsapp_provider_short') ? whatsapp_provider_short() : 'provider') ?> and locally?');">
        <?= csrf_field() ?>
        <button type="submit" class="btn btn-outline-danger btn-sm"><i class="fas fa-trash me-1"></i> Delete</button>
    </form>
<?php endif; ?>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php $t = $template ?? []; ?>
<div class="row g-3">
    <div class="col-lg-6">
        <div class="dash-panel">
            <div class="panel-head"><h3>Details</h3></div>
            <div class="panel-body">
                <dl class="row mb-0">
                    <dt class="col-sm-4 text-muted">Name</dt>
                    <dd class="col-sm-8 fw-semibold"><?= esc($t['name'] ?? '') ?></dd>
                    <dt class="col-sm-4 text-muted">Language</dt>
                    <dd class="col-sm-8"><?= esc($t['language'] ?? '') ?></dd>
                    <dt class="col-sm-4 text-muted">Category</dt>
                    <dd class="col-sm-8"><?= esc($t['category'] ?? '') ?></dd>
                    <dt class="col-sm-4 text-muted">Template Type</dt>
                    <dd class="col-sm-8"><?= esc(ucfirst((string) ($t['template_type'] ?? 'default'))) ?></dd>
                    <dt class="col-sm-4 text-muted">Header Type</dt>
                    <dd class="col-sm-8"><?= esc(ucfirst((string) ($t['header_type'] ?? 'none'))) ?></dd>
                    <dt class="col-sm-4 text-muted">Status</dt>
                    <dd class="col-sm-8"><?= view('partials/status_badge', ['status' => $t['status'] ?? '']) ?></dd>
                    <dt class="col-sm-4 text-muted">WABA</dt>
                    <dd class="col-sm-8"><code><?= esc($t['waba_id'] ?? '') ?></code></dd>
                    <dt class="col-sm-4 text-muted">Provider ID</dt>
                    <dd class="col-sm-8"><code><?= esc($t['meta_id'] ?? '') ?></code></dd>
                    <dt class="col-sm-4 text-muted">Variables</dt>
                    <dd class="col-sm-8">
                        <?php
                        $defs = \App\Libraries\WhatsAppTemplateVariables::definitionsForTemplate(
                            $t['variables'] ?? null,
                            (string) ($t['body'] ?? ''),
                            $t['raw_payload'] ?? null
                        );
                        if ($defs === []):
                            echo 'None';
                        else:
                            echo count($defs) . ' — ';
                            $labels = [];
                            foreach ($defs as $def) {
                                $labels[] = \App\Libraries\WhatsAppTemplateVariables::labelFor($def);
                            }
                            echo esc(implode(', ', $labels));
                        endif;
                        ?>
                    </dd>
                    <?php if (! empty($t['rejected_reason'])): ?>
                        <dt class="col-sm-4 text-muted">Rejected reason</dt>
                        <dd class="col-sm-8 text-danger"><?= esc($t['rejected_reason']) ?></dd>
                    <?php endif; ?>
                    <dt class="col-sm-4 text-muted">Last Synced</dt>
                    <dd class="col-sm-8"><?= esc(format_app_datetime($t['synced_at'] ?? null)) ?></dd>
                </dl>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="dash-panel">
            <div class="panel-head"><h3>Preview</h3></div>
            <div class="panel-body" style="background:var(--wa-chat-bg);border-radius:0 0 var(--radius) var(--radius)">
                <div class="msg-bubble inbound" style="display:inline-block;max-width:100%">
                    <?= view('partials/template_header_preview', [
                        'headerType'    => $t['header_type'] ?? '',
                        'headerContent' => $t['header_content'] ?? '',
                    ]) ?>
                    <div><?= nl2br(esc($t['body'] ?? '')) ?></div>
                    <?php if (! empty($t['footer'])): ?>
                        <div class="text-muted small mt-1"><?= esc($t['footer']) ?></div>
                    <?php endif; ?>
                </div>
                <?php
                $buttons = $t['buttons'] ?? [];
                if (is_string($buttons)) {
                    $buttons = json_decode($buttons, true) ?: [];
                }
                if (is_array($buttons) && $buttons !== []):
                ?>
                    <div class="mt-3">
                        <?php foreach ($buttons as $btn): ?>
                            <button type="button" class="btn btn-sm btn-outline-secondary me-1 mb-1" disabled>
                                <?= esc($btn['text'] ?? $btn['title'] ?? 'Button') ?>
                                <?php if (! empty($btn['type'])): ?>
                                    (<?= esc(strtolower((string) $btn['type'])) ?>)
                                <?php endif; ?>
                            </button>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="tplSendTestModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Send Test — <span id="tplSendTestName"></span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="tplSendTestId" value="">
                <div class="mb-3">
                    <label class="form-label">Recipient phone</label>
                    <input type="text" class="form-control" id="tplSendTestTo" placeholder="9198XXXXXXXX">
                </div>
                <div id="tplSendTestVars"></div>
                <div id="tplSendTestError" class="alert alert-danger d-none mt-3 mb-0"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-wa" id="tplSendTestSubmit">Send Test</button>
            </div>
        </div>
    </div>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
$(function () {
    $('#btnShowSendTest').on('click', function () {
        var $b = $(this);
        var id = $b.data('id');
        $.getJSON(APP.baseUrl + '/templates/' + id + '/preview').done(function (res) {
            var defs = (res && res.data && res.data.variable_definitions) ? res.data.variable_definitions : [];
            $('#tplSendTestId').val(id);
            $('#tplSendTestName').text($b.data('name') + ' (' + ($b.data('language') || '') + ')');
            $('#tplSendTestError').addClass('d-none');
            var $vars = $('#tplSendTestVars').empty();
            defs.forEach(function (def) {
                $vars.append(
                    '<div class="mb-2"><label class="form-label">' + $('<div>').text(def.label || ('Variable {{' + def.key + '}}')).html() +
                    '</label><input type="text" class="form-control tpl-send-var" data-key="' + $('<div>').text(def.key || '').html() + '"></div>'
                );
            });
            bootstrap.Modal.getOrCreateInstance(document.getElementById('tplSendTestModal')).show();
        });
    });
    $('#tplSendTestSubmit').on('click', function () {
        var id = $('#tplSendTestId').val();
        var to = ($('#tplSendTestTo').val() || '').trim();
        var vars = {};
        $('.tpl-send-var').each(function () { vars[$(this).data('key')] = ($(this).val() || '').trim(); });
        var $err = $('#tplSendTestError').addClass('d-none');
        if (!to) { $err.removeClass('d-none').text('Recipient phone number is required.'); return; }
        var csrf = $('meta[name="csrf-token"]').attr('content') || '';
        $.ajax({
            url: APP.baseUrl + '/templates/' + id + '/send-test',
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest' },
            contentType: 'application/json',
            dataType: 'json',
            data: JSON.stringify({ to: to, variables: vars })
        }).done(function (res) {
            if (res && res.success) {
                if (APP.toast) APP.toast(res.message || 'Sent', 'success');
                bootstrap.Modal.getOrCreateInstance(document.getElementById('tplSendTestModal')).hide();
            } else {
                $err.removeClass('d-none').text((res && res.message) || 'Send failed');
            }
        }).fail(function (xhr) {
            $err.removeClass('d-none').text((xhr.responseJSON && xhr.responseJSON.message) || 'Send failed');
        });
    });
});
</script>
<?= $this->endSection() ?>
