<?= $this->extend('layouts/main') ?>

<?= $this->section('header_actions') ?>
<?= view('reports/_export_menu', ['exportUrl' => site_url('reports/cart-reminders/export'), 'exportQuery' => http_build_query(['from' => (string) $from, 'to' => (string) $to, 'status' => (string) ($status ?? '')])]) ?>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php
$sum    = $totals;
$plats  = \App\Libraries\CartRecoveryService::PLATFORMS;
$labels = ['open' => 'Waiting', 'reminded' => 'Reminded', 'recovered' => 'Recovered', 'skipped' => 'Skipped'];
?>
<div class="page-list">

<div class="card mb-3">
    <div class="card-body py-3">
        <form method="get" action="<?= site_url('reports/cart-reminders') ?>" class="row g-2 align-items-end">
            <div class="col-6 col-md-3"><label class="form-label small mb-1" for="crrFrom">From</label><input class="form-control form-control-sm" id="crrFrom" type="date" name="from" value="<?= esc((string) $from) ?>"></div>
            <div class="col-6 col-md-3"><label class="form-label small mb-1" for="crrTo">To</label><input class="form-control form-control-sm" id="crrTo" type="date" name="to" value="<?= esc((string) $to) ?>"></div>
            <div class="col-6 col-md-3">
                <label class="form-label small mb-1" for="crrStatus">Status</label>
                <select class="form-select form-select-sm" id="crrStatus" name="status">
                    <option value="">All</option>
                    <?php foreach ($labels as $k => $label): ?>
                        <option value="<?= $k ?>" <?= ($status ?? '') === $k ? 'selected' : '' ?>><?= $label ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-3 d-flex gap-2">
                <button class="btn btn-wa btn-sm" type="submit">Apply</button>
                <a class="btn btn-outline-secondary btn-sm" href="<?= site_url('reports/cart-reminders') ?>">This month</a>
            </div>
        </form>
    </div>
</div>

<div class="row g-2 mb-3 text-center">
    <?php foreach ([
        'Carts'            => number_format((int) $sum['carts']),
        'Reminded'         => number_format((int) $sum['reminded']),
        'Delivered'        => number_format((int) $sum['delivered']),
        'Failed'           => number_format((int) $sum['failed']),
        'Recovered'        => number_format((int) $sum['recovered']),
        'Recovery rate'    => $sum['recovery_rate'] . '%',
        'Recovered value'  => number_format((float) $sum['recovered_amount'], 2),
    ] as $label => $value): ?>
        <div class="col-6 col-md"><div class="card"><div class="card-body p-2"><div class="small text-muted"><?= esc($label) ?></div><strong><?= esc((string) $value) ?></strong></div></div></div>
    <?php endforeach; ?>
</div>

<div class="card">
    <div class="card-header"><h2 class="card-title mb-0">Abandoned carts and reminders</h2></div>
    <div class="card-body py-3">
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>Store</th>
                        <th>Customer</th>
                        <th>Items</th>
                        <th class="text-end">Total</th>
                        <th>Status</th>
                        <th class="text-end">Reminders</th>
                        <th>Last reminded</th>
                        <th>Last activity</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($rows)): ?>
                    <tr><td colspan="8" class="text-center text-muted py-4">No carts in this period.</td></tr>
                <?php endif; ?>
                <?php foreach ($rows as $r): ?>
                    <?php
                    $badge = ['open' => 'warning', 'reminded' => 'info', 'recovered' => 'success', 'skipped' => 'secondary'][$r['status']] ?? 'secondary';
                    $dl    = ['sent' => ['success', 'Delivered to WhatsApp'], 'failed' => ['danger', 'Delivery failed'], 'cancelled' => ['secondary', 'Cancelled'], 'pending' => ['info', 'Sending'], 'processing' => ['info', 'Sending']][(string) $r['delivery_status']] ?? null;
                    ?>
                    <tr>
                        <td><?= esc($plats[$r['platform']] ?? $r['platform']) ?></td>
                        <td><?= esc($r['customer_name'] !== '' ? $r['customer_name'] : '-') ?><div class="small text-muted"><?= esc($r['phone'] !== '' ? $r['phone'] : 'No phone') ?></div></td>
                        <td class="small"><?= esc($r['items_text']) ?></td>
                        <td class="text-end"><?= esc($r['total']) ?></td>
                        <td>
                            <span class="badge bg-<?= $badge ?>"><?= esc(ucfirst((string) $r['status'])) ?></span>
                            <?php if ($r['last_error'] !== ''): ?><div class="small text-muted"><?= esc($r['last_error']) ?></div><?php endif; ?>
                            <?php if ($dl !== null): ?>
                                <div class="small text-<?= $dl[0] ?>"><?= esc($dl[1]) ?><?= ($r['delivery_status'] === 'failed' && (string) $r['delivery_error'] !== '') ? ': ' . esc(mb_substr((string) $r['delivery_error'], 0, 160)) : '' ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="text-end"><?= (int) $r['reminders_sent'] ?></td>
                        <td class="small"><?= esc(format_app_datetime($r['last_reminded_at'], 'd M Y, g:i A', '-')) ?></td>
                        <td class="small"><?= esc(format_app_datetime($r['last_activity_at'], 'd M Y, g:i A', '-')) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if (count($rows) >= 2000): ?><div class="small text-muted mt-2">Showing the latest 2,000 carts. Narrow the dates to see others.</div><?php endif; ?>
    </div>
</div>

</div>
<?= $this->endSection() ?>
