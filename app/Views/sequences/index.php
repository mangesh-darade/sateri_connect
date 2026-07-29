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
                            <?php if (function_exists('can') && can('sequences.edit')): ?>
                            <a href="<?= site_url('sequences/' . (int) $s['id'] . '/edit') ?>" class="btn btn-sm btn-outline-secondary">Edit</a>
                            <?php endif; ?>
                            <?php if (function_exists('can') && can('sequences.delete')): ?>
                            <form action="<?= site_url('sequences/' . (int) $s['id'] . '/delete') ?>" method="post" class="d-inline" onsubmit="return confirm('Delete sequence?');">
                                <?= csrf_field() ?>
                                <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
                            </form>
                            <?php endif; ?>
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
