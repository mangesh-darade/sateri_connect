/**
 * APP.emailEditor — rich-text (Summernote lite) editor bound to an email body <textarea>.
 *
 * The textarea stays the single source of truth: its `value` property is bridged to the
 * editor, so existing `$(el).val()` / `el.value` reads and writes keep working unchanged.
 * Full HTML documents (<html>, <head>, <style>…) are edited as code so designs are not stripped.
 *
 * Auto-init: <textarea data-email-editor>. Dynamic rows: APP.emailEditor.init(el).
 */
(function (window, $) {
    'use strict';

    var APP = window.APP = window.APP || {};
    var nativeValue = Object.getOwnPropertyDescriptor(HTMLTextAreaElement.prototype, 'value');
    var FULL_DOCUMENT = /<(!doctype|html|head|body|style)\b/i;
    var TOOLBAR = [
        ['style', ['style']],
        ['font', ['bold', 'italic', 'underline', 'strikethrough', 'clear']],
        ['fontname', ['fontname']],
        ['fontsize', ['fontsize']],
        ['color', ['color']],
        ['para', ['ul', 'ol', 'paragraph']],
        ['table', ['table']],
        ['insert', ['link', 'picture', 'hr']],
        ['view', ['codeview', 'undo', 'redo']]
    ];

    function available() {
        return !!($ && $.fn && $.fn.summernote);
    }

    function readRaw(el) {
        return nativeValue.get.call(el);
    }

    function writeRaw(el, value) {
        nativeValue.set.call(el, value);
    }

    function notify(el) {
        el.dispatchEvent(new Event('input', { bubbles: true }));
    }

    /** Summernote leaves "<p><br></p>" when empty — treat that as no content. */
    function clean(html) {
        html = String(html || '');
        var probe = document.createElement('div');
        probe.innerHTML = html;
        var hasText = (probe.textContent || '').trim() !== '';
        return hasText || /<(img|hr|table)\b/i.test(html) ? html : '';
    }

    function syncFromEditor(el, html) {
        var state = el._emailEditor;
        if (!state || state.mode !== 'visual' || state.applying) return;
        writeRaw(el, clean(html));
        notify(el);
    }

    function editorBox(el) {
        return $(el).next('.note-editor');
    }

    function renderModeBar(el) {
        var state = el._emailEditor;
        var visual = state.mode === 'visual';
        state.$bar.html(visual
            ? '<span class="text-muted">Formatted email — what you see is what recipients get.</span>'
            : '<span class="text-muted"><i class="fas fa-code me-1"></i>Editing as HTML code.</span> '
              + '<a href="#" class="js-email-editor-visual">Switch to visual editor</a>');
    }

    function setMode(el, mode) {
        var state = el._emailEditor;
        state.mode = mode;
        if (mode === 'visual') {
            state.applying = true;
            $(el).summernote('code', readRaw(el));
            state.applying = false;
            el.style.display = 'none';
            editorBox(el).show();
        } else {
            editorBox(el).hide();
            el.style.display = '';
        }
        renderModeBar(el);
    }

    /** Programmatic value change: route full documents to code mode, everything else to visual. */
    function applyValue(el, value) {
        var state = el._emailEditor;
        if (FULL_DOCUMENT.test(value)) {
            if (state.mode !== 'code') setMode(el, 'code');
            return;
        }
        if (state.mode !== 'visual') {
            setMode(el, 'visual');
            return;
        }
        state.applying = true;
        $(el).summernote('code', value);
        state.applying = false;
    }

    function bridgeValue(el) {
        Object.defineProperty(el, 'value', {
            configurable: true,
            get: function () { return readRaw(el); },
            set: function (value) {
                value = value == null ? '' : String(value);
                var state = el._emailEditor;
                // Summernote's own textarea auto-sync echoes the editor content back — never re-apply it.
                if (state && state.mode === 'visual' && !state.applying && value === $(el).summernote('code')) {
                    writeRaw(el, clean(value));
                    return;
                }
                writeRaw(el, value);
                if (state && !state.applying) applyValue(el, value);
            }
        });
    }

    function init(el, options) {
        el = el && el.jquery ? el[0] : el;
        if (!el || el._emailEditor || !available()) return;

        options = options || {};
        var rows = parseInt(el.getAttribute('rows'), 10) || 8;
        var height = options.height || parseInt(el.getAttribute('data-editor-height'), 10) || Math.max(140, rows * 26);

        el._emailEditor = { mode: 'visual', applying: false, $bar: $('<div class="email-editor-mode small mt-1"></div>') };
        // Hidden textareas cannot be focused, so native "required" would block submit; servers validate the body.
        el.removeAttribute('required');

        $(el).summernote({
            height: height,
            toolbar: TOOLBAR,
            placeholder: options.placeholder || el.getAttribute('data-editor-placeholder') || 'Write your email…',
            disableDragAndDrop: true,
            callbacks: {
                onChange: function (contents) { syncFromEditor(el, contents); },
                onChangeCodeview: function (contents) { syncFromEditor(el, contents); },
                onBlur: function () { $(el).summernote('editor.saveRange'); },
                onImageUpload: function () {
                    var msg = 'Uploaded images are not delivered in email. Use "Image URL" with a public https link.';
                    if (APP.toast) APP.toast(msg, 'warning'); else window.alert(msg);
                }
            }
        });

        editorBox(el).after(el._emailEditor.$bar);
        bridgeValue(el);

        el._emailEditor.$bar.on('click', '.js-email-editor-visual', function (e) {
            e.preventDefault();
            var go = function () { setMode(el, 'visual'); syncFromEditor(el, $(el).summernote('code')); };
            if (!FULL_DOCUMENT.test(readRaw(el))) { go(); return; }
            var text = 'The visual editor keeps the content but removes <html>, <head> and <style> blocks. Continue?';
            if (APP.confirm) {
                APP.confirm({ title: 'Switch to visual editor?', text: text, confirmText: 'Switch' })
                    .then(function (r) { if (r.isConfirmed) go(); });
            } else if (window.confirm(text)) {
                go();
            }
        });

        if (el.form) {
            $(el.form).on('reset', function () {
                setTimeout(function () { el.value = readRaw(el); }, 0);
            });
        }

        applyValue(el, readRaw(el));
        if (el._emailEditor.mode === 'visual') renderModeBar(el);
    }

    /** Insert plain text (e.g. "{{name}}") at the cursor in either mode. */
    function insertText(el, text) {
        el = el && el.jquery ? el[0] : el;
        if (!el) return;
        if (el._emailEditor && el._emailEditor.mode === 'visual') {
            $(el).summernote('editor.restoreRange');
            $(el).summernote('editor.focus');
            $(el).summernote('editor.insertText', text);
            return;
        }
        var start = el.selectionStart || 0;
        var end = el.selectionEnd || 0;
        var current = el.value;
        el.value = current.substring(0, start) + text + current.substring(end);
        el.focus();
        el.selectionStart = el.selectionEnd = start + text.length;
        notify(el);
    }

    APP.emailEditor = { init: init, insertText: insertText };

    $(function () {
        $('textarea[data-email-editor]').each(function () { init(this); });
    });
})(window, window.jQuery);
