<?php
/**
 * DataTables for platform pages (the platform layout does not load jQuery). Include once, near the table:
 *   <?= view('partials/platform_dt_assets') ?>
 * Then build tables with AppDT.table('#id', {...}) (see public/assets/js/app-datatable.js).
 */
?>
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/jquery.dataTables.min.css">
<link rel="stylesheet" href="<?= asset_url('assets/css/platform-datatables.css') ?>">
<script>
if (!window.jQuery) {
    document.write('<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"><\/script>');
}
</script>
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script src="<?= asset_url('assets/js/app-datatable.js') ?>"></script>
