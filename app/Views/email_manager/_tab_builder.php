<?php
/** @var list<array<string,mixed>> $builders */
/** @var bool $canSend */
$builders = $builders ?? [];
$canSend = ! empty($canSend);
?>
<div class="row g-3">
    <div class="col-lg-5">
        <div class="card border-0 shadow-sm rounded-3">
            <div class="card-header bg-white py-3 border-bottom d-flex align-items-center justify-content-between">
                <h6 class="mb-0 fw-bold text-dark"><i class="fas fa-paint-brush text-primary me-2"></i><?= $canSend ? 'Template Designer' : 'Template Preview' ?></h6>
                <span class="badge bg-primary-subtle text-primary" style="font-size: 0.7rem;">Reusable HTML</span>
            </div>
            <div class="card-body p-3">
                <?php if (! $canSend): ?>
                    <div class="alert alert-warning py-2 small mb-0">Permission <code>emails.send</code> is required to save templates.</div>
                <?php else: ?>
                <form id="builderForm" class="em-form">
                    <input type="hidden" name="id" id="builder_id" value="">
                    
                    <div class="mb-2">
                        <label class="form-label small fw-semibold mb-1">Template Name</label>
                        <input type="text" name="name" id="builder_name" class="form-control form-control-sm" placeholder="e.g. Welcome Onboarding Email" required maxlength="191">
                    </div>

                    <div class="mb-2">
                        <label class="form-label small fw-semibold mb-1">Default Subject Line</label>
                        <input type="text" name="subject" id="builder_subject" class="form-control form-control-sm" placeholder="e.g. Welcome to Our Family!" maxlength="255">
                    </div>

                    <?php if (! empty($isCheerio)): ?>
                    <div class="mb-2">
                        <label class="form-label small fw-semibold mb-1">Cheerio Builder ID <span class="text-muted">(Optional)</span></label>
                        <input type="text" name="cheerio_builder_id" id="builder_cheerio_id" class="form-control form-control-sm" placeholder="From Cheerio Email Builder">
                    </div>
                    <?php endif; ?>

                    <div class="mb-2">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <label class="form-label small fw-semibold mb-0">HTML Source Code</label>
                            <span class="text-muted" style="font-size: 0.7rem;">Tags: <code>{{name}}</code> <code>{{email}}</code></span>
                        </div>
                        <textarea name="html_content" id="builder_html" class="form-control form-control-sm font-monospace" rows="10" placeholder="<div style='font-family:sans-serif;'>&#10;  <h2>Hello {{name}},</h2>&#10;  <p>Thank you for choosing us.</p>&#10;</div>"></textarea>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-semibold mb-1">Status</label>
                        <select name="status" id="builder_status" class="form-select form-select-sm">
                            <option value="active">Active (Ready for Campaigns)</option>
                            <option value="draft">Draft (Work in progress)</option>
                            <option value="archived">Archived</option>
                        </select>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary btn-sm px-3"><i class="fas fa-save me-1"></i> Save Template</button>
                        <button type="button" class="btn btn-light border btn-sm px-3" id="builderReset"><i class="fas fa-undo me-1"></i> Reset</button>
                    </div>
                    <div class="em-msg mt-2 small" id="builderMsg"></div>
                </form>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Saved Templates Table -->
    <div class="col-lg-7">
        <div class="card border-0 shadow-sm rounded-3">
            <div class="card-header bg-white py-3 border-bottom d-flex align-items-center justify-content-between">
                <div>
                    <h6 class="mb-0 fw-bold text-dark"><i class="fas fa-layer-group text-primary me-2"></i>Saved Template Library</h6>
                    <small class="text-muted">Reusable for Campaigns &amp; Auto Drips</small>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <span class="badge bg-secondary-subtle text-secondary"><?= count($builders) ?> templates</span>
                    <a href="<?= site_url('templates') ?>" class="btn btn-outline-secondary btn-xs">
                        <i class="fab fa-whatsapp text-success me-1"></i> WhatsApp Templates
                    </a>
                </div>
            </div>
            <div class="card-body p-0">
                <?php if ($builders === []): ?>
                    <div class="text-center py-5 text-muted">
                        <div class="mb-3 text-secondary opacity-50">
                            <i class="fas fa-paint-brush fa-3x"></i>
                        </div>
                        <h6 class="fw-semibold text-dark mb-1">No email templates created yet</h6>
                        <p class="small text-muted mb-0">Design your first reusable HTML layout using the form on the left.</p>
                    </div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle mb-0 em-table">
                        <thead class="table-light">
                            <tr>
                                <th>Template Name</th>
                                <th>Status</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($builders as $b): ?>
                            <?php
                            $bStatus = (string) ($b['status'] ?? 'draft');
                            $bBadge = match ($bStatus) {
                                'active'   => 'bg-success-subtle text-success border border-success-subtle',
                                'archived' => 'bg-secondary-subtle text-secondary border border-secondary-subtle',
                                default    => 'bg-warning-subtle text-warning border border-warning-subtle',
                            };
                            ?>
                            <tr data-builder='<?= esc(json_encode($b), 'attr') ?>'>
                                <td>
                                    <div class="fw-semibold text-dark"><?= esc($b['name']) ?></div>
                                    <?php if (! empty($b['subject'])): ?>
                                        <div class="text-muted small text-truncate" style="max-width: 260px;">
                                            <i class="fas fa-tag me-1 text-secondary opacity-75"></i><?= esc($b['subject']) ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge <?= $bBadge ?> rounded-pill px-2 py-1">
                                        <?= esc(ucfirst($bStatus)) ?>
                                    </span>
                                </td>
                                <td class="text-end text-nowrap">
                                    <?php if ($canSend): ?>
                                        <button type="button" class="btn btn-xs btn-outline-primary em-edit-builder" title="Load into editor">
                                            <i class="fas fa-edit me-1"></i> Edit
                                        </button>
                                        <button type="button" class="btn btn-xs btn-outline-danger em-del-builder ms-1" data-id="<?= (int) $b['id'] ?>" title="Delete template">
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
