<?= $this->extend('layouts/main') ?>

<?= $this->section('header_actions') ?>
<a href="<?= site_url('contacts') ?>" class="btn btn-outline-secondary btn-sm">
    <i class="fas fa-arrow-left me-1"></i> Back to contacts
</a>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<div class="page-list">
<div class="card">
    <div class="card-header">
        <h2 class="card-title mb-0">Duplicate mobiles</h2>
    </div>
    <div class="card-body table-responsive p-0">
        <table class="table table-hover mb-0">
            <thead>
            <tr>
                <th>Mobile</th>
                <th>Count</th>
                <th>Names</th>
                <th>IDs</th>
            </tr>
            </thead>
            <tbody>
            <?php if (empty($duplicates)): ?>
                <tr>
                    <td colspan="4">
                        <?= view('partials/empty_state', [
                            'icon'  => 'check-circle',
                            'title' => 'No duplicates found',
                            'text'  => 'Every mobile number currently maps to a single contact.',
                        ]) ?>
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($duplicates as $row): ?>
                    <tr>
                        <td class="fw-semibold"><?= esc($row['mobile'] ?? '') ?></td>
                        <td><span class="badge rounded-pill" style="background:#f0a202;color:#042f2a"><?= (int) ($row['cnt'] ?? 0) ?></span></td>
                        <td><?= esc($row['names'] ?? '') ?></td>
                        <td>
                            <?php
                            $ids = array_filter(array_map('trim', explode(',', (string) ($row['ids'] ?? ''))));
                            foreach ($ids as $id):
                            ?>
                                <a href="<?= site_url('contacts/' . $id) ?>" class="badge rounded-pill text-decoration-none me-1" style="background:var(--wa-mist);color:var(--wa-teal)">#<?= esc($id) ?></a>
                            <?php endforeach; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
</div>
<?= $this->endSection() ?>
