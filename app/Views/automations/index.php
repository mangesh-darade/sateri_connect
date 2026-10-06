<?= $this->extend('layouts/main') ?>

<?= $this->section('header_actions') ?>
<?php if (function_exists('can') && can('automations.create')): ?>
    <a href="<?= site_url('automations/create') ?>" class="btn btn-wa btn-sm"><i class="fas fa-plus me-1"></i> New workflow</a>
<?php endif; ?>
<?php if (function_exists('can') && (can('automations.create') || can('automations.edit'))): ?>
    <form action="<?= site_url('automations/sync-cheerio') ?>" method="post" class="d-inline" id="formSyncCheerioWorkflows">
        <?= csrf_field() ?>
        <button type="submit" class="btn btn-outline-secondary btn-sm" id="btnSyncCheerioWorkflows"
                title="Sync workflows for the active WhatsApp provider">
            <i class="fas fa-cloud-download-alt me-1"></i> <?= esc(function_exists('whatsapp_sync_label') ? whatsapp_sync_label() : 'Sync workflows') ?>
        </button>
    </form>
<?php endif; ?>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<div class="page-list">
    <!-- Workflow Tabs: Visual Automations & Automated Journeys (Drips) -->
    <ul class="nav nav-pills mb-3 bg-white p-1 rounded-3 border shadow-sm d-flex flex-nowrap overflow-x-auto" role="tablist">
        <li class="nav-item">
            <a class="nav-link py-2 px-3 text-nowrap fw-medium active" href="<?= site_url('automations') ?>">
                <i class="fab fa-whatsapp me-1 text-success"></i> WhatsApp Workflows
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link py-2 px-3 text-nowrap fw-medium text-secondary" href="<?= site_url('automations?channel=email') ?>">
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

<div class="card">
    <div class="card-header d-flex align-items-center justify-content-between gap-2">
        <h2 class="card-title mb-0">Workflows</h2>
        <span class="small text-muted">Trigger → condition → action</span>
    </div>
    <div class="card-body py-3">
        <?php if (! empty($automations)): ?>
        <table class="table table-sm table-hover align-middle w-100" id="automationsTable">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Trigger</th>
                    <th>Priority</th>
                    <th>Active</th>
                    <th>Updated</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($automations as $a): ?>
                    <tr>
                        <td>
                            <div class="fw-semibold"><?= esc($a['name']) ?></div>
                            <?php if (($a['trigger_type'] ?? '') === 'cheerio_workflow'): ?>
                                <small class="text-muted"><i class="fas fa-cloud"></i> Synced from Cheerio</small>
                            <?php elseif (! empty($a['flow_graph'])): ?>
                                <small class="text-wa"><i class="fas fa-project-diagram"></i> Visual workflow</small>
                            <?php endif; ?>
                        </td>
                        <td><code><?= esc($a['trigger_type'] ?? '') ?></code></td>
                        <td><?= esc((string) ($a['priority'] ?? 0)) ?></td>
                        <td>
                            <?php if (function_exists('can') && can('automations.edit')): ?>
                                <form action="<?= site_url('automations/' . (int) $a['id'] . '/toggle') ?>" method="post" class="d-inline"><?= csrf_field() ?>
                                    <button type="submit" class="btn btn-sm <?= ! empty($a['is_active']) ? 'btn-wa' : 'btn-outline-secondary' ?>">
                                        <?= ! empty($a['is_active']) ? 'On' : 'Off' ?>
                                    </button>
                                </form>
                            <?php else: ?>
                                <?= ! empty($a['is_active']) ? 'On' : 'Off' ?>
                            <?php endif; ?>
                        </td>
                        <td class="text-muted small text-nowrap"><?= esc(format_app_datetime($a['updated_at'] ?? null)) ?></td>
                        <td class="text-end">
                            <div class="table-actions justify-content-end">
                            <?php if (function_exists('can') && can('automations.edit')): ?>
                                <a href="<?= site_url('automations/' . (int) $a['id'] . '/edit') ?>" class="btn btn-sm btn-outline-secondary" title="Edit"><i class="fas fa-edit"></i></a>
                                <a href="<?= site_url('automations/' . (int) $a['id'] . '/builder') ?>" class="btn btn-sm btn-outline-secondary" title="Builder"><i class="fas fa-project-diagram"></i></a>
                            <?php endif; ?>
                            <?php if (function_exists('can') && can('automations.delete')): ?>
                                <button type="button" class="btn btn-sm btn-outline-danger" data-confirm-delete data-url="<?= site_url('automations/' . (int) $a['id'] . '/delete') ?>" title="Delete"><i class="fas fa-trash"></i></button>
                            <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
            <?= view('partials/empty_state', [
                'icon'        => 'robot',
                'title'       => 'No automations yet',
                'text'        => 'Create a workflow to get started.',
                'actionUrl'   => (function_exists('can') && can('automations.create')) ? site_url('automations/create') : null,
                'actionLabel' => (function_exists('can') && can('automations.create')) ? 'New workflow' : null,
            ]) ?>
        <?php endif; ?>
    </div>
</div>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
$(function () {
    if ($.fn.DataTable && $('#automationsTable').length) { $('#automationsTable').DataTable(); }
    $('#formSyncCheerioWorkflows').on('submit', function () {
        var $btn = $('#btnSyncCheerioWorkflows').prop('disabled', true);
        $btn.html('<i class="fas fa-spinner fa-spin me-1"></i> Syncing…');
    });
});
</script>
<?= $this->endSection() ?>
