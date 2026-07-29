<?= $this->extend('layouts/main') ?>

<?= $this->section('styles') ?>
<link rel="stylesheet" href="<?= base_url('assets/css/automations-flow.css') ?>?v=5">
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php
$automation = $automation ?? [];
$id = (int) ($automation['id'] ?? 0);
$graph = $flowGraph ?? ['nodes' => [], 'edges' => []];
?>
<div class="flow-shell" id="flowBuilder"
     data-id="<?= $id ?>"
     data-save-url="<?= site_url('automations/' . $id . '/builder') ?>"
     data-csrf="<?= csrf_hash() ?>">

    <header class="flow-topbar">
        <div class="flow-topbar-left">
            <a href="<?= site_url('automations') ?>" class="btn btn-sm btn-outline-secondary" title="Back to workflows"><i class="fas fa-arrow-left"></i></a>
            <input type="text" id="flowName" class="flow-name-input" value="<?= esc($automation['name'] ?? 'Untitled workflow') ?>" maxlength="191" aria-label="Workflow name">
            <span class="flow-pill" id="flowTriggerPill"><?= esc($automation['trigger_type'] ?? 'incoming_message') ?></span>
        </div>
        <div class="flow-topbar-right">
            <label class="flow-switch mb-0">
                <input type="checkbox" id="flowActive" <?= ! empty($automation['is_active']) ? 'checked' : '' ?>>
                <span>Active</span>
            </label>
            <button type="button" class="btn btn-sm btn-outline-secondary" id="btnFlowZoomOut" title="Zoom out"><i class="fas fa-search-minus"></i></button>
            <span class="flow-zoom-label" id="flowZoomLabel">100%</span>
            <button type="button" class="btn btn-sm btn-outline-secondary" id="btnFlowZoomIn" title="Zoom in"><i class="fas fa-search-plus"></i></button>
            <button type="button" class="btn btn-sm btn-outline-secondary" id="btnFlowZoomFit" title="Fit to view"><i class="fas fa-compress-arrows-alt"></i></button>
            <button type="button" class="btn btn-sm btn-wa" id="btnFlowSave"><i class="fas fa-save me-1"></i> Save</button>
        </div>
    </header>

    <div class="flow-workspace">
        <aside class="flow-palette">
            <h6>When this happens</h6>
            <div class="palette-item" draggable="true" data-palette="trigger" data-trigger="incoming_message">
                <i class="fab fa-whatsapp"></i> Incoming WhatsApp
            </div>
            <div class="palette-item" draggable="true" data-palette="trigger" data-trigger="campaign_sent">
                <i class="fas fa-paper-plane"></i> Campaign Sent
            </div>
            <div class="palette-item" draggable="true" data-palette="trigger" data-trigger="shopify_event">
                <i class="fas fa-shopping-bag"></i> Shopify Events
            </div>
            <div class="palette-item" draggable="true" data-palette="trigger" data-trigger="facebook_lead">
                <i class="fab fa-facebook"></i> Facebook Lead
            </div>
            <div class="palette-item" draggable="true" data-palette="trigger" data-trigger="kylas_event_create">
                <i class="fas fa-plus-square"></i> Kylas Event Create
            </div>
            <div class="palette-item" draggable="true" data-palette="trigger" data-trigger="kylas_event_update">
                <i class="fas fa-sync-alt"></i> Kylas Event Update
            </div>
            <div class="palette-item" draggable="true" data-palette="trigger" data-trigger="pabbly_event">
                <i class="fas fa-bolt"></i> Pabbly Event
            </div>
            <div class="palette-item" draggable="true" data-palette="trigger" data-trigger="incoming_webhook">
                <i class="fas fa-link"></i> Incoming Webhook
            </div>
            <div class="palette-item" draggable="true" data-palette="trigger" data-trigger="messenger">
                <i class="fab fa-facebook-messenger"></i> Messenger
            </div>
            <div class="palette-item" draggable="true" data-palette="trigger" data-trigger="instagram">
                <i class="fab fa-instagram"></i> Instagram
            </div>
            <div class="palette-item" draggable="true" data-palette="trigger" data-trigger="commerce_event">
                <i class="fas fa-store"></i> Commerce Event
            </div>
            <div class="palette-item" draggable="true" data-palette="trigger" data-trigger="contact_created">
                <i class="fas fa-user-plus"></i> New contact
            </div>
            <div class="palette-item" draggable="true" data-palette="trigger" data-trigger="form_response">
                <i class="fas fa-wpforms"></i> New form response
            </div>

            <h6 class="mt-3">More triggers</h6>
            <div class="palette-item" draggable="true" data-palette="trigger" data-trigger="keyword_matched">
                <i class="fas fa-key"></i> Keyword matched
            </div>
            <div class="palette-item" draggable="true" data-palette="trigger" data-trigger="tag_added">
                <i class="fas fa-tags"></i> Tag added
            </div>
            <div class="palette-item" draggable="true" data-palette="trigger" data-trigger="birthday">
                <i class="fas fa-birthday-cake"></i> Birthday
            </div>
            <div class="palette-item" draggable="true" data-palette="trigger" data-trigger="campaign_replied">
                <i class="fas fa-reply"></i> Campaign reply
            </div>

            <h6 class="mt-3">Logic</h6>
            <div class="palette-item" draggable="true" data-palette="condition" data-condition="message_contains">
                <i class="fas fa-code-branch"></i> If / Else
            </div>
            <div class="palette-item" draggable="true" data-palette="condition" data-condition="message_equals">
                <i class="fas fa-equals"></i> Message equals
            </div>
            <div class="palette-item" draggable="true" data-palette="condition" data-condition="caption_contains">
                <i class="fas fa-closed-captioning"></i> Caption contains
            </div>
            <div class="palette-item" draggable="true" data-palette="condition" data-condition="message_type">
                <i class="fas fa-photo-video"></i> Message type
            </div>
            <div class="palette-item" draggable="true" data-palette="condition" data-condition="has_tag">
                <i class="fas fa-tag"></i> Has tag
            </div>
            <div class="palette-item" draggable="true" data-palette="condition" data-condition="within_window">
                <i class="fas fa-clock"></i> Within 24h window
            </div>
            <div class="palette-item" draggable="true" data-palette="condition" data-condition="contact_status">
                <i class="fas fa-user-check"></i> Contact status
            </div>
            <div class="palette-item" draggable="true" data-palette="condition" data-condition="attribute_condition">
                <i class="fas fa-sliders-h"></i> Attribute condition
            </div>

            <h6 class="mt-3">Actions</h6>
            <div class="palette-item" draggable="true" data-palette="action" data-action="system_initiated">
                <i class="fas fa-robot"></i> System initiated
            </div>
            <div class="palette-item" draggable="true" data-palette="action" data-action="response_message">
                <i class="fab fa-whatsapp"></i> Response message
            </div>
            <div class="palette-item" draggable="true" data-palette="action" data-action="collect_images">
                <i class="fas fa-images"></i> Collect Images
            </div>
            <div class="palette-item" draggable="true" data-palette="action" data-action="send_template">
                <i class="fas fa-file-alt"></i> Send WA template
            </div>

            <h6 class="mt-3">More actions</h6>
            <div class="palette-item" draggable="true" data-palette="action" data-action="send_text">
                <i class="fas fa-comment"></i> Send text
            </div>
            <div class="palette-item" draggable="true" data-palette="action" data-action="send_email">
                <i class="fas fa-envelope"></i> Send email
            </div>
            <div class="palette-item" draggable="true" data-palette="action" data-action="set_attribute">
                <i class="fas fa-pen"></i> Update attribute
            </div>
            <div class="palette-item" draggable="true" data-palette="action" data-action="add_tag">
                <i class="fas fa-tag"></i> Add tag
            </div>
            <div class="palette-item" draggable="true" data-palette="action" data-action="remove_tag">
                <i class="fas fa-minus-circle"></i> Remove tag
            </div>
            <div class="palette-item" draggable="true" data-palette="action" data-action="assign_agent">
                <i class="fas fa-user-tie"></i> Assign agent
            </div>
            <div class="palette-item" draggable="true" data-palette="action" data-action="assign_bot">
                <i class="fas fa-robot"></i> Assign to bot
            </div>
            <div class="palette-item" draggable="true" data-palette="action" data-action="update_chat_status">
                <i class="fas fa-comments"></i> Update chat status
            </div>
            <div class="palette-item" draggable="true" data-palette="action" data-action="add_note">
                <i class="fas fa-sticky-note"></i> Add note
            </div>
            <div class="palette-item" draggable="true" data-palette="action" data-action="delay">
                <i class="fas fa-clock"></i> Delay
            </div>
            <div class="palette-item" draggable="true" data-palette="action" data-action="webhook">
                <i class="fas fa-plug"></i> Webhook
            </div>
            <div class="palette-item" draggable="true" data-palette="end" data-action="end">
                <i class="fas fa-flag-checkered"></i> End
            </div>
            <p class="palette-hint">Drag blocks onto the canvas. Connect left←right ports by dragging from green dots to grey dots. Pan with Space+drag or middle mouse.</p>
        </aside>

        <main class="flow-canvas-wrap" id="flowCanvasWrap">
            <div class="flow-viewport" id="flowViewport">
                <svg id="flowEdges" class="flow-edges">
                    <defs>
                        <marker id="flowArrow" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse">
                            <path d="M 0 0 L 10 5 L 0 10 z" fill="rgba(7, 94, 84, 0.45)"></path>
                        </marker>
                        <marker id="flowArrowTrue" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse">
                            <path d="M 0 0 L 10 5 L 0 10 z" fill="#8e53f7"></path>
                        </marker>
                        <marker id="flowArrowFalse" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse">
                            <path d="M 0 0 L 10 5 L 0 10 z" fill="#e25555"></path>
                        </marker>
                    </defs>
                </svg>
                <div id="flowCanvas" class="flow-canvas"></div>
            </div>
            <div class="flow-hint-bar"><span>Drag nodes · Connect green → grey ports · Space/middle-drag to pan · Ctrl+scroll zoom · Click edge then Delete</span></div>
        </main>

        <aside class="flow-inspector" id="flowInspector">
            <h6>Inspector</h6>
            <p class="text-muted small mb-0" id="inspectorEmpty">Select a node to edit its settings.</p>
            <div id="inspectorBody" class="d-none"></div>
            <button type="button" class="btn btn-sm btn-outline-danger mt-3 d-none" id="btnDeleteNode">Delete node</button>
        </aside>
    </div>
</div>

<textarea id="flowGraphJson" class="d-none"></textarea>
<script type="application/json" id="flowGraphData"><?= json_encode($graph, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS) ?></script>
<script type="application/json" id="flowMetaJson"><?= json_encode([
    'tags' => array_map(static fn ($t) => ['id' => (int) $t['id'], 'name' => $t['name']], $tags ?? []),
    'templates' => array_map(static fn ($t) => [
        'id' => (int) $t['id'],
        'name' => $t['name'],
        'language' => $t['language'] ?? 'en_US',
    ], $templates ?? []),
    'agents' => array_map(static fn ($u) => ['id' => (int) $u['id'], 'name' => $u['name']], $agents ?? []),
    'attributes' => array_values($attributes ?? []),
    'webhook_base' => rtrim(site_url('webhooks/automation'), '/'),
], JSON_UNESCAPED_UNICODE) ?></script>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= base_url('assets/js/automations-flow.js') ?>?v=9"></script>
<?= $this->endSection() ?>
