<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>
<div class="page-toolbar">
    <div class="toolbar-actions">
        <a href="<?= site_url('contacts') ?>" class="btn btn-outline-secondary btn-sm"><i class="fas fa-arrow-left me-1"></i> Back</a>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h3 class="card-title">Import contacts</h3>
    </div>
    <form action="<?= site_url('contacts/import') ?>" method="post" enctype="multipart/form-data" id="importContactsForm">
        <?= csrf_field() ?>
        <div class="card-body">
            <div class="alert border mb-3" style="background:var(--wa-mist);border-color:var(--border)!important;color:var(--wa-ink)">
                Upload a CSV with headers: <code>name,mobile,email,country,notes,tags</code>.
                Mobile is required. Tags may be comma-separated names.
            </div>
            <div class="mb-3">
                <label class="form-label">CSV file</label>
                <input type="file" name="file" id="importFile" class="form-control" accept=".csv,text/csv" required>
                <div class="form-text" id="importFileName">Max size: 5 MB</div>
            </div>
            <div class="form-check mb-3">
                <input class="form-check-input" type="checkbox" name="skip_duplicates" value="1" id="skipDup" checked>
                <label class="form-check-label" for="skipDup">Skip duplicate mobiles</label>
            </div>
            <a href="<?= site_url('contacts/export?sample=1') ?>" class="btn btn-link btn-sm px-0"><i class="fas fa-download me-1"></i> Download sample CSV</a>
        </div>
        <div class="card-footer d-flex gap-2">
            <button type="submit" class="btn btn-wa" id="btnImportSubmit"><i class="fas fa-file-import me-1"></i> Import</button>
            <a href="<?= site_url('contacts') ?>" class="btn btn-outline-secondary">Cancel</a>
        </div>
    </form>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= base_url('assets/js/contacts.js') ?>"></script>
<?= $this->endSection() ?>
