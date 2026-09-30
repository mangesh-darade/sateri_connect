<?php
/**
 * Team Inbox → Export chats / Import chats modals (used by chat-transfer.js).
 *
 * @var list<array<string, mixed>> $tags
 */
?>
<?php if (can('contacts.export')): ?>
<div class="modal fade" id="chatExportModal" tabindex="-1" aria-labelledby="chatExportTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" id="chatExportForm" autocomplete="off">
            <div class="modal-header">
                <h5 class="modal-title" id="chatExportTitle">Export / backup chats</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <label class="form-label fw-semibold small mb-1">What to download?</label>
                <div class="d-grid gap-1 mb-3">
                    <label class="form-check"><input class="form-check-input" type="radio" name="format" value="csv" checked> <span class="form-check-label">Chat history <span class="text-muted small">(CSV — opens in Excel, can be imported back)</span></span></label>
                    <label class="form-check"><input class="form-check-input" type="radio" name="format" value="backup"> <span class="form-check-label">Full backup <span class="text-muted small">(ZIP — chats, media files, contacts, groups, chat status &amp; notes)</span></span></label>
                </div>
                <label class="form-label fw-semibold small mb-1">Which chats?</label>
                <div class="d-grid gap-1 mb-3">
                    <label class="form-check"><input class="form-check-input" type="radio" name="scope" value="all" checked> <span class="form-check-label">All chats</span></label>
                    <label class="form-check"><input class="form-check-input" type="radio" name="scope" value="numbers"> <span class="form-check-label">Specific phone numbers</span></label>
                    <label class="form-check"><input class="form-check-input" type="radio" name="scope" value="group"> <span class="form-check-label">Customer group</span></label>
                    <label class="form-check"><input class="form-check-input" type="radio" name="scope" value="contact" id="chatExportScopeContact" disabled> <span class="form-check-label">Open chat <span class="text-muted small" id="chatExportContactName">(open a chat first)</span></span></label>
                </div>
                <div class="mb-3 d-none" data-scope-panel="numbers">
                    <textarea class="form-control" name="numbers" rows="4" placeholder="919876543210&#10;919812345678, 919900000000"></textarea>
                    <div class="form-text">One per line or comma separated, with country code.</div>
                </div>
                <div class="mb-3 d-none" data-scope-panel="group">
                    <select class="form-select" name="tag_id">
                        <option value="">Choose group…</option>
                        <?php foreach ($tags as $tag): ?>
                            <option value="<?= (int) $tag['id'] ?>"><?= esc($tag['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <input type="hidden" name="contact_id" value="">
                <div class="row g-2">
                    <div class="col-6">
                        <label class="form-label small mb-1">From date <span class="text-muted">(optional)</span></label>
                        <input type="date" class="form-control" name="from">
                    </div>
                    <div class="col-6">
                        <label class="form-label small mb-1">To date <span class="text-muted">(optional)</span></label>
                        <input type="date" class="form-control" name="to">
                    </div>
                </div>
                <div class="form-text mt-2" data-format-hint="csv">CSV with one row per message: timestamp, mobile, name, direction, type, message, media URL, status. The same file can be imported back.</div>
                <div class="form-text mt-2 d-none" data-format-hint="backup">One ZIP you can keep safe and restore later from <strong>Import chats…</strong> — on this or another server. Large inboxes with many media files can take a minute to prepare.</div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-wa" id="chatExportSubmit"><i class="fas fa-download me-1"></i> Download CSV</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php if (can('contacts.import')): ?>
<div class="modal fade" id="chatImportModal" tabindex="-1" aria-labelledby="chatImportTitle" aria-hidden="true" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="chatImportTitle">Import chats / restore backup</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div data-import-step="upload">
                    <p class="small text-muted mb-2">
                        Upload a chat export (CSV or Excel), for example from Cheerio. Each row is one message with the customer's phone number,
                        date &amp; time, who sent it (customer / agent) and the text.
                        <a href="<?= site_url('chat/transfer/sample') ?>">Download sample file</a>
                    </p>
                    <p class="small text-muted mb-2">To restore a <strong>full backup</strong>, upload the <code>.zip</code> file downloaded from Export chats → Full backup.</p>
                    <input type="file" class="form-control" id="chatImportFile" accept=".csv,.xlsx,.zip">
                    <div class="progress mt-2 d-none" id="chatImportProgress" style="height:6px"><div class="progress-bar bg-success" style="width:0"></div></div>
                    <div class="alert alert-light border small mt-3 mb-0">
                        <i class="fas fa-shield-halved me-1 text-success"></i>
                        Import only saves history. No message is sent, no workflow or keyword runs, WhatsApp opt-in is not changed,
                        and imported messages never open the 24-hour reply window. Re-importing the same file skips messages already imported.
                    </div>
                </div>

                <div data-import-step="map" class="d-none">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <div class="small"><strong id="chatImportFileName"></strong> · <span id="chatImportRowCount"></span></div>
                        <button type="button" class="btn btn-link btn-sm p-0" data-import-restart>Choose another file</button>
                    </div>
                    <div class="table-responsive border rounded mb-2" style="max-height:320px">
                        <table class="table table-sm align-middle mb-0">
                            <thead class="table-light"><tr><th style="width:32%">File column</th><th style="width:30%">Saves as</th><th>Sample</th></tr></thead>
                            <tbody id="chatImportMapping"></tbody>
                        </table>
                    </div>
                    <div id="chatImportStats" class="small"></div>
                    <div class="form-check mt-2">
                        <input class="form-check-input" type="checkbox" id="chatImportCreateContacts" checked>
                        <label class="form-check-label small" for="chatImportCreateContacts">Create contacts for numbers not saved yet (without WhatsApp opt-in)</label>
                    </div>
                </div>

                <div data-import-step="backup" class="d-none">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <div class="small"><strong id="chatBackupFileName"></strong></div>
                        <button type="button" class="btn btn-link btn-sm p-0" data-import-restart>Choose another file</button>
                    </div>
                    <div id="chatBackupSummary"></div>
                    <div class="alert alert-light border small mt-3 mb-0">
                        <i class="fas fa-shield-halved me-1 text-success"></i>
                        Restore only adds what is missing. Existing chats, contacts and chat status are kept; messages already here are skipped,
                        so restoring the same backup twice is safe. Nothing is sent, no workflow runs, and restored messages never open the
                        24-hour reply window. WhatsApp opt-in is restored only for contacts that have no consent record here; opt-outs are always kept.
                    </div>
                </div>

                <div data-import-step="done" class="d-none">
                    <div id="chatImportResult"></div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-wa" id="chatImportNext" disabled>Continue</button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>
