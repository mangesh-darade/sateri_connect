<?= $this->extend('layouts/main') ?>

<?= $this->section('header_actions') ?>
<?php
$canCreateWa = ! empty($canCreateWa);
$canCreateEmail = ! empty($canCreateEmail);
?>
<button type="button" class="btn btn-outline-secondary btn-sm" id="campaignRefreshBtn" title="Refresh list">
    <i class="fas fa-sync-alt"></i>
</button>
<a href="<?= site_url('reports/campaigns') ?>" class="btn btn-outline-secondary btn-sm">
    <i class="fas fa-file-export me-1"></i> Export Report
</a>
<?php if ($canCreateWa || $canCreateEmail): ?>
<div class="dropdown">
    <button class="btn btn-wa btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
        <i class="fas fa-plus-circle me-1"></i> New Campaign
    </button>
    <ul class="dropdown-menu dropdown-menu-end shadow-sm">
        <?php if ($canCreateWa): ?>
        <li>
            <button type="button" class="dropdown-item js-open-campaign-wizard" data-channel="whatsapp">
                <i class="fab fa-whatsapp me-2 text-success"></i> WhatsApp Campaign
            </button>
        </li>
        <?php endif; ?>
        <?php if ($canCreateEmail): ?>
        <li>
            <button type="button" class="dropdown-item js-open-campaign-wizard" data-channel="email">
                <i class="fas fa-envelope me-2 text-primary"></i> Email Campaign
            </button>
        </li>
        <?php endif; ?>
        <li><hr class="dropdown-divider"></li>
        <li>
            <span class="dropdown-item disabled text-muted">
                <i class="fas fa-sms me-2"></i> SMS Campaign <span class="badge text-bg-light ms-1">Coming soon</span>
            </span>
        </li>
    </ul>
</div>
<?php endif; ?>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php
$canCreateWa = ! empty($canCreateWa);
$canCreateEmail = ! empty($canCreateEmail);
$filterChannel = (string) ($filterChannel ?? '');
$filterStatus = (string) ($filterStatus ?? '');
$filterSearch = (string) ($filterSearch ?? '');
$filterSort = (string) ($filterSort ?? 'latest');
$openChannel = (string) ($openChannel ?? '');
$campaigns = $campaigns ?? [];
?>
<div class="page-list campaign-hub" id="campaignHub"
     data-open-channel="<?= esc($openChannel, 'attr') ?>"
     data-can-wa="<?= $canCreateWa ? '1' : '0' ?>"
     data-can-email="<?= $canCreateEmail ? '1' : '0' ?>">

    <div class="card">
        <div class="card-body py-3">
            <form method="get" action="<?= site_url('campaigns') ?>" class="filter-bar mb-0" id="campaignFilterForm">
                <input type="search" name="q" value="<?= esc($filterSearch) ?>" class="form-control form-control-sm" placeholder="Search campaign" title="Search">
                <select name="channel" class="form-select form-select-sm" title="Channel">
                    <option value="">All channels</option>
                    <option value="whatsapp" <?= $filterChannel === 'whatsapp' ? 'selected' : '' ?>>WhatsApp</option>
                    <option value="email" <?= $filterChannel === 'email' ? 'selected' : '' ?>>Email</option>
                </select>
                <select name="status" class="form-select form-select-sm" title="Status">
                    <option value="">All status</option>
                    <?php foreach (['draft', 'scheduled', 'queued', 'running', 'sending', 'completed', 'sent', 'paused', 'failed', 'cancelled'] as $st): ?>
                        <option value="<?= $st ?>" <?= $filterStatus === $st ? 'selected' : '' ?>><?= esc(ucfirst($st)) ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="sort" class="form-select form-select-sm" title="Sort">
                    <option value="latest" <?= $filterSort === 'latest' ? 'selected' : '' ?>>Latest</option>
                    <option value="oldest" <?= $filterSort === 'oldest' ? 'selected' : '' ?>>Oldest</option>
                    <option value="name" <?= $filterSort === 'name' ? 'selected' : '' ?>>Name</option>
                </select>
                <div class="filter-bar-actions">
                    <button type="submit" class="btn btn-wa btn-sm"><i class="fas fa-filter me-1"></i> Filter</button>
                    <a href="<?= site_url('campaigns') ?>" class="btn btn-link btn-sm px-1">Reset</a>
                </div>
            </form>
        </div>
    </div>

    <div class="page-hint">
        <i class="fas fa-info-circle" aria-hidden="true"></i>
        <span>Before sending, sync templates from the template library — categories sometimes change on the provider side.</span>
    </div>

    <div class="card">
        <div class="card-header d-flex align-items-center justify-content-between gap-2">
            <h2 class="card-title mb-0">Recent campaigns</h2>
            <span class="small text-muted"><?= count($campaigns) ?> total</span>
        </div>
        <div class="card-body py-3">
            <?php if (! empty($campaigns)): ?>
            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle w-100 mb-0" id="campaignsTable">
                    <thead>
                        <tr>
                            <th>Campaign Name</th>
                            <th>Label</th>
                            <th>Campaign Type</th>
                            <th>Channels</th>
                            <th>Status</th>
                            <th>Contacts</th>
                            <th>Sent</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($campaigns as $c): ?>
                            <?php
                            $channel = (string) ($c['channel'] ?? 'whatsapp');
                            $created = ! empty($c['created_at']) ? date('d-M-Y, g:iA', strtotime((string) $c['created_at'])) : '';
                            ?>
                            <tr>
                                <td>
                                    <a class="fw-semibold text-decoration-none" href="<?= esc($c['view_url'] ?? '#') ?>"><?= esc($c['name'] ?? '') ?></a>
                                    <?php if ($created !== ''): ?>
                                        <div class="text-muted small"><?= esc($created) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="text-muted"><?= esc($c['label'] !== '' ? $c['label'] : '—') ?></td>
                                <td class="text-muted small"><?= esc($c['campaign_type'] ?? '—') ?></td>
                                <td>
                                    <?php if ($channel === 'email'): ?>
                                        <span class="badge text-bg-light border">Email</span>
                                    <?php else: ?>
                                        <span class="badge text-bg-light border">WhatsApp</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= view('partials/status_badge', ['status' => $c['status'] ?? 'draft']) ?></td>
                                <td><?= (int) ($c['contacts'] ?? 0) ?></td>
                                <td><?= (int) ($c['sent'] ?? 0) ?></td>
                                <td class="text-end">
                                    <div class="table-actions justify-content-end">
                                        <a class="btn btn-sm btn-outline-secondary" href="<?= esc($c['view_url'] ?? '#') ?>" title="View"><i class="fas fa-eye"></i></a>
                                        <?php if ($channel === 'whatsapp' && function_exists('can') && can('campaigns.edit') && in_array($c['status'] ?? '', ['draft', 'scheduled', 'paused'], true)): ?>
                                            <a class="btn btn-sm btn-outline-secondary" href="<?= esc($c['edit_url'] ?? '#') ?>" title="Edit"><i class="fas fa-edit"></i></a>
                                        <?php endif; ?>
                                        <?php if ($channel === 'whatsapp' && function_exists('can') && can('campaigns.delete')): ?>
                                            <button type="button" class="btn btn-sm btn-outline-danger" data-confirm-delete data-url="<?= site_url('campaigns/' . (int) $c['id'] . '/delete') ?>" title="Delete"><i class="fas fa-trash"></i></button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
                <div class="activity-empty">
                    <i class="fas fa-bullhorn"></i>
                    No campaigns yet — create a WhatsApp or Email campaign to start broadcasting.
                    <?php if ($canCreateWa || $canCreateEmail): ?>
                        <div class="mt-3">
                            <button type="button" class="btn btn-wa btn-sm js-open-campaign-wizard" data-channel="<?= $canCreateWa ? 'whatsapp' : 'email' ?>">New campaign</button>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?= $this->include('campaigns/_wizard') ?>
