<?php
/**
 * One channel summary panel (WhatsApp / Email).
 *
 * @var string $key       css modifier: whatsapp|email
 * @var string $title
 * @var string $icon      Font Awesome classes
 * @var string $url       detailed report link
 * @var list<array{label:string,value:int,hint?:string,alert?:bool}> $metrics
 * @var int    $today     sent today
 * @var int    $month     sent this month
 */
?>
<div class="dash-panel channel-panel channel-<?= esc($key, 'attr') ?>">
    <div class="panel-head">
        <h3 class="channel-title">
            <span class="channel-chip"><i class="<?= esc($icon, 'attr') ?>"></i></span>
            <?= esc($title) ?>
        </h3>
        <div class="channel-head-meta">
            <span>Today <strong><?= esc(number_format($today)) ?></strong></span>
            <span>Month <strong><?= esc(number_format($month)) ?></strong></span>
            <a href="<?= esc($url, 'attr') ?>" class="btn btn-xs btn-outline-secondary">Report</a>
        </div>
    </div>
    <div class="panel-body">
        <div class="channel-metrics">
            <?php foreach ($metrics as $m): ?>
                <div class="channel-metric<?= ! empty($m['alert']) ? ' is-alert' : '' ?>">
                    <span class="channel-metric-label"><?= esc($m['label']) ?></span>
                    <span class="channel-metric-value"><?= esc(number_format((int) $m['value'])) ?></span>
                    <span class="channel-metric-hint"><?= esc($m['hint'] ?? "\u{00A0}") ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
