<?= $this->extend('layouts/main') ?>

<?= $this->section('header_actions') ?>
<?php if (function_exists('can') && can('contacts.export')): ?>
    <a href="<?= site_url('customer-groups/export') ?>" class="btn btn-outline-secondary btn-sm">
        <i class="fas fa-file-export me-1"></i> Export
    </a>
<?php endif; ?>
<?php if (function_exists('can') && can('contacts.create')): ?>
    <button type="button" class="btn btn-wa btn-sm" id="btnAddContactToGroup">
        <i class="fas fa-user-plus me-1"></i> Add Contacts
    </button>
<?php endif; ?>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<div class="page-list page-customer-groups">

<div class="card">
    <div class="card-header d-flex align-items-center justify-content-between gap-2">
        <h2 class="card-title mb-0">Customer groups</h2>
        <span class="small text-muted">Use groups as campaign audiences</span>
    </div>
    <div class="card-body py-3">
        <table id="customerGroupsTable" class="table table-sm table-hover align-middle w-100">
            <thead>
                <tr>
                    <th>Group</th>
                    <th class="text-center" style="width:110px">Contacts</th>
                    <th style="width:220px">Added on</th>
                    <th class="text-end" style="width:130px">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($groups)): ?>
                    <tr>
                        <td colspan="4" class="text-center text-muted py-4">
                            No customer groups yet. Add a contact to create your first group for campaigns.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($groups as $group): ?>
                        <tr data-id="<?= (int) $group['id'] ?>">
                            <td>
                                <span class="fw-medium"><?= esc($group['name'] ?? '') ?></span>
                            </td>
                            <td class="text-center"><?= (int) ($group['contact_count'] ?? 0) ?></td>
                            <?php $added = $group['created_at'] ?? ''; ?>
                            <td class="text-muted small" data-order="<?= $added !== '' ? (int) strtotime($added) : 0 ?>">
                                <?= $added !== '' ? esc(format_app_datetime($added)) : '—' ?>
                            </td>
                            <td class="text-end">
                                <div class="table-actions justify-content-end">
                                    <a href="<?= site_url('customer-groups/' . (int) $group['id']) ?>"
                                       class="btn btn-sm btn-outline-secondary" title="View contacts">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    <?php if (function_exists('can') && can('contacts.delete')): ?>
                                        <button type="button" class="btn btn-sm btn-outline-danger"
                                                data-confirm-delete
                                                data-url="<?= site_url('customer-groups/' . (int) $group['id'] . '/delete') ?>"
                                                title="Delete group">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    <?php endif; ?>
                                    <?php if (function_exists('can') && can('contacts.export')): ?>
                                        <a href="<?= site_url('customer-groups/' . (int) $group['id'] . '/export') ?>"
                                           class="btn btn-sm btn-outline-secondary" title="Download contacts">
                                            <i class="fas fa-download"></i>
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
</div>

<?php if (function_exists('can') && can('contacts.create')): ?>
<div class="modal fade" id="addContactGroupModal" tabindex="-1" aria-labelledby="addContactGroupModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="addContactGroupModalLabel">Add a contact</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="addContactGroupForm" autocomplete="off">
                <div class="modal-body">
                    <div id="addContactGroupErrors" class="alert alert-danger d-none py-2 px-3 small" role="alert"></div>
                    <div class="mb-3">
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="radio" name="mode" id="modeNewGroup" value="new" checked>
                            <label class="form-check-label" for="modeNewGroup">
                                Add contacts to new list
                                <span class="text-muted small ms-1" id="groupNameCount">0/30</span>
                            </label>
                        </div>
                        <div id="newGroupFields" class="ps-4 mb-3">
                            <input type="text" class="form-control form-control-sm border-0 border-bottom rounded-0 px-0"
                                   id="newGroupName" name="group_name" maxlength="30" placeholder="Enter group name">
                            <div class="invalid-feedback" id="err_group_name"></div>
                        </div>

                        <div class="form-check mb-2">
                            <input class="form-check-input" type="radio" name="mode" id="modeExistingGroup" value="existing">
                            <label class="form-check-label" for="modeExistingGroup">Add contacts to existing list</label>
                        </div>
                        <div id="existingGroupFields" class="ps-4 mb-2 d-none">
                            <select class="form-select form-select-sm" id="existingGroupId" name="group_id">
                                <option value="">Select group</option>
                                <?php foreach (($groups ?? []) as $group): ?>
                                    <option value="<?= (int) $group['id'] ?>"><?= esc($group['name'] ?? '') ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="invalid-feedback" id="err_group_id"></div>
                        </div>
                    </div>

                    <div class="row g-3">
                        <div class="col-md-4">
                            <input type="text" class="form-control" name="name" id="contactName" placeholder="Name" maxlength="150">
                            <div class="invalid-feedback" id="err_name"></div>
                        </div>
                        <div class="col-md-4">
                            <input type="text" class="form-control" name="mobile" id="contactMobile" placeholder="91XXXXXXXXXX" required>
                            <div class="invalid-feedback" id="err_mobile"></div>
                        </div>
                        <div class="col-md-4">
                            <input type="email" class="form-control" name="email" id="contactEmail" placeholder="Email">
                            <div class="invalid-feedback" id="err_email"></div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-wa" id="btnSaveContactGroup">Save Contact</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= base_url('assets/js/customer-groups.js') ?>"></script>
<?= $this->endSection() ?>
