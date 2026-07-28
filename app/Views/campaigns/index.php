<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>
<div class="page-list">
<div class="page-toolbar">
    <div class="toolbar-actions">
        <?php if (function_exists('can') && can('campaigns.create')): ?>
            <a href="<?= site_url('campaigns/create') ?>" class="btn btn-wa btn-sm"><i class="fas fa-plus me-1"></i> New campaign</a>
        <?php endif; ?>
    </div>
</div>

<div class="card">
    <div class="card-body py-3">
        <?php if (! empty($campaigns)): ?>
        <table class="table table-sm table-hover align-middle w-100" id="campaignsTable">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Template</th>
                    <th>Status</th>
                    <th>Contacts</th>
                    <th>Sent</th>
                    <th>Delivered</th>
                    <th>Failed</th>
                    <th>Scheduled</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($campaigns as $c): ?>
                    <tr>
                        <td><a class="fw-semibold text-decoration-none" href="<?= site_url('campaigns/' . (int) $c['id']) ?>"><?= esc($c['name']) ?></a></td>
                        <td class="text-muted"><?= esc($c['template_name'] ?? $c['template_id'] ?? '—') ?></td>
                        <td><?= view('partials/status_badge', ['status' => $c['status'] ?? 'draft']) ?></td>
                        <td><?= esc((string) ($c['total_contacts'] ?? 0)) ?></td>
                        <td><?= esc((string) ($c['sent_count'] ?? 0)) ?></td>
                        <td><?= esc((string) ($c['delivered_count'] ?? 0)) ?></td>
                        <td><?= esc((string) ($c['failed_count'] ?? 0)) ?></td>
                        <td class="text-muted small text-nowrap"><?= esc($c['scheduled_at'] ?? '—') ?></td>
                        <td class="text-end">
                            <div class="table-actions justify-content-end">
                            <a class="btn btn-sm btn-outline-secondary" href="<?= site_url('campaigns/' . (int) $c['id']) ?>" title="View"><i class="fas fa-eye"></i></a>
                            <?php if (function_exists('can') && can('campaigns.edit') && in_array($c['status'] ?? '', ['draft', 'scheduled', 'paused'], true)): ?>
                                <a class="btn btn-sm btn-outline-secondary" href="<?= site_url('campaigns/' . (int) $c['id'] . '/edit') ?>" title="Edit"><i class="fas fa-edit"></i></a>
                            <?php endif; ?>
                            <?php if (function_exists('can') && can('campaigns.delete')): ?>
                                <button type="button" class="btn btn-sm btn-outline-danger" data-confirm-delete data-url="<?= site_url('campaigns/' . (int) $c['id'] . '/delete') ?>" title="Delete"><i class="fas fa-trash"></i></button>
                            <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
            <div class="activity-empty">
                <i class="fas fa-bullhorn"></i>
                No campaigns yet — create one to start broadcasting.
                <?php if (function_exists('can') && can('campaigns.create')): ?>
                    <div class="mt-3"><a href="<?= site_url('campaigns/create') ?>" class="btn btn-wa btn-sm">New campaign</a></div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</div>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>$(function(){ if($.fn.DataTable && $('#campaignsTable').length){ $('#campaignsTable').DataTable({order:[[0,'asc']]}); } });</script>
<?= $this->endSection() ?>
