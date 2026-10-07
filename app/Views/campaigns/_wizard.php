<?php
/** Multi-step New Campaign wizard (WhatsApp + Email). */
?>
<div class="modal fade campaign-wizard" id="campaignWizardModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable" id="campaignWizardDialog">
        <div class="modal-content">
            <div class="modal-header border-0 pb-0">
                <div class="d-flex align-items-center gap-2">
                    <button type="button" class="btn btn-link text-decoration-none p-0 d-none" id="cwBackBtn" title="Back">
                        <i class="fas fa-arrow-left"></i>
                    </button>
                    <h5 class="modal-title mb-0" id="cwTitle">New Campaign</h5>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <a href="<?= site_url('templates') ?>" class="btn btn-outline-secondary btn-sm rounded-pill d-none" id="cwHelpSyncLink" target="_blank">Help guide</a>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
            </div>
            <div class="modal-body pt-2">
                <div class="alert alert-danger py-2 px-3 small d-none" id="cwFormError" role="alert"></div>
                <input type="hidden" id="cwChannel" value="whatsapp">
                <input type="hidden" id="cwCampaignId" value="">
                <input type="hidden" id="cwStep" value="1">

                <!-- Step 1: Name + Label -->
                <div class="campaign-wizard-step is-active" data-step="1">
                    <div class="mb-3">
                        <div class="d-flex justify-content-between align-items-center">
                            <label class="form-label fw-semibold" for="cwName">Name Campaign</label>
                            <span class="small text-muted"><span id="cwNameCount">0</span>/30</span>
                        </div>
                        <input type="text" id="cwName" class="form-control" maxlength="30" placeholder="Enter here" autocomplete="off">
                        <div class="invalid-feedback" id="cwNameError">Enter a campaign name (max 30 characters).</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold" for="cwLabel">Select a Label</label>
                        <select id="cwLabel" class="form-select">
                            <option value="">Select segment</option>
                        </select>
                        <div class="invalid-feedback" id="cwLabelError">Select a label / segment.</div>
                        <div class="form-text">Labels are customer groups (tags) used as campaign audience.</div>
                    </div>
                    <div class="d-flex flex-wrap gap-2">
                        <button type="button" class="btn btn-outline-secondary btn-sm rounded-pill" id="cwCreateLabelBtn">
                            <i class="fas fa-upload me-1"></i> Upload New Label
                        </button>
                        <a href="<?= site_url('contacts') ?>" class="btn btn-link btn-sm" target="_blank">Manage contacts</a>
                    </div>
                    <div class="border rounded-3 p-3 mt-3 d-none" id="cwNewLabelWrap">
                        <label class="form-label">New label name</label>
                        <div class="input-group input-group-sm">
                            <input type="text" id="cwNewLabelName" class="form-control" maxlength="100" placeholder="e.g. Expo Leads">
                            <button type="button" class="btn btn-wa" id="cwSaveLabelBtn">Create</button>
                        </div>
                    </div>
                </div>

                <!-- Step 2: Attributes -->
                <div class="campaign-wizard-step" data-step="2">
                    <div class="mb-2">
                        <label class="form-label fw-semibold mb-1">Selected label</label>
                        <div><span class="cw-label-chip" id="cwSelectedLabelChip">—</span></div>
                        <div class="form-text">To filter data from existing label you can add an attribute based on label you have selected.</div>
                    </div>
                    <div class="cw-audience-stats-wrap mb-3 p-2 bg-light rounded-3 border" id="cwAudienceStatsBox">
                        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-1">
                            <span class="small fw-semibold text-secondary">
                                <i class="fas fa-users-viewfinder me-1"></i> Audience Breakdown:
                            </span>
                            <button type="button" class="btn btn-sm btn-link text-decoration-none py-0 px-1 text-primary fw-medium small" id="cwViewAudienceDetailsBtn">
                                <i class="fas fa-list-ul me-1"></i> View Full List
                            </button>
                        </div>
                        <div class="d-flex flex-wrap gap-2" id="cwAudiencePills">
                            <button type="button" class="btn btn-sm btn-outline-secondary cw-filter-chip active py-1 px-2" data-tab="all" title="Click to view all fetched contacts">
                                <i class="fas fa-users text-primary me-1"></i> Total: <strong id="cwTotalCount">0</strong>
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-secondary cw-filter-chip py-1 px-2" data-tab="phone" title="Click to view contacts with phone numbers">
                                <i class="fas fa-phone-alt text-info me-1"></i> Phone: <strong id="cwPhoneCount">0</strong>
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-secondary cw-filter-chip py-1 px-2" data-tab="email" title="Click to view contacts with email">
                                <i class="far fa-envelope text-secondary me-1"></i> Email: <strong id="cwEmailCount">0</strong>
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-success cw-filter-chip py-1 px-2" data-tab="eligible" id="cwEligibleChip" title="Click to view WhatsApp eligible contacts">
                                <i class="fab fa-whatsapp text-success me-1"></i> Eligible: <strong id="cwEligibleCount">0</strong>
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-warning cw-filter-chip py-1 px-2 d-none" data-tab="skipped" id="cwSkippedChip" title="Click to view skipped contacts & reasons">
                                <i class="fas fa-user-slash text-warning me-1"></i> Skipped: <strong id="cwSkippedCount">0</strong>
                            </button>
                        </div>
                        <div class="small text-muted mt-1" id="cwAudienceExcludedNote" style="font-size:0.75rem;"></div>
                        <div class="d-none" id="cwAudienceCounts"></div>
                    </div>
                    <div id="cwAttrRows"></div>
                    <div class="d-flex flex-wrap gap-2 mt-2">
                        <button type="button" class="btn btn-outline-secondary btn-sm rounded-pill" id="cwAddAttrBtn">Add attribute</button>
                        <button type="button" class="btn btn-secondary btn-sm rounded-pill" id="cwVerifyAttrBtn">Verify attribute</button>
                    </div>
                </div>

                <!-- Step 3: Choose template -->
                <div class="campaign-wizard-step" data-step="3">
                    <div class="alert alert-danger border-0 py-2 px-3 small d-flex justify-content-between align-items-center gap-2" id="cwTplSyncAlert">
                        <span>Make sure to double-check your template category before launching a campaign.</span>
                        <button type="button" class="btn btn-sm btn-outline-danger" id="cwSyncTemplatesBtn">SYNC</button>
                    </div>
                    <div class="mb-2" id="cwTemplateSearchWrap">
                        <input type="search" id="cwTemplateSearch" class="form-control form-control-sm" placeholder="Search for templates">
                    </div>
                    <div class="cw-template-grid" id="cwTemplateGrid"></div>
                    <div class="d-none" id="cwEmailTemplateWrap">
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Email builder / template</label>
                            <select id="cwEmailBuilder" class="form-select">
                                <option value="">Custom HTML</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Subject</label>
                            <input type="text" id="cwEmailSubject" class="form-control" maxlength="255" placeholder="Email subject">
                            <div class="invalid-feedback">Email subject is required.</div>
                        </div>
                        <div class="mb-0">
                            <label class="form-label fw-semibold">Email content</label>
                            <textarea id="cwEmailHtml" class="form-control font-monospace" rows="8" data-email-editor placeholder="<p>Hello…</p>"></textarea>
                        </div>
                    </div>
                </div>

                <!-- Step 4: Media / details + preview -->
                <div class="campaign-wizard-step" data-step="4">
                    <div class="row g-3">
                        <div class="col-md-6" id="cwMediaCol">
                            <label class="form-label fw-semibold">Upload file</label>
                            <div class="cw-upload-box" id="cwUploadBox" role="button" tabindex="0" aria-label="Upload media by click or drag and drop">
                                <i class="fas fa-cloud-upload-alt fa-2x text-muted mb-2"></i>
                                <div class="fw-semibold" id="cwUploadTitle">Upload media here</div>
                                <div class="small text-muted" id="cwUploadHint">Drag & drop or click to choose file</div>
                                <div class="small text-muted mt-1">Max size: 16MB · PNG, JPEG, MP4, PDF</div>
                                <button type="button" class="btn btn-outline-secondary btn-sm mt-3" id="cwChooseFileBtn">Choose File</button>
                                <input type="file" id="cwMediaFile" class="d-none" accept="image/png,image/jpeg,image/jpg,image/webp,video/mp4,application/pdf">
                                <input type="url" id="cwMediaUrl" class="form-control form-control-sm mt-2" placeholder="Or paste media URL">
                                <div class="invalid-feedback d-block d-none" id="cwMediaUrlError">Upload or paste a valid media URL for this template header.</div>
                                <div class="small text-muted mt-2 d-none" id="cwMediaStatus"></div>
                            </div>
                            <div class="mt-3" id="cwVarMapWrap">
                                <label class="form-label fw-semibold">Variable mapping</label>
                                <div id="cwVariableMap"></div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Preview</label>
                            <div class="cw-phone-preview" id="cwPreview">
                                <div class="d-flex align-items-center gap-2 mb-2">
                                    <i class="fab fa-whatsapp text-success"></i>
                                    <strong id="cwPreviewChannelLabel">WhatsApp</strong>
                                </div>
                                <div class="cw-bubble" id="cwPreviewBody">Select a template to preview.</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Step 5: Share / run -->
                <div class="campaign-wizard-step" data-step="5">
                    <div class="mb-3">
                        <div class="text-muted small mb-1">Sharing to:</div>
                        <span class="cw-label-chip" id="cwShareLabelChip">—</span>
                    </div>
                    <div class="mb-3">
                        <div class="text-muted small mb-1">Audience</div>
                        <div id="cwShareCounts" class="fw-semibold">0 contacts</div>
                    </div>
                    <div class="mb-3">
                        <div class="text-muted small mb-1">Template used</div>
                        <div class="border rounded-3 p-3" id="cwShareTemplateCard">
                            <div class="fw-semibold" id="cwShareTplName">—</div>
                            <div class="small text-muted mt-1" id="cwShareTplBody"></div>
                        </div>
                    </div>
                    <div class="border rounded-3 p-3 d-none" id="cwScheduleWrap">
                        <label class="form-label fw-semibold" for="cwScheduledAt">Schedule for</label>
                        <input type="datetime-local" id="cwScheduledAt" class="form-control">
                        <div class="form-text">Uses app timezone: <?= esc(settings_timezone()) ?></div>
                        <div class="invalid-feedback">Pick a future date and time.</div>
                    </div>
                </div>
            </div>
            <div class="modal-footer border-0 pt-0">
                <div class="ms-auto d-flex flex-wrap gap-2" id="cwFooterActions">
                    <button type="button" class="btn btn-wa px-4" id="cwNextBtn">Next</button>
                    <button type="button" class="btn btn-outline-secondary d-none" id="cwScheduleBtn">
                        <i class="fas fa-clock me-1"></i> Schedule Campaign
                    </button>
                    <button type="button" class="btn btn-wa d-none" id="cwRunBtn">
                        <i class="fas fa-paper-plane me-1"></i> Run Campaign
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal: Audience Breakdown & Contact List Modal -->
<div class="modal fade" id="cwAudienceModal" tabindex="-1" aria-labelledby="cwAudienceModalLabel" aria-hidden="true" style="z-index: 1065;">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content shadow border-0">
            <div class="modal-header py-2 px-3 border-bottom bg-light">
                <div class="d-flex align-items-center gap-2">
                    <span class="badge bg-success-subtle text-success p-2 rounded-circle">
                        <i class="fas fa-users"></i>
                    </span>
                    <div>
                        <h5 class="modal-title h6 fw-bold mb-0" id="cwAudienceModalLabel">Audience Contact List</h5>
                        <span class="text-muted small" id="cwAudienceModalSub">Review eligible and skipped contacts before launching</span>
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-3">
                <!-- Search & Filter Tabs -->
                <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
                    <div class="btn-group btn-group-sm" role="group" id="cwAudienceModalFilterTabs">
                        <button type="button" class="btn btn-outline-secondary active" data-tab="all">
                            All (<span class="tab-count-all">0</span>)
                        </button>
                        <button type="button" class="btn btn-outline-success" data-tab="eligible">
                            <i class="fab fa-whatsapp me-1"></i> Eligible (<span class="tab-count-eligible">0</span>)
                        </button>
                        <button type="button" class="btn btn-outline-warning" data-tab="skipped">
                            <i class="fas fa-exclamation-triangle me-1"></i> Skipped (<span class="tab-count-skipped">0</span>)
                        </button>
                        <button type="button" class="btn btn-outline-secondary" data-tab="phone">
                            Phone (<span class="tab-count-phone">0</span>)
                        </button>
                    </div>
                    <div class="input-group input-group-sm" style="max-width: 220px;">
                        <span class="input-group-text bg-white border-end-0"><i class="fas fa-search text-muted"></i></span>
                        <input type="search" id="cwAudienceSearchInput" class="form-control border-start-0" placeholder="Search contacts...">
                    </div>
                </div>

                <!-- Table of Contacts -->
                <div class="table-responsive border rounded" style="max-height: 380px;">
                    <table class="table table-sm table-hover align-middle mb-0" id="cwAudienceTable">
                        <thead class="table-light sticky-top">
                            <tr>
                                <th style="width: 32px;">#</th>
                                <th>Contact Name</th>
                                <th>Mobile</th>
                                <th>Email</th>
                                <th>Status</th>
                                <th>Reason / Eligibility Note</th>
                            </tr>
                        </thead>
                        <tbody id="cwAudienceTableBody">
                            <tr>
                                <td colspan="6" class="text-center text-muted py-4">No contacts loaded yet.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer py-2 px-3 bg-light d-flex justify-content-between">
                <span class="small text-muted" id="cwAudienceSummaryText">0 contacts</span>
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>
