<?php
/**
 * Per-recipient status of one email send — filled by public/assets/js/email-recipients.js.
 * Open with any `.js-email-recipients[data-log-id]` button.
 */
?>
<div class="modal fade" id="emailRecipientsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <div class="min-w-0">
                    <h5 class="modal-title mb-0"><i class="fas fa-users me-2 text-primary"></i>Recipients — <span id="erSubject"></span></h5>
                    <div class="small text-muted" id="erMeta"></div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="d-flex flex-wrap gap-2 mb-3" id="erCounts"></div>
                <div class="alert alert-light border small py-2 mb-3 d-none" id="erNote"></div>
                <div class="d-flex flex-wrap gap-2 align-items-center mb-2">
                    <input type="search" class="form-control form-control-sm" id="erSearch" placeholder="Search email…" style="max-width: 260px;">
                    <select class="form-select form-select-sm" id="erFilter" style="max-width: 180px;">
                        <option value="">All statuses</option>
                        <option value="sent">Sent</option>
                        <option value="delivered">Delivered</option>
                        <option value="opened">Opened</option>
                        <option value="clicked">Clicked</option>
                        <option value="bounced">Bounced</option>
                        <option value="complaint">Spam complaint</option>
                        <option value="failed">Failed</option>
                        <option value="unknown">Unknown</option>
                    </select>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Email</th>
                                <th>Status</th>
                                <th>Details</th>
                                <th class="text-center">Opens</th>
                                <th class="text-center">Clicks</th>
                            </tr>
                        </thead>
                        <tbody id="erRows">
                            <tr><td colspan="5" class="text-center text-muted py-4">Loading…</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <span class="small text-muted me-auto"><i class="fas fa-info-circle me-1"></i>Bounces &amp; spam complaints appear once the Amazon SES bounce webhook is connected.</span>
                <button type="button" class="btn btn-light border btn-sm" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>
