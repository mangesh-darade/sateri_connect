<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>
<div class="page-list">

<div class="card mb-3">
    <div class="card-body py-3">
        <form method="get" action="<?= site_url('reports/ctwa') ?>" class="row g-2 align-items-end">
            <div class="col-6 col-md-3"><label class="form-label small mb-1">From</label><input class="form-control form-control-sm" type="date" name="from" value="<?= esc((string) $from) ?>"></div>
            <div class="col-6 col-md-3"><label class="form-label small mb-1">To</label><input class="form-control form-control-sm" type="date" name="to" value="<?= esc((string) $to) ?>"></div>
            <div class="col-12 col-md-3 d-flex gap-2">
                <button class="btn btn-wa btn-sm" type="submit">Apply</button>
                <a class="btn btn-outline-secondary btn-sm" href="<?= site_url('reports/ctwa') ?>">This month</a>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header"><h2 class="card-title mb-0">Conversations started from ads</h2></div>
    <div class="card-body py-3">
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>Ad</th>
                        <th>Source ID</th>
                        <th>Type</th>
                        <th class="text-end">Conversations</th>
                        <th class="text-end">Answered</th>
                        <th class="text-end">Spend</th>
                        <th class="text-end">Cost per chat</th>
                        <th>First</th>
                        <th>Last</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($rows)): ?>
                    <tr><td colspan="9" class="text-center text-muted py-4">No conversations from Click-to-WhatsApp ads in this period.</td></tr>
                <?php endif; ?>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td>
                            <?php if ($r['source_url'] !== ''): ?>
                                <a href="<?= esc($r['source_url'], 'attr') ?>" target="_blank" rel="noopener noreferrer"><?= esc($r['headline'] !== '' ? $r['headline'] : 'View ad') ?></a>
                            <?php else: ?>
                                <?= esc($r['headline'] !== '' ? $r['headline'] : '-') ?>
                            <?php endif; ?>
                            <?php if ($r['campaign'] !== ''): ?><div class="small text-muted">Campaign: <?= esc($r['campaign']) ?></div><?php endif; ?>
                        </td>
                        <td class="font-monospace small"><?= esc($r['ad_id']) ?></td>
                        <td><?= esc($r['source_type'] !== '' ? $r['source_type'] : '-') ?></td>
                        <td class="text-end"><?= number_format($r['conversations']) ?></td>
                        <td class="text-end"><?= number_format($r['answered']) ?></td>
                        <td class="text-end"><?= $r['spend_minor'] !== null ? number_format($r['spend_minor'] / 100, 2) : '-' ?></td>
                        <td class="text-end"><?= $r['cost_per_conversation_minor'] !== null ? number_format($r['cost_per_conversation_minor'] / 100, 2) : '-' ?></td>
                        <td class="small"><?= esc((string) $r['first_at']) ?></td>
                        <td class="small"><?= esc((string) $r['last_at']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="small text-muted mt-2">
            Counts new conversations whose first customer message came from a Click-to-WhatsApp ad. A returning customer who already had a conversation is credited to the first ad only.
            Answered means we sent at least one reply. Only ads clicked after this feature went live are listed. Spend and cost per chat are shown for ads created in Ads (live mode); other ads show a dash.
        </div>
    </div>
</div>

</div>
<?= $this->endSection() ?>
