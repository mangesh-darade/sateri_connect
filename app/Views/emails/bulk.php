<?= $this->extend('layouts/main') ?>

<?= $this->section('header_actions') ?>
<a href="<?= site_url('emails') ?>" class="btn btn-sm btn-outline-secondary d-inline-flex align-items-center gap-1 shadow-sm">
    <i class="fas fa-arrow-left"></i>
    <span>Back to Emails</span>
</a>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php
$provider = $provider ?? 'smtp';
$providerLabel = $providerLabel ?? 'SMTP';
$providerDetail = $providerDetail ?? '';
$isCheerio = ! empty($isCheerio);
$defaultCampaign = $defaultCampaign ?? 'app-direct';
$campaigns = $campaigns ?? [];
$emailCampaigns = $emailCampaigns ?? [];
$emailTemplates = $emailTemplates ?? [];
$customerGroups = $customerGroups ?? [];
$contactsWithEmail = $contactsWithEmail ?? [];
$maxRecipients = (int) ($maxRecipients ?? 100);
$defaultTo = $defaultTo ?? 'sateri.mangesh@gmail.com';
?>

<div class="composer-container w-100">
    <div class="card border-0 shadow-sm rounded-4 overflow-hidden" id="emailBulkCard"
         data-send-url="<?= site_url('emails/bulk') ?>"
         data-provider="<?= esc($provider) ?>"
         data-max="<?= $maxRecipients ?>"
         style="background: #ffffff; border: 1px solid #e2e8f0 !important;">

        <form id="emailBulkForm" method="post" action="<?= site_url('emails/bulk') ?>" enctype="multipart/form-data">
            <?= csrf_field() ?>

            <!-- Top Header Strip: Clean & Modern -->
            <div class="px-4 py-3 bg-white border-bottom d-flex flex-wrap justify-content-between align-items-center gap-3">
                <div class="d-flex align-items-center gap-3">
                    <div class="rounded-3 d-flex align-items-center justify-content-center text-white shadow-sm"
                         style="width: 38px; height: 38px; background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);">
                        <i class="fas fa-paper-plane" style="font-size: 0.95rem;"></i>
                    </div>
                    <div>
                        <div class="d-flex align-items-center gap-2">
                            <h5 class="mb-0 fw-bold text-dark" style="font-size: 1.05rem; letter-spacing: -0.2px;">Bulk Email Dispatcher</h5>
                            <span class="badge rounded-pill bg-success-subtle text-success border border-success-subtle px-2 py-1" style="font-size: 0.7rem; font-weight: 600;">
                                <i class="fas fa-circle me-1" style="font-size: 0.45rem;"></i> <?= esc($providerLabel) ?>
                            </span>
                        </div>
                        <p class="text-muted small mb-0" style="font-size: 0.78rem;">Broadcast custom email campaigns to multiple contacts or customer groups.</p>
                    </div>
                </div>

                <div class="d-flex align-items-center">
                    <span class="badge bg-slate-50 text-secondary border px-3 py-2 rounded-pill d-inline-flex align-items-center gap-1" style="font-size: 0.75rem; background: #f8fafc;">
                        <i class="fas fa-layer-group text-primary"></i>
                        <span>Max <strong><?= $maxRecipients ?></strong> / batch</span>
                    </span>
                </div>
            </div>

            <div class="p-4">
                <!-- Target Audience Mode Selector (Segmented Pill Switch) -->
                <div class="mb-4">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <label class="form-label text-uppercase fw-bold text-muted mb-0" style="font-size: 0.72rem; letter-spacing: 0.6px;">
                            Step 1 · Target Audience
                        </label>
                        <span class="text-muted small" style="font-size: 0.74rem;">Choose how to select recipients</span>
                    </div>

                    <div class="audience-pill-bar d-inline-flex p-1 rounded-3 border" style="background: #f1f5f9; border-color: #e2e8f0 !important;">
                        <input type="radio" class="btn-check" name="mode" id="modeRecipients" value="recipients" checked autocomplete="off">
                        <label class="btn btn-sm rounded-3 px-3 py-1 fw-semibold d-inline-flex align-items-center gap-2 audience-pill-btn" for="modeRecipients">
                            <i class="fas fa-users"></i>
                            <span>Pick Contacts / Paste Emails</span>
                        </label>

                        <input type="radio" class="btn-check" name="mode" id="modeLabel" value="label" autocomplete="off">
                        <label class="btn btn-sm rounded-3 px-3 py-1 fw-semibold d-inline-flex align-items-center gap-2 audience-pill-btn" for="modeLabel">
                            <i class="fas fa-tags"></i>
                            <span>Customer Group / Label</span>
                        </label>
                    </div>
                </div>

                <!-- Custom List / Pick Contacts Panel (Balanced 2-Column Split) -->
                <div id="bulkRecipientsPanel" class="row g-3 mb-4">
                    <!-- Left: Paste Emails -->
                    <div class="col-md-5">
                        <div class="h-100 p-3 rounded-3 border bg-white d-flex flex-column" style="border-color: #e2e8f0 !important; background: #fdfdfd;">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <label class="form-label fw-bold small text-dark mb-0 d-flex align-items-center gap-1" for="bulkRecipients">
                                    <i class="fas fa-keyboard text-primary"></i>
                                    <span>Paste Email List</span>
                                </label>
                                <span class="badge rounded-pill bg-light text-muted border px-2" style="font-size: 0.68rem;">Max <?= $maxRecipients ?></span>
                            </div>

                            <textarea class="form-control font-monospace border flex-grow-1" id="bulkRecipients" name="recipients" rows="7"
                                      style="min-height: 180px; font-size: 0.8rem; background: #fafbfc; border-color: #cbd5e1; resize: vertical; line-height: 1.5;"
                                      placeholder="name@company.com&#10;client@domain.in&#10;user@domain.org"><?= esc(old('recipients') ?? '') ?></textarea>

                            <div class="mt-2 d-flex justify-content-between align-items-center text-muted" style="font-size: 0.72rem;">
                                <span><i class="fas fa-info-circle me-1"></i> Comma or line separated</span>
                                <span id="pastedCount" class="fw-semibold text-primary"></span>
                            </div>
                        </div>
                    </div>

                    <!-- Right: Pick from CRM Contacts (Dropdown UI) -->
                    <div class="col-md-7">
                        <div class="h-100 p-3 rounded-3 border bg-white d-flex flex-column" style="border-color: #e2e8f0 !important;">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <label class="form-label fw-bold small text-dark mb-0 d-flex align-items-center gap-1.5">
                                    <i class="fas fa-address-book text-success"></i>
                                    <span>Pick from CRM Contacts</span>
                                </label>
                                <div class="d-flex align-items-center gap-2">
                                    <button type="button" class="btn btn-link btn-xs text-danger text-decoration-none p-0 fw-semibold d-none" id="btnClearAllSelectedBtn" style="font-size: 0.72rem;">
                                        Clear all
                                    </button>
                                    <span class="badge rounded-pill bg-primary text-white px-2.5 py-1 fw-semibold" id="bulkSelectedBadge" style="font-size: 0.72rem;">
                                        0 selected
                                    </span>
                                </div>
                            </div>

                            <!-- Group Filter & Dropdown Trigger Row -->
                            <div class="row g-2 mb-2">
                                <div class="col-sm-5">
                                    <label class="form-label text-muted small mb-1" style="font-size: 0.72rem;">1. Filter by Group</label>
                                    <select id="bulkFilterGroup" class="form-select form-select-sm py-1.5" style="font-size: 0.78rem; border-color: #cbd5e1;">
                                        <option value="">All Groups (<?= count($contactsWithEmail) ?>)</option>
                                        <?php foreach ($customerGroups as $cg): ?>
                                            <option value="<?= (int) $cg['id'] ?>" data-name="<?= esc($cg['name']) ?>" data-count="<?= (int) ($cg['contact_count'] ?? 0) ?>">
                                                <?= esc($cg['name']) ?> (<?= (int) ($cg['contact_count'] ?? 0) ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-sm-7">
                                    <label class="form-label text-muted small mb-1" style="font-size: 0.72rem;">2. Select Contacts</label>
                                    <!-- Custom Multi-Select Dropdown -->
                                    <div class="dropdown w-100 position-relative" id="bulkContactDropdown">
                                        <button class="btn btn-outline-secondary form-select text-start d-flex justify-content-between align-items-center w-100 py-1.5 px-2 bg-white"
                                                type="button" id="bulkDropdownBtn" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false"
                                                style="border-color: #cbd5e1; font-size: 0.78rem;">
                                            <span class="text-truncate fw-medium text-dark" id="bulkDropdownBtnText">
                                                <i class="fas fa-users text-primary me-1"></i> Choose contacts...
                                            </span>
                                            <i class="fas fa-chevron-down text-muted small ms-1"></i>
                                        </button>

                                        <!-- Dropdown Menu Window -->
                                        <div class="dropdown-menu dropdown-menu-end shadow-lg border p-2 w-100" style="min-width: 320px; max-width: 420px; border-color: #cbd5e1; z-index: 1055;">
                                            <!-- Search Bar -->
                                            <div class="mb-2">
                                                <div class="input-group input-group-sm">
                                                    <span class="input-group-text bg-light text-muted border-end-0 py-1 px-2" style="border-color: #cbd5e1;"><i class="fas fa-search" style="font-size: 0.72rem;"></i></span>
                                                    <input type="text" id="bulkContactSearch" class="form-control form-control-sm border-start-0 py-1" style="font-size: 0.78rem; border-color: #cbd5e1;" placeholder="Search name or email...">
                                                </div>
                                            </div>

                                            <!-- Action Strip: Select All & Quick Actions -->
                                            <div class="d-flex justify-content-between align-items-center bg-light px-2 py-1.5 rounded-2 mb-2 border" style="font-size: 0.74rem; border-color: #e2e8f0 !important;">
                                                <label class="d-flex align-items-center gap-1.5 mb-0 cursor-pointer" for="bulkCheckAll">
                                                    <input class="bulk-contact-cb my-0" type="checkbox" id="bulkCheckAll">
                                                    <span class="fw-semibold text-dark user-select-none">Select All (<span id="bulkVisibleCount"><?= count($contactsWithEmail) ?></span>)</span>
                                                </label>
                                                <div class="d-flex gap-1.5">
                                                    <button type="button" class="btn btn-link btn-xs p-0 text-primary text-decoration-none fw-semibold" id="btnSelectFiltered">Visible</button>
                                                    <span class="text-muted">·</span>
                                                    <button type="button" class="btn btn-link btn-xs p-0 text-danger text-decoration-none fw-semibold" id="btnClearSelection">Clear</button>
                                                </div>
                                            </div>

                                            <!-- Scrollable Contact Checkbox List -->
                                            <div class="overflow-y-auto border rounded bg-white" id="bulkContactList" style="max-height: 200px; border-color: #e2e8f0 !important;">
                                                <div id="bulkNoVisibleNotice" class="text-center py-4 text-muted small d-none">
                                                    <i class="fas fa-user-slash opacity-25 d-block mb-1 fs-5"></i>
                                                    No contacts with email in this group.
                                                </div>
                                                <?php if (empty($contactsWithEmail)): ?>
                                                    <div class="text-center py-4 text-muted small">
                                                        <i class="fas fa-user-slash opacity-25 d-block mb-1 fs-5"></i>
                                                        No contacts with email found.
                                                    </div>
                                                <?php else: ?>
                                                    <?php foreach ($contactsWithEmail as $c): ?>
                                                        <label class="bulk-contact-row align-items-center px-2 py-1.5 border-bottom cursor-pointer text-decoration-none m-0 gap-2"
                                                               data-id="<?= (int) $c['id'] ?>"
                                                               data-name="<?= esc(strtolower($c['name'] ?? '')) ?>"
                                                               data-email="<?= esc(strtolower($c['email'])) ?>"
                                                               data-tags='<?= esc(json_encode($c['tag_ids'] ?? []), 'attr') ?>'>
                                                            <input class="bulk-contact-cb flex-shrink-0 my-0" type="checkbox"
                                                                   value="<?= (int) $c['id'] ?>" data-email="<?= esc($c['email']) ?>" data-name="<?= esc($c['name'] ?: 'Contact') ?>">
                                                            <div class="rounded-circle text-primary fw-bold d-flex align-items-center justify-content-center flex-shrink-0"
                                                                 style="width: 22px; height: 22px; font-size: 0.65rem; background: #e0e7ff; color: #4338ca;">
                                                                <?= esc(strtoupper(substr($c['name'] ?: 'C', 0, 1))) ?>
                                                            </div>
                                                            <div class="text-truncate flex-grow-1" style="min-width: 0;">
                                                                <div class="fw-semibold text-dark text-truncate" style="font-size: 0.77rem; line-height: 1.2;">
                                                                    <?= esc($c['name'] ?: 'Contact') ?>
                                                                </div>
                                                                <div class="text-muted font-monospace text-truncate" style="font-size: 0.71rem;">
                                                                    <?= esc($c['email']) ?>
                                                                </div>
                                                            </div>
                                                        </label>
                                                    <?php endforeach; ?>
                                                <?php endif; ?>
                                            </div>

                                            <!-- Dropdown Footer -->
                                            <div class="d-flex justify-content-between align-items-center mt-2 pt-1 border-top" style="font-size: 0.7rem;">
                                                <span class="text-muted" id="bulkSearchStatus"><?= count($contactsWithEmail) ?> total contacts</span>
                                                <button type="button" class="btn btn-primary btn-xs px-2.5 py-0.5 fw-semibold" id="btnDoneDropdown" style="font-size: 0.72rem;">Done</button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Selected Contacts Pills Container -->
                            <div class="mt-1 border rounded-2 p-2 bg-light flex-grow-1" style="min-height: 80px; max-height: 120px; overflow-y: auto; border-color: #e2e8f0 !important;" id="bulkSelectedPillsBox">
                                <div id="bulkEmptyPillsNotice" class="text-muted small text-center py-3" style="font-size: 0.74rem;">
                                    <i class="fas fa-hand-pointer opacity-50 me-1"></i> No contacts selected. Choose contacts from the dropdown above.
                                </div>
                                <div class="d-flex flex-wrap gap-1.5 d-none" id="bulkSelectedChips"></div>
                            </div>

                            <!-- Hidden multi-select kept for form serialization -->
                            <select class="d-none" id="bulkContacts" name="contact_ids[]" multiple>
                                <?php foreach ($contactsWithEmail as $c): ?>
                                    <option value="<?= (int) $c['id'] ?>"><?= esc($c['email']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Customer Group / Label Panel (Sleek Card & Perfect Alignment) -->
                <div id="bulkLabelPanel" class="rounded-3 p-3 mb-4 d-none border" style="background: #f8fafc; border-color: #cbd5e1 !important;">
                    <div class="d-flex align-items-center gap-2 mb-2">
                        <div class="rounded-circle bg-success-subtle text-success d-flex align-items-center justify-content-center" style="width: 26px; height: 26px;">
                            <i class="fas fa-tags" style="font-size: 0.75rem;"></i>
                        </div>
                        <div>
                            <span class="fw-bold text-dark small">Broadcast by Customer Group / Label</span>
                            <span class="text-muted small ms-1" style="font-size: 0.74rem;">— sends automatically to all contacts assigned this group.</span>
                        </div>
                    </div>

                    <div class="row g-2 align-items-end pt-1">
                        <div class="col-md-7">
                            <label class="form-label small fw-bold text-dark mb-1" for="bulkLabelSelect">
                                Select Target Customer Group <span class="text-danger">*</span>
                            </label>
                            <select class="form-select form-select-sm" id="bulkLabelSelect" name="label_name" style="height: 36px; border-color: #cbd5e1; font-size: 0.85rem;">
                                <option value="">— Choose a Customer Group —</option>
                                <?php foreach ($customerGroups as $cg): ?>
                                    <option value="<?= esc($cg['name']) ?>" data-id="<?= (int) $cg['id'] ?>" data-count="<?= (int) ($cg['contact_count'] ?? 0) ?>">
                                        <?= esc($cg['name']) ?> (<?= (int) ($cg['contact_count'] ?? 0) ?> contacts)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-5">
                            <button type="button" class="btn btn-outline-primary btn-sm w-100 d-inline-flex align-items-center justify-content-center gap-1 fw-semibold"
                                    id="btnLoadGroupIntoRecipients" style="height: 36px; font-size: 0.82rem;">
                                <i class="fas fa-cloud-arrow-down"></i>
                                <span>Load Contacts into Custom List</span>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Campaign & Subject Fields (Compact 2-Column Row) -->
                <div class="pt-3 border-top mb-3" style="border-color: #e2e8f0 !important;">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <label class="form-label text-uppercase fw-bold text-muted mb-0" style="font-size: 0.72rem; letter-spacing: 0.6px;">
                            Step 2 · Message Details
                        </label>
                        <!-- Mode Toggle: Campaign vs Template -->
                        <div class="btn-group btn-group-sm p-0.5 rounded-pill border" role="group" id="bulkModeToggleGroup" style="background: #f1f5f9; border-color: #e2e8f0 !important;">
                            <input type="radio" class="btn-check" name="step2_type" id="typeCampaign" value="campaign" checked autocomplete="off">
                            <label class="btn btn-xs rounded-pill px-3 py-1 fw-semibold text-secondary" for="typeCampaign" style="cursor: pointer; font-size: 0.74rem;">
                                <i class="fas fa-bullhorn me-1 text-primary"></i> Campaign
                            </label>
                            <input type="radio" class="btn-check" name="step2_type" id="typeTemplate" value="template" autocomplete="off">
                            <label class="btn btn-xs rounded-pill px-3 py-1 fw-semibold text-secondary" for="typeTemplate" style="cursor: pointer; font-size: 0.74rem;">
                                <i class="fas fa-file-code me-1 text-info"></i> Template
                            </label>
                        </div>
                    </div>

                    <div class="row g-3">
                        <div class="col-md-4">
                            <!-- Campaign Dropdown Wrapper -->
                            <div id="bulkCampaignWrapper">
                                <label class="form-label small fw-bold text-dark mb-1" for="bulkCampaign">
                                    Campaign Name <span class="text-muted fw-normal" style="font-size: 0.75rem;">(Optional)</span>
                                </label>
                                <?php 
                                    $selectedCampaign = (string) (old('campaign_name') ?? $defaultCampaign ?? '');
                                    $isKnownCampaign = $selectedCampaign === '' || $selectedCampaign === 'app-direct' 
                                        || in_array($selectedCampaign, array_column($emailCampaigns ?? [], 'name'), true);
                                ?>
                                <select class="form-select form-select-sm" id="bulkCampaign" name="campaign_name"
                                        style="height: 38px; border-color: #cbd5e1; font-size: 0.85rem;">
                                    <option value="">— Select Campaign (Optional) —</option>
                                    <option value="app-direct" <?= ($selectedCampaign === 'app-direct') ? 'selected' : '' ?>>
                                        app-direct (Default)
                                    </option>
                                    <?php if (!empty($emailCampaigns)): ?>
                                        <optgroup label="Email Campaigns">
                                            <?php foreach ($emailCampaigns as $ec): ?>
                                                <?php 
                                                    $ecName = (string) ($ec['name'] ?? '');
                                                    if ($ecName === '' || $ecName === 'app-direct') continue;
                                                ?>
                                                <option value="<?= esc($ecName) ?>" <?= ($selectedCampaign === $ecName) ? 'selected' : '' ?>>
                                                    <?= esc($ecName) ?><?= !empty($ec['status']) ? ' (' . esc(ucfirst($ec['status'])) . ')' : '' ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </optgroup>
                                    <?php endif; ?>
                                    <option value="__custom__" <?= (! $isKnownCampaign && $selectedCampaign !== '') ? 'selected' : '' ?>>
                                        + Enter Custom Campaign...
                                    </option>
                                </select>
                                <input type="text" class="form-control form-control-sm mt-1.5 <?= (! $isKnownCampaign && $selectedCampaign !== '') ? '' : 'd-none' ?>" 
                                       id="bulkCampaignCustom" 
                                       placeholder="Type custom campaign name..."
                                       value="<?= (! $isKnownCampaign) ? esc($selectedCampaign) : '' ?>"
                                       style="height: 34px; border-color: #cbd5e1; font-size: 0.85rem;">
                            </div>

                            <!-- Template Dropdown Wrapper -->
                            <div id="bulkTemplateWrapper" class="d-none">
                                <label class="form-label small fw-bold text-dark mb-1" for="bulkTemplateSelect">
                                    Email Template <span class="text-muted fw-normal" style="font-size: 0.75rem;">(Select to Load)</span>
                                </label>
                                <select class="form-select form-select-sm" id="bulkTemplateSelect"
                                        style="height: 38px; border-color: #cbd5e1; font-size: 0.85rem;">
                                    <option value="">— Select Template (Optional) —</option>
                                    <?php if (!empty($emailTemplates)): ?>
                                        <?php foreach ($emailTemplates as $tpl): ?>
                                            <option value="<?= (int) $tpl['id'] ?>"
                                                    data-name="<?= esc($tpl['name']) ?>"
                                                    data-subject="<?= esc($tpl['subject'] ?? '') ?>"
                                                    data-content="<?= esc($tpl['html_content'] ?? '', 'attr') ?>">
                                                <?= esc($tpl['name']) ?><?= !empty($tpl['subject']) ? ' (' . esc($tpl['subject']) . ')' : '' ?>
                                            </option>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <option value="" disabled>No templates created yet</option>
                                    <?php endif; ?>
                                </select>
                                <div class="form-text text-muted" style="font-size: 0.72rem;">
                                    Selecting loads template subject &amp; body below.
                                </div>
                            </div>
                        </div>
                        <div class="col-md-8">
                            <label class="form-label small fw-bold text-dark mb-1" for="bulkSubject">
                                Subject Line <span class="text-danger">*</span>
                            </label>
                            <input type="text" class="form-control form-control-sm" id="bulkSubject" name="subject" required maxlength="250"
                                   style="height: 38px; border-color: #cbd5e1; font-size: 0.88rem;"
                                   value="<?= esc(old('subject') ?? '') ?>" placeholder="e.g. Special Announcement for Our Valued Clients">
                        </div>
                    </div>
                </div>

                <!-- Message Body with Clean Tag Inserts & Switch -->
                <div class="mb-2">
                    <div class="d-flex flex-wrap justify-content-between align-items-center mb-2 gap-2">
                        <div class="d-flex align-items-center gap-2">
                            <label class="form-label small fw-bold text-dark mb-0" for="bulkBody">
                                Message Body <span class="text-danger">*</span>
                            </label>
                        </div>
                    </div>

                    <textarea class="form-control font-monospace border rounded-3 p-3" id="bulkBody" name="body" rows="7" required data-email-editor data-email-subject="#bulkSubject"
                              style="min-height: 190px; font-size: 0.85rem; background: #ffffff; border-color: #cbd5e1; line-height: 1.55;"
                              placeholder="Write your email body or HTML layout here…"><?= esc(old('body') ?? '') ?></textarea>
                </div>

                <!-- Attachment Field -->
                <div class="mt-3 p-3 rounded-3 bg-light border" style="border-color: #cbd5e1 !important;">
                    <label class="form-label small fw-bold text-dark mb-1" for="bulkAttachment">
                        <i class="fas fa-paperclip text-primary me-1"></i> Attach Document / Media 
                        <span class="text-muted fw-normal small">(Optional — PDF, JPG, PNG, WEBP, XLSX, CSV, DOCX — Max 5MB)</span>
                    </label>
                    <div class="input-group">
                        <input type="file" class="form-control form-control-sm" id="bulkAttachment" name="attachment" 
                               accept=".pdf,.jpg,.jpeg,.png,.webp,.xlsx,.csv,.docx" style="font-size: 0.85rem;">
                        <button class="btn btn-outline-secondary btn-sm d-none" type="button" id="btnClearBulkAttachment" title="Clear file">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                    <div class="form-text text-muted small mt-1" style="font-size: 0.74rem;">
                        File will be safely uploaded and attached to every email delivered in this bulk send.
                    </div>
                </div>
            </div>

            <!-- Card Footer: Clean Action Bar with Aligned Buttons -->
            <div class="px-4 py-3 bg-light border-top d-flex flex-wrap justify-content-between align-items-center gap-2" style="border-color: #e2e8f0 !important; background: #f8fafc !important;">
                <div class="d-flex align-items-center gap-2">
                    <a href="<?= site_url('emails') ?>" class="btn btn-sm btn-outline-secondary px-3" style="font-size: 0.82rem;">Cancel</a>
                    <button type="reset" class="btn btn-sm btn-link text-muted px-2 text-decoration-none" style="font-size: 0.82rem;">Reset Form</button>
                </div>
                <button type="submit" class="btn btn-primary btn-sm px-4 py-2 fw-semibold shadow-sm d-inline-flex align-items-center gap-2" id="btnSendBulk" style="font-size: 0.85rem; border-radius: 6px;">
                    <i class="fas fa-paper-plane"></i>
                    <span>Send Bulk Email via <?= esc($providerLabel) ?></span>
                </button>
            </div>
        </form>

        <div id="emailBulkResult" class="px-4 pb-3"></div>
    </div>
</div>
<?= $this->endSection() ?>

<?= $this->section('styles') ?>
<link rel="stylesheet" href="<?= asset_url('assets/css/emails.css') ?>">
<style>
.audience-pill-bar {
    gap: 4px;
}
.audience-pill-btn {
    color: #64748b;
    border: 1px solid transparent;
    transition: all 0.15s ease-in-out;
    font-size: 0.82rem;
}
.audience-pill-btn:hover {
    color: #1e293b;
    background-color: rgba(255, 255, 255, 0.6);
}
.audience-pill-bar .btn-check:checked + .audience-pill-btn,
#bulkModeToggleGroup .btn-check:checked + label {
    background-color: #ffffff !important;
    color: #2563eb !important;
    border: 1px solid #cbd5e1 !important;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08) !important;
}
.bulk-contact-row {
    transition: background-color 0.12s ease;
}
.bulk-contact-row:hover {
    background-color: #f8fafc;
}
.bulk-contact-row.is-selected {
    background-color: #eff6ff;
}
.btn-xs {
    padding: 0.15rem 0.4rem;
    font-size: 0.72rem;
    line-height: 1.2;
    border-radius: 0.25rem;
}
.cursor-pointer {
    cursor: pointer;
}
</style>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<?= $this->include('partials/email_editor_assets') ?>
<script src="<?= asset_url('assets/js/emails.js') ?>"></script>
<script>
$(function () {
    // Update pasted email count in real time
    $('#bulkRecipients').on('input', function () {
        var text = $.trim($(this).val() || '');
        var count = text ? text.split(/[\s,;]+/).filter(Boolean).length : 0;
        $('#pastedCount').text(count > 0 ? count + ' email(s) detected' : '');
    }).trigger('input');
});
</script>
<?= $this->endSection() ?>
