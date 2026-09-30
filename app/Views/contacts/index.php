<?= $this->extend('layouts/main') ?>

<?= $this->section('header_actions') ?>
<?php if (function_exists('can') && can('contacts.create')): ?>
    <a href="<?= site_url('contacts/create') ?>" class="btn btn-wa btn-sm"><i class="fas fa-plus me-1"></i> Add contact</a>
<?php endif; ?>
<?php if (function_exists('can') && can('contacts.import')): ?>
    <a href="<?= site_url('contacts/import') ?>" class="btn btn-outline-secondary btn-sm"><i class="fas fa-file-import me-1"></i> Import</a>
    <form action="<?= site_url('contacts/sync-cheerio') ?>" method="post" class="d-inline" id="formSyncCheerioContacts">
        <?= csrf_field() ?>
        <button type="submit" class="btn btn-outline-secondary btn-sm" id="btnSyncCheerioContacts"
                title="Sync contacts for the active WhatsApp provider">
            <i class="fas fa-cloud-download-alt me-1"></i> <?= esc(function_exists('whatsapp_sync_label') ? whatsapp_sync_label() : 'Sync contacts') ?>
        </button>
    </form>
    <form action="<?= site_url('contacts/sync-elintom') ?>" method="post" class="d-inline" id="formSyncElintOmContacts">
        <?= csrf_field() ?>
        <button type="submit" class="btn btn-outline-secondary btn-sm" id="btnSyncElintOmContacts"
                title="Fetch customers from ElintOm POS (sma_companies) into Contacts">
            <i class="fas fa-cash-register me-1"></i> Sync ElintOm customers
        </button>
    </form>
<?php endif; ?>
<?php if (function_exists('can') && can('contacts.export')): ?>
    <a href="<?= site_url('contacts/export') ?>" id="btnExportContacts" class="btn btn-outline-secondary btn-sm"
       title="Download filtered contacts with groups, consent and all attributes"><i class="fas fa-file-export me-1"></i> Export</a>
<?php endif; ?>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<div class="page-list page-contacts">

