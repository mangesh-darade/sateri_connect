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

        if (options.tools !== false && el.getAttribute('data-email-tools') !== 'off') {
            mountTools(el, options);
        }
    }

    /* ---------- Shared tools: "+ Variable" and "Write with AI" (config: APP.emailTools) ---------- */

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function toast(msg, type) {
        if (APP.toast) APP.toast(msg, type || 'info'); else window.alert(msg);
    }

    /** Subject input paired with an editor: option / data-email-subject selector, else nearest subject field. */
    function findSubject(el, options) {
        var sel = options.subject || el.getAttribute('data-email-subject');
        if (sel) return $(sel).get(0) || null;
        var $scope = $(el).parent();
        for (var i = 0; i < 6 && $scope.length; i++) {
            var $s = $scope.find('input[name="subject"], input.step-subject, input[id$="Subject"], input[id$="_subject"]').first();
            if ($s.length) return $s.get(0);
            $scope = $scope.parent();
        }
        return null;
    }

    function varMenuHtml() {
        var vars = (APP.emailTools && APP.emailTools.vars) || [];
        var html = '', group = '';
        vars.forEach(function (v) {
            if (v.group !== group) {
                group = v.group;
                html += '<li><h6 class="dropdown-header">' + esc(group) + '</h6></li>';
            }
            html += '<li><button type="button" class="dropdown-item d-flex justify-content-between gap-3 js-email-var" data-tag="' + esc(v.tag) + '">'
                + '<span>' + esc(v.label) + '</span><code class="small text-muted">' + esc(v.tag) + '</code></button></li>';
        });
        return html;
    }

    function mountTools(el, options) {
        var subject = findSubject(el, options);
        var state = el._emailEditor;
        state.subject = subject;
        state.lastField = el;

        var $bar = $('<div class="email-editor-tools"></div>');
        $bar.append(
            '<div class="dropdown">'
            + '<button type="button" class="btn btn-sm btn-outline-secondary rounded-pill" data-bs-toggle="dropdown" aria-expanded="false" title="Inserts at the cursor in the subject or content">'
            + '<i class="fas fa-plus me-1"></i>Variable</button>'
            + '<ul class="dropdown-menu dropdown-menu-end shadow-sm">' + varMenuHtml() + '</ul></div>'
        );
        if (APP.emailTools && APP.emailTools.ai) {
            $bar.append('<button type="button" class="btn btn-sm rounded-pill email-ai-btn"><i class="fas fa-wand-magic-sparkles me-1"></i>Write with AI</button>');
        }
        $(el).before($bar);

        if (subject) {
            $(subject).on('focus', function () { state.lastField = subject; });
        }
        $(el).on('summernote.focus', function () { state.lastField = el; });
        editorBox(el).on('focusin', function () { state.lastField = el; });
        $(el).on('focus', function () { state.lastField = el; });

        $bar.on('mousedown', '.js-email-var', function (e) { e.preventDefault(); });
        $bar.on('click', '.js-email-var', function () {
            var target = state.lastField && document.body.contains(state.lastField) ? state.lastField : el;
            if (target === el) {
                insertText(el, String($(this).data('tag') || ''));
            } else {
                insertIntoInput(target, String($(this).data('tag') || ''));
            }
        });
        $bar.on('click', '.email-ai-btn', function () { openAiDrawer(el); });
    }

    function insertIntoInput(input, text) {
        var start = input.selectionStart != null ? input.selectionStart : input.value.length;
        var end = input.selectionEnd != null ? input.selectionEnd : start;
        input.value = input.value.substring(0, start) + text + input.value.substring(end);
        input.focus();
        input.selectionStart = input.selectionEnd = start + text.length;
        input.dispatchEvent(new Event('input', { bubbles: true }));
    }

    function hasContent(el) {
        return String(el.value || '').replace(/<[^>]+>/g, '').trim() !== '';
    }

    var $drawer = null;
    var drawerTarget = null;

    function buildDrawer() {
        $drawer = $(
            '<aside class="email-ai-drawer" aria-hidden="true">'
            + '<div class="d-flex align-items-center justify-content-between px-3 py-2 border-bottom">'
            + '<span class="fw-semibold"><i class="fas fa-wand-magic-sparkles me-1" style="color:#7c3aed"></i>Write with AI</span>'
            + '<button type="button" class="btn-close js-ai-close" aria-label="Close"></button></div>'
            + '<div class="p-3 d-flex flex-column gap-2 flex-grow-1 overflow-auto">'
            + '<label class="form-label fw-semibold small mb-0">What should the email say?</label>'
            + '<textarea class="form-control js-ai-brief" rows="7" maxlength="2000" placeholder="e.g. Mango season sale: 20% off on Alphonso mangoes till Sunday, free delivery above ₹999, order on WhatsApp"></textarea>'
            + '<div class="row g-2"><div class="col-6"><label class="form-label small text-muted mb-1">Tone</label>'
            + '<select class="form-select form-select-sm js-ai-tone"><option value="friendly">Friendly</option><option value="professional">Professional</option>'
            + '<option value="promotional">Promotional</option><option value="urgent">Urgent</option><option value="formal">Formal</option></select></div>'
            + '<div class="col-6"><label class="form-label small text-muted mb-1">Language</label>'
            + '<select class="form-select form-select-sm js-ai-lang"><option value="English">English</option><option value="Marathi">Marathi</option><option value="Hindi">Hindi</option></select></div></div>'
            + '<div class="form-text mt-0">The subject and email content fill in automatically. For "Improve current", write an instruction like "make it shorter".</div>'
            + '</div>'
            + '<div class="d-flex gap-2 p-3 border-top">'
            + '<button type="button" class="btn btn-outline-secondary btn-sm d-none js-ai-run" data-mode="edit">Improve current</button>'
            + '<button type="button" class="btn btn-wa btn-sm ms-auto js-ai-run" data-mode="new"><i class="fas fa-wand-magic-sparkles me-1"></i>Write email</button>'
            + '</div></aside>'
        );
        $drawer.on('click', '.js-ai-close', closeAiDrawer);
        $drawer.on('click', '.js-ai-run', function () { runAi($(this).data('mode'), $(this)); });
        $(document).on('hide.bs.modal', function (e) {
            if ($drawer && $.contains(e.target, $drawer[0])) closeAiDrawer();
        });
    }

    function openAiDrawer(el) {
        if (!$drawer) buildDrawer();
        drawerTarget = el;
        // Inside a modal the drawer must live in .modal-content (Bootstrap's focus trap blocks outside inputs).
        var $host = $(el).closest('.modal-content');
        $drawer.toggleClass('in-modal', $host.length > 0).appendTo($host.length ? $host : document.body);
        $drawer.find('.js-ai-run[data-mode="edit"]').toggleClass('d-none', !hasContent(el));
        $drawer[0].offsetWidth; // reflow so the slide-in transition runs after re-parenting
        $drawer.addClass('is-open').attr('aria-hidden', 'false');
        setTimeout(function () { $drawer.find('.js-ai-brief').trigger('focus'); }, 150);
    }

    function closeAiDrawer() {
        if ($drawer) $drawer.removeClass('is-open').attr('aria-hidden', 'true');
    }

    function runAi(mode, $btn) {
        var el = drawerTarget;
        if (!el) return;
        var brief = String($drawer.find('.js-ai-brief').val() || '').trim();
        if (!brief) {
            toast(mode === 'edit' ? 'Write what to change, e.g. "make it shorter".' : 'Describe what the email should say.', 'warning');
            $drawer.find('.js-ai-brief').trigger('focus');
            return;
        }
        var subject = el._emailEditor && el._emailEditor.subject;
        var go = function () {
            var $all = $drawer.find('.js-ai-run').prop('disabled', true);
            var original = $btn.html();
            $btn.html('<span class="spinner-border spinner-border-sm me-1"></span>Writing…');
            APP.post(APP.emailTools.aiUrl, {
                mode: mode,
                brief: brief,
                tone: $drawer.find('.js-ai-tone').val(),
                language: $drawer.find('.js-ai-lang').val(),
                subject: mode === 'edit' && subject ? subject.value : '',
                html: mode === 'edit' ? el.value : ''
            }).done(function (res) {
                var data = (res && res.data) || {};
                if (!data.html) {
                    toast((res && res.message) || 'AI could not write the email.', 'error');
                    return;
                }
                if (subject && data.subject) {
                    subject.value = data.subject;
                    $(subject).removeClass('is-invalid').trigger('input');
                }
                el.value = data.html;
                $(el).trigger('email-ai:applied', [data]);
                closeAiDrawer();
                toast(mode === 'edit' ? 'Email updated with AI. Review before sending.' : 'Email drafted with AI. Review and edit before sending.', 'success');
            }).fail(function (xhr) {
                toast((xhr.responseJSON && xhr.responseJSON.message) || 'AI request failed. Try again.', 'error');
            }).always(function () {
                $all.prop('disabled', false);
                $btn.html(original);
            });
        };
        if (mode === 'new' && hasContent(el) && APP.confirm) {
            APP.confirm({ title: 'Replace current email?', text: 'AI will replace the current subject and content.', confirmText: 'Replace' })
                .then(function (r) { if (r.isConfirmed) go(); });
            return;
        }
        go();
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

    APP.emailEditor = { init: init, insertText: insertText, closeAi: closeAiDrawer };

    $(function () {
        $('textarea[data-email-editor]').each(function () { init(this); });
    });
})(window, window.jQuery);
