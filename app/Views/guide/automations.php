<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>
<?php
$provider = $provider ?? 'meta';
$providerLabel = $providerLabel ?? 'Meta';
$flows = $flows ?? [];
$stats = $stats ?? ['total' => 0, 'active' => 0, 'triggers' => 0, 'conditions' => 0, 'actions' => 0];
?>
<div class="page-list">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
        <div>
            <h4 class="mb-1">Automation flows guide</h4>
            <p class="text-muted small mb-0">
                Catalog flows seeded by trigger / condition / action.
                Active WhatsApp provider: <strong><?= esc($providerLabel) ?></strong>.
            </p>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a href="<?= site_url('automations') ?>" class="btn btn-sm btn-wa">Open Automations</a>
            <a href="<?= site_url('guide/local') ?>" class="btn btn-sm btn-outline-secondary">Local guide</a>
        </div>
    </div>

    <div class="row g-2 mb-3">
        <div class="col-6 col-md"><div class="card form-card"><div class="card-body py-2"><div class="small text-muted">Total catalog</div><div class="fs-5 fw-semibold"><?= (int) $stats['total'] ?></div></div></div></div>
        <div class="col-6 col-md"><div class="card form-card"><div class="card-body py-2"><div class="small text-muted">Active</div><div class="fs-5 fw-semibold text-success"><?= (int) $stats['active'] ?></div></div></div></div>
        <div class="col-4 col-md"><div class="card form-card"><div class="card-body py-2"><div class="small text-muted">Triggers</div><div class="fs-5 fw-semibold"><?= (int) $stats['triggers'] ?></div></div></div></div>
        <div class="col-4 col-md"><div class="card form-card"><div class="card-body py-2"><div class="small text-muted">Conditions</div><div class="fs-5 fw-semibold"><?= (int) $stats['conditions'] ?></div></div></div></div>
        <div class="col-4 col-md"><div class="card form-card"><div class="card-body py-2"><div class="small text-muted">Actions</div><div class="fs-5 fw-semibold"><?= (int) $stats['actions'] ?></div></div></div></div>
    </div>

    <div class="alert alert-light border small mb-3">
        <strong>How to fire safely:</strong>
        Message-based flows use a keyword filter like <code>FLOWTEST_…</code> so they do not reply to every chat.
        External triggers (Shopify, Facebook, webhook, …) run when <code>processTrigger</code> is called with that type.
        Re-seed / Meta test: <code>php spark automations:seed-catalog --test</code>
    </div>

    <?php if ($flows === []): ?>
        <div class="activity-empty">
            <i class="fas fa-robot"></i>
            No catalog flows yet. Run:
            <code>php spark automations:seed-catalog --test</code>
        </div>
    <?php else: ?>
        <?php
        $groups = ['trigger' => [], 'condition' => [], 'action' => [], 'other' => []];
        foreach ($flows as $f) {
            $name = (string) ($f['name'] ?? '');
            if (str_contains($name, 'Trigger:')) {
                $groups['trigger'][] = $f;
            } elseif (str_contains($name, 'Condition:')) {
                $groups['condition'][] = $f;
            } elseif (str_contains($name, 'Action:')) {
                $groups['action'][] = $f;
            } else {
                $groups['other'][] = $f;
            }
        }
        $titles = [
            'trigger'   => 'Triggers',
            'condition' => 'Conditions',
            'action'    => 'Actions',
            'other'     => 'Other',
        ];
        ?>
        <?php foreach ($titles as $key => $title): ?>
            <?php if ($groups[$key] === []) {
                continue;
            } ?>
            <div class="card form-card mb-3">
                <div class="card-header bg-white fw-semibold"><?= esc($title) ?> (<?= count($groups[$key]) ?>)</div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm table-hover align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Flow</th>
                                    <th>Trigger</th>
                                    <th>Keyword / filter</th>
                                    <th>Active</th>
                                    <th>How to test</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($groups[$key] as $f): ?>
                                <?php
                                $cfg = $f['trigger_config'] ?? [];
                                if (is_string($cfg)) {
                                    $cfg = json_decode($cfg, true) ?: [];
                                }
                                $keyword = (string) ($cfg['keyword'] ?? '');
                                $active  = ! empty($f['is_active']);
                                $testHint = $keyword !== ''
                                    ? 'Send WhatsApp text containing <code>' . esc($keyword) . '</code> on the active Meta number'
                                    : 'Fire via system: <code>processTrigger(' . esc((string) $f['trigger_type']) . ')</code>';
                                ?>
                                <tr>
                                    <td>#<?= (int) $f['id'] ?></td>
                                    <td class="fw-semibold"><?= esc($f['name']) ?></td>
                                    <td><code><?= esc((string) $f['trigger_type']) ?></code></td>
                                    <td class="small"><?= $keyword !== '' ? '<code>' . esc($keyword) . '</code>' : '—' ?></td>
                                    <td>
                                        <?php if ($active): ?>
                                            <span class="badge text-bg-success">Active</span>
                                        <?php else: ?>
                                            <span class="badge text-bg-secondary">Off</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="small"><?= $testHint ?></td>
                                    <td class="text-end">
                                        <a class="btn btn-sm btn-outline-secondary" href="<?= site_url('automations/' . (int) $f['id'] . '/builder') ?>">Open</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
<?= $this->endSection() ?>
