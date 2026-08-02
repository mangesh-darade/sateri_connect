<?= $this->extend('layouts/main') ?>

<?= $this->section('header_actions') ?>
<?php
$automation = $automation ?? [];
$isEdit = ! empty($automation['id']);
?>
<?php if ($isEdit): ?>
    <a href="<?= site_url('automations/' . (int) $automation['id'] . '/builder') ?>" class="btn btn-sm btn-wa">
        <i class="fas fa-project-diagram me-1"></i> Open builder
    </a>
<?php endif; ?>
<a href="<?= site_url('automations') ?>" class="btn btn-outline-secondary btn-sm"><i class="fas fa-arrow-left me-1"></i> Back</a>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php
$automation = $automation ?? [];
$isEdit = ! empty($automation['id']);
$action = $isEdit
    ? site_url('automations/' . (int) $automation['id'])
    : site_url('automations');
// Prefer DB values on normal GET; only use old() after a failed validation redirect.
$hasFormErrors = ! empty(session('errors'));
$nameValue     = $hasFormErrors && old('name') !== null
    ? (string) old('name')
    : (string) ($automation['name'] ?? '');
$triggerValue  = $hasFormErrors && old('trigger_type') !== null
    ? (string) old('trigger_type')
    : (string) ($automation['trigger_type'] ?? '');
?>
<div class="page-stack">
<div class="form-shell form-shell-lg">
<div class="card form-card" id="automationBuilder">
    <form action="<?= $action ?>" method="post">
        <?= csrf_field() ?>
        <div class="card-body">
            <div class="row g-2">
                <div class="col-md-6">
                    <label class="form-label">Name <span class="text-danger">*</span></label>
                    <input type="text" name="name" class="form-control" required value="<?= esc($nameValue) ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Trigger <span class="text-danger">*</span></label>
                    <select name="trigger_type" id="triggerType" class="form-select" required>
                        <option value="">Select trigger…</option>
                        <?php
                        $triggers = [
                            'incoming_message'   => 'Incoming WhatsApp',
                            'campaign_sent'      => 'Campaign Sent',
                            'shopify_event'      => 'Shopify Events',
                            'facebook_lead'      => 'Facebook Lead',
                            'kylas_event_create' => 'Kylas Event Create',
                            'kylas_event_update' => 'Kylas Event Update',
                            'pabbly_event'       => 'Pabbly Event',
                            'incoming_webhook'   => 'Incoming Webhook',
                            'messenger'          => 'Messenger',
                            'instagram'          => 'Instagram',
                            'commerce_event'     => 'Commerce Event',
                            'contact_created'    => 'New contact',
                            'form_response'      => 'New form response',
                            'keyword_matched'    => 'Keyword matched',
                            'campaign_replied'   => 'Campaign reply received',
                            'tag_added'          => 'Tag added',
                            'birthday'           => 'Birthday',
                            'schedule'           => 'Scheduled / cron',
                            'cheerio_workflow'   => 'Imported Cheerio workflow',
                        ];
                        $current = $triggerValue;
                        foreach ($triggers as $val => $label):
                        ?>
                            <option value="<?= $val ?>" <?= $current === $val ? 'selected' : '' ?>><?= esc($label) ?></option>
                        <?php endforeach; ?>
                        <?php if ($current !== '' && ! isset($triggers[$current])): ?>
                            <option value="<?= esc($current) ?>" selected><?= esc($current) ?> (custom)</option>
                        <?php endif; ?>
                    </select>
                    <?php if ($current === 'cheerio_workflow'): ?>
                        <div class="form-text">Imported from Cheerio — keep the name matching the original Cheerio workflow title. Sends use the active provider (<?= esc(function_exists('whatsapp_provider_short') ? whatsapp_provider_short() : 'Cheerio') ?>).</div>
                    <?php endif; ?>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Priority</label>
                    <input type="number" name="priority" class="form-control" value="<?= esc(old('priority') ?? ($automation['priority'] ?? '10')) ?>">
                </div>
            </div>
            <div class="form-check form-switch my-3">
                <input class="form-check-input" type="checkbox" name="is_active" value="1" id="isActive" <?= ! empty(old('is_active') ?? ($automation['is_active'] ?? 1)) ? 'checked' : '' ?>>
                <label class="form-check-label" for="isActive">Active</label>
            </div>
            <div class="mb-3">
                <label class="form-label">Trigger config (JSON, optional)</label>
                <textarea name="trigger_config" class="form-control font-monospace" rows="2" placeholder='{"keyword":"hello"}'><?php
                    $tc = old('trigger_config') ?? ($automation['trigger_config'] ?? '');
                    echo esc(is_array($tc) ? json_encode($tc) : (string) $tc);
                ?></textarea>
            </div>

            <div class="d-flex justify-content-between align-items-center mb-2">
                <h5 class="mb-0" style="font-family:var(--font-display);font-size:0.95rem">Rules / steps</h5>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="btnAddCondition"><i class="fas fa-plus me-1"></i> Condition</button>
                    <button type="button" class="btn btn-sm btn-wa" id="btnAddAction"><i class="fas fa-plus me-1"></i> Action</button>
                </div>
            </div>
            <?php
            $rules = $rules ?? ($automation['rules'] ?? []);
            if (is_string($rules)) {
                $decoded = json_decode($rules, true);
                $rules = is_array($decoded) ? $decoded : [];
            }
            ?>
            <textarea id="existingRules" class="d-none"><?= esc(json_encode(array_values($rules ?: []))) ?></textarea>
            <div id="rulesList"></div>
        </div>
        <div class="card-footer d-flex flex-wrap gap-2">
            <button type="submit" class="btn btn-wa"><i class="fas fa-save me-1"></i> Save automation</button>
            <?php if ($isEdit): ?>
                <a href="<?= site_url('automations/' . (int) $automation['id'] . '/builder') ?>" class="btn btn-outline-secondary">Open builder</a>
            <?php endif; ?>
            <a href="<?= site_url('automations') ?>" class="btn btn-outline-secondary">Cancel</a>
        </div>
    </form>
</div>
</div>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= base_url('assets/js/automations.js') ?>"></script>
<?= $this->endSection() ?>
