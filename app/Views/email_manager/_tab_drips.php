<?php
/** @var list<array<string,mixed>> $drips */
/** @var list<array<string,mixed>> $builders */
/** @var bool $canSend */
$drips = $drips ?? [];
$builders = $builders ?? [];
$canSend = ! empty($canSend);
?>
<div class="row g-3">
    <div class="col-lg-5">
        <div class="dash-panel">
            <div class="panel-head"><h3>Create drip sequence</h3></div>
            <div class="panel-body">
                <?php if (! $canSend): ?>
                    <p class="text-muted small mb-0">Need <code>emails.send</code> permission.</p>
                <?php else: ?>
                <form id="dripForm" class="em-form">
                    <input type="hidden" name="id" id="drip_id" value="">
                    <div class="mb-2">
                        <label class="form-label">Name</label>
                        <input type="text" name="name" id="drip_name" class="form-control form-control-sm" required>
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Description</label>
                        <textarea name="description" id="drip_description" class="form-control form-control-sm" rows="2"></textarea>
                    </div>
                    <div class="row g-2 mb-2">
                        <div class="col-6">
                            <label class="form-label">Trigger</label>
                            <select name="trigger_type" id="drip_trigger" class="form-select form-select-sm">
                                <option value="manual">Manual</option>
                                <option value="on_subscribe">On subscribe</option>
                                <option value="on_tag">On tag</option>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label">Trigger value</label>
                            <input type="text" name="trigger_value" id="drip_trigger_value" class="form-control form-control-sm" placeholder="tag name">
                        </div>
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Status</label>
                        <select name="status" id="drip_status" class="form-select form-select-sm">
                            <option value="draft">Draft</option>
                            <option value="active">Active</option>
                            <option value="paused">Paused</option>
                            <option value="archived">Archived</option>
                        </select>
                    </div>
                    <div class="mb-2">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <label class="form-label mb-0">Steps</label>
                            <button type="button" class="btn btn-xs btn-outline-secondary" id="dripAddStep">+ Step</button>
                        </div>
                        <div id="dripSteps"></div>
                    </div>
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-wa btn-sm">Save drip</button>
                        <button type="button" class="btn btn-outline-secondary btn-sm" id="dripReset">Reset</button>
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
    <div class="col-lg-7">
        <div class="dash-panel">
            <div class="panel-head"><h3>Drip sequences</h3></div>
            <div class="panel-body">
                <?php if ($drips === []): ?>
                    <div class="activity-empty py-3">No drips yet. Build a multi-step email sequence and test-send a step via Cheerio.</div>
                <?php else: ?>
                    <?php foreach ($drips as $d): ?>
                        <div class="em-drip-card mb-3" data-drip='<?= esc(json_encode($d), 'attr') ?>'>
                            <div class="d-flex justify-content-between gap-2">
                                <div>
                                    <strong><?= esc($d['name']) ?></strong>
                                    <span class="badge text-bg-secondary ms-1"><?= esc($d['status']) ?></span>
                                    <div class="text-muted small"><?= esc($d['trigger_type']) ?><?= ! empty($d['trigger_value']) ? ': ' . esc($d['trigger_value']) : '' ?></div>
                                </div>
                                <?php if ($canSend): ?>
                                <div class="text-nowrap">
                                    <button type="button" class="btn btn-xs btn-outline-primary em-edit-drip">Edit</button>
                                    <button type="button" class="btn btn-xs btn-outline-danger em-del-drip" data-id="<?= (int) $d['id'] ?>">Del</button>
                                </div>
                                <?php endif; ?>
                            </div>
                            <?php if (! empty($d['steps'])): ?>
                            <ol class="small mb-2 mt-2 ps-3">
                                <?php foreach ($d['steps'] as $s): ?>
                                    <li class="mb-1">
                                        <strong><?= esc($s['subject']) ?></strong>
                                        <span class="text-muted">(+<?= (int) $s['delay_hours'] ?>h)</span>
                                        <?php if ($canSend): ?>
                                            <button type="button" class="btn btn-xs btn-outline-success em-send-step"
                                                data-drip-id="<?= (int) $d['id'] ?>"
                                                data-step-id="<?= (int) $s['id'] ?>">Test send</button>
                                        <?php endif; ?>
                                    </li>
                                <?php endforeach; ?>
                            </ol>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
