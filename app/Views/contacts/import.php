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
                Upload a CSV or Excel (XLSX) file, choose an optional customer group, then map columns to CRM fields.
                Unknown columns can be saved as new custom fields. Mobile is required.
            </div>
            <div class="import-mode mb-3" role="tablist" aria-label="Import type">
                <button type="button" class="import-mode-btn active" data-mode="single" role="tab" aria-selected="true">
                    <i class="fas fa-file-csv"></i>
                    <span><strong>One file</strong><small>Map columns, optional group</small></span>
                </button>
                <button type="button" class="import-mode-btn" data-mode="batch" role="tab" aria-selected="false">
                    <i class="fas fa-file-zipper"></i>
                    <span><strong>ZIP or many files</strong><small>File name becomes the group</small></span>
                </button>
            </div>
            <div class="mb-3" id="singleFileWrap">
                <label class="form-label" for="importFile">CSV or XLSX file</label>
                <input type="file" name="file" id="importFile" class="form-control" accept=".csv,.xlsx,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required>
                <div class="form-text" id="importFileName">Max size: 5 MB · up to 20,000 rows · .csv or .xlsx</div>
            </div>
            <div class="mb-3 d-none" id="batchFileWrap">
                <label class="batch-drop" for="batchFiles" id="batchDrop">
                    <i class="fas fa-file-zipper"></i>
                    <span class="batch-drop-title" id="batchDropTitle">Choose a ZIP, or select several CSV / XLSX files</span>
                    <span class="batch-drop-sub">Every file becomes a customer group named after the file. Up to 50 files, 5 MB each.</span>
                </label>
                <input type="file" id="batchFiles" class="d-none" accept=".zip,.csv,.xlsx" multiple>
                <div class="form-check mt-3">
                    <input class="form-check-input" type="checkbox" id="batchExtraFields" checked>
                    <label class="form-check-label" for="batchExtraFields">Save extra columns (e.g. state, order) as custom fields</label>
                </div>
            </div>
            <div class="mb-3">
                <label class="form-label fw-semibold" for="importDefaultCountry">Default country code</label>
                <select name="default_country_code" id="importDefaultCountry" class="form-select">
                    <option value="">Auto-detect from the number (numbers include the country code)</option>
                    <?php foreach (($countries ?? countries_list()) as $c): ?>
                        <option value="<?= esc($c['dial_code']) ?>" <?= ((string)$c['dial_code'] === '91') ? 'selected' : '' ?>>
                            +<?= esc($c['dial_code']) ?> — <?= esc($c['name']) ?> (<?= (int)$c['min_digits'] ?> digits required)
                        </option>
                    <?php endforeach; ?>
                </select>
                <div class="form-text">Used for numbers without a country code (e.g. 10-digit Indian numbers). Choose Auto-detect when your numbers already start with the country code, like 9715… or 9198…. A number that cannot be matched is still imported, without a mobile, and the reason is listed.</div>
            </div>
            <div class="mb-3" id="singleGroupWrap">
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
            <div class="border rounded p-2 mb-3">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="wa_opt_in" value="1" id="importOptIn" checked>
                    <label class="form-check-label" for="importOptIn">Everyone in this file agreed to receive WhatsApp messages from us</label>
                </div>
                <select name="wa_opt_in_source" id="importOptInSource" class="form-select form-select-sm mt-2">
                    <option value="">How was consent collected?</option>
                    <?php foreach (\App\Libraries\WhatsAppConsentService::OPT_IN_SOURCES as $key => $label): ?>
                        <option value="<?= esc($key) ?>"<?= $key === 'import' ? ' selected' : '' ?>><?= esc($label) ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="form-text">New contacts from Excel or CSV get WhatsApp opt-in automatically (source: imported list). Contacts already in the system are not changed. Untick this for bought, scraped or old lists without consent.</div>
            </div>
            <div class="d-flex flex-wrap gap-3">
                <a href="<?= site_url('contacts/export?sample=1') ?>" class="btn btn-link btn-sm px-0"><i class="fas fa-download me-1"></i> Sample CSV</a>
                <a href="<?= site_url('contacts/export?sample=1&format=xlsx') ?>" class="btn btn-link btn-sm px-0"><i class="fas fa-file-excel me-1"></i> Sample XLSX</a>
            </div>
        </div>
        <div class="card-footer d-flex gap-2">
            <button type="submit" class="btn btn-wa" id="btnImportContinue"><i class="fas fa-arrow-right me-1"></i> Continue to mapping</button>
            <a href="<?= site_url('contacts') ?>" class="btn btn-outline-secondary">Cancel</a>
        </div>
    </form>
</div>

<div class="card d-none" id="importStepBatch">
    <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
        <h3 class="card-title mb-0">Review files and groups</h3>
        <div class="d-flex flex-wrap gap-2" id="batchSummary"></div>
    </div>
    <div class="card-body">
        <div class="alert alert-light border mb-3">
            Each file is imported into the customer group shown below. Change a group name if needed, or untick a file to leave it out.
            A group that does not exist yet is created.
        </div>
        <div class="alert alert-warning border d-none mb-3" id="batchIgnored"></div>
        <div class="table-responsive">
            <table class="table align-middle batch-table" id="batchTable">
                <thead>
                    <tr>
                        <th style="width:36px"><input type="checkbox" class="form-check-input" id="batchCheckAll" checked aria-label="Select all files"></th>
                        <th>File</th>
                        <th style="min-width:220px">Customer group</th>
                        <th class="text-end">Rows</th>
                        <th>Columns</th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
    </div>
    <div class="card-footer d-flex gap-2">
        <button type="button" class="btn btn-wa" id="btnBatchCommit"><i class="fas fa-file-import me-1"></i> <span id="btnBatchCommitLabel">Import</span></button>
        <button type="button" class="btn btn-outline-secondary" id="btnBatchBack">Back</button>
    </div>
</div>

<div class="card d-none" id="importStepBatchDone">
    <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
        <h3 class="card-title mb-0">Import finished</h3>
        <div class="d-flex flex-wrap gap-2" id="batchDoneSummary"></div>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table align-middle batch-table" id="batchResultTable">
                <thead>
                    <tr><th>File</th><th>Customer group</th><th class="text-end">New</th><th class="text-end">Updated</th><th class="text-end">Skipped</th><th>Notes</th></tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
    </div>
    <div class="card-footer d-flex gap-2">
        <a href="<?= site_url('customer-groups') ?>" class="btn btn-wa"><i class="fas fa-layer-group me-1"></i> View customer groups</a>
        <a href="<?= site_url('contacts') ?>" class="btn btn-outline-secondary">Go to contacts</a>
    </div>
</div>

<div class="card d-none" id="importStepMap">
    <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
        <h3 class="card-title mb-0">Map columns to CRM</h3>
        <span class="small text-muted" id="importMapMeta"></span>
    </div>
    <div class="card-body">
        <div class="alert alert-warning border d-none mb-3" id="importMapWarning"></div>
        <div class="alert alert-light border mb-3" id="importMapHint">
            Match each column to a CRM field. Choose <strong>Create new custom field</strong> for unknown columns — values are saved in contact custom fields.
        </div>
        <div class="table-responsive mb-3">
            <table class="table table-sm align-middle" id="importMappingTable">
                <thead>
                    <tr>
                        <th>File column</th>
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
<script src="<?= asset_url('assets/js/contacts.js') ?>"></script>
<script>
$(function () {
    if (window.Contacts && typeof Contacts.initImport === 'function') {
        Contacts.initImport();
    }
});
</script>
<?= $this->endSection() ?>
