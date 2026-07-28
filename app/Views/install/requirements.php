<?= $this->extend('layouts/install') ?>

<?= $this->section('content') ?>
<h3 class="mb-1" style="font-family:var(--font-display);color:var(--wa-ink)">Server requirements</h3>
<p class="text-muted mb-3">Everything below must pass before we continue.</p>
<?php $checks = $checks ?? $requirements ?? []; ?>
<div class="table-responsive">
<table class="table table-hover">
    <thead>
        <tr><th>Requirement</th><th>Status</th><th>Notes</th></tr>
    </thead>
    <tbody>
        <?php if (! empty($checks)): ?>
            <?php foreach ($checks as $row): ?>
                <?php
                $ok = ! empty($row['pass'])
                    || ! empty($row['ok'])
                    || ! empty($row['passed'])
                    || (($row['status'] ?? '') === 'ok');
                ?>
                <tr>
                    <td class="fw-semibold"><?= esc($row['name'] ?? $row['label'] ?? '') ?></td>
                    <td>
                        <?php if ($ok): ?>
                            <span class="badge rounded-pill" style="background:var(--wa-mist);color:var(--wa-teal)">OK</span>
                        <?php else: ?>
                            <span class="badge rounded-pill bg-danger">Fail</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-muted small"><?= esc($row['detail'] ?? $row['message'] ?? $row['value'] ?? '') ?></td>
                </tr>
            <?php endforeach; ?>
        <?php else: ?>
            <tr><td>PHP >= 8.2</td><td><?= version_compare(PHP_VERSION, '8.2.0', '>=') ? '<span class="badge rounded-pill" style="background:var(--wa-mist);color:var(--wa-teal)">OK</span>' : '<span class="badge rounded-pill bg-danger">Fail</span>' ?></td><td class="text-muted small"><?= esc(PHP_VERSION) ?></td></tr>
            <tr><td>intl</td><td><?= extension_loaded('intl') ? '<span class="badge rounded-pill" style="background:var(--wa-mist);color:var(--wa-teal)">OK</span>' : '<span class="badge rounded-pill bg-danger">Fail</span>' ?></td><td></td></tr>
            <tr><td>mbstring</td><td><?= extension_loaded('mbstring') ? '<span class="badge rounded-pill" style="background:var(--wa-mist);color:var(--wa-teal)">OK</span>' : '<span class="badge rounded-pill bg-danger">Fail</span>' ?></td><td></td></tr>
            <tr><td>json</td><td><?= extension_loaded('json') ? '<span class="badge rounded-pill" style="background:var(--wa-mist);color:var(--wa-teal)">OK</span>' : '<span class="badge rounded-pill bg-danger">Fail</span>' ?></td><td></td></tr>
            <tr><td>curl</td><td><?= extension_loaded('curl') ? '<span class="badge rounded-pill" style="background:var(--wa-mist);color:var(--wa-teal)">OK</span>' : '<span class="badge rounded-pill bg-danger">Fail</span>' ?></td><td></td></tr>
            <tr><td>mysqli / PDO MySQL</td><td><?= (extension_loaded('mysqli') || extension_loaded('pdo_mysql')) ? '<span class="badge rounded-pill" style="background:var(--wa-mist);color:var(--wa-teal)">OK</span>' : '<span class="badge rounded-pill bg-danger">Fail</span>' ?></td><td></td></tr>
            <tr><td>writable/</td><td><?= is_writable(WRITEPATH) ? '<span class="badge rounded-pill" style="background:var(--wa-mist);color:var(--wa-teal)">OK</span>' : '<span class="badge rounded-pill bg-danger">Fail</span>' ?></td><td class="text-muted small"><?= esc(WRITEPATH) ?></td></tr>
        <?php endif; ?>
    </tbody>
</table>
</div>
<?php $canContinue = $can_continue ?? $allPassed ?? $all_passed ?? true; ?>
<div class="d-flex justify-content-between mt-4">
    <a href="<?= site_url('install') ?>" class="btn btn-outline-secondary">Back</a>
    <?php if ($canContinue): ?>
        <a href="<?= site_url('install/database') ?>" class="btn btn-wa">Continue <i class="fas fa-arrow-right ms-1"></i></a>
    <?php else: ?>
        <button type="button" class="btn btn-secondary" disabled>Fix requirements to continue</button>
    <?php endif; ?>
</div>
<?= $this->endSection() ?>
