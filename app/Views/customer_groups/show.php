<?= $this->extend('layouts/main') ?>

<?= $this->section('header_actions') ?>
<a href="<?= site_url('customer-groups') ?>" class="btn btn-sm btn-outline-secondary">
    <i class="fas fa-arrow-left me-1"></i> Back to groups
</a>
<?php if (function_exists('can') && can('contacts.export')): ?>
    <a href="<?= site_url('customer-groups/' . (int) $group['id'] . '/export') ?>" class="btn btn-outline-secondary btn-sm">
        <i class="fas fa-download me-1"></i> Download CSV
    </a>
<?php endif; ?>
<a href="<?= site_url('campaigns/create') ?>" class="btn btn-sm btn-outline-secondary">
    <i class="fas fa-bullhorn me-1"></i> Create campaign
</a>
<?php if (function_exists('can') && can('contacts.create')): ?>
    <a href="<?= site_url('customer-groups') ?>?add=1&group_id=<?= (int) $group['id'] ?>" class="btn btn-wa btn-sm">
        <i class="fas fa-user-plus me-1"></i> Add contact
    </a>
<?php endif; ?>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<div class="page-list page-customer-groups-show">
<div class="card mb-3">
    <div class="card-body py-3">
        <h2 class="h5 mb-1" style="font-family:var(--font-display)"><?= esc($group['name'] ?? '') ?></h2>
        <p class="text-muted small mb-0">
            <?= count($contacts ?? []) ?> contact<?= count($contacts ?? []) === 1 ? '' : 's' ?>
            · Use this group as audience when creating a campaign
        </p>
    </div>
</div>

<div class="card">
    <div class="card-body py-3">
        <table class="table table-sm table-hover align-middle w-100" id="groupContactsTable">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Mobile</th>
                    <th>Email</th>
                    <th>Status</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($contacts)): ?>
                    <tr>
                        <td colspan="5" class="text-center text-muted py-4">No contacts in this group yet.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($contacts as $contact): ?>
                        <tr data-contact-id="<?= (int) $contact['id'] ?>">
                            <td>
                                <a href="<?= site_url('contacts/' . (int) $contact['id']) ?>">
                                    <?= esc($contact['name'] ?: '—') ?>
                                </a>
                            </td>
                            <td><?= esc($contact['mobile'] ?? '—') ?></td>
                            <td><?= esc($contact['email'] ?: '—') ?></td>
                            <td>
                                <?php
                                $status = (string) ($contact['status'] ?? 'active');
                                $badgeClass = $status === 'active' ? 'badge-soft' : 'bg-secondary';
                                ?>
                                <span class="badge rounded-pill <?= esc($badgeClass) ?>"><?= esc($status) ?></span>
                            </td>
                            <td class="text-end">
                                <div class="table-actions justify-content-end">
                                    <a href="<?= site_url('contacts/' . (int) $contact['id']) ?>"
                                       class="btn btn-sm btn-outline-secondary" title="View contact">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    <?php if (function_exists('can') && can('contacts.edit')): ?>
                                        <button type="button"
                                                class="btn btn-sm btn-outline-danger btn-remove-from-group"
                                                data-group-id="<?= (int) $group['id'] ?>"
                                                data-contact-id="<?= (int) $contact['id'] ?>"
                                                title="Remove from group">
                                            <i class="fas fa-user-minus"></i>
                                        </button>
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
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= base_url('assets/js/customer-groups.js') ?>"></script>
<?= $this->endSection() ?>
