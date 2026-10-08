<?php
$emailToolVars = [
    ['group' => 'Contact', 'label' => 'First name', 'tag' => '{{first_name}}'],
    ['group' => 'Contact', 'label' => 'Full name', 'tag' => '{{name}}'],
    ['group' => 'Contact', 'label' => 'Email', 'tag' => '{{email}}'],
    ['group' => 'Contact', 'label' => 'Mobile', 'tag' => '{{mobile}}'],
];
$emailToolsAi = false;
try {
    foreach (service('contactAttributes')->definitions() as $key => $def) {
        $emailToolVars[] = ['group' => 'Attributes', 'label' => (string) ($def['label'] ?? $key), 'tag' => '{{attributes.' . $key . '}}'];
    }
    $emailToolsAi = service('aiService')->isConfigured();
} catch (\Throwable $e) {
    // attributes / AI settings unavailable — contact variables only
}
?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/summernote@0.9.1/dist/summernote-lite.min.css">
<style>
.note-editor.note-frame { border-color: #cbd5e1; border-radius: 6px; background: #fff; }
.note-editor .note-toolbar { background: #f8fafc; border-bottom: 1px solid #e2e8f0; }
.note-editor .note-editable { font-family: Arial, sans-serif; font-size: 14px; line-height: 1.6; color: #1e293b; }
.email-editor-mode { font-size: .74rem; }
.email-editor-tools { display: flex; justify-content: flex-end; align-items: center; gap: .5rem; margin-bottom: .35rem; }
.email-editor-tools .btn { font-size: .78rem; padding: .15rem .6rem; }
.email-editor-tools .email-ai-btn { background: #f3edff; color: #7c3aed; border: 1px solid #dccbff; }
.email-editor-tools .dropdown-menu { max-height: 320px; overflow: auto; }
.email-ai-drawer {
    position: fixed; top: 0; right: 0; bottom: 0; z-index: 1090;
    width: min(400px, 100%); display: flex; flex-direction: column;
    background: #fff; border-left: 1px solid #e9e3ff; box-shadow: -12px 0 32px rgba(76, 29, 149, .14);
    opacity: 0; transform: translateX(24px); visibility: hidden;
    transition: transform .22s ease, opacity .22s ease, visibility .22s;
}
.email-ai-drawer.in-modal { position: absolute; z-index: 20; border-radius: 0 var(--bs-modal-border-radius, .5rem) var(--bs-modal-border-radius, .5rem) 0; }
.email-ai-drawer.is-open { opacity: 1; transform: translateX(0); visibility: visible; }
</style>
<script>
window.APP = window.APP || {};
APP.emailTools = <?= json_encode([
    'vars'  => $emailToolVars,
    'ai'    => $emailToolsAi,
    'aiUrl' => site_url('emails/ai-write'),
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
</script>
<script src="https://cdn.jsdelivr.net/npm/summernote@0.9.1/dist/summernote-lite.min.js"></script>
<script src="<?= asset_url('assets/js/email-editor.js') ?>"></script>
