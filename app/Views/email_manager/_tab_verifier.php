<?php
/** @var list<array<string,mixed>> $verifications */
$verifications = $verifications ?? [];
?>
<div class="row g-3">
    <!-- Input Form -->
    <div class="col-lg-5">
        <div class="card border-0 shadow-sm rounded-3">
            <div class="card-header bg-white py-3 border-bottom d-flex align-items-center justify-content-between">
                <h6 class="mb-0 fw-bold text-dark"><i class="fas fa-shield-alt text-primary me-2"></i>Email List Verifier</h6>
                <span class="badge bg-success-subtle text-success" style="font-size: 0.7rem;">Reputation Shield</span>
            </div>
            <div class="card-body p-3">
                <div class="alert alert-info py-2 small mb-3 border-0 bg-info-subtle text-info-emphasis">
                    <i class="fas fa-info-circle me-1"></i> <strong>Why verify?</strong> Checks syntax, live domain MX records, and detects disposable burner inboxes. Keeps your Amazon SES bounce rate below <strong>2%</strong> to prevent ISP blacklisting.
                </div>
                
                <form id="verifyForm" class="em-form">
                    <div class="mb-2">
                        <label class="form-label small fw-semibold mb-1">Enter Email Addresses (Max 50 at once)</label>
                        <textarea name="emails" id="verify_emails" class="form-control form-control-sm font-monospace" rows="8" placeholder="alex@company.com&#10;sales@target.in&#10;contact@domain.org" required></textarea>
                        <div class="form-text small text-muted">Separate multiple emails with new lines, commas, or spaces.</div>
                    </div>
                    
                    <button type="submit" class="btn btn-primary btn-sm px-3 mt-2">
                        <i class="fas fa-check-circle me-1"></i> Run Instant Verification
                    </button>
                    <div class="em-msg mt-2 small" id="verifyMsg"></div>
                </form>
            </div>
        </div>
    </div>

    <!-- Verification Results Table -->
    <div class="col-lg-7">
        <div class="card border-0 shadow-sm rounded-3">
            <div class="card-header bg-white py-3 border-bottom d-flex align-items-center justify-content-between">
                <h6 class="mb-0 fw-bold text-dark"><i class="fas fa-clipboard-check text-primary me-2"></i>Verification Results</h6>
                <span class="badge bg-secondary-subtle text-secondary" style="font-size: 0.7rem;">Live Inspection</span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0 em-table" id="verifyTable">
                        <thead>
                            <tr>
                                <th>Email Address</th>
                                <th>Status</th>
                                <th class="text-center">Syntax</th>
                                <th class="text-center">MX Record</th>
                                <th class="text-center">Disposable</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if ($verifications === []): ?>
                            <tr class="em-empty-row">
                                <td colspan="5" class="text-muted text-center py-5">
                                    <i class="fas fa-search fa-2x mb-2 text-muted opacity-25 d-block"></i>
                                    No email verifications run yet in this session.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($verifications as $v): ?>
                            <tr>
                                <td class="fw-medium font-monospace small text-dark"><?= esc($v['email']) ?></td>
                                <td>
                                    <?php
                                    $st = $v['status'] ?? 'unknown';
                                    $badge = match($st) {
                                        'valid' => 'bg-success-subtle text-success border border-success-subtle',
                                        'risky' => 'bg-warning-subtle text-warning border border-warning-subtle',
                                        'invalid' => 'bg-danger-subtle text-danger border border-danger-subtle',
                                        default => 'bg-secondary-subtle text-secondary',
                                    };
                                    ?>
                                    <span class="badge <?= $badge ?> text-uppercase" style="font-size: 0.7rem;"><?= esc($st) ?></span>
                                </td>
                                <td class="text-center">
                                    <?= ! empty($v['syntax_ok']) 
                                        ? '<i class="fas fa-check text-success"></i>' 
                                        : '<i class="fas fa-times text-danger"></i>' ?>
                                </td>
                                <td class="text-center">
                                    <?= ! empty($v['mx_ok']) 
                                        ? '<i class="fas fa-check text-success"></i>' 
                                        : '<i class="fas fa-times text-danger"></i>' ?>
                                </td>
                                <td class="text-center">
                                    <?= ! empty($v['disposable']) 
                                        ? '<span class="badge bg-danger-subtle text-danger">Yes (Burner)</span>' 
                                        : '<span class="badge bg-light text-muted border">No</span>' ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
