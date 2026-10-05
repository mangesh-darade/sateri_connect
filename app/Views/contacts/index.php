<?= $this->extend('layouts/main') ?>

<?= $this->section('header_actions') ?>
<div class="d-flex align-items-center gap-2 flex-wrap">
    <?php if (function_exists('can') && can('contacts.create')): ?>
        <a href="<?= site_url('contacts/create') ?>" class="btn btn-wa btn-sm fw-semibold shadow-sm">
            <i class="fas fa-plus me-1"></i> Add Contact
        </a>
    <?php endif; ?>

    <?php if (function_exists('can') && can('contacts.import')): ?>
        <a href="<?= site_url('contacts/import') ?>" class="btn btn-outline-secondary btn-sm" title="Import contacts from CSV/Excel">
            <i class="fas fa-file-import me-1"></i> Import
        </a>
    <?php endif; ?>

    <?php if (function_exists('can') && can('contacts.export')): ?>
        <a href="<?= site_url('contacts/export') ?>" id="btnExportContacts" class="btn btn-outline-secondary btn-sm" title="Export filtered contacts to CSV">
            <i class="fas fa-file-export me-1"></i> Export
        </a>
    <?php endif; ?>

    <!-- More Actions & Sync Dropdown -->
    <div class="dropdown d-inline-block">
        <button class="btn btn-outline-secondary btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false" title="More contact actions">
            <i class="fas fa-ellipsis-v me-1"></i> More
        </button>
        <ul class="dropdown-menu dropdown-menu-end shadow-sm border py-1" style="min-width: 220px;">
            <li>
                <button type="button" id="btnDetectDuplicates" class="dropdown-item small py-2 d-flex align-items-center gap-2">
                    <i class="fas fa-clone text-primary"></i>
                    <span>Find Duplicate Mobiles</span>
                </button>
            </li>
            <li><hr class="dropdown-divider my-1"></li>
            <?php if (function_exists('can') && can('contacts.import')): ?>
                <li>
                    <form action="<?= site_url('contacts/sync-cheerio') ?>" method="post" id="formSyncCheerioContacts" class="m-0">
                        <?= csrf_field() ?>
                        <button type="submit" class="dropdown-item small py-2 d-flex align-items-center gap-2" id="btnSyncCheerioContacts">
                            <i class="fas fa-cloud-download-alt text-success"></i>
                            <span><?= esc(function_exists('whatsapp_sync_label') ? whatsapp_sync_label() : 'Sync WhatsApp Contacts') ?></span>
                        </button>
                    </form>
                </li>
                <li>
                    <form action="<?= site_url('contacts/sync-elintom') ?>" method="post" id="formSyncElintOmContacts" class="m-0">
                        <?= csrf_field() ?>
                        <button type="submit" class="dropdown-item small py-2 d-flex align-items-center gap-2" id="btnSyncElintOmContacts">
                            <i class="fas fa-cash-register text-info"></i>
                            <span>Sync ElintOm Customers</span>
                        </button>
                    </form>
                </li>
            <?php endif; ?>
        </ul>
    </div>
</div>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<style>
/* Compact Header & Page Intro */
.content-header {
    padding-top: 0.35rem !important;
    padding-bottom: 0.25rem !important;
}
.page-intro {
    padding: 0.35rem 0.75rem !important;
    margin-bottom: 0.35rem !important;
}
.page-intro-crumb {
    margin-bottom: 0.1rem !important;
    font-size: 0.72rem !important;
}
.page-header-row {
    margin-bottom: 0 !important;
}
.page-header-row h1 {
    font-size: 1.25rem !important;
    line-height: 1.2 !important;
}
.content {
    padding-top: 0.2rem !important;
}

