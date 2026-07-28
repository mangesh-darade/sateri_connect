<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>
<?php
$keyword = $keyword ?? [];
$isEdit = ! empty($keyword['id']);
$action = $isEdit ? site_url('keywords/' . (int) $keyword['id']) : site_url('keywords');
$provider = function_exists('whatsapp_provider') ? whatsapp_provider() : (string) ($whatsappProvider ?? 'cheerio');
$providerShort = function_exists('whatsapp_provider_short') ? whatsapp_provider_short() : ($provider === 'meta' ? 'Meta' : 'Cheerio');
?>
<div class="form-shell">
<div class="alert alert-light border mb-3 py-2 px-3 d-flex flex-wrap align-items-center gap-2" role="status">
    <span class="badge <?= $provider === 'meta' ? 'text-bg-primary' : 'text-bg-success' ?>">
        Settings: <?= esc($providerShort) ?>
    </span>
    <span class="small text-muted mb-0">
        Keyword + auto-reply work only on the <strong><?= esc($providerShort) ?></strong> business number.
        Cheerio active → message Cheerio number. Meta active → message Meta number.
        <a href="<?= site_url('settings') ?>">Settings</a>
    </span>
</div>
<div class="card form-card" id="keywordFormCard"
     data-provider="<?= esc($provider) ?>"
     data-provider-short="<?= esc($providerShort) ?>">
    <form action="<?= $action ?>" method="post" id="keywordForm">
        <?= csrf_field() ?>
        <div class="card-body">
            <div class="row g-2">
                <div class="col-md-6">
                    <label class="form-label">Keyword <span class="text-danger">*</span></label>
                    <input type="text" name="keyword" class="form-control" required value="<?= esc(old('keyword') ?? ($keyword['keyword'] ?? '')) ?>" placeholder="hi, help, menu…">
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label">Match type</label>
                    <select name="match_type" class="form-select">
                        <?php foreach (['exact' => 'Exact', 'contains' => 'Contains', 'starts_with' => 'Starts with'] as $v => $l): ?>
                            <option value="<?= $v ?>" <?= (old('match_type') ?? ($keyword['match_type'] ?? 'exact')) === $v ? 'selected' : '' ?>><?= $l ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label">Menu order</label>
                    <input type="number" name="menu_order" class="form-control" value="<?= esc(old('menu_order') ?? ($keyword['menu_order'] ?? '0')) ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Response type</label>
                    <select name="response_type" id="responseType" class="form-select">
                        <?php
                        $responseTypes = [
                            'text'     => 'Text',
                            'template' => 'Template',
                            'image'    => 'Image + caption',
                            'video'    => 'Video + caption',
                            'document' => 'Document + caption',
                            'list'     => 'Interactive list',
                            'buttons'  => 'Reply buttons',
                            'menu'     => 'Menu (from children)',
                            'workflow' => 'Start workflow',
                        ];
                        $curType = old('response_type') ?? ($keyword['response_type'] ?? 'text');
                        foreach ($responseTypes as $v => $l):
                        ?>
                            <option value="<?= $v ?>" <?= $curType === $v ? 'selected' : '' ?>><?= $l ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Parent keyword (optional)</label>
                    <select name="parent_id" class="form-select">
                        <option value="">— None —</option>
                        <?php foreach (($parents ?? []) as $p): ?>
                            <?php if (($keyword['id'] ?? null) && (int) $p['id'] === (int) $keyword['id']) continue; ?>
                            <option value="<?= (int) $p['id'] ?>" <?= (string) (old('parent_id') ?? ($keyword['parent_id'] ?? '')) === (string) $p['id'] ? 'selected' : '' ?>><?= esc($p['keyword']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4 d-flex align-items-end">
                    <div class="form-check form-switch mb-2">
                        <input class="form-check-input" type="checkbox" name="is_active" value="1" id="kwActive" <?= ! empty(old('is_active') ?? ($keyword['is_active'] ?? 1)) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="kwActive">Active</label>
                    </div>
                </div>
                <div class="col-md-6 kw-template-fields d-none" id="kwTemplateFields">
                    <label class="form-label" for="kwTemplateSelect">Select template</label>
                    <select class="form-select" id="kwTemplateSelect">
                        <option value="">— Choose approved template —</option>
                        <?php foreach (($templates ?? []) as $tpl): ?>
                            <option value="<?= esc($tpl['name']) ?>" data-lang="<?= esc($tpl['language'] ?? '') ?>"><?= esc($tpl['name']) ?> (<?= esc($tpl['language'] ?? '') ?>)</option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (empty($templates)): ?>
                        <div class="form-text text-warning">No approved templates. <a href="<?= site_url('templates') ?>">Sync templates</a> first.</div>
                    <?php else: ?>
                        <div class="form-text"><?= count($templates) ?> approved template(s). Or type a name below manually.</div>
                    <?php endif; ?>
                </div>
                <div class="col-12">
                    <label class="form-label" for="responseContent">Response content</label>
                    <textarea name="response_content" id="responseContent" class="form-control" rows="3" placeholder="Message body, media URL, template name, or automation ID…"><?= esc(old('response_content') ?? ($keyword['response_content'] ?? '')) ?></textarea>
                    <div class="form-text">Type here — payload JSON auto-fills for the active provider (<strong id="kwProviderLabel"><?= esc($providerShort) ?></strong>).</div>
                </div>
                <div class="col-md-8 kw-media-fields d-none" id="kwMediaFields">
                    <label class="form-label" for="kwMediaUrl">Media URL</label>
                    <input type="url" class="form-control" id="kwMediaUrl" placeholder="https://…">
                </div>
                <div class="col-md-4 kw-media-fields d-none" id="kwCaptionField">
                    <label class="form-label" for="kwCaption">Caption</label>
                    <input type="text" class="form-control" id="kwCaption" placeholder="Optional caption">
                </div>
                <div class="col-md-6 kw-workflow-fields d-none" id="kwWorkflowFields">
                    <label class="form-label" for="kwAutomationId">Workflow (automation)</label>
                    <select class="form-select" id="kwAutomationId">
                        <option value="">— Select automation —</option>
                        <?php foreach (($automations ?? []) as $auto): ?>
                            <option value="<?= (int) $auto['id'] ?>"><?= esc($auto['name'] ?? ('#' . $auto['id'])) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text">Starts this automation when the keyword matches.</div>
                </div>
                <div class="col-md-6 kw-workflow-fields d-none">
                    <label class="form-label" for="kwAckText">Optional ack text</label>
                    <input type="text" class="form-control" id="kwAckText" placeholder="Thanks — starting…">
                </div>
                <div class="col-12">
                    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-1">
                        <label class="form-label mb-0" for="responsePayload">Response payload (JSON)</label>
                        <div class="d-flex align-items-center gap-2">
                            <span class="badge <?= $provider === 'meta' ? 'text-bg-primary' : 'text-bg-success' ?>" id="kwPayloadProviderBadge"><?= esc($providerShort) ?> format</span>
                            <button type="button" class="btn btn-outline-secondary btn-sm" id="btnRegenPayload" title="Rebuild JSON from content">Regenerate</button>
                        </div>
                    </div>
                    <textarea name="response_payload" id="responsePayload" class="form-control font-monospace" rows="8" placeholder='{"type":"text",...}'><?php
                        $rp = old('response_payload') ?? ($keyword['response_payload'] ?? '');
                        echo esc(is_array($rp) ? json_encode($rp, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : (string) $rp);
                    ?></textarea>
                    <div class="form-text" id="kwPayloadHint">Auto-generated for <?= esc($providerShort) ?>. Manual edits are kept until you change content/type or click Regenerate.</div>
                </div>
            </div>
        </div>
        <div class="card-footer d-flex flex-wrap gap-2">
            <button type="submit" class="btn btn-wa btn-sm"><i class="fas fa-save me-1"></i> Save</button>
            <a href="<?= site_url('keywords') ?>" class="btn btn-outline-secondary btn-sm">Cancel</a>
        </div>
    </form>
</div>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= base_url('assets/js/keywords-form.js') ?>?v=3"></script>
<?= $this->endSection() ?>