<?= $this->endSection() ?>

<?= $this->section('styles') ?>
<style>
.campaign-wizard .modal-dialog { max-width: 760px; }
.campaign-wizard .modal-dialog.modal-xl { max-width: 980px; }
.campaign-wizard-step { display: none; }
.campaign-wizard-step.is-active { display: block; }
.cw-label-chip {
    display: inline-flex; align-items: center; gap: .35rem;
    border: 1px solid #f0b27a; color: #c06a1a; background: #fff8f0;
    border-radius: 999px; padding: .25rem .75rem; font-size: .85rem; font-weight: 600;
}
.cw-template-grid {
    display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 1rem;
    max-height: 420px; overflow: auto;
}
.cw-template-card {
    border: 1px solid #e5e7eb; border-radius: 12px; padding: 1rem; background: #fff;
    display: flex; flex-direction: column; gap: .65rem; min-height: 180px;
}
.cw-template-card.is-selected { border-color: var(--brand-500, #8e53f7); box-shadow: 0 0 0 2px rgba(142,83,247,.2); }
.cw-template-card .cw-tpl-name { font-weight: 700; font-size: .95rem; word-break: break-word; }
.cw-template-card .cw-tpl-body { color: #667085; font-size: .82rem; flex: 1;
    display: -webkit-box; -webkit-line-clamp: 4; -webkit-box-orient: vertical; overflow: hidden; }
.cw-phone-preview {
    background: #111b21; color: #e9edef; border-radius: 16px; padding: 1rem;
    min-height: 280px; font-size: .9rem; white-space: pre-wrap;
}
.cw-phone-preview .cw-bubble {
    background: #005c4b; border-radius: 8px; padding: .75rem; margin-top: .5rem;
}
.cw-attr-row { display: grid; grid-template-columns: 1fr 1fr 1fr auto; gap: .5rem; align-items: center; margin-bottom: .5rem; }
@media (max-width: 767px) {
    .cw-attr-row { grid-template-columns: 1fr; }
}
.cw-upload-box {
    border: 1.5px dashed #d0d5dd; border-radius: 12px; padding: 2rem 1rem; text-align: center; background: #fafafa;
    cursor: pointer; transition: border-color .15s ease, background .15s ease, box-shadow .15s ease;
}
.cw-upload-box:hover, .cw-upload-box:focus {
    border-color: var(--brand-500, #8e53f7); background: #f6f1ff; outline: none;
}
.cw-upload-box.is-dragover {
    border-color: var(--brand-500, #8e53f7);
    background: #eafff3;
    box-shadow: inset 0 0 0 2px rgba(37, 211, 102, .25);
}
.cw-upload-box .form-control, .cw-upload-box .btn { cursor: auto; }
.cw-count-line { font-size: .85rem; color: #475467; margin-bottom: .75rem; }
</style>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= asset_url('assets/js/campaigns.js') ?>"></script>
<?= $this->endSection() ?>
