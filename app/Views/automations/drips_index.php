<?= $this->extend('layouts/main') ?>

<?= $this->section('header_actions') ?>
<?php if (! empty($canEdit)): ?>
    <button type="button" class="btn btn-wa btn-sm" id="btnCreateDrip">
        <i class="fas fa-plus me-1"></i> New Auto Drip
    </button>
<?php endif; ?>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php
$drips    = $drips ?? [];
$builders = $builders ?? [];
$totalDrips = count($drips);
$activeDrips = count(array_filter($drips, fn($d) => ($d['status'] ?? '') === 'active'));
$totalSteps = array_sum(array_map(fn($d) => is_array($d['steps'] ?? null) ? count($d['steps']) : 0, $drips));
?>
<div class="page-list" id="emailDripsPageRoot">

    <!-- Workflow Tabs: Visual Automations & Automated Journeys (Drips) -->
    <ul class="nav nav-pills mb-3 bg-white p-1 rounded-3 border shadow-sm d-flex flex-nowrap overflow-x-auto" role="tablist">
        <li class="nav-item">
            <a class="nav-link py-2 px-3 text-nowrap fw-medium text-secondary" href="<?= site_url('automations') ?>">
                <i class="fab fa-whatsapp me-1 text-success"></i> WhatsApp Workflows
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link py-2 px-3 text-nowrap fw-medium active" href="<?= site_url('automations?channel=email') ?>">
                <i class="fas fa-envelope me-1 text-primary"></i> Email Workflows (Auto Drips)
            </a>
        </li>
        <?php if (function_exists('can') && can('sequences.view')): ?>
        <li class="nav-item">
            <a class="nav-link py-2 px-3 text-nowrap fw-medium text-secondary" href="<?= site_url('sequences') ?>">
                <i class="fas fa-list-ol me-1 text-info"></i> Sequences
            </a>
        </li>
        <?php endif; ?>
    </ul>

    <!-- KPI Summary Cards -->
    <div class="row g-2 mb-3">
        <div class="col-6 col-md-4">
            <div class="kpi-card kpi-accent-sky">
                <span class="kpi-icon"><i class="fas fa-stream"></i></span>
                <span class="kpi-label">Total Drip Workflows</span>
                <span class="kpi-value"><?= (int) $totalDrips ?></span>
            </div>
        </div>
        <div class="col-6 col-md-4">
            <div class="kpi-card kpi-accent-green">
                <span class="kpi-icon"><i class="fas fa-play-circle"></i></span>
                <span class="kpi-label">Active Journeys</span>
                <span class="kpi-value"><?= (int) $activeDrips ?></span>
            </div>
        </div>
        <div class="col-12 col-md-4">
            <div class="kpi-card kpi-accent-amber">
                <span class="kpi-icon"><i class="fas fa-layer-group"></i></span>
                <span class="kpi-label">Total Email Steps</span>
                <span class="kpi-value"><?= (int) $totalSteps ?></span>
            </div>
        </div>
    </div>

    <!-- Drip Workflows List -->
    <div class="card border-0 shadow-sm rounded-3">
        <div class="card-header bg-white py-3 border-bottom d-flex align-items-center justify-content-between">
            <div>
                <h6 class="mb-0 fw-bold text-dark"><i class="fas fa-paper-plane text-primary me-2"></i>Email Nurture Journeys</h6>
                <small class="text-muted">Automated multi-step timed email sequences triggered on tags, subscriptions, or events.</small>
            </div>
            <span class="badge bg-secondary-subtle text-secondary"><?= count($drips) ?> workflows</span>
        </div>
        <div class="card-body p-0">
            <?php if (empty($drips)): ?>
                <div class="text-center py-5 text-muted">
                    <div class="mb-3 text-secondary opacity-50">
                        <i class="fas fa-stream fa-3x"></i>
                    </div>
                    <h6 class="fw-semibold text-dark mb-1">No email drip workflows yet</h6>
                    <p class="small text-muted mb-3">Set up your first automated email series (e.g., Welcome series, onboarding steps).</p>
                    <?php if (! empty($canEdit)): ?>
                        <button type="button" class="btn btn-wa btn-sm" id="btnEmptyCreateDrip">
                            <i class="fas fa-plus me-1"></i> Create First Drip Workflow
                        </button>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0" id="dripsTable">
                        <thead class="table-light">
                            <tr>
                                <th style="width: 32%;">Workflow Name</th>
                                <th style="width: 25%;">Trigger Condition</th>
                                <th style="width: 15%;">Steps Count</th>
                                <th style="width: 13%;">Status</th>
                                <th style="width: 15%;" class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($drips as $d): ?>
                                <?php
                                $dId      = (int) ($d['id'] ?? 0);
                                $dName    = (string) ($d['name'] ?? 'Untitled Drip');
                                $dDesc    = (string) ($d['description'] ?? '');
                                $dTrigger = (string) ($d['trigger_type'] ?? 'manual');
                                $dVal     = (string) ($d['trigger_value'] ?? '');
                                $dStatus  = strtolower((string) ($d['status'] ?? 'draft'));
                                $steps    = is_array($d['steps'] ?? null) ? $d['steps'] : [];
                                $stepCnt  = count($steps);
                                $dJson    = json_encode($d);
                                ?>
                                <tr data-id="<?= $dId ?>">
                                    <td>
                                        <div class="d-flex align-items-center gap-2">
                                            <div class="rounded-2 bg-primary-subtle text-primary p-2 d-flex align-items-center justify-content-center" style="width:36px; height:36px;">
                                                <i class="fas fa-stream"></i>
                                            </div>
                                            <div>
                                                <div class="fw-semibold text-dark"><?= esc($dName) ?></div>
                                                <?php if ($dDesc !== ''): ?>
                                                    <div class="small text-muted text-truncate" style="max-width: 240px;"><?= esc($dDesc) ?></div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if ($dTrigger === 'on_tag'): ?>
                                            <span class="badge bg-info-subtle text-info border"><i class="fas fa-tag me-1"></i> On Tag: <strong><?= esc($dVal ?: 'Any') ?></strong></span>
                                        <?php elseif ($dTrigger === 'on_subscribe'): ?>
                                            <span class="badge bg-success-subtle text-success border"><i class="fas fa-user-plus me-1"></i> On New Contact</span>
                                        <?php else: ?>
                                            <span class="badge bg-light text-dark border"><i class="fas fa-hand-pointer me-1"></i> Manual / API Trigger</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge bg-secondary-subtle text-secondary fs-7">
                                            <i class="fas fa-layer-group me-1"></i><?= $stepCnt ?> step<?= $stepCnt === 1 ? '' : 's' ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?= view('partials/status_badge', [
                                            'status' => $dStatus,
                                            'map'    => [
                                                'active'   => 'success',
                                                'draft'    => 'warning',
                                                'paused'   => 'warning',
                                                'archived' => 'secondary',
                                            ],
                                        ]) ?>
                                    </td>
                                    <td class="text-end">
                                        <div class="table-actions justify-content-end gap-1">
                                            <?php if (! empty($canEdit)): ?>
                                                <button type="button" class="btn btn-sm btn-icon-action js-edit-drip"
                                                        data-drip="<?= esc($dJson) ?>"
                                                        title="Edit Drip Workflow">
                                                    <i class="fas fa-pen text-primary"></i>
                                                </button>
                                                <button type="button" class="btn btn-sm btn-icon-action js-delete-drip"
                                                        data-id="<?= $dId ?>"
                                                        data-name="<?= esc($dName) ?>"
                                                        title="Delete Drip">
                                                    <i class="fas fa-trash text-danger"></i>
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Create / Edit Drip Modal -->
<div class="modal fade" id="dripModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <form id="dripModalForm">
                <?= csrf_field() ?>
                <input type="hidden" name="id" id="drip_id" value="">
                <div class="modal-header border-bottom py-2 bg-light">
                    <h6 class="modal-title fw-bold" id="dripModalTitle"><i class="fas fa-stream text-primary me-2"></i>Email Drip Workflow</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-3">
                    <div class="row g-2 mb-3">
                        <div class="col-md-7">
                            <label class="form-label small fw-semibold mb-1">Workflow Name <span class="text-danger">*</span></label>
                            <input type="text" name="name" id="drip_name" class="form-control form-control-sm" placeholder="e.g. 7-Day Customer Onboarding Drip" required maxlength="191">
                        </div>
                        <div class="col-md-5">
                            <label class="form-label small fw-semibold mb-1">Status</label>
                            <select name="status" id="drip_status" class="form-select form-select-sm">
                                <option value="active">Active (Automations Running)</option>
                                <option value="draft">Draft (Work in progress)</option>
                                <option value="paused">Paused</option>
                            </select>
                        </div>
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold mb-1">Trigger Event</label>
                            <select name="trigger_type" id="drip_trigger_type" class="form-select form-select-sm">
                                <option value="on_tag">When Contact is Tagged</option>
                                <option value="on_subscribe">When New Contact is Added</option>
                                <option value="manual">Manual Trigger Only</option>
                            </select>
                        </div>
                        <div class="col-md-6" id="dripTriggerValueWrap">
                            <label class="form-label small fw-semibold mb-1">Tag Name</label>
                            <input type="text" name="trigger_value" id="drip_trigger_value" class="form-control form-control-sm" placeholder="e.g. VIP, Customer, New Lead">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-semibold mb-1">Description <span class="text-muted">(Optional)</span></label>
                        <input type="text" name="description" id="drip_description" class="form-control form-control-sm" placeholder="Brief note on what this journey achieves">
                    </div>

                    <!-- Steps Timeline -->
                    <div class="border rounded-3 p-3 bg-light">
                        <div class="d-flex align-items-center justify-content-between mb-2">
                            <h6 class="mb-0 fw-bold small text-dark"><i class="fas fa-list-ol me-1 text-primary"></i> Sequence Steps</h6>
                            <button type="button" class="btn btn-sm btn-outline-primary py-0 px-2" id="btnAddDripStep">
                                <i class="fas fa-plus me-1"></i> Add Step
                            </button>
                        </div>
                        <div id="dripStepsContainer" class="d-flex flex-column gap-2"></div>
                    </div>

                    <div class="alert alert-danger py-2 small d-none mt-2 mb-0" id="dripModalError"></div>
                </div>
                <div class="modal-footer py-2">
                    <button type="button" class="btn btn-sm btn-light border" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-wa btn-sm px-3" id="btnSaveDrip">
                        <i class="fas fa-save me-1"></i> Save Workflow
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Builder Template Options for Step Select -->
<template id="builderOptionsTpl">
    <option value="">-- Custom HTML Content --</option>
    <?php foreach ($builders as $b): ?>
        <option value="<?= (int) $b['id'] ?>"><?= esc($b['name']) ?></option>
    <?php endforeach; ?>
