<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>
<?php
$fmt = static function (?int $sec): string {
    if ($sec === null) {
        return '-';
    }
    if ($sec < 60) {
        return $sec . 's';
    }
    if ($sec < 3600) {
        return floor($sec / 60) . 'm ' . ($sec % 60) . 's';
    }

    return floor($sec / 3600) . 'h ' . floor(($sec % 3600) / 60) . 'm';
};
?>
<div class="page-list">

<div class="card mb-3">
    <div class="card-body py-3">
        <form method="get" action="<?= site_url('reports/agents') ?>" class="row g-2 align-items-end">
            <div class="col-6 col-md-3"><label class="form-label small mb-1">From</label><input class="form-control form-control-sm" type="date" name="from" value="<?= esc((string) $from) ?>"></div>
            <div class="col-6 col-md-3"><label class="form-label small mb-1">To</label><input class="form-control form-control-sm" type="date" name="to" value="<?= esc((string) $to) ?>"></div>
            <div class="col-12 col-md-3 d-flex gap-2">
                <button class="btn btn-wa btn-sm" type="submit">Apply</button>
                <a class="btn btn-outline-secondary btn-sm" href="<?= site_url('reports/agents') ?>">This month</a>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header"><h2 class="card-title mb-0">Inbox performance by agent</h2></div>
    <div class="card-body py-3">
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>Agent</th>
                        <th class="text-end">Conversations</th>
                        <th class="text-end">Answered</th>
                        <th class="text-end">Avg first response</th>
                        <th class="text-end">Replies sent</th>
                        <th class="text-end">Open now</th>
                        <th class="text-end">CSAT (1-5)</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($rows)): ?>
                    <tr><td colspan="7" class="text-center text-muted py-4">No inbox activity in this period.</td></tr>
                <?php endif; ?>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><?= esc($r['name']) ?></td>
                        <td class="text-end"><?= number_format($r['conversations']) ?></td>
                        <td class="text-end"><?= number_format($r['answered']) ?></td>
                        <td class="text-end"><?= esc($fmt($r['avg_first_response_sec'])) ?></td>
                        <td class="text-end"><?= number_format($r['replies']) ?></td>
                        <td class="text-end"><?= number_format($r['open_now']) ?></td>
                        <td class="text-end"><?= $r['csat_avg'] === null ? '-' : esc(number_format($r['csat_avg'], 1)) . ' <span class="text-muted small">(' . (int) $r['csat_count'] . ')</span>' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="small text-muted mt-2">
            Conversations are credited to the agent they are assigned to now. Conversations with an inbound message in the period are counted.
            First response is the time from the first inbound message to the first non-broadcast reply, and automated replies count as replies.
            Open now shows current open and pending conversations regardless of the period.
            CSAT is the average rating customers sent in the period, with the number of ratings in brackets; it is credited to the agent the chat belonged to when the question was sent.
        </div>
    </div>
</div>

</div>
<?= $this->endSection() ?>
