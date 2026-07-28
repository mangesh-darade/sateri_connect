<?= $this->extend('layouts/main') ?>

<?= $this->section('header_actions') ?>
<a href="<?= site_url('reports') ?>" class="btn btn-outline-secondary btn-sm">
    <i class="fas fa-arrow-left me-1"></i> All reports
</a>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<div class="page-list">
<div class="card">
    <div class="card-body py-3">
        <table class="table table-sm table-hover align-middle w-100" id="campaignReportsTable">
            <thead>
            <tr>
                <th>Campaign</th>
                <th>Status</th>
                <th>Total</th>
                <th>Sent</th>
                <th>Delivered</th>
                <th>Read</th>
                <th>Failed</th>
                <th>Replies</th>
                <th class="text-end">Actions</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($campaigns ?? [] as $c): ?>
                <tr>
                    <td class="fw-semibold"><?= esc($c['name'] ?? '') ?></td>
                    <td><?= view('partials/status_badge', ['status' => $c['status'] ?? '']) ?></td>
                    <td><?= (int) ($c['total_contacts'] ?? 0) ?></td>
                    <td><?= (int) ($c['sent_count'] ?? 0) ?></td>
                    <td><?= (int) ($c['delivered_count'] ?? 0) ?></td>
                    <td><?= (int) ($c['read_count'] ?? 0) ?></td>
                    <td><?= (int) ($c['failed_count'] ?? 0) ?></td>
                    <td><?= (int) ($c['reply_count'] ?? 0) ?></td>
                    <td class="text-end">
                        <div class="table-actions justify-content-end">
                            <a href="<?= site_url('campaigns/' . ($c['id'] ?? 0)) ?>" class="btn btn-sm btn-outline-secondary" title="View"><i class="fas fa-eye"></i></a>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
$(function () {
    if ($.fn.DataTable) {
        $('#campaignReportsTable').DataTable({ order: [[0, 'asc']], pageLength: 25 });
    }
});
</script>
<?= $this->endSection() ?>