</template>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<?= $this->include('partials/email_editor_assets') ?>
<script>
$(function () {
    var dripModal = new bootstrap.Modal(document.getElementById('dripModal'));
    var stepIndex = 0;

    function renderStep(step) {
        step = step || { delay_hours: 0, subject: '', html_content: '', builder_id: '' };
        var idx = stepIndex++;
        var builderOpts = $('#builderOptionsTpl').html();

        var html = '<div class="card border shadow-none drip-step-card p-2 bg-white" data-step-idx="' + idx + '">' +
            '<div class="d-flex align-items-center justify-content-between mb-2">' +
                '<span class="badge bg-primary text-white step-num-badge">Step</span>' +
                '<button type="button" class="btn btn-link text-danger p-0 js-remove-step" title="Remove step"><i class="fas fa-times"></i></button>' +
            '</div>' +
            '<div class="row g-2 mb-2">' +
                '<div class="col-md-4">' +
                    '<label class="form-label small mb-1 fw-semibold">Delay (Hours after previous)</label>' +
                    '<input type="number" min="0" class="form-control form-control-sm step-delay" value="' + (step.delay_hours || 0) + '">' +
                '</div>' +
                '<div class="col-md-8">' +
                    '<label class="form-label small mb-1 fw-semibold">Email Subject <span class="text-danger">*</span></label>' +
                    '<input type="text" class="form-control form-control-sm step-subject" value="' + (escapeHtml(step.subject || '')) + '" placeholder="e.g. Day 1: Welcome to the family!">' +
                '</div>' +
            '</div>' +
            '<div class="row g-2">' +
                '<div class="col-md-5">' +
                    '<label class="form-label small mb-1 fw-semibold">Use Reusable Template</label>' +
                    '<select class="form-select form-select-sm step-builder">' + builderOpts + '</select>' +
                '</div>' +
                '<div class="col-md-7">' +
                    '<label class="form-label small mb-1 fw-semibold">Or Custom Email Content</label>' +
                    '<textarea class="form-control form-control-sm font-monospace step-html" rows="2" placeholder="<p>Hello {{name}}, welcome!</p>">' + (escapeHtml(step.html_content || '')) + '</textarea>' +
                '</div>' +
            '</div>' +
        '</div>';

        var $el = $(html);
        if (step.builder_id) {
            $el.find('.step-builder').val(step.builder_id);
        }
        $('#dripStepsContainer').append($el);
        APP.emailEditor.init($el.find('.step-html'), { height: 140, placeholder: 'Email content (optional if template selected)' });
        updateStepBadges();
    }

    function updateStepBadges() {
        $('#dripStepsContainer .drip-step-card').each(function (i) {
            $(this).find('.step-num-badge').text('Step ' + (i + 1));
        });
    }

    function escapeHtml(text) {
        return $('<div>').text(text).html();
    }

    $('#drip_trigger_type').on('change', function () {
        if (this.value === 'on_tag') {
            $('#dripTriggerValueWrap').removeClass('d-none');
        } else {
            $('#dripTriggerValueWrap').addClass('d-none');
        }
    });

    $('#btnAddDripStep').on('click', function () {
        renderStep();
    });

    $(document).on('click', '.js-remove-step', function () {
        $(this).closest('.drip-step-card').remove();
        updateStepBadges();
    });

    // Create Drip
    $('#btnCreateDrip, #btnEmptyCreateDrip').on('click', function () {
        $('#dripModalTitle').html('<i class="fas fa-plus-circle text-primary me-2"></i>Create Email Drip Workflow');
        $('#drip_id').val('');
        $('#drip_name').val('');
        $('#drip_description').val('');
        $('#drip_trigger_type').val('on_tag').trigger('change');
        $('#drip_trigger_value').val('');
        $('#drip_status').val('active');
        $('#dripStepsContainer').empty();
        $('#dripModalError').addClass('d-none').text('');
        renderStep({ delay_hours: 0, subject: 'Welcome to our platform!', html_content: '<p>Hello {{name}}, welcome!</p>' });
        dripModal.show();
    });

    // Edit Drip
    $(document).on('click', '.js-edit-drip', function () {
        var drip = $(this).data('drip');
        if (typeof drip === 'string') drip = JSON.parse(drip);

        $('#dripModalTitle').html('<i class="fas fa-pen text-primary me-2"></i>Edit Workflow #' + drip.id);
        $('#drip_id').val(drip.id);
        $('#drip_name').val(drip.name);
        $('#drip_description').val(drip.description || '');
        $('#drip_trigger_type').val(drip.trigger_type || 'on_tag').trigger('change');
        $('#drip_trigger_value').val(drip.trigger_value || '');
        $('#drip_status').val(drip.status || 'active');
        $('#dripStepsContainer').empty();
        $('#dripModalError').addClass('d-none').text('');

        var steps = drip.steps || [];
        if (steps.length === 0) {
            renderStep();
        } else {
            steps.forEach(function (s) { renderStep(s); });
        }

        dripModal.show();
    });

    // Submit Drip Form
    $('#dripModalForm').on('submit', function (e) {
        e.preventDefault();
        var $btn = $('#btnSaveDrip');
        var origHtml = $btn.html();
        $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin me-1"></i> Saving...');
        $('#dripModalError').addClass('d-none').text('');

        var steps = [];
        $('#dripStepsContainer .drip-step-card').each(function () {
            var $c = $(this);
            var subj = $c.find('.step-subject').val().trim();
            if (subj) {
                steps.push({
                    delay_hours: parseInt($c.find('.step-delay').val()) || 0,
                    subject: subj,
                    builder_id: $c.find('.step-builder').val() || null,
                    html_content: $c.find('.step-html').val() || ''
                });
            }
        });

        if (steps.length === 0) {
            $('#dripModalError').removeClass('d-none').text('Please add at least one sequence step with a subject line.');
            $btn.prop('disabled', false).html(origHtml);
            return;
        }

        var payload = {
            id: $('#drip_id').val(),
            name: $('#drip_name').val(),
            description: $('#drip_description').val(),
            trigger_type: $('#drip_trigger_type').val(),
            trigger_value: $('#drip_trigger_value').val(),
            status: $('#drip_status').val(),
            steps: JSON.stringify(steps)
        };

        APP.post(APP.baseUrl + '/email-manager/drips', payload).done(function (res) {
            APP.toast(res.message || 'Workflow saved', 'success');
            dripModal.hide();
            setTimeout(function () { window.location.reload(); }, 600);
        }).fail(function (xhr) {
            var msg = (xhr.responseJSON && xhr.responseJSON.message) || 'Failed to save workflow';
            $('#dripModalError').removeClass('d-none').text(msg);
            $btn.prop('disabled', false).html(origHtml);
        });
    });

    // Delete Drip
    $(document).on('click', '.js-delete-drip', function () {
        var id = $(this).data('id');
        var name = $(this).data('name');
        APP.confirm({
            title: 'Delete Drip Workflow?',
            text: 'Are you sure you want to delete "' + name + '"? Any queued drip emails will be stopped.',
            confirmText: 'Yes, Delete',
            confirmColor: '#dc2626'
        }).then(function (result) {
            if (!result.isConfirmed) return;
            APP.post(APP.baseUrl + '/email-manager/drips/' + id + '/delete', {}).done(function (res) {
                APP.toast(res.message || 'Workflow deleted', 'success');
                setTimeout(function () { window.location.reload(); }, 600);
            }).fail(function (xhr) {
                APP.toast((xhr.responseJSON && xhr.responseJSON.message) || 'Delete failed', 'error');
            });
        });
    });
});
</script>
<?= $this->endSection() ?>
