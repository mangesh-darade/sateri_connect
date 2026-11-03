<?= $this->extend('layouts/main') ?>

<?php $canEdit = function_exists('can') && can('settings.edit'); ?>

<?= $this->section('header_actions') ?>
<?php if ($canEdit): ?>
    <button type="button" class="btn btn-wa btn-sm" id="btnWhNew"><i class="fas fa-plus me-1"></i> Add endpoint</button>
<?php endif; ?>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<div class="page-list" id="whPage">

<div class="card mb-3">
    <div class="card-header"><h2 class="card-title mb-0">Endpoints</h2></div>
    <div class="card-body py-3">
        <p class="text-muted small">We send a JSON <code>POST</code> to your URL when one of the chosen events happens. Calls are retried up to 5 times (1 min, 5 min, 30 min, 2 h, 6 h). Your URL must be <code>https://</code> on port 443 and reachable from the internet.</p>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead><tr><th>Name</th><th>URL</th><th>Events</th><th>Status</th><?php if ($canEdit): ?><th class="text-end">Actions</th><?php endif; ?></tr></thead>
                <tbody>
                <?php if (empty($endpoints)): ?>
                    <tr><td colspan="5" class="text-center text-muted py-4">No endpoints yet.</td></tr>
                <?php endif; ?>
                <?php foreach ($endpoints as $e): ?>
                    <tr>
                        <td class="fw-medium"><?= esc($e['name']) ?></td>
                        <td class="small font-monospace text-break"><?= esc($e['url']) ?></td>
                        <td class="small"><?= esc(implode(', ', $e['events'])) ?></td>
                        <td><?= (int) $e['is_active'] === 1 ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Off</span>' ?></td>
                        <?php if ($canEdit): ?>
                        <td class="text-end text-nowrap">
                            <button type="button" class="btn btn-sm btn-outline-secondary js-wh-test" data-id="<?= (int) $e['id'] ?>" title="Send a test event"><i class="fas fa-paper-plane"></i></button>
                            <button type="button" class="btn btn-sm btn-outline-secondary js-wh-edit" data-row="<?= esc(json_encode($e), 'attr') ?>" title="Edit"><i class="fas fa-pen"></i></button>
                            <button type="button" class="btn btn-sm btn-outline-secondary js-wh-rotate" data-id="<?= (int) $e['id'] ?>" data-name="<?= esc($e['name'], 'attr') ?>" title="Rotate secret"><i class="fas fa-key"></i></button>
                            <button type="button" class="btn btn-sm btn-outline-danger js-wh-delete" data-id="<?= (int) $e['id'] ?>" data-name="<?= esc($e['name'], 'attr') ?>" title="Delete"><i class="fas fa-trash"></i></button>
                        </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="card mb-3">
    <div class="card-header"><h2 class="card-title mb-0">Verify the signature</h2></div>
    <div class="card-body py-3 small">
        Every call has the headers <code>X-Webhook-Event</code>, <code>X-Webhook-Id</code>, <code>X-Webhook-Timestamp</code> and <code>X-Webhook-Signature: sha256=…</code>.
        Compute <code>HMAC-SHA256(timestamp + "." + raw request body, your secret)</code> as hex and compare it to the signature. Reject calls whose timestamp is more than 5 minutes old. Answer with any <code>2xx</code> status to confirm.
    </div>
</div>

<div class="card">
    <div class="card-header"><h2 class="card-title mb-0">Recent calls</h2></div>
    <div class="card-body py-3">
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead><tr><th>Time</th><th>Endpoint</th><th>Event</th><th>Status</th><th class="text-end">Attempts</th><th>Last result</th></tr></thead>
                <tbody>
                <?php if (empty($deliveries)): ?>
                    <tr><td colspan="6" class="text-center text-muted py-4">Nothing sent yet.</td></tr>
                <?php endif; ?>
                <?php foreach ($deliveries as $d): ?>
                    <?php $badge = ['sent' => 'success', 'failed' => 'danger', 'pending' => 'warning'][$d['status']] ?? 'secondary'; ?>
                    <tr>
                        <td class="small"><?= esc((string) $d['created_at']) ?></td>
                        <td><?= esc((string) ($d['endpoint_name'] ?? '-')) ?></td>
                        <td class="small"><?= esc($d['event']) ?></td>
                        <td><span class="badge bg-<?= $badge ?>"><?= esc($d['status']) ?></span></td>
                        <td class="text-end"><?= (int) $d['attempts'] ?></td>
                        <td class="small text-muted"><?= esc($d['last_error'] ?? ($d['last_http_status'] ? 'HTTP ' . $d['last_http_status'] : '-')) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
</div>

<?php if ($canEdit): ?>
<div class="modal fade" id="whModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form autocomplete="off">
                <div class="modal-header">
                    <h5 class="modal-title" id="whModalTitle">Add endpoint</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Name</label>
                        <input type="text" class="form-control" name="name" maxlength="100" required placeholder="My CRM">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">URL</label>
                        <input type="url" class="form-control font-monospace" name="url" maxlength="500" required placeholder="https://example.com/hooks/sateri">
                    </div>
                    <div class="mb-3">
                        <label class="form-label d-block">Events</label>
                        <?php foreach ($events as $key => $label): ?>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="events[]" value="<?= esc($key, 'attr') ?>" id="whEv_<?= esc(str_replace('.', '_', $key), 'attr') ?>">
                                <label class="form-check-label" for="whEv_<?= esc(str_replace('.', '_', $key), 'attr') ?>"><code><?= esc($key) ?></code> <span class="text-muted small"><?= esc($label) ?></span></label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="mb-1 d-none" id="whActiveRow">
                        <label class="form-label">Status</label>
                        <select class="form-select" name="is_active">
                            <option value="1">Active</option>
                            <option value="0">Off</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-wa">Save</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= asset_url('assets/js/webhook-endpoints.js') ?>"></script>
<?= $this->endSection() ?>
