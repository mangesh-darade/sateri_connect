<?= $this->extend('layouts/main') ?>

<?= $this->section('header_actions') ?>
<?php if (function_exists('can') && can('sequences.create')): ?>
    <a href="<?= site_url('sequences/create') ?>" class="btn btn-wa btn-sm"><i class="fas fa-plus me-1"></i> New sequence</a>
<?php endif; ?>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<div class="page-list">
<div class="card">
    <div class="card-header d-flex align-items-center justify-content-between gap-2">
        <h2 class="card-title mb-0">Sequences</h2>
        <span class="small text-muted">Multi-step drips · exit on reply</span>
    </div>
    <div class="card-body py-3">
        <?php if (! empty($sequences)): ?>
        <table class="table table-sm table-hover align-middle w-100">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Steps</th>
                    <th>Active enrollments</th>
                    <th>Status</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($sequences as $s): ?>
                    <tr>
                        <td class="fw-semibold"><?= esc($s['name'] ?? '') ?></td>
                        <td><?= (int) ($s['step_count'] ?? 0) ?></td>
                        <td><?= (int) ($s['active_enrollments'] ?? 0) ?></td>
                        <td><?= ! empty($s['is_active']) ? 'On' : 'Off' ?></td>
                        <td class="text-end">
                            <div class="table-actions justify-content-end">
                            <?php if (function_exists('can') && can('sequences.edit')): ?>
                            <a href="<?= site_url('sequences/' . (int) $s['id'] . '/edit') ?>" class="btn btn-sm btn-outline-secondary" title="Edit"><i class="fas fa-edit"></i></a>
                            <?php endif; ?>
                            <?php if (function_exists('can') && can('sequences.delete')): ?>
                            <button type="button" class="btn btn-sm btn-outline-danger" title="Delete"
                                    data-confirm-delete
                                    data-url="<?= site_url('sequences/' . (int) $s['id'] . '/delete') ?>"
                                    data-title="Delete sequence?"
                                    data-text="This cannot be undone.">
                                <i class="fas fa-trash"></i>
                            </button>
                            <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
            <div class="page-hint">No sequences yet. Create a multi-step drip (text or template) and enroll contacts.</div>
        <?php endif; ?>
    </div>
</div>
</div>
<?= $this->endSection() ?>
