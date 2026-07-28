<?= $this->extend('layouts/main') ?>

<?= $this->section('header_actions') ?>
<?php $t = $template ?? []; ?>
<a href="<?= site_url('templates') ?>" class="btn btn-outline-secondary btn-sm">
    <i class="fas fa-arrow-left me-1"></i> Back
</a>
<?php if (function_exists('can') && can('templates.delete')): ?>
    <form action="<?= site_url('templates/' . (int) ($t['id'] ?? 0) . '/delete') ?>" method="post"
          onsubmit="return confirm('Delete this template from <?= esc(function_exists('whatsapp_provider_short') ? whatsapp_provider_short() : 'provider') ?> and locally?');">
        <?= csrf_field() ?>
        <button type="submit" class="btn btn-outline-danger btn-sm"><i class="fas fa-trash me-1"></i> Delete</button>
    </form>
<?php endif; ?>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php $t = $template ?? []; ?>
<div class="row g-3">
    <div class="col-lg-6">
        <div class="dash-panel">
            <div class="panel-head"><h3>Details</h3></div>
            <div class="panel-body">
                <dl class="row mb-0">
                    <dt class="col-sm-4 text-muted">Name</dt>
                    <dd class="col-sm-8 fw-semibold"><?= esc($t['name'] ?? '') ?></dd>
                    <dt class="col-sm-4 text-muted">Language</dt>
                    <dd class="col-sm-8"><?= esc($t['language'] ?? '') ?></dd>
                    <dt class="col-sm-4 text-muted">Category</dt>
                    <dd class="col-sm-8"><?= esc($t['category'] ?? '') ?></dd>
                    <dt class="col-sm-4 text-muted">Template Type</dt>
                    <dd class="col-sm-8"><?= esc(ucfirst((string) ($t['template_type'] ?? 'default'))) ?></dd>
                    <dt class="col-sm-4 text-muted">Header Type</dt>
                    <dd class="col-sm-8"><?= esc(ucfirst((string) ($t['header_type'] ?? 'none'))) ?></dd>
                    <dt class="col-sm-4 text-muted">Status</dt>
                    <dd class="col-sm-8"><?= view('partials/status_badge', ['status' => $t['status'] ?? '']) ?></dd>
                    <dt class="col-sm-4 text-muted">Provider ID</dt>
                    <dd class="col-sm-8"><code><?= esc($t['meta_id'] ?? '') ?></code></dd>
                    <dt class="col-sm-4 text-muted">Synced</dt>
                    <dd class="col-sm-8"><?= esc($t['synced_at'] ?? '—') ?></dd>
                </dl>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="dash-panel">
            <div class="panel-head"><h3>Preview</h3></div>
            <div class="panel-body" style="background:var(--wa-chat-bg);border-radius:0 0 var(--radius) var(--radius)">
                <div class="msg-bubble inbound" style="display:inline-block;max-width:100%">
                    <?php if (in_array(strtolower((string) ($t['header_type'] ?? '')), ['image', 'video', 'document'], true) && ! empty($t['header_content'])): ?>
                        <div class="small text-muted mb-2">
                            <?= esc(ucfirst((string) ($t['header_type'] ?? 'media'))) ?> header sample:
                            <a href="<?= esc((string) $t['header_content']) ?>" target="_blank" rel="noopener">Open media</a>
                        </div>
                    <?php elseif (! empty($t['header_content'])): ?>
                        <div class="fw-bold mb-1"><?= esc($t['header_content']) ?></div>
                    <?php endif; ?>
                    <div><?= nl2br(esc($t['body'] ?? '')) ?></div>
                    <?php if (! empty($t['footer'])): ?>
                        <div class="text-muted small mt-1"><?= esc($t['footer']) ?></div>
                    <?php endif; ?>
                </div>
                <?php
                $buttons = $t['buttons'] ?? [];
                if (is_string($buttons)) {
                    $buttons = json_decode($buttons, true) ?: [];
                }
                if (is_array($buttons) && $buttons !== []):
                ?>
                    <div class="mt-3">
                        <?php foreach ($buttons as $btn): ?>
                            <button type="button" class="btn btn-sm btn-outline-secondary me-1 mb-1" disabled>
                                <?= esc($btn['text'] ?? $btn['title'] ?? 'Button') ?>
                                <?php if (! empty($btn['type'])): ?>
                                    (<?= esc(strtolower((string) $btn['type'])) ?>)
                                <?php endif; ?>
                            </button>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?= $this->endSection() ?>
