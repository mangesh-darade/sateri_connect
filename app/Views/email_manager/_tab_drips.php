<?php
/** @var list<array<string,mixed>> $drips */
/** @var list<array<string,mixed>> $builders */
/** @var bool $canSend */
$drips = $drips ?? [];
$builders = $builders ?? [];
$canSend = ! empty($canSend);
?>
<div class="row g-3">
    <!-- Sequence Creator / Editor -->
    <div class="col-lg-5">
        <div class="card border-0 shadow-sm rounded-3">
            <div class="card-header bg-white py-3 border-bottom d-flex align-items-center justify-content-between">
                <h6 class="mb-0 fw-bold text-dark"><i class="fas fa-stream text-primary me-2"></i>Drip Sequence Builder</h6>
                <span class="badge bg-primary-subtle text-primary" style="font-size: 0.7rem;">Automated Journey</span>
            </div>
            <div class="card-body p-3">
                <?php if (! $canSend): ?>
                    <div class="alert alert-warning py-2 small mb-0">Permission <code>emails.send</code> is required to configure drip sequences.</div>
                <?php else: ?>
                <form id="dripForm" class="em-form">
                    <input type="hidden" name="id" id="drip_id" value="">
                    
                    <div class="mb-2">
                        <label class="form-label small fw-semibold mb-1">Sequence Name</label>
                        <input type="text" name="name" id="drip_name" class="form-control form-control-sm" placeholder="e.g. 5-Day New Lead Nurture" required>
                    </div>

                    <div class="mb-2">
                        <label class="form-label small fw-semibold mb-1">Description <span class="text-muted fw-normal">(Optional)</span></label>
                        <textarea name="description" id="drip_description" class="form-control form-control-sm" rows="2" placeholder="Brief notes about the sequence audience and schedule"></textarea>
                    </div>

                    <div class="row g-2 mb-2">
                        <div class="col-6">
                            <label class="form-label small fw-semibold mb-1">Trigger Event</label>
                            <select name="trigger_type" id="drip_trigger" class="form-select form-select-sm">
                                <option value="manual">Manual Trigger</option>
                                <option value="on_subscribe">On Contact Subscribe</option>
                                <option value="on_tag">On Contact Tag Added</option>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-semibold mb-1">Trigger Value</label>
                            <input type="text" name="trigger_value" id="drip_trigger_value" class="form-control form-control-sm" placeholder="e.g. vip-lead">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-semibold mb-1">Status</label>
                        <select name="status" id="drip_status" class="form-select form-select-sm">
                            <option value="active">Active (Running)</option>
                            <option value="draft">Draft (Paused)</option>
                            <option value="paused">Paused</option>
                            <option value="archived">Archived</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <label class="form-label small fw-semibold mb-0">Sequence Steps (Email Timeline)</label>
                            <button type="button" class="btn btn-xs btn-outline-primary" id="dripAddStep">
                                <i class="fas fa-plus me-1"></i> Add Step
                            </button>
                        </div>
                        <div id="dripSteps" class="d-flex flex-column gap-2"></div>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary btn-sm px-3"><i class="fas fa-save me-1"></i> Save Drip</button>
                        <button type="button" class="btn btn-light border btn-sm px-3" id="dripReset"><i class="fas fa-undo me-1"></i> Reset</button>
                    </div>
                    <div class="em-msg mt-2 small" id="dripMsg"></div>
                </form>
                <script type="application/json" id="dripBuildersJson"><?= json_encode(array_map(static fn ($b) => [
                    'id' => (int) $b['id'],
                    'name' => (string) $b['name'],
                ], $builders), JSON_UNESCAPED_UNICODE) ?></script>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Active Sequences List -->
    <div class="col-lg-7">
        <div class="card border-0 shadow-sm rounded-3">
            <div class="card-header bg-white py-3 border-bottom d-flex align-items-center justify-content-between">
                <h6 class="mb-0 fw-bold text-dark"><i class="fas fa-layer-group text-primary me-2"></i>Configured Drip Sequences</h6>
                <div class="d-flex align-items-center gap-2">
                    <span class="badge bg-secondary-subtle text-secondary" style="font-size: 0.7rem;"><?= count($drips) ?> Total</span>
                    <a href="<?= site_url('automations') ?>" class="btn btn-outline-secondary btn-xs">
                        <i class="fas fa-project-diagram me-1"></i> Visual Workflows
                    </a>
                </div>
            </div>
            <div class="card-body p-3">
                <?php if ($drips === []): ?>
                    <div class="text-center py-5">
                        <div class="mb-3">
                            <i class="fas fa-stream fa-3x text-muted opacity-25"></i>
                        </div>
                        <h6 class="text-muted fw-semibold mb-1">No drip sequences configured yet</h6>
                        <p class="text-muted small mb-0">Build automated multi-step email journeys with schedule delays (+24h, +48h).</p>
                    </div>
                <?php else: ?>
                    <div class="d-flex flex-column gap-3">
                        <?php foreach ($drips as $d): ?>
                            <div class="em-drip-card p-3 rounded-3 border bg-light bg-opacity-25" data-drip='<?= esc(json_encode($d), 'attr') ?>'>
                                <div class="d-flex justify-content-between align-items-start gap-2 mb-2 pb-2 border-bottom">
                                    <div>
                                        <div class="d-flex align-items-center gap-2">
                                            <strong class="text-dark fs-6"><?= esc($d['name']) ?></strong>
                                            <?php
                                            $stClass = match($d['status'] ?? 'draft') {
                                                'active' => 'success',
                                                'paused' => 'warning',
                                                'archived' => 'secondary',
                                                default => 'light text-dark border',
                                            };
                                            ?>
                                            <span class="badge bg-<?= $stClass ?>-subtle text-<?= $stClass ?> border border-<?= $stClass ?>-subtle text-capitalize"><?= esc($d['status']) ?></span>
                                        </div>
                                        <div class="text-muted small mt-1">
                                            <i class="fas fa-bolt text-warning me-1"></i> Trigger: <span class="fw-medium text-dark"><?= esc($d['trigger_type']) ?></span>
                                            <?= ! empty($d['trigger_value']) ? ' &bull; Target: <span class="badge bg-secondary-subtle text-secondary">' . esc($d['trigger_value']) . '</span>' : '' ?>
                                        </div>
                                        <?php if (! empty($d['description'])): ?>
                                            <div class="text-muted small mt-1 fst-italic"><?= esc($d['description']) ?></div>
                                        <?php endif; ?>
                                    </div>
                                    <?php if ($canSend): ?>
                                    <div class="d-flex gap-1 text-nowrap">
                                        <button type="button" class="btn btn-xs btn-outline-primary em-edit-drip"><i class="fas fa-edit me-1"></i>Edit</button>
                                        <button type="button" class="btn btn-xs btn-outline-danger em-del-drip" data-id="<?= (int) $d['id'] ?>"><i class="fas fa-trash me-1"></i>Delete</button>
                                    </div>
                                    <?php endif; ?>
                                </div>

                                <?php if (! empty($d['steps'])): ?>
                                <div class="mt-2">
                                    <span class="text-uppercase fw-bold text-muted" style="font-size: 0.68rem; letter-spacing: 0.05em;">Timeline Steps (<?= count($d['steps']) ?>)</span>
                                    <div class="mt-2 d-flex flex-column gap-2 ps-1">
                                        <?php foreach ($d['steps'] as $idx => $s): ?>
                                            <div class="d-flex align-items-center justify-content-between p-2 rounded bg-white border small">
                                                <div class="d-flex align-items-center gap-2">
                                                    <span class="badge bg-primary text-white rounded-pill px-2 py-1" style="font-size: 0.7rem;">Step <?= $idx + 1 ?></span>
                                                    <span class="badge bg-info-subtle text-info border border-info-subtle" title="Delay from previous step">
                                                        <i class="far fa-clock me-1"></i>+<?= (int) $s['delay_hours'] ?>h
                                                    </span>
                                                    <span class="fw-medium text-dark"><?= esc($s['subject']) ?></span>
                                                </div>
                                                <?php if ($canSend): ?>
                                                    <button type="button" class="btn btn-xs btn-outline-success em-send-step"
                                                        data-drip-id="<?= (int) $d['id'] ?>"
                                                        data-step-id="<?= (int) $s['id'] ?>">
                                                        <i class="fas fa-paper-plane me-1"></i> Test Send
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <?php else: ?>
                                    <div class="text-muted small fst-italic">No steps configured yet. Click Edit to add steps.</div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