<div class="card">
    <div class="card-header d-flex align-items-center justify-content-between gap-2">
        <h2 class="card-title mb-0">All contacts</h2>
        <span class="small text-muted">Filter, select, then bulk update</span>
    </div>
    <div class="card-body py-3">
        <div class="filter-bar">
            <select id="filterStatus" class="form-select form-select-sm" style="max-width:130px">
                <option value="">All statuses</option>
                <option value="active">Active</option>
                <option value="inactive">Inactive</option>
                <option value="blocked">Blocked</option>
            </select>
            <select id="filterConsent" class="form-select form-select-sm" style="max-width:170px" title="WhatsApp consent">
                <option value="">All WhatsApp consent</option>
                <option value="opted_in">Opted in</option>
                <option value="no_opt_in">No opt-in</option>
                <option value="opted_out">Opted out (STOP)</option>
                <option value="suppressed">Paused (delivery failed)</option>
            </select>
            <select id="filterTag" class="form-select form-select-sm" style="max-width:160px">
                <option value="">All groups</option>
                <?php foreach (($tags ?? []) as $tag): ?>
                    <option value="<?= (int) $tag['id'] ?>"><?= esc($tag['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <select id="filterAssigned" class="form-select form-select-sm" style="max-width:140px">
                <option value="">All agents</option>
                <?php foreach (($agents ?? []) as $agent): ?>
                    <option value="<?= (int) $agent['id'] ?>"><?= esc($agent['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <select id="filterAttrKey" class="form-select form-select-sm" style="max-width:160px" title="Filter by attribute">
                <option value="">Any attribute</option>
                <?php foreach (($attributeKeys ?? []) as $key): ?>
                    <option value="<?= esc($key) ?>"><?= esc(isset($attributeDefs[$key]) ? $attributeDefs[$key]['label'] : $key) ?></option>
                <?php endforeach; ?>
            </select>
            <select id="filterAttrOp" class="form-select form-select-sm d-none" style="max-width:135px">
                <?php foreach (($attributeOps ?? []) as $op => $label): ?>
                    <option value="<?= esc($op) ?>"><?= esc($label) ?></option>
                <?php endforeach; ?>
            </select>
            <input type="text" id="filterAttrValue" class="form-control form-control-sm d-none" style="max-width:140px" placeholder="Value">
            <button type="button" id="btnFilterContacts" class="btn btn-sm btn-wa"><i class="fas fa-filter me-1"></i> Filter</button>
            <div class="filter-bar-actions">
                <button type="button" id="btnDetectDuplicates" class="btn btn-sm btn-outline-secondary"><i class="fas fa-clone me-1"></i> Duplicates</button>
                <?php if (function_exists('can') && can('contacts.delete')): ?>
                    <button type="button" id="btnBulkDelete" class="btn btn-sm btn-soft-danger"><i class="fas fa-trash me-1"></i> Bulk delete</button>
                <?php endif; ?>
                <?php if (function_exists('can') && can('contacts.edit')): ?>
                    <button type="button" id="btnBulkTags" class="btn btn-sm btn-soft-secondary"><i class="fas fa-tags me-1"></i> Bulk groups</button>
                    <button type="button" id="btnBulkConsent" class="btn btn-sm btn-soft-secondary"><i class="fab fa-whatsapp me-1"></i> WhatsApp consent</button>
                    <button type="button" id="btnBulkAttribute" class="btn btn-sm btn-soft-secondary"><i class="fas fa-pen-to-square me-1"></i> Set attribute</button>
                <?php endif; ?>
            </div>
        </div>

        <?php
        $attrColumns = [];
        foreach (($attributeDefs ?? []) as $key => $def) {
            $attrColumns[] = ['key' => (string) $key, 'label' => (string) $def['label'], 'type' => (string) $def['type']];
        }
        ?>
        <table id="contactsTable" class="table table-sm table-hover align-middle w-100" data-attr-columns="<?= esc(json_encode($attrColumns), 'attr') ?>">
            <thead>
                <tr>
                    <th class="dt-check-col" scope="col">
                        <input type="checkbox" class="form-check-input" id="checkAllContacts" title="Select all" aria-label="Select all">
                    </th>
                    <th>Name</th>
                    <th>Mobile</th>
                    <th>Email</th>
                    <th>Groups</th>
                    <th>Status</th>
                    <th>Last Message</th>
                    <?php foreach ($attrColumns as $col): ?>
                        <th class="text-nowrap" title="Attribute: <?= esc($col['key'], 'attr') ?>"><?= esc($col['label']) ?></th>
                    <?php endforeach; ?>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
        </table>
    </div>
</div>
</div>

<div class="modal fade" id="bulkTagsModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Bulk Groups</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">Action</label>
                    <select id="bulkTagAction" class="form-select">
                        <option value="add">Add to groups</option>
                        <option value="remove">Remove from groups</option>
                    </select>
                </div>
                <div class="mb-0">
                    <label class="form-label">Groups</label>
                    <select id="bulkTagIds" class="form-select" multiple size="6">
                        <?php foreach (($tags ?? []) as $tag): ?>
                            <option value="<?= (int) $tag['id'] ?>"><?= esc($tag['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-wa" id="btnApplyBulkTags">Apply</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="bulkConsentModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">WhatsApp consent</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label" for="bulkConsentAction">Action</label>
                    <select id="bulkConsentAction" class="form-select">
                        <option value="opt_in">Record opt-in (customers agreed)</option>
                        <option value="opt_out">Opt out (stop all WhatsApp marketing)</option>
                    </select>
                </div>
                <div class="mb-2" id="bulkConsentSourceWrap">
                    <label class="form-label" for="bulkConsentSource">How did they give consent?</label>
                    <select id="bulkConsentSource" class="form-select">
                        <option value="">Choose…</option>
                        <?php foreach (\App\Libraries\WhatsAppConsentService::OPT_IN_SOURCES as $key => $label): ?>
                            <option value="<?= esc($key) ?>"><?= esc($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <p class="small text-muted mb-0">
                    Only record opt-in when you have proof the customer agreed to WhatsApp messages from your business.
                    Messaging people without consent is the main reason Meta bans numbers. Customers who sent STOP are skipped.
                </p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-wa" id="btnApplyBulkConsent">Apply</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="bulkAttributeModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Set attribute</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label" for="bulkAttrKey">Attribute</label>
                    <select id="bulkAttrKey" class="form-select">
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
                    <label class="form-label" for="bulkAttrValue">Value</label>
                    <input type="text" id="bulkAttrValue" class="form-control" maxlength="1000" placeholder="Leave empty to clear">
                    <select id="bulkAttrValueSelect" class="form-select d-none"></select>
                </div>
                <p class="small text-muted mb-0">
                    Applies to the selected contacts. Typed attributes (number, date, dropdown, yes/no) are checked before saving.
                    <a href="<?= site_url('attributes') ?>">Manage attributes</a>
                </p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-wa" id="btnApplyBulkAttribute">Apply</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="duplicatesModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Duplicate Mobiles</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="duplicatesModalBody"></div>
        </div>
    </div>
</div>

<div class="modal fade" id="contactDetailModal" tabindex="-1" aria-labelledby="contactDetailTitle">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="contactDetailTitle">Contact details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="contactDetailBody">
                <div class="text-muted">Select a contact row to view details.</div>
            </div>
            <div class="modal-footer">
                <a href="#" class="btn btn-outline-secondary btn-sm" id="contactDetailViewLink"><i class="fas fa-eye me-1"></i> Full page</a>
                <a href="#" class="btn btn-outline-secondary btn-sm" id="contactDetailEditLink"><i class="fas fa-edit me-1"></i> Edit</a>
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= base_url('assets/js/contacts.js') ?>"></script>
<?= $this->endSection() ?>
