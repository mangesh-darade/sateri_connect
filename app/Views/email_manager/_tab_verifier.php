<?php
/** @var list<array<string,mixed>> $verifications */
$verifications = $verifications ?? [];
?>
<div class="row g-3">
    <div class="col-lg-5">
        <div class="dash-panel">
            <div class="panel-head"><h3>Verify emails</h3></div>
            <div class="panel-body">
                <p class="text-muted small">Syntax + MX + disposable checks (server-side). Paste up to 50 addresses.</p>
                <form id="verifyForm" class="em-form">
                    <textarea name="emails" id="verify_emails" class="form-control form-control-sm font-monospace" rows="8" placeholder="one@example.com&#10;two@example.com" required></textarea>
                    <button type="submit" class="btn btn-wa btn-sm mt-2">Run verifier</button>
                    <div class="em-msg mt-2 small" id="verifyMsg"></div>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="dash-panel">
            <div class="panel-head"><h3>Recent results</h3></div>
            <div class="panel-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0 em-table" id="verifyTable">
                        <thead><tr><th>Email</th><th>Status</th><th>Syntax</th><th>MX</th><th>Disposable</th></tr></thead>
                        <tbody>
                        <?php if ($verifications === []): ?>
                            <tr class="em-empty-row"><td colspan="5" class="text-muted text-center py-3">No verifications yet.</td></tr>
                        <?php else: ?>
                            <?php foreach ($verifications as $v): ?>
                            <tr>
                                <td><?= esc($v['email']) ?></td>
                                <td><span class="badge em-status-<?= esc($v['status']) ?>"><?= esc($v['status']) ?></span></td>
                                <td><?= ! empty($v['syntax_ok']) ? '✓' : '✗' ?></td>
                                <td><?= ! empty($v['mx_ok']) ? '✓' : '✗' ?></td>
                                <td><?= ! empty($v['disposable']) ? 'yes' : 'no' ?></td>
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
