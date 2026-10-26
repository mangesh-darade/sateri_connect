<?php
/**
 * Export dropdown shared by the report pages.
 *
 * @var string $exportUrl   export route, for example site_url('reports/deposits/export')
 * @var string $exportQuery current filters as a query string (no leading ?)
 */
$exportQuery = (string) ($exportQuery ?? '');
$link = static fn (string $fmt): string => $exportUrl . '?' . ($exportQuery !== '' ? $exportQuery . '&' : '') . 'format=' . $fmt;
?>
<?php if (function_exists('can') && can('reports.export')): ?>
<div class="dropdown d-inline-block">
    <button class="btn btn-wa btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false"><i class="fas fa-download me-1"></i> Export</button>
    <ul class="dropdown-menu dropdown-menu-end">
        <li><a class="dropdown-item" href="<?= esc($link('excel'), 'attr') ?>"><i class="fas fa-file-excel text-success me-2"></i>Excel (.xls)</a></li>
        <li><a class="dropdown-item" href="<?= esc($link('pdf'), 'attr') ?>" target="_blank" rel="noopener"><i class="fas fa-file-pdf text-danger me-2"></i>PDF (print / save)</a></li>
        <li><a class="dropdown-item" href="<?= esc($link('csv'), 'attr') ?>"><i class="fas fa-file-csv text-secondary me-2"></i>CSV</a></li>
    </ul>
</div>
<?php endif; ?>
