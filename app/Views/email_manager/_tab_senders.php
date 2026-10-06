<?php
/** @var list<array<string,mixed>> $senders */
/** @var bool $canSend */
/** @var bool $isCheerio */
$senders = $senders ?? [];
$canSend = ! empty($canSend);
$isCheerio = ! empty($isCheerio);
?>
<div class="row g-3">
    <!-- Sender Identity Form -->
    <div class="col-lg-5">
        <div class="card border-0 shadow-sm rounded-3">
            <div class="card-header bg-white py-3 border-bottom d-flex align-items-center justify-content-between">
                <h6 class="mb-0 fw-bold text-dark"><i class="fas fa-id-badge text-primary me-2"></i>Sender &amp; Domain Identity</h6>
                <span class="badge bg-primary-subtle text-primary" style="font-size: 0.7rem;">Authentication</span>
            </div>
            <div class="card-body p-3">
                <div class="alert alert-light border py-2 small mb-3 text-secondary">
                    <i class="fas fa-info-circle text-primary me-1"></i> <strong>From Identity:</strong> Configure verified sender emails or custom domains. Helps establish DKIM / SPF trust so emails land in inboxes, not spam.
                </div>

                <?php if ($isCheerio): ?>
                <div class="alert alert-warning py-2 small mb-3 border-0 bg-warning-subtle text-warning-emphasis">
                    <i class="fas fa-shield-alt me-1"></i> Cheerio delivery requires a verified <strong>Sender ID</strong> or <strong>Domain</strong> in your Cheerio dashboard.
                </div>
                <?php endif; ?>

                <?php if (! $canSend): ?>
                    <div class="alert alert-warning py-2 small mb-0">Permission <code>emails.send</code> is required to manage senders.</div>
                <?php else: ?>
                <form id="senderForm" class="em-form">
                    <input type="hidden" name="id" id="sender_id" value="">
                    
                    <div class="mb-2">
                        <label class="form-label small fw-semibold mb-1">Identity Type</label>
                        <select name="type" id="sender_type" class="form-select form-select-sm">
                            <option value="sender">Single Sender Email Address</option>
                            <option value="domain">Full Domain (DKIM/SPF Authenticated)</option>
                        </select>
                    </div>

                    <div class="mb-2">
                        <label class="form-label small fw-semibold mb-1">Display Name / Label</label>
                        <input type="text" name="name" id="sender_name" class="form-control form-control-sm" placeholder="e.g. Sateri Support, Updates" required>
                    </div>

                    <div class="mb-2" id="senderEmailWrap">
                        <label class="form-label small fw-semibold mb-1">From Email Address</label>
                        <input type="email" name="email" id="sender_email" class="form-control form-control-sm" placeholder="e.g. hello@yourbrand.com">
                    </div>

                    <div class="mb-2 d-none" id="senderDomainWrap">
                        <label class="form-label small fw-semibold mb-1">Domain Name</label>
                        <input type="text" name="domain" id="sender_domain" class="form-control form-control-sm" placeholder="e.g. yourbrand.com">
                    </div>

                    <?php if (! empty($isCheerio)): ?>
                    <div class="mb-2">
                        <label class="form-label small fw-semibold mb-1">Cheerio External ID <span class="text-muted fw-normal">(Optional)</span></label>
                        <input type="text" name="cheerio_id" id="sender_cheerio_id" class="form-control form-control-sm" placeholder="Cheerio dashboard identity ID">
                    </div>
                    <?php endif; ?>

                    <div class="mb-2">
                        <label class="form-label small fw-semibold mb-1">Verification Status</label>
                        <select name="status" id="sender_status" class="form-select form-select-sm">
                            <option value="pending">Pending Verification</option>
                            <option value="verified">Verified &amp; Active</option>
                            <option value="failed">Failed / Rejected</option>
                            <option value="disabled">Disabled</option>
                        </select>
                    </div>

                    <div class="mb-2">
                        <label class="form-label small fw-semibold mb-1">DNS Notes <span class="text-muted fw-normal">(Optional)</span></label>
                        <textarea name="notes" id="sender_notes" class="form-control form-control-sm" rows="2" placeholder="Record TXT/CNAME or SPF/DKIM verification notes"></textarea>
                    </div>

                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" name="is_default" id="sender_default" value="1">
                        <label class="form-check-label small fw-semibold" for="sender_default">Set as default sender identity</label>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary btn-sm px-3"><i class="fas fa-save me-1"></i> Save Identity</button>
                        <button type="button" class="btn btn-light border btn-sm px-3" id="senderReset"><i class="fas fa-undo me-1"></i> Reset</button>
                    </div>
                    <div class="em-msg mt-2 small" id="senderMsg"></div>
                </form>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Senders List Table -->
    <div class="col-lg-7">
        <div class="card border-0 shadow-sm rounded-3">
            <div class="card-header bg-white py-3 border-bottom d-flex align-items-center justify-content-between">
                <h6 class="mb-0 fw-bold text-dark"><i class="fas fa-list-check text-primary me-2"></i>Configured Identities</h6>
                <span class="badge bg-secondary-subtle text-secondary" style="font-size: 0.7rem;"><?= count($senders) ?> Total</span>
            </div>
            <div class="card-body p-0">
                <?php if ($senders === []): ?>
                    <div class="text-center py-5">
                        <i class="fas fa-id-card fa-2x mb-2 text-muted opacity-25 d-block"></i>
                        <h6 class="text-muted fw-semibold mb-1">No sender identities registered</h6>
                        <p class="text-muted small mb-0">Add your brand's sender email or domain to dispatch campaigns.</p>
                    </div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0 em-table">
                        <thead>
                            <tr>
                                <th>Type</th>
                                <th>Identity Name</th>
                                <th>Email / Domain</th>
                                <th>Cheerio ID</th>
                                <th>Status</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($senders as $s): ?>
                            <tr data-sender='<?= esc(json_encode($s), 'attr') ?>'>
                                <td>
                                    <?php if (($s['type'] ?? '') === 'domain'): ?>
                                        <span class="badge bg-purple-subtle text-dark border"><i class="fas fa-globe me-1"></i>Domain</span>
                                    <?php else: ?>
                                        <span class="badge bg-blue-subtle text-primary border"><i class="fas fa-envelope me-1"></i>Sender</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="fw-semibold text-dark"><?= esc($s['name']) ?></span>
                                    <?php if (! empty($s['is_default'])): ?>
                                        <span class="badge bg-success text-white ms-1" style="font-size: 0.65rem;">Default</span>
                                    <?php endif; ?>
                                </td>
                                <td class="small font-monospace"><?= esc($s['type'] === 'domain' ? ($s['domain'] ?? '') : ($s['email'] ?? '')) ?></td>
                                <td><code class="small text-muted"><?= esc($s['cheerio_id'] ?? '—') ?></code></td>
                                <td>
                                    <?php
                                    $st = $s['status'] ?? 'pending';
                                    $stBadge = match($st) {
                                        'verified' => 'bg-success-subtle text-success border border-success-subtle',
                                        'failed'   => 'bg-danger-subtle text-danger border border-danger-subtle',
                                        'disabled' => 'bg-secondary-subtle text-secondary border border-secondary-subtle',
                                        default    => 'bg-warning-subtle text-warning border border-warning-subtle',
                                    };
                                    ?>
                                    <span class="badge <?= $stBadge ?> text-capitalize"><?= esc($st) ?></span>
                                </td>
                                <td class="text-end text-nowrap">
                                    <?php if ($canSend): ?>
                                        <button type="button" class="btn btn-xs btn-outline-primary em-edit-sender"><i class="fas fa-edit me-1"></i>Edit</button>
                                        <button type="button" class="btn btn-xs btn-outline-danger em-del-sender" data-id="<?= (int) $s['id'] ?>"><i class="fas fa-trash me-1"></i>Del</button>
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
