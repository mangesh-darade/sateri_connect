<?php
/** @var list<array<string,mixed>> $builders */
/** @var bool $canSend */
$builders = $builders ?? [];
$canSend = ! empty($canSend);
?>
<div class="row g-3">
    <div class="col-lg-5">
        <div class="dash-panel">
            <div class="panel-head"><h3><?= $canSend ? 'Create / edit builder' : 'Builder' ?></h3></div>
            <div class="panel-body">
                <?php if (! $canSend): ?>
                    <p class="text-muted small mb-0">You need <code>emails.send</code> to save builders.</p>
                <?php else: ?>
                <form id="builderForm" class="em-form">
                    <input type="hidden" name="id" id="builder_id" value="">
                    <div class="mb-2">
                        <label class="form-label">Name</label>
                        <input type="text" name="name" id="builder_name" class="form-control form-control-sm" required maxlength="191">
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Default subject</label>
                        <input type="text" name="subject" id="builder_subject" class="form-control form-control-sm" maxlength="255">
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Cheerio Builder ID <span class="text-muted">(optional)</span></label>
                        <input type="text" name="cheerio_builder_id" id="builder_cheerio_id" class="form-control form-control-sm" placeholder="From Cheerio Email Builder">
                    </div>
                    <div class="mb-2">
                        <label class="form-label">HTML content</label>
                        <textarea name="html_content" id="builder_html" class="form-control form-control-sm font-monospace" rows="10" placeholder="<h1>Hello</h1>"></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Status</label>
                        <select name="status" id="builder_status" class="form-select form-select-sm">
                            <option value="draft">Draft</option>
                            <option value="active">Active</option>
                            <option value="archived">Archived</option>
                        </select>
                    </div>
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-wa btn-sm">Save builder</button>
                        <button type="button" class="btn btn-outline-secondary btn-sm" id="builderReset">Reset</button>
                    </div>
                    <div class="em-msg mt-2 small" id="builderMsg"></div>
                </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="dash-panel">
            <div class="panel-head"><h3>Saved builders</h3></div>
            <div class="panel-body p-0">
                <?php if ($builders === []): ?>
                    <div class="activity-empty py-4">No builders yet. Create an HTML template or link a Cheerio Builder ID.</div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0 em-table">
                        <thead><tr><th>Name</th><th>Cheerio ID</th><th>Status</th><th></th></tr></thead>
                        <tbody>
                        <?php foreach ($builders as $b): ?>
                            <tr data-builder='<?= esc(json_encode($b), 'attr') ?>'>
                                <td>
                                    <strong><?= esc($b['name']) ?></strong>
                                    <?php if (! empty($b['subject'])): ?>
                                        <div class="text-muted small"><?= esc($b['subject']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td><code class="small"><?= esc($b['cheerio_builder_id'] ?? '—') ?></code></td>
                                <td><span class="badge text-bg-secondary"><?= esc($b['status']) ?></span></td>
                                <td class="text-end text-nowrap">
                                    <?php if ($canSend): ?>
                                        <button type="button" class="btn btn-xs btn-outline-primary em-edit-builder">Edit</button>
                                        <button type="button" class="btn btn-xs btn-outline-danger em-del-builder" data-id="<?= (int) $b['id'] ?>">Del</button>
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