/* Contacts KPI Cards - Compact */
.contacts-kpi-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 0.6rem;
    margin-bottom: 0.75rem;
}
@media (max-width: 991px) {
    .contacts-kpi-grid { grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 575px) {
    .contacts-kpi-grid { grid-template-columns: 1fr; }
}
.contacts-kpi-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    padding: 0.45rem 0.75rem;
    box-shadow: 0 1px 2px rgba(0, 0, 0, 0.02);
    display: flex;
    align-items: center;
    justify-content: space-between;
    transition: transform 0.15s ease, box-shadow 0.15s ease;
}
.contacts-kpi-card:hover {
    transform: translateY(-1px);
    box-shadow: 0 3px 8px rgba(0, 0, 0, 0.04);
}
.contacts-kpi-label {
    font-size: 0.68rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: #64748b;
    margin-bottom: 0.1rem;
}
.contacts-kpi-val {
    font-size: 1.2rem;
    font-weight: 700;
    color: #0f172a;
    line-height: 1.1;
    margin-bottom: 0;
}
.contacts-kpi-sub {
    font-size: 0.68rem;
    color: #94a3b8;
    line-height: 1.1;
    margin-top: 0.1rem;
}
.contacts-kpi-icon {
    width: 32px;
    height: 32px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.95rem;
    flex-shrink: 0;
}
.contacts-kpi-icon.kpi-purple { background: #f5f3ff; color: #7c3aed; }
.contacts-kpi-icon.kpi-green  { background: #ecfdf5; color: #10b981; }
.contacts-kpi-icon.kpi-blue   { background: #eff6ff; color: #3b82f6; }
.contacts-kpi-icon.kpi-amber  { background: #fffbeb; color: #f59e0b; }

/* Main Panel Card */
.contacts-main-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.03);
    overflow: hidden;
}

/* Filter Bar */
.contacts-filter-bar {
    background: #f8fafc;
    border-bottom: 1px solid #e2e8f0;
    padding: 0.55rem 0.85rem;
}
.filter-pill-select {
    font-size: 0.8125rem;
    padding: 0.35rem 0.65rem;
    border-radius: 6px;
    border: 1px solid #cbd5e1;
    background-color: #ffffff;
    color: #334155;
    transition: border-color 0.15s ease, box-shadow 0.15s ease;
}
.filter-pill-select:focus {
    border-color: #25d366;
    box-shadow: 0 0 0 2px rgba(37, 211, 102, 0.15);
    outline: none;
}

/* Bulk Actions Toolbar */
.contacts-bulk-bar {
    background: #f0fdf4;
    border: 1px solid #bbf7d0;
    border-radius: 8px;
    padding: 0.55rem 0.9rem;
    margin: 0.85rem 1rem 0;
    display: none;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 0.5rem;
    animation: fadeInDown 0.2s ease-in-out;
}
@keyframes fadeInDown {
    from { opacity: 0; transform: translateY(-6px); }
    to { opacity: 1; transform: translateY(0); }
}

/* Table Enhancements */
#contactsTable thead th {
    background: #f8fafc;
    color: #475569;
    font-weight: 600;
    font-size: 0.78rem;
    text-transform: uppercase;
    letter-spacing: 0.03em;
    border-bottom: 2px solid #e2e8f0;
    padding-top: 0.75rem;
    padding-bottom: 0.75rem;
    vertical-align: middle;
}
#contactsTable tbody td {
    font-size: 0.85rem;
    vertical-align: middle;
    padding-top: 0.65rem;
    padding-bottom: 0.65rem;
}
.contact-avatar-circle {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    color: #ffffff;
    font-size: 0.8125rem;
    font-weight: 700;
    flex-shrink: 0;
    text-shadow: 0 1px 1px rgba(0,0,0,0.15);
}
.contact-name-cell {
    min-width: 0;
    line-height: 1.25;
}
.contact-name-title {
    font-weight: 600;
    color: #1e293b;
    font-size: 0.875rem;
    display: block;
}
.contact-sub-id {
    font-size: 0.72rem;
    color: #94a3b8;
}
.contact-phone-badge {
    font-family: SFMono-Regular, Menlo, Monaco, Consolas, monospace;
    font-size: 0.825rem;
    color: #334155;
    background: #f1f5f9;
    padding: 3px 8px;
    border-radius: 6px;
    border: 1px solid #e2e8f0;
    white-space: nowrap;
    display: inline-flex;
    align-items: center;
}
.contact-tag-badge {
    font-size: 0.72rem;
    font-weight: 500;
    padding: 0.22rem 0.5rem;
    border-radius: 4px;
}
.btn-icon-action {
    width: 28px;
    height: 28px;
    padding: 0;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 6px;
    border: 1px solid #e2e8f0;
    color: #64748b;
    background: #ffffff;
    transition: all 0.15s ease;
    text-decoration: none;
}
.btn-icon-action:hover {
    color: #0f172a;
    border-color: #cbd5e1;
    background: #f8fafc;
    transform: translateY(-1px);
}
.btn-icon-action.text-danger:hover {
    color: #ef4444 !important;
    border-color: #fecaca;
    background: #fef2f2;
}
</style>

