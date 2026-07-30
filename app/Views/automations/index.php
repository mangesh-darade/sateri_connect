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
            <div class="activity-empty py-5">
                <i class="fas fa-robot"></i>
                No automations yet — create a workflow to get started.
                <?php if (function_exists('can') && can('automations.create')): ?>
                    <div class="mt-3"><a href="<?= site_url('automations/create') ?>" class="btn btn-wa btn-sm">New workflow</a></div>
                <?php endif; ?>
            </div>
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
