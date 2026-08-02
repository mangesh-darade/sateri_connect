<?= $this->extend('layouts/main') ?>

<?= $this->section('header_actions') ?>
<a href="<?= site_url('contacts') ?>" class="btn btn-outline-secondary btn-sm"><i class="fas fa-arrow-left me-1"></i> Back</a>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<div class="page-list">
<div class="card" id="importStepUpload">
    <form id="importContactsForm" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <div class="card-body">
            <div class="alert border mb-3" style="background:var(--wa-mist);border-color:var(--border)!important;color:var(--wa-ink)">
                Upload a CSV, choose an optional customer group, then map columns to CRM fields.
                Unknown columns can be saved as new custom fields. Mobile is required.
            </div>
            <div class="mb-3">
                <label class="form-label" for="importFile">CSV file</label>
                <input type="file" name="file" id="importFile" class="form-control" accept=".csv,text/csv" required>
                <div class="form-text" id="importFileName">Max size: 5 MB · up to 5,000 rows</div>
            </div>
            <div class="mb-3">
                <label class="form-label" for="importGroupId">Customer group (optional)</label>
                <select name="group_id" id="importGroupId" class="form-select">
                    <option value="">— No group —</option>
                    <?php foreach (($groups ?? []) as $g): ?>
                        <option value="<?= (int) ($g['id'] ?? 0) ?>"><?= esc($g['name'] ?? '') ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="form-text">All imported contacts will be added to this group.</div>
            </div>
            <div class="form-check mb-3">
                <input class="form-check-input" type="checkbox" name="skip_duplicates" value="1" id="skipDup" checked>
                <label class="form-check-label" for="skipDup">Skip duplicate mobiles</label>
            </div>
            <a href="<?= site_url('contacts/export?sample=1') ?>" class="btn btn-link btn-sm px-0"><i class="fas fa-download me-1"></i> Download sample CSV</a>
        </div>
        <div class="card-footer d-flex gap-2">
            <button type="submit" class="btn btn-wa" id="btnImportContinue"><i class="fas fa-arrow-right me-1"></i> Continue to mapping</button>
            <a href="<?= site_url('contacts') ?>" class="btn btn-outline-secondary">Cancel</a>
        </div>
    </form>
</div>

<div class="card d-none" id="importStepMap">
    <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
        <h3 class="card-title mb-0">Map CSV fields to CRM</h3>
        <span class="small text-muted" id="importMapMeta"></span>
    </div>
    <div class="card-body">
        <div class="alert alert-light border mb-3" id="importMapHint">
            Match each CSV column to a CRM field. Choose <strong>Create new custom field</strong> for unknown columns — values are saved in contact custom fields.
        </div>
        <div class="table-responsive mb-3">
            <table class="table table-sm align-middle" id="importMappingTable">
                <thead>
                    <tr>
                        <th>CSV column</th>
                        <th>Sample</th>
                        <th>CRM field</th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
        <div class="small text-muted" id="importSampleNote"></div>
    </div>
    <div class="card-footer d-flex gap-2">
        <button type="button" class="btn btn-wa" id="btnImportCommit"><i class="fas fa-file-import me-1"></i> Import contacts</button>
        <button type="button" class="btn btn-outline-secondary" id="btnImportBack">Back</button>
    </div>
</div>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= base_url('assets/js/contacts.js') ?>"></script>
<script>
$(function () {
    if (window.Contacts && typeof Contacts.initImport === 'function') {
        Contacts.initImport();
    }
});
</script>
<?= $this->endSection() ?>
