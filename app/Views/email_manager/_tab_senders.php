<?php
/** @var list<array<string,mixed>> $senders */
/** @var bool $canSend */
/** @var bool $isCheerio */
$senders = $senders ?? [];
$canSend = ! empty($canSend);
$isCheerio = ! empty($isCheerio);
?>
<div class="row g-3">
    <div class="col-lg-5">
        <div class="dash-panel">
            <div class="panel-head"><h3>Sender ID / Domain ID</h3></div>
            <div class="panel-body">
                <?php if ($isCheerio): ?>
                <div class="alert alert-warning py-2 small">
                    Cheerio delivery depends on a <strong>verified Sender ID</strong> in the Cheerio Dashboard.
                    Store IDs here for reference — DNS verify happens in Cheerio / your DNS provider.
                </div>
                <?php endif; ?>
                <?php if (! $canSend): ?>
                    <p class="text-muted small mb-0">Need <code>emails.send</code> to manage senders.</p>
                <?php else: ?>
                <form id="senderForm" class="em-form">
                    <input type="hidden" name="id" id="sender_id" value="">
                    <div class="mb-2">
                        <label class="form-label">Type</label>
                        <select name="type" id="sender_type" class="form-select form-select-sm">
                            <option value="sender">Sender ID</option>
                            <option value="domain">Domain ID</option>
                        </select>
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Display name</label>
                        <input type="text" name="name" id="sender_name" class="form-control form-control-sm" required>
                    </div>
                    <div class="mb-2" id="senderEmailWrap">
                        <label class="form-label">From email</label>
                        <input type="email" name="email" id="sender_email" class="form-control form-control-sm">
                    </div>
                    <div class="mb-2 d-none" id="senderDomainWrap">
                        <label class="form-label">Domain</label>
                        <input type="text" name="domain" id="sender_domain" class="form-control form-control-sm" placeholder="example.com">
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Cheerio ID</label>
                        <input type="text" name="cheerio_id" id="sender_cheerio_id" class="form-control form-control-sm" placeholder="External ID from Cheerio">
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Status</label>
                        <select name="status" id="sender_status" class="form-select form-select-sm">
                            <option value="pending">Pending</option>
                            <option value="verified">Verified</option>
                            <option value="failed">Failed</option>
                            <option value="disabled">Disabled</option>
                        </select>
                    </div>
                    <div class="mb-2">
                        <label class="form-label">DNS / notes</label>
                        <textarea name="notes" id="sender_notes" class="form-control form-control-sm" rows="3" placeholder="SPF / DKIM / DMARC notes"></textarea>
                    </div>
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" name="is_default" id="sender_default" value="1">
                        <label class="form-check-label" for="sender_default">Default for this type</label>
                    </div>
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-wa btn-sm">Save</button>
                        <button type="button" class="btn btn-outline-secondary btn-sm" id="senderReset">Reset</button>
                    </div>
                    <div class="em-msg mt-2 small" id="senderMsg"></div>
                </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="dash-panel">
            <div class="panel-head"><h3>Configured identities</h3></div>
            <div class="panel-body p-0">
                <?php if ($senders === []): ?>
                    <div class="activity-empty py-4">No sender or domain records yet.</div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0 em-table">
                        <thead><tr><th>Type</th><th>Name</th><th>Email / Domain</th><th>Cheerio ID</th><th>Status</th><th></th></tr></thead>
                        <tbody>
                        <?php foreach ($senders as $s): ?>
                            <tr data-sender='<?= esc(json_encode($s), 'attr') ?>'>
                                <td><span class="badge text-bg-dark"><?= esc($s['type']) ?></span></td>
                                <td>
                                    <?= esc($s['name']) ?>
                                    <?php if (! empty($s['is_default'])): ?><span class="badge text-bg-success ms-1">default</span><?php endif; ?>
                                </td>
                                <td class="small"><?= esc($s['type'] === 'domain' ? ($s['domain'] ?? '') : ($s['email'] ?? '')) ?></td>
                                <td><code class="small"><?= esc($s['cheerio_id'] ?? '—') ?></code></td>
                                <td><span class="badge em-status-<?= esc($s['status']) ?>"><?= esc($s['status']) ?></span></td>
                                <td class="text-end text-nowrap">
                                    <?php if ($canSend): ?>
                                        <button type="button" class="btn btn-xs btn-outline-primary em-edit-sender">Edit</button>
                                        <button type="button" class="btn btn-xs btn-outline-danger em-del-sender" data-id="<?= (int) $s['id'] ?>">Del</button>
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