<div class="page-list page-contacts">
    <!-- Top KPI Cards -->
    <div class="contacts-kpi-grid">
        <div class="contacts-kpi-card">
            <div>
                <div class="contacts-kpi-label">Total Audience</div>
                <div class="contacts-kpi-val"><?= esc(number_format((int) ($stats['total'] ?? 0))) ?></div>
                <div class="contacts-kpi-sub">Registered contacts</div>
            </div>
            <div class="contacts-kpi-icon kpi-purple">
                <i class="fas fa-users"></i>
            </div>
        </div>

        <div class="contacts-kpi-card">
            <div>
                <div class="contacts-kpi-label">WhatsApp Opt-in</div>
                <div class="contacts-kpi-val text-success"><?= esc(number_format((int) ($stats['opted_in'] ?? 0))) ?></div>
                <div class="contacts-kpi-sub">Consented for messaging</div>
            </div>
            <div class="contacts-kpi-icon kpi-green">
                <i class="fab fa-whatsapp"></i>
            </div>
        </div>

        <div class="contacts-kpi-card">
            <div>
                <div class="contacts-kpi-label">Active Contacts</div>
                <div class="contacts-kpi-val text-primary"><?= esc(number_format((int) ($stats['active'] ?? 0))) ?></div>
                <div class="contacts-kpi-sub">Ready for campaigns</div>
            </div>
            <div class="contacts-kpi-icon kpi-blue">
                <i class="fas fa-user-check"></i>
            </div>
        </div>

        <div class="contacts-kpi-card">
            <div>
                <div class="contacts-kpi-label">Customer Groups</div>
                <div class="contacts-kpi-val text-warning"><?= esc(number_format((int) ($stats['groups'] ?? 0))) ?></div>
                <div class="contacts-kpi-sub">Audience segments</div>
            </div>
            <div class="contacts-kpi-icon kpi-amber">
                <i class="fas fa-layer-group"></i>
            </div>
        </div>
    </div>

    <!-- Main Table Card -->
    <div class="contacts-main-card">
        <!-- Filters Bar -->
        <div class="contacts-filter-bar">
            <div class="d-flex align-items-center gap-2 flex-wrap">
                <span class="text-muted small fw-semibold me-1 d-flex align-items-center gap-1">
                    <i class="fas fa-filter text-secondary"></i> Filters:
                </span>
                
                <select id="filterStatus" class="form-select form-select-sm filter-pill-select" style="max-width:130px">
                    <option value="">All statuses</option>
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                    <option value="blocked">Blocked</option>
                </select>

                <select id="filterTag" class="form-select form-select-sm filter-pill-select" style="max-width:150px">
                    <option value="">All groups</option>
                    <?php foreach (($tags ?? []) as $tag): ?>
                        <option value="<?= (int) $tag['id'] ?>"><?= esc($tag['name']) ?></option>
                    <?php endforeach; ?>
                </select>

                <select id="filterConsent" class="form-select form-select-sm filter-pill-select" style="max-width:160px" title="WhatsApp consent">
                    <option value="">All WhatsApp consent</option>
                    <option value="opted_in">Opted in</option>
                    <option value="no_opt_in">No opt-in</option>
                    <option value="opted_out">Opted out (STOP)</option>
                    <option value="suppressed">Paused (failed)</option>
                </select>

                <select id="filterAssigned" class="form-select form-select-sm filter-pill-select" style="max-width:130px">
                    <option value="">All agents</option>
                    <?php foreach (($agents ?? []) as $agent): ?>
                        <option value="<?= (int) $agent['id'] ?>"><?= esc($agent['name']) ?></option>
                    <?php endforeach; ?>
                </select>

                <select id="filterAttrKey" class="form-select form-select-sm filter-pill-select" style="max-width:145px" title="Filter by attribute">
                    <option value="">Any attribute</option>
                    <?php foreach (($attributeKeys ?? []) as $key): ?>
                        <option value="<?= esc($key) ?>"><?= esc(isset($attributeDefs[$key]) ? $attributeDefs[$key]['label'] : $key) ?></option>
                    <?php endforeach; ?>
                </select>

                <select id="filterAttrOp" class="form-select form-select-sm filter-pill-select d-none" style="max-width:125px">
                    <?php foreach (($attributeOps ?? []) as $op => $label): ?>
                        <option value="<?= esc($op) ?>"><?= esc($label) ?></option>
                    <?php endforeach; ?>
                </select>

                <input type="text" id="filterAttrValue" class="form-control form-control-sm filter-pill-select d-none" style="max-width:130px" placeholder="Value">

                <button type="button" id="btnFilterContacts" class="btn btn-sm btn-wa px-3 shadow-none">
                    <i class="fas fa-search me-1"></i> Filter
                </button>
                <button type="button" id="btnResetContactsFilter" class="btn btn-sm btn-light border px-2 text-secondary" title="Reset all filters">
                    <i class="fas fa-undo"></i>
                </button>
            </div>
        </div>

        <!-- Dynamic Bulk Actions Toolbar (Appears smoothly when checkboxes checked) -->
        <?php if (function_exists('can') && (can('contacts.edit') || can('contacts.delete'))): ?>
            <div id="contactsBulkBar" class="contacts-bulk-bar">
                <div class="d-flex align-items-center gap-2">
                    <span class="badge bg-success text-white small px-2 py-1">
                        <i class="fas fa-check-circle me-1"></i> <span id="selectedContactsCount">0</span> selected
                    </span>
                    <span class="text-muted small" style="font-size:0.8rem;">Apply bulk actions to selected contacts:</span>
                </div>
                <div class="d-flex align-items-center gap-1 flex-wrap">
                    <?php if (can('contacts.edit')): ?>
                        <button type="button" id="btnBulkTags" class="btn btn-sm btn-outline-primary bg-white py-1 px-2" title="Assign or remove groups for selected contacts">
                            <i class="fas fa-tags me-1 text-primary"></i> Bulk groups
                        </button>
                        <button type="button" id="btnBulkConsent" class="btn btn-sm btn-outline-success bg-white py-1 px-2" title="Update WhatsApp consent status">
                            <i class="fab fa-whatsapp me-1 text-success"></i> WhatsApp consent
                        </button>
                        <button type="button" id="btnBulkAttribute" class="btn btn-sm btn-outline-info bg-white py-1 px-2" title="Set custom attribute for selected contacts">
                            <i class="fas fa-pen-to-square me-1 text-info"></i> Set attribute
                        </button>
                    <?php endif; ?>
                    <?php if (can('contacts.delete')): ?>
                        <button type="button" id="btnBulkDelete" class="btn btn-sm btn-outline-danger bg-white py-1 px-2 ms-1" title="Delete selected contacts">
                            <i class="fas fa-trash me-1"></i> Bulk delete
                        </button>
                    <?php endif; ?>
                    <button type="button" id="btnDeselectAllContacts" class="btn btn-sm btn-link text-muted py-1 px-2 text-decoration-none" title="Clear selection">
                        Cancel
                    </button>
                </div>
            </div>
        <?php endif; ?>

        <!-- DataTable Container -->
        <?php $attrColumns = $attrColumns ?? []; ?>
        <div class="p-3">
            <div class="table-responsive">
                <table id="contactsTable" class="table table-sm table-hover align-middle w-100" data-attr-columns="<?= esc(json_encode($attrColumns), 'attr') ?>">
                    <thead>
                        <tr>
                            <th class="dt-check-col" scope="col" style="width: 38px;">
                                <input type="checkbox" class="form-check-input" id="checkAllContacts" title="Select all" aria-label="Select all">
                            </th>
                            <th>Contact</th>
                            <th>Mobile</th>
                            <th>Email</th>
                            <th>Groups</th>
                            <th>Status & Consent</th>
                            <th>Last Activity</th>
                            <?php foreach ($attrColumns as $col): ?>
                                <?php if ($col['defined']): ?>
                                    <th class="text-nowrap" title="Attribute: <?= esc($col['key'], 'attr') ?>"><?= esc($col['label']) ?></th>
                                <?php else: ?>
                                    <th class="text-nowrap" title="Saved on contacts"><?= esc($col['label']) ?> <i class="fas fa-circle-info text-muted small"></i></th>
                                <?php endif; ?>
                            <?php endforeach; ?>
                            <th class="text-end" style="width: 110px;">Actions</th>
                        </tr>
                    </thead>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Modal 1: Bulk Groups Modal -->
