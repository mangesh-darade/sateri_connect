/**
 * Keywords create/edit — auto-build response_payload JSON
 * from Response content + type, shaped for active Cheerio / Meta provider.
 * Supports text, template, image/video/document + caption, interactive, workflow.
 */
(function (window, $) {
    'use strict';

    var $card = $('#keywordFormCard');
    if (!$card.length) return;

    var provider = String($card.data('provider') || (window.APP && APP.whatsappProvider) || 'cheerio').toLowerCase();
    var providerShort = String($card.data('provider-short') || (provider === 'meta' ? 'Meta' : 'Cheerio'));
    var $content = $('#responseContent');
    var $type = $('#responseType');
    var $payload = $('#responsePayload');
    var $hint = $('#kwPayloadHint');
    var $mediaUrl = $('#kwMediaUrl');
    var $caption = $('#kwCaption');
    var $autoId = $('#kwAutomationId');
    var $ack = $('#kwAckText');
    var $tplSelect = $('#kwTemplateSelect');
    var manualLock = false;
    var lastAutoJson = '';
    var debounceTimer = null;
    var syncing = false;

    function pretty(obj) {
        return JSON.stringify(obj, null, 2);
    }

    function isHttpUrl(s) {
        return /^https?:\/\//i.test(String(s || '').trim());
    }

    function isMediaType(type) {
        return type === 'image' || type === 'video' || type === 'document';
    }

    function toggleExtraFields() {
        var type = String($type.val() || 'text');
        $('.kw-media-fields').toggleClass('d-none', !isMediaType(type));
        $('.kw-workflow-fields').toggleClass('d-none', type !== 'workflow' && type !== 'automation');
        $('.kw-template-fields').toggleClass('d-none', type !== 'template');
    }

    function parseContentLines(text) {
        var lines = String(text || '').split(/\r?\n/).map(function (l) { return l.trim(); }).filter(Boolean);
        var url = '';
        var caption = '';
        lines.forEach(function (line) {
            if (!url && isHttpUrl(line)) url = line;
            else if (!caption) caption = line;
            else caption += ' ' + line;
        });
        if (!url && isHttpUrl(String(text || '').trim())) {
            url = String(text || '').trim();
            caption = '';
        }
        return { url: url, caption: caption };
    }

    function syncExtrasFromContent() {
        if (syncing) return;
        syncing = true;
        var type = String($type.val() || 'text');
        var text = $content.val() || '';
        if (isMediaType(type)) {
            var p = parseContentLines(text);
            if (p.url) $mediaUrl.val(p.url);
            if (p.caption || !$mediaUrl.val()) $caption.val(p.caption);
            if (!p.url && !p.caption && text && !isHttpUrl(text)) $caption.val(text);
        }
        if (type === 'workflow' || type === 'automation') {
            var id = String(text || '').trim();
            if (/^\d+$/.test(id)) $autoId.val(id);
        }
        syncing = false;
    }

    function syncContentFromExtras() {
        if (syncing) return;
        syncing = true;
        var type = String($type.val() || 'text');
        if (isMediaType(type)) {
            var url = String($mediaUrl.val() || '').trim();
            var cap = String($caption.val() || '').trim();
            var parts = [];
            if (url) parts.push(url);
            if (cap) parts.push(cap);
            $content.val(parts.join('\n'));
        }
        if (type === 'workflow' || type === 'automation') {
            var id = String($autoId.val() || '').trim();
            if (id) $content.val(id);
        }
        syncing = false;
    }

    function buildPayload(responseType, content) {
        var text = String(content || '').trim();
        var type = String(responseType || 'text');
        var base = {
            _provider: provider,
            _generated: true
        };

        if (type === 'text') {
            return Object.assign(base, {
                type: 'text',
                text: { preview_url: false, body: text }
            });
        }

        if (type === 'template') {
            var name = text || 'hello_world';
            var lang = provider === 'meta' ? 'en_US' : 'en';
            return Object.assign(base, {
                type: 'template',
                template_name: name,
                name: name,
                language: lang,
                components: [],
                template: {
                    name: name,
                    language: { code: lang },
                    components: []
                }
            });
        }

        if (isMediaType(type)) {
            var url = String($mediaUrl.val() || '').trim();
            var caption = String($caption.val() || '').trim();
            if (!url || !caption) {
                var parsed = parseContentLines(text);
                if (!url) url = parsed.url;
                if (!caption) caption = parsed.caption;
            }
            var media = url ? { link: url } : { link: '' };
            if (caption) media.caption = caption;
            if (type === 'document') media.filename = 'file';
            var out = Object.assign(base, {
                type: type,
                link: url,
                caption: caption || undefined
            });
            out[type] = media;
            if (type === 'document') out.filename = 'file';
            return out;
        }

        if (type === 'workflow' || type === 'automation') {
            var aid = parseInt(String($autoId.val() || text || '0'), 10) || 0;
            var ack = String($ack.val() || '').trim();
            return Object.assign(base, {
                type: 'workflow',
                automation_id: aid,
                workflow_id: aid,
                ack_text: ack || undefined
            });
        }

        if (type === 'list' || type === 'interactive_list') {
            return Object.assign(base, {
                type: 'interactive',
                body: text || 'Please choose an option:',
                button_text: 'Menu',
                header: null,
                footer: null,
                sections: [
                    {
                        title: 'Options',
                        rows: [
                            { id: 'opt_1', title: 'Option 1', description: 'Edit or replace this row' },
                            { id: 'opt_2', title: 'Option 2', description: 'Add more rows as needed' }
                        ]
                    }
                ],
                interactive: {
                    type: 'list',
                    body: { text: text || 'Please choose an option:' },
                    action: {
                        button: 'Menu',
                        sections: [
                            {
                                title: 'Options',
                                rows: [
                                    { id: 'opt_1', title: 'Option 1', description: 'Edit or replace this row' },
                                    { id: 'opt_2', title: 'Option 2', description: 'Add more rows as needed' }
                                ]
                            }
                        ]
                    }
                }
            });
        }

        if (type === 'buttons' || type === 'interactive_buttons' || type === 'quick_reply') {
            return Object.assign(base, {
                type: 'interactive',
                body: text || 'Please choose:',
                header: null,
                footer: null,
                buttons: [
                    { id: 'btn_1', title: 'Yes' },
                    { id: 'btn_2', title: 'No' }
                ],
                interactive: {
                    type: 'button',
                    body: { text: text || 'Please choose:' },
                    action: {
                        buttons: [
                            { type: 'reply', reply: { id: 'btn_1', title: 'Yes' } },
                            { type: 'reply', reply: { id: 'btn_2', title: 'No' } }
                        ]
                    }
                }
            });
        }

        if (type === 'menu') {
            return Object.assign(base, {
                type: 'menu',
                body: text || 'Please choose an option:',
                button_text: 'Options',
                header: null,
                footer: null
            });
        }

        return Object.assign(base, {
            type: 'text',
            text: { preview_url: false, body: text }
        });
    }

    function currentPayloadIsAuto() {
        var raw = $.trim($payload.val() || '');
        if (!raw) return true;
        if (raw === lastAutoJson) return true;
        try {
            var obj = JSON.parse(raw);
            return !!(obj && obj._generated === true);
        } catch (e) {
            return false;
        }
    }

    function applyPayload(force) {
        var content = $content.val() || '';
        var type = String($type.val() || 'text');
        if (!force && manualLock && !currentPayloadIsAuto()) {
            $hint.text('Payload locked (manual edit). Change content/type or click Regenerate to refresh ' + providerShort + ' JSON.');
            return;
        }
        var obj = buildPayload(type, content);
        lastAutoJson = pretty(obj);
        $payload.val(lastAutoJson);
        manualLock = false;
        $hint.text('Auto-generated for ' + providerShort + ' (' + type + '). Edit freely, or Regenerate to rebuild.');
    }

    function scheduleApply(force) {
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(function () { applyPayload(!!force); }, force ? 0 : 280);
    }

    function hydrateExtrasFromPayload() {
        try {
            var obj = JSON.parse($payload.val() || '{}');
            if (!obj || typeof obj !== 'object') return;
            if (obj.link) $mediaUrl.val(obj.link);
            if (obj.caption) $caption.val(obj.caption);
            if (obj.automation_id) $autoId.val(String(obj.automation_id));
            if (obj.ack_text) $ack.val(obj.ack_text);
            if (obj.template_name || obj.name) {
                var tplName = obj.template_name || obj.name;
                if ($tplSelect.find('option[value="' + tplName + '"]').length) {
                    $tplSelect.val(tplName);
                }
            }
            var media = obj.image || obj.video || obj.document;
            if (media) {
                if (media.link) $mediaUrl.val(media.link);
                if (media.caption) $caption.val(media.caption);
            }
        } catch (e) { /* ignore */ }
    }

    $content.on('input', function () {
        syncExtrasFromContent();
        scheduleApply(false);
    });
    $type.on('change', function () {
        toggleExtraFields();
        syncExtrasFromContent();
        scheduleApply(true);
    });
    $mediaUrl.add($caption).on('input', function () {
        syncContentFromExtras();
        scheduleApply(false);
    });
    $autoId.add($ack).on('change input', function () {
        syncContentFromExtras();
        scheduleApply(false);
    });
    $tplSelect.on('change', function () {
        var name = $(this).val();
        if (name) {
            $content.val(name);
            scheduleApply(true);
        }
    });
    $payload.on('input', function () {
        var raw = $.trim($payload.val() || '');
        if (raw && raw !== lastAutoJson) {
            manualLock = true;
            $hint.text('Manual edit detected — auto-update paused. Click Regenerate to rebuild from content.');
        }
    });
    $('#btnRegenPayload').on('click', function () {
        manualLock = false;
        applyPayload(true);
        if (window.APP && APP.toast) APP.toast('Payload regenerated for ' + providerShort, 'success');
    });

    toggleExtraFields();
    hydrateExtrasFromPayload();

    var initial = $.trim($payload.val() || '');
    if (!initial) {
        applyPayload(true);
    } else {
        try {
            var parsed = JSON.parse(initial);
            if (parsed && parsed._generated) {
                lastAutoJson = pretty(parsed);
            } else {
                manualLock = true;
                lastAutoJson = '';
            }
        } catch (e) {
            manualLock = true;
        }
    }
})(window, jQuery);
