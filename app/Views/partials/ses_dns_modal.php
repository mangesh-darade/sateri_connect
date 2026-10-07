<?php
/**
 * Amazon SES DNS records modal — rendered by public/assets/js/email-identities.js.
 *
 * @var bool $canManage
 */
$canManage = ! empty($canManage);
?>
<div class="modal fade" id="sesDnsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-globe me-2 text-primary"></i>DNS Records — <span id="sesDnsDomain"></span> <span class="badge ms-2" id="sesDnsStatus"></span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="row g-2 mb-3 small" id="sesDnsSummary"></div>
                <div class="alert alert-light border small py-2 mb-3">
                    <i class="fas fa-info-circle text-primary me-1"></i> Add each <strong>Required</strong> record at your DNS provider (GoDaddy, Cloudflare, Route 53…). Use <strong>Host</strong> if your provider adds the domain automatically. In Cloudflare keep CNAMEs as <em>DNS only</em> (grey cloud).
                </div>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-3">
                        <thead class="table-light">
                            <tr>
                                <th>Purpose</th>
                                <th>Type</th>
                                <th>Host / Name</th>
                                <th>Value</th>
                                <th>Required</th>
                                <th>In DNS</th>
                            </tr>
                        </thead>
                        <tbody id="sesDnsRows"></tbody>
                    </table>
                </div>
                <ul class="small text-secondary mb-0" id="sesDnsSteps"></ul>
            </div>
            <div class="modal-footer">
                <span class="small text-muted me-auto" id="sesDnsChecked"></span>
                <?php if ($canManage): ?>
                <button type="button" class="btn btn-outline-success btn-sm" id="sesDnsRecheck"><i class="fas fa-sync-alt me-1"></i> Check Verification</button>
                <?php endif; ?>
                <button type="button" class="btn btn-light border btn-sm" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>