<div class="modal fade" id="bulkTagsModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content shadow border-0">
            <div class="modal-header py-2 px-3 border-bottom bg-light">
                <h5 class="modal-title h6 fw-bold mb-0"><i class="fas fa-tags text-primary me-2"></i>Bulk Groups</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-3">
                <div class="mb-3">
                    <label class="form-label small fw-semibold">Action</label>
                    <select id="bulkTagAction" class="form-select form-select-sm">
                        <option value="add">Add to groups</option>
                        <option value="remove">Remove from groups</option>
                    </select>
                </div>
                <div class="mb-0">
                    <label class="form-label small fw-semibold">Select Groups</label>
                    <select id="bulkTagIds" class="form-select form-select-sm" multiple size="6">
                        <?php foreach (($tags ?? []) as $tag): ?>
                            <option value="<?= (int) $tag['id'] ?>"><?= esc($tag['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text small">Hold Ctrl (Windows) to select multiple groups.</div>
                </div>
            </div>
            <div class="modal-footer py-2 px-3 bg-light">
                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-sm btn-wa px-3" id="btnApplyBulkTags">Apply Changes</button>
            </div>
        </div>
    </div>
</div>

<!-- Modal 2: Bulk Consent Modal -->
<div class="modal fade" id="bulkConsentModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content shadow border-0">
            <div class="modal-header py-2 px-3 border-bottom bg-light">
                <h5 class="modal-title h6 fw-bold mb-0"><i class="fab fa-whatsapp text-success me-2"></i>WhatsApp Consent</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-3">
                <div class="mb-3">
                    <label class="form-label small fw-semibold" for="bulkConsentAction">Action</label>
                    <select id="bulkConsentAction" class="form-select form-select-sm">
                        <option value="opt_in">Record opt-in (customers agreed)</option>
                        <option value="opt_out">Opt out (stop all WhatsApp marketing)</option>
                    </select>
                </div>
                <div class="mb-2" id="bulkConsentSourceWrap">
                    <label class="form-label small fw-semibold" for="bulkConsentSource">How did they give consent?</label>
                    <select id="bulkConsentSource" class="form-select form-select-sm">
                        <option value="">Choose…</option>
                        <?php foreach (\App\Libraries\WhatsAppConsentService::OPT_IN_SOURCES as $key => $label): ?>
                            <option value="<?= esc($key) ?>"><?= esc($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <p class="small text-muted mb-0">
                    Only record opt-in when you have proof the customer agreed to WhatsApp messages from your business.
                </p>
            </div>
            <div class="modal-footer py-2 px-3 bg-light">
                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-sm btn-wa px-3" id="btnApplyBulkConsent">Apply Consent</button>
            </div>
        </div>
    </div>
</div>

<!-- Modal 3: Bulk Attribute Modal -->
<div class="modal fade" id="bulkAttributeModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content shadow border-0">
            <div class="modal-header py-2 px-3 border-bottom bg-light">
                <h5 class="modal-title h6 fw-bold mb-0"><i class="fas fa-pen-to-square text-info me-2"></i>Set Attribute</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-3">
                <div class="mb-3">
                    <label class="form-label small fw-semibold" for="bulkAttrKey">Attribute</label>
                    <select id="bulkAttrKey" class="form-select form-select-sm">
                        <option value="">Choose…</option>
                        <?php foreach (($attributeKeys ?? []) as $key): ?>
                            <?php if (in_array($key, ['name', 'mobile', 'notes'], true)) { continue; } ?>
                            <?php $def = $attributeDefs[$key] ?? null; ?>
                            <option value="<?= esc($key) ?>" data-type="<?= esc($def['type'] ?? 'text') ?>"
                                    data-options="<?= esc(json_encode($def['options'] ?? []), 'attr') ?>">
                                <?= esc($def['label'] ?? $key) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-2">
                    <label class="form-label small fw-semibold" for="bulkAttrValue">Value</label>
                    <input type="text" id="bulkAttrValue" class="form-control form-control-sm" maxlength="1000" placeholder="Leave empty to clear">
                    <select id="bulkAttrValueSelect" class="form-select form-select-sm d-none"></select>
                </div>
                <p class="small text-muted mb-0">
                    Applies to the selected contacts.
                    <a href="<?= site_url('attributes') ?>" target="_blank">Manage attributes</a>
                </p>
            </div>
            <div class="modal-footer py-2 px-3 bg-light">
                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-sm btn-wa px-3" id="btnApplyBulkAttribute">Apply</button>
            </div>
        </div>
    </div>
</div>

<!-- Modal 4: Duplicates Modal -->
<div class="modal fade" id="duplicatesModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content shadow border-0">
            <div class="modal-header py-2 px-3 border-bottom bg-light">
                <h5 class="modal-title h6 fw-bold mb-0"><i class="fas fa-clone text-primary me-2"></i>Duplicate Mobiles</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-3" id="duplicatesModalBody"></div>
        </div>
    </div>
</div>

<!-- Modal 5: Contact Detail Modal -->
<div class="modal fade" id="contactDetailModal" tabindex="-1" aria-labelledby="contactDetailTitle">
    <div class="modal-dialog modal-lg modal-dialog-scrollable modal-dialog-centered">
        <div class="modal-content shadow border-0">
            <div class="modal-header py-2 px-3 border-bottom bg-light">
                <h5 class="modal-title h6 fw-bold mb-0" id="contactDetailTitle">
                    <i class="fas fa-user-circle text-primary me-2"></i>Contact Details
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-3" id="contactDetailBody">
                <div class="text-muted small">Select a contact row to view details.</div>
            </div>
            <div class="modal-footer py-2 px-3 bg-light">
                <a href="#" class="btn btn-outline-secondary btn-sm" id="contactDetailViewLink"><i class="fas fa-eye me-1"></i> Full Page</a>
                <a href="#" class="btn btn-outline-secondary btn-sm" id="contactDetailEditLink"><i class="fas fa-edit me-1"></i> Edit</a>
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= asset_url('assets/js/contacts.js') ?>"></script>
<script>
(function ($) {
    'use strict';
    // Clear / Reset filters button
    $('#btnResetContactsFilter').on('click', function () {
        $('#filterStatus, #filterTag, #filterConsent, #filterAssigned, #filterAttrKey').val('');
        $('#filterAttrOp, #filterAttrValue').addClass('d-none').val('');
        if ($('#contactsTable').length && $.fn.DataTable) {
            $('#contactsTable').DataTable().ajax.reload();
        }
    });
})(jQuery);
</script>
<?= $this->endSection() ?>
