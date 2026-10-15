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
$hasFormErrors = ! empty(session('errors'));
$nameValue     = $hasFormErrors && old('name') !== null
    ? (string) old('name')
    : (string) ($automation['name'] ?? '');
$triggerValue  = $hasFormErrors && old('trigger_type') !== null
    ? (string) old('trigger_type')
    : (string) ($automation['trigger_type'] ?? 'incoming_message');
$priorityValue = $hasFormErrors && old('priority') !== null
    ? (string) old('priority')
    : (string) ($automation['priority'] ?? '10');
$activeOn = $hasFormErrors
    ? (string) old('is_active') === '1'
    : ! empty($automation['is_active'] ?? 1);
$tc = $hasFormErrors ? old('trigger_config') : ($automation['trigger_config'] ?? '');
if (is_array($tc)) {
    $tc = json_encode($tc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
}
$tc = trim((string) $tc);
$graph = $automation['flow_graph'] ?? null;
$hasVisual = $isEdit && is_array($graph) && ! empty($graph['nodes']);
$triggerGroups = [
    'WhatsApp' => [
        'incoming_message' => 'Incoming WhatsApp',
        'keyword_matched'  => 'Keyword matched',
        'messenger'        => 'Messenger',
        'instagram'        => 'Instagram',
    ],
    'Contacts' => [
        'contact_created'   => 'New contact',
        'tag_added'         => 'Tag added',
        'attribute_updated' => 'Attribute updated',
        'form_response'     => 'New form response',
        'birthday'          => 'Birthday',
    ],
    'Campaigns' => [
        'campaign_sent'    => 'Campaign sent',
        'campaign_replied' => 'Campaign reply received',
    ],
    'Schedule' => [
        'schedule' => 'Scheduled / cron',
    ],
    'Integrations' => [
        'shopify_event'      => 'Shopify event',
        'facebook_lead'      => 'Facebook lead',
        'kylas_event_create' => 'Kylas event create',
        'kylas_event_update' => 'Kylas event update',
        'pabbly_event'       => 'Pabbly event',
        'incoming_webhook'   => 'Incoming webhook',
        'commerce_event'     => 'Commerce event',
    ],
];
$knownTriggers = [];
foreach ($triggerGroups as $options) {
    $knownTriggers += $options;
}
?>
<div class="page-stack">
<div class="form-shell form-shell-lg">
<form action="<?= $action ?>" method="post" id="automationBuilder" class="auto-form">
    <?= csrf_field() ?>

    <?php if ($hasFormErrors): ?>
        <div class="alert alert-danger mb-0" role="alert">
            <?php foreach ((array) session('errors') as $message): ?>
                <div><?= esc(is_array($message) ? implode(' ', $message) : (string) $message) ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="card">
        <div class="card-body">
            <div class="auto-setup-head">
                <div>
                    <div class="auto-kicker"><?= $isEdit ? 'Workflow' : 'New workflow' ?></div>
                    <h2 class="auto-section-title"><?= $isEdit ? 'Setup' : 'When should this run?' ?></h2>
                    <p class="auto-help">Name the workflow, pick the event, then design the steps in the builder.</p>
                </div>
                <div class="form-check form-switch auto-active">
                    <input class="form-check-input" type="checkbox" name="is_active" value="1" id="isActive" <?= $activeOn ? 'checked' : '' ?>>
                    <label class="form-check-label" for="isActive">Active</label>
                </div>
            </div>

            <div class="row g-3 mt-1">
                <div class="col-md-6">
                    <label class="form-label" for="automationName">Name <span class="text-danger">*</span></label>
                    <input type="text" name="name" id="automationName" class="form-control" required maxlength="191" value="<?= esc($nameValue) ?>" placeholder="Welcome reply, order update…">
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="triggerType">Trigger <span class="text-danger">*</span></label>
                    <select name="trigger_type" id="triggerType" class="form-select" required>
                        <?php foreach ($triggerGroups as $group => $options): ?>
                            <optgroup label="<?= esc($group) ?>">
                                <?php foreach ($options as $val => $label): ?>
                                    <option value="<?= esc($val) ?>" <?= $triggerValue === $val ? 'selected' : '' ?>><?= esc($label) ?></option>
                                <?php endforeach; ?>
                            </optgroup>
                        <?php endforeach; ?>
                        <?php if ($triggerValue !== '' && ! isset($knownTriggers[$triggerValue])): ?>
                            <optgroup label="Other">
                                <option value="<?= esc($triggerValue) ?>" selected><?= esc($triggerValue === 'cheerio_workflow' ? 'Imported External workflow' : $triggerValue) ?></option>
                            </optgroup>
                        <?php endif; ?>
                    </select>
                    <div class="form-text" id="triggerHint"></div>
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="automationPriority">Priority</label>
                    <input type="number" name="priority" id="automationPriority" class="form-control" value="<?= esc($priorityValue) ?>">
                    <div class="form-text">Lower runs first.</div>
                </div>
            </div>

            <div class="auto-query">
                <label class="form-label" for="triggerConfig">Filter query (JSON)</label>
                <textarea name="trigger_config" id="triggerConfig" class="form-control font-monospace" rows="4" placeholder='{"query":"content contains hello"}'><?= esc($tc) ?></textarea>
                <div class="form-text">Optional. Leave this empty to run on every event. A saved query is checked when the trigger fires, and the workflow runs only when it matches.</div>
                <div class="form-text">Examples: <code>{"query":"content contains hello"}</code> · <code>{"keyword":"hello","match_type":"exact"}</code> · <code>{"field":"contact.status","operator":"equals","value":"active"}</code></div>
                <div class="form-text text-danger d-none" id="triggerConfigError" role="alert"></div>
            </div>
        </div>
    </div>

    <?php if ($hasVisual): ?>
        <div class="card">
            <div class="card-body auto-visual">
                <div>
                    <div class="auto-kicker">Flow</div>
                    <h2 class="auto-section-title">This workflow is designed in the builder</h2>
                    <p class="auto-help mb-0">Name, trigger, and priority save from this page. Steps stay on the canvas so a save here does not flatten the flow.</p>
                </div>
                <a href="<?= site_url('automations/' . (int) $automation['id'] . '/builder') ?>" class="btn btn-wa">
                    <i class="fas fa-project-diagram me-1"></i> Open builder
                </a>
            </div>
        </div>
    <?php endif; ?>

    <div class="auto-form-bar">
        <a href="<?= site_url('automations') ?>" class="btn btn-outline-secondary">Cancel</a>
        <button type="submit" class="btn btn-wa">
            <i class="fas fa-<?= $isEdit ? 'save' : 'arrow-right' ?> me-1"></i>
            <?= $isEdit ? 'Save changes' : 'Save and open builder' ?>
        </button>
    </div>
</form>
</div>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= asset_url('assets/js/automations.js') ?>"></script>
<?= $this->endSection() ?>
