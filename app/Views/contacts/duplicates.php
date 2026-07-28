<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>
<div class="page-toolbar">
    <div class="toolbar-actions">
        <a href="<?= site_url('contacts') ?>" class="btn btn-outline-secondary btn-sm">
            <i class="fas fa-arrow-left me-1"></i> Back to contacts
        </a>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h3 class="card-title">Duplicate mobiles</h3>
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
                    <td colspan="4"><div class="activity-empty">No duplicates found.</div></td>
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
<?= $this->endSection() ?>
