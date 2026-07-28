<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>
<?php $campaign = $campaign ?? []; $preview = $preview ?? []; ?>
<div class="card card-wa">
    <div class="card-header">
        <h3 class="card-title">Preview — <?= esc($campaign['name'] ?? 'Campaign') ?></h3>
    </div>
    <div class="card-body">
        <div class="row">
            <div class="col-md-6">
                <div class="border rounded p-3 mb-3" style="background:#e5ddd5;min-height:200px">
                    <div class="msg-bubble" style="background:#dcf8c6;display:inline-block;max-width:100%">
                        <?php if (! empty($preview['header'])): ?>
                            <div class="fw-semibold mb-1"><?= esc($preview['header']) ?></div>
                        <?php endif; ?>
                        <div><?= nl2br(esc($preview['body'] ?? $preview['text'] ?? 'No preview available')) ?></div>
                        <?php if (! empty($preview['footer'])): ?>
                            <div class="text-muted small mt-1"><?= esc($preview['footer']) ?></div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <h5>Details</h5>
                <ul class="list-unstyled">
                    <li><strong>Template:</strong> <?= esc($preview['template_name'] ?? $campaign['template_name'] ?? '—') ?></li>
                    <li><strong>Language:</strong> <?= esc($preview['language'] ?? '—') ?></li>
                    <li><strong>Audience size:</strong> <?= esc((string) ($preview['audience_count'] ?? $campaign['total_contacts'] ?? 0)) ?></li>
                </ul>
                <?php if (! empty($preview['variables']) && is_array($preview['variables'])): ?>
                    <h6>Variables</h6>
                    <pre class="bg-light p-2 rounded"><?= esc(json_encode($preview['variables'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="card-footer">
        <a href="<?= site_url('campaigns/' . (int) ($campaign['id'] ?? 0)) ?>" class="btn btn-secondary">Back</a>
    </div>
</div>
<?= $this->endSection() ?>
