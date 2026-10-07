<?php
/** @var list<array<string,mixed>> $campaigns */
/** @var list<array<string,mixed>> $builders */
/** @var list<array<string,mixed>> $customerGroups */
/** @var bool $canSend */
/** @var bool $isCheerio */
$campaigns = $campaigns ?? [];
$builders = $builders ?? [];
$customerGroups = $customerGroups ?? [];
$canSend = ! empty($canSend);
$isCheerio = ! empty($isCheerio);
?>
<div class="row g-3">
    <div class="col-lg-5">
        <div class="card border-0 shadow-sm rounded-3">
            <div class="card-header bg-white py-3 border-bottom d-flex align-items-center justify-content-between">
                <h6 class="mb-0 fw-bold text-dark"><i class="fas fa-paper-plane text-primary me-2"></i>Create Campaign Draft</h6>
                <span class="badge bg-primary-subtle text-primary" style="font-size: 0.7rem;">Broadcaster</span>
            </div>
            <div class="card-body p-3">
                <?php if (! $canSend): ?>
                    <div class="alert alert-warning py-2 small mb-0">Permission <code>emails.send</code> is required to create campaigns.</div>
                <?php else: ?>
                <form id="campaignForm" class="em-form">
                    <input type="hidden" name="id" id="camp_id" value="">
                    
                    <div class="mb-2">
                        <label class="form-label small fw-semibold mb-1">Campaign Name</label>
                        <input type="text" name="name" id="camp_name" class="form-control form-control-sm" placeholder="e.g. Diwali Offer 2026" required>
                    </div>

                    <div class="mb-2">
                        <label class="form-label small fw-semibold mb-1">Email Subject</label>
                        <input type="text" name="subject" id="camp_subject" class="form-control form-control-sm" placeholder="e.g. Special 30% Off on All Services" required>
                    </div>

                    <div class="mb-2">
                        <label class="form-label small fw-semibold mb-1">Select Template (Optional)</label>
                        <select name="builder_id" id="camp_builder" class="form-select form-select-sm">
                            <option value="">— Write Custom HTML —</option>
                            <?php foreach ($builders as $b): ?>
                                <option value="<?= (int) $b['id'] ?>"
                                    data-cheerio="<?= esc($b['cheerio_builder_id'] ?? '', 'attr') ?>">
                                    <?= esc($b['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <?php if ($isCheerio): ?>
                    <div class="mb-2">
                        <label class="form-label small fw-semibold mb-1">Cheerio Builder ID</label>
                        <input type="text" name="cheerio_builder_id" id="camp_cheerio_id" class="form-control form-control-sm">
                    </div>
                    <?php endif; ?>

                    <div class="mb-2">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <label class="form-label small fw-semibold mb-0">Email Content</label>
                            <span class="text-muted" style="font-size: 0.7rem;">Tags: <code>{{name}}</code> <code>{{unsubscribe_url}}</code></span>
                        </div>
                        <textarea name="html_content" id="camp_html" class="form-control form-control-sm font-monospace" rows="6" placeholder="<p>Hello {{name}}, welcome to our newsletter!</p>" data-email-editor data-editor-height="200"></textarea>
                    </div>

                    <div class="mb-2">
                        <label class="form-label small fw-semibold mb-1">Send Mode</label>
                        <select name="mode" id="camp_mode" class="form-select form-select-sm">
                            <option value="recipients">Direct Email Recipients List</option>
                            <?php if ($isCheerio): ?>
                            <option value="label">Cheerio Target Label</option>
                            <?php endif; ?>
                        </select>
                    </div>

                    <!-- Audience / Customer Groups Selector -->
                    <div class="mb-2 p-2 rounded-2 bg-light border" id="campAudienceGroupWrap">
                        <label class="form-label small fw-semibold mb-1 d-flex align-items-center justify-content-between">
                            <span><i class="fas fa-users text-primary me-1"></i> Import Customer Group</span>
                            <span class="badge bg-secondary-subtle text-secondary" style="font-size: 0.65rem;">Opt-in Verified</span>
                        </label>
                        <div class="input-group input-group-sm mb-1">
                            <select id="campGroupSelect" class="form-select form-select-sm">
                                <option value="">— Select Customer Group / Tag —</option>
                                <?php foreach ($customerGroups as $cg): ?>
                                    <option value="<?= (int) $cg['id'] ?>">
                                        <?= esc($cg['name']) ?> (<?= (int) ($cg['contact_count'] ?? 0) ?> contacts)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <button type="button" class="btn btn-primary btn-sm px-3" id="btnLoadGroupEmails">
                                <i class="fas fa-user-plus me-1"></i> Add
                            </button>
                        </div>
                        <div class="text-muted" id="groupSelectStatus" style="font-size: 0.72rem;">
                            Auto-loads active emails and filters unsubscribed recipients.
                        </div>
                    </div>

                    <div class="mb-2" id="campRecipientsWrap">
                        <label class="form-label small fw-semibold mb-1">Recipients (comma or line separated)</label>
                        <textarea name="recipients" id="camp_recipients" class="form-control form-control-sm" rows="3" placeholder="alex@example.com, john@example.com"></textarea>
                    </div>

                    <div class="mb-3 d-none" id="campLabelWrap">
                        <label class="form-label small fw-semibold mb-1">Cheerio Label Name</label>
                        <input type="text" name="label_name" id="camp_label" class="form-control form-control-sm">
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-semibold mb-1">
                            <i class="fas fa-paperclip text-primary me-1"></i> Attach Document / Media
                            <span class="text-muted fw-normal" style="font-size: 0.72rem;">(Optional — PDF, Media — Max 5MB)</span>
                        </label>
                        <input type="file" name="attachment" id="camp_attachment" class="form-control form-control-sm"
                               accept=".pdf,.jpg,.jpeg,.png,.webp,.xlsx,.csv,.docx">
                        <div id="camp_current_attachment" class="d-none mt-1.5 p-1.5 rounded-2 bg-light border small d-flex align-items-center justify-content-between">
                            <span class="text-truncate me-2"><i class="fas fa-file-pdf text-danger me-1"></i> <strong id="camp_att_name"></strong></span>
                            <button type="button" class="btn btn-xs btn-outline-danger" id="camp_remove_att_btn" title="Remove attachment">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                        <input type="hidden" name="remove_attachment" id="camp_remove_attachment" value="0">
                        <div class="form-text text-muted" style="font-size: 0.72rem;">If template has an attachment, it will be automatically attached if no custom file is uploaded.</div>
                    </div>

                    <div class="d-flex gap-2 mt-3">
                        <button type="submit" class="btn btn-primary btn-sm px-3"><i class="fas fa-save me-1"></i> Save Draft</button>
                        <button type="button" class="btn btn-light border btn-sm px-3" id="campReset"><i class="fas fa-undo me-1"></i> Reset</button>
                    </div>
                    <div class="em-msg mt-2 small" id="campMsg"></div>
                </form>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Campaigns List -->
    <div class="col-lg-7">
        <div class="card border-0 shadow-sm rounded-3">
            <div class="card-header bg-white py-3 border-bottom d-flex align-items-center justify-content-between">
                <div>
                    <h6 class="mb-0 fw-bold text-dark"><i class="fas fa-history text-primary me-2"></i>Campaign Broadcasts</h6>
                    <small class="text-muted">Broadcast logs and delivery status</small>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <span class="badge bg-secondary-subtle text-secondary"><?= count($campaigns) ?> campaigns</span>
                    <a href="<?= site_url('campaigns?channel=email') ?>" class="btn btn-outline-secondary btn-xs">
                        <i class="fas fa-external-link-alt me-1"></i> Broadcast Hub
                    </a>
                </div>
            </div>
            <div class="card-body p-0">
                <?php if ($campaigns === []): ?>
                    <div class="text-center py-5 text-muted">
                        <div class="mb-3 text-secondary opacity-50">
                            <i class="fas fa-bullhorn fa-3x"></i>
                        </div>
                        <h6 class="fw-semibold text-dark mb-1">No campaigns created yet</h6>
                        <p class="small text-muted mb-0">Compose and save your first campaign draft on the left.</p>
                    </div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle mb-0 em-table">
                        <thead class="table-light">
                            <tr>
                                <th>Campaign</th>
                                <th>Status</th>
                                <th>Sent / Failed</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($campaigns as $c): ?>
                            <?php
                            if (! is_array($c)) {
                                continue;
                            }
                            $campaignId = (int) ($c['id'] ?? 0);
                            $campaignName = (string) ($c['name'] ?? ('Campaign #' . $campaignId));
                            $campaignSubject = (string) ($c['subject'] ?? '');
                            $campaignStatus = (string) ($c['status'] ?? 'draft');
                            $stBadge = match ($campaignStatus) {
                                'sent', 'completed' => 'bg-success-subtle text-success border border-success-subtle',
                                'failed'            => 'bg-danger-subtle text-danger border border-danger-subtle',
                                'sending'           => 'bg-info-subtle text-info border border-info-subtle',
                                'paused'            => 'bg-warning-subtle text-warning-emphasis border border-warning-subtle',
                                default             => 'bg-secondary-subtle text-secondary border border-secondary-subtle',
                            };
                            $canRetryFailed = $campaignStatus === 'sent' && (int) ($c['failed_count'] ?? 0) > 0;
                            ?>
                            <tr>
                                <td>
                                    <span class="fw-semibold text-dark"><?= esc($campaignName) ?></span>
                                    <div class="text-muted small text-truncate" style="max-width: 200px;">
                                        <?= esc($campaignSubject !== '' ? $campaignSubject : 'No subject') ?>
                                    </div>
                                    <?php if (! empty($c['attachment_name'])): ?>
                                        <div class="mt-0.5">
                                            <span class="badge bg-light text-secondary border" style="font-size: 0.65rem;">
                                                <i class="fas fa-paperclip text-primary me-1"></i><?= esc($c['attachment_name']) ?>
                                            </span>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge <?= $stBadge ?> rounded-pill px-2 py-1">
                                        <?= esc($canRetryFailed ? 'Sent with errors' : ucfirst($campaignStatus)) ?>
                                    </span>
                                    <?php if (in_array($campaignStatus, ['paused', 'failed'], true) && ! empty($c['last_error'])): ?>
                                        <div class="text-muted small text-truncate" style="max-width: 220px;" title="<?= esc((string) $c['last_error'], 'attr') ?>">
                                            <?= esc((string) $c['last_error']) ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="text-success fw-semibold"><?= (int) ($c['sent_count'] ?? 0) ?></span>
                                    <span class="text-muted">/</span>
                                    <span class="text-danger"><?= (int) ($c['failed_count'] ?? 0) ?></span>
                                </td>
                                <td class="text-end text-nowrap">
                                    <a href="<?= site_url('campaigns/email/' . $campaignId) ?>" class="btn btn-xs btn-outline-secondary me-1" title="View details">
                                        <i class="fas fa-eye me-1"></i> View
                                    </a>
                                    <?php if ($canSend && $campaignId > 0 && in_array($campaignStatus, ['draft', 'failed', 'paused'], true)): ?>
                                        <button type="button" class="btn btn-xs btn-primary em-send-camp" data-id="<?= $campaignId ?>" title="<?= $campaignStatus === 'draft' ? 'Broadcast campaign now' : 'Send to recipients who have not received it yet' ?>">
                                            <i class="fas fa-paper-plane me-1"></i> <?= $campaignStatus === 'draft' ? 'Send' : ($campaignStatus === 'paused' ? 'Resume' : 'Retry') ?>
                                        </button>
                                    <?php elseif ($canSend && $campaignId > 0 && $canRetryFailed): ?>
                                        <button type="button" class="btn btn-xs btn-outline-primary em-send-camp" data-id="<?= $campaignId ?>" title="Send only to recipients that failed">
                                            <i class="fas fa-redo me-1"></i> Retry failed
                                        </button>
                                    <?php endif; ?>
                                    <?php if ($canSend && $campaignId > 0): ?>
                                        <button type="button" class="btn btn-xs btn-outline-danger em-del-camp ms-1" data-id="<?= $campaignId ?>" title="Delete">
                                            <i class="fas fa-trash-alt"></i>
                                        </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
