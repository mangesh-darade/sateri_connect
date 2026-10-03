/**
 * Inbox extras (Cheerio parity): quick replies ("/shortcut" in the composer) and the
 * contact panel (consent, groups, attributes — edited in place).
 * Depends on window.Chat (chat.js) and APP (app.js).
 */
(function (window, $) {
    'use strict';

    var Chat = window.Chat;
    if (!Chat) {
        return;
    }

    function base() { return APP.baseUrl || ''; }
    function esc(v) { return APP.escapeHtml(v); }
    function apiError(xhr, fallback) { return (xhr && xhr.responseJSON && xhr.responseJSON.message) || fallback; }

    /* ---------------- Quick replies ---------------- */

    var QR = {
        cache: {},          // contactId → list
        items: [],          // currently shown
        index: 0,
        open: false
    };

    QR.load = function () {
        var cid = Chat.contactId || 0;
        if (QR.cache[cid]) {
            return $.Deferred().resolve(QR.cache[cid]).promise();
        }
        return APP.get(base() + '/chat/quick-replies', { contact_id: cid }).then(function (res) {
            QR.cache[cid] = (res && res.data && res.data.quick_replies) || [];
            return QR.cache[cid];
        });
    };

    QR.render = function (query) {
        var q = String(query || '').toLowerCase();
        QR.load().done(function (list) {
            QR.items = list.filter(function (r) {
                return !q || r.shortcut.indexOf(q) === 0 || String(r.title).toLowerCase().indexOf(q) !== -1;
            }).slice(0, 30);
            QR.index = 0;
            var $menu = $('#quickReplyMenu');
            if (!list.length) {
                $menu.html('<div class="p-3 small text-muted">No quick replies yet. <a href="' + base() + '/quick-replies">Add quick replies</a></div>');
            } else if (!QR.items.length) {
                $menu.html('<div class="p-3 small text-muted">No quick reply matches “/' + esc(q) + '”.</div>');
            } else {
                $menu.html(QR.items.map(function (r, i) {
                    return '<button type="button" class="quick-reply-item' + (i === 0 ? ' active' : '') + '" data-i="' + i + '" role="option">'
                        + '<span class="qr-shortcut">/' + esc(r.shortcut) + '</span> <strong class="small">' + esc(r.title) + '</strong>'
                        + '<span class="qr-preview">' + esc(r.message) + '</span></button>';
                }).join(''));
            }
            $menu.removeClass('d-none');
            QR.open = true;
        }).fail(function () {
            APP.toast('Could not load quick replies', 'error');
        });
    };

    QR.close = function () {
        $('#quickReplyMenu').addClass('d-none');
        QR.open = false;
    };

    QR.choose = function (i) {
        var item = QR.items[i];
        if (!item) return;
        var $input = $('#chatInput');
        var value = String($input.val() || '');
        // Replace the "/query" being typed, otherwise append.
        var next = /^\/\S*$/.test(value.trim()) ? item.message : (value ? value.replace(/\s*$/, ' ') : '') + item.message;
        $input.val(next).trigger('input').focus();
        QR.close();
    };

    QR.highlight = function (i) {
        if (!QR.items.length) return;
        QR.index = (i + QR.items.length) % QR.items.length;
        var $items = $('#quickReplyMenu .quick-reply-item').removeClass('active');
        var el = $items.eq(QR.index).addClass('active')[0];
        if (el && el.scrollIntoView) el.scrollIntoView({ block: 'nearest' });
    };

    /** Called by chat.js before its own Enter-to-send handling. Returns true when handled. */
    QR.handleKey = function (e) {
        if (!QR.open) return false;
        if (e.key === 'ArrowDown') { e.preventDefault(); QR.highlight(QR.index + 1); return true; }
        if (e.key === 'ArrowUp') { e.preventDefault(); QR.highlight(QR.index - 1); return true; }
        if (e.key === 'Escape') { e.preventDefault(); QR.close(); return true; }
        if ((e.key === 'Enter' && !e.shiftKey) || e.key === 'Tab') {
            if (!QR.items.length) { QR.close(); return e.key === 'Tab'; }
            e.preventDefault();
            QR.choose(QR.index);
            return true;
        }
        return false;
    };

    $('#chatInput').on('input', function () {
        var value = String($(this).val() || '');
        var m = /^\/(\S*)$/.exec(value);
        if (m && Chat.within24h) {
            QR.render(m[1]);
        } else if (QR.open) {
            QR.close();
        }
    });
    $('#btnQuickReply').on('click', function (e) {
        e.stopPropagation();
        if (!Chat.within24h) {
            APP.toast('Outside 24h window — use Template', 'warning');
            return;
        }
        if (QR.open) { QR.close(); return; }
        QR.render('');
    });
    $(document).on('click', '#quickReplyMenu .quick-reply-item', function () {
        QR.choose(parseInt($(this).data('i'), 10));
    });
    $(document).on('click', function (e) {
        if (QR.open && !$(e.target).closest('#quickReplyMenu, #btnQuickReply, #chatInput').length) {
            QR.close();
        }
    });

    window.ChatQuickReplies = QR;

    /* ---------------- Contact panel ---------------- */

    var Panel = { contactId: null, data: null };
    var $canvas = $('#chatContactCanvas');
    var canEdit = $canvas.data('canEdit') === 1 || $canvas.data('canEdit') === '1';

    var CONSENT = {
        opted_in: ['success', 'WhatsApp opted in'],
        no_opt_in: ['warning', 'No WhatsApp opt-in'],
        opted_out: ['danger', 'Opted out (STOP)'],
        none: ['secondary', 'Consent not tracked']
    };

    function fieldHtml(row) {
        var id = 'ccAttr_' + row.key;
        var dis = canEdit ? '' : ' disabled';
        var input;
        if (row.type === 'dropdown' || row.type === 'boolean') {
            var opts = row.type === 'boolean' ? ['Yes', 'No'] : (row.options || []);
            input = '<select class="form-select form-select-sm" id="' + esc(id) + '" data-key="' + esc(row.key) + '"' + dis + '>'
                + '<option value="">—</option>'
                + opts.map(function (o) {
                    return '<option value="' + esc(o) + '"' + (String(o).toLowerCase() === String(row.value).toLowerCase() ? ' selected' : '') + '>' + esc(o) + '</option>';
                }).join('') + '</select>';
        } else {
            var type = row.type === 'date' ? 'date' : (row.type === 'number' ? 'number' : 'text');
            input = '<input type="' + type + '" class="form-control form-control-sm" id="' + esc(id) + '" data-key="' + esc(row.key) + '" value="' + esc(row.value) + '"' + dis + '>';
        }
        return '<div class="cc-attr"><label class="d-block" for="' + esc(id) + '">' + esc(row.label)
            + (row.defined ? '' : ' <span class="text-muted">(custom)</span>') + '</label>' + input + '</div>';
    }

    Panel.render = function (d) {
        Panel.data = d;
        var c = d.contact || {};
        var consent = CONSENT[c.consent] || CONSENT.none;
        $('#chatContactCanvasTitle').text(c.name || c.mobile || 'Contact');
        $('#chatContactMobile').text(c.mobile || '');

        var tagIds = (d.tags || []).map(function (t) { return String(t.id); });
        var tagsHtml = (d.tags || []).map(function (t) {
            return '<span class="cc-tag" style="background:' + esc(t.color || '#6B7280') + '">' + esc(t.name)
                + (canEdit ? ' <button type="button" data-remove-tag="' + (+t.id) + '" title="Remove">&times;</button>' : '') + '</span>';
        }).join('') || '<span class="small text-muted">No groups</span>';

        var addTag = canEdit
            ? '<div class="input-group input-group-sm mt-2">'
              + '<input type="text" class="form-control" id="ccTagInput" list="ccTagList" placeholder="Add to group…" maxlength="100">'
              + '<button type="button" class="btn btn-outline-secondary" id="ccTagAdd">Add</button></div>'
              + '<datalist id="ccTagList">' + (d.all_tags || []).filter(function (t) { return tagIds.indexOf(String(t.id)) === -1; })
                  .map(function (t) { return '<option value="' + esc(t.name) + '">'; }).join('') + '</datalist>'
            : '';

        var attrs = (d.attributes || []);
        $('#chatContactBody').html(
            '<div class="cc-section"><span class="badge bg-' + consent[0] + '-subtle text-' + consent[0] + '-emphasis border">'
            + '<i class="fab fa-whatsapp me-1"></i>' + esc(consent[1]) + '</span>'
            + ' <a class="small ms-2" href="' + esc(c.url) + '" target="_blank" rel="noopener">Full profile</a></div>'
            + '<div class="cc-section"><div class="cc-section-title">Groups</div>' + tagsHtml + addTag + '</div>'
            + '<div class="cc-section"><div class="cc-section-title">Details</div>' + (d.core || []).map(fieldHtml).join('') + '</div>'
            + '<div class="cc-section"><div class="cc-section-title d-flex justify-content-between">Attributes'
            + '<a class="text-decoration-none" href="' + base() + '/attributes" target="_blank" rel="noopener">Manage</a></div>'
            + (attrs.length ? attrs.map(fieldHtml).join('') : '<div class="small text-muted">No attributes yet.</div>') + '</div>'
        );
    };

    Panel.load = function () {
        if (!Chat.contactId) return;
        Panel.contactId = Chat.contactId;
        $('#chatContactBody').html('<div class="text-muted small">Loading…</div>');
        APP.get(base() + '/chat/contact/' + Chat.contactId).done(function (res) {
            Panel.render((res && res.data) || {});
        }).fail(function (xhr) {
            $('#chatContactBody').html('<div class="text-danger small">' + esc(apiError(xhr, 'Could not load contact')) + '</div>');
        });
    };

    Panel.saveAttr = function ($el) {
        var key = $el.data('key');
        var value = $el.val();
        $el.prop('disabled', true);
        APP.post(base() + '/chat/contact-attribute', { contact_id: Panel.contactId, key: key, value: value })
            .done(function (res) {
                if (res && res.data && res.data.value !== undefined && $el.is('input')) {
                    $el.val(res.data.value);
                }
                $el.removeClass('is-invalid');
                delete QR.cache[Panel.contactId]; // placeholders may use this attribute
                APP.toast((res && res.message) || 'Saved');
            })
            .fail(function (xhr) {
                $el.addClass('is-invalid');
                APP.toast(apiError(xhr, 'Could not save'), 'error');
            })
            .always(function () { $el.prop('disabled', false); });
    };

    Panel.tag = function (payload) {
        APP.post(base() + '/chat/contact-tag', $.extend({ contact_id: Panel.contactId }, payload))
            .done(function (res) {
                if (res && res.data) Panel.render(res.data);
                APP.toast((res && res.message) || 'Updated');
            })
            .fail(function (xhr) { APP.toast(apiError(xhr, 'Could not update group'), 'error'); });
    };

    $('#btnChatContact').on('click', function () {
        if (!Chat.contactId) return;
        Panel.load();
        bootstrap.Offcanvas.getOrCreateInstance($canvas[0]).show();
    });
    $canvas.on('change', '[data-key]', function () { Panel.saveAttr($(this)); });
    $canvas.on('keydown', 'input[data-key]', function (e) {
        if (e.key === 'Enter') { e.preventDefault(); $(this).trigger('blur'); }
    });
    $canvas.on('click', '[data-remove-tag]', function () {
        Panel.tag({ action: 'remove', tag_id: $(this).data('removeTag') });
    });
    function addTypedTag() {
        var name = String($('#ccTagInput').val() || '').trim();
        if (!name) { APP.toast('Type a group name', 'warning'); return; }
        var match = ((Panel.data && Panel.data.all_tags) || []).filter(function (t) { return t.name.toLowerCase() === name.toLowerCase(); })[0];
        Panel.tag(match ? { action: 'add', tag_id: match.id } : { action: 'add', tag_name: name });
    }
    $canvas.on('click', '#ccTagAdd', addTypedTag);
    $canvas.on('keydown', '#ccTagInput', function (e) {
        if (e.key === 'Enter') { e.preventDefault(); addTypedTag(); }
    });

    // Keep the open panel in sync when the agent switches conversation.
    var originalLoad = Chat.loadMessages;
    Chat.loadMessages = function (contactId, silent) {
        var result = originalLoad.apply(Chat, arguments);
        if (!silent && $canvas.hasClass('show') && String(contactId) !== String(Panel.contactId)) {
            Panel.load();
        }
        if (!silent) QR.close();
        return result;
    };

    /* ---------------- Filter canvas: attribute value toggle ---------------- */

    function syncAttrFilter() {
        var on = !!$('#chatFilterAttrKey').val();
        $('#chatFilterAttrOp').prop('disabled', !on);
        $('#chatFilterAttrValue').prop('disabled', !on || ['is_empty', 'not_empty'].indexOf($('#chatFilterAttrOp').val()) !== -1);
    }
    $('#chatFilterAttrKey, #chatFilterAttrOp').on('change', syncAttrFilter);
    syncAttrFilter();

    /* ---------------- AI Copilot (Gemini Integration) ---------------- */

    var $aiShelf = $('#aiSuggestionsShelf');
    var $aiList = $('#aiSuggestionsList');
    var $btnAiSuggest = $('#btnAiSuggest');
    var $btnAiSummary = $('#btnChatAiSummary');
    var $summaryModal = $('#aiSummaryModal');
    var $summaryBody = $('#aiSummaryBody');

    $('#btnCloseAiSuggestions').on('click', function () {
        $aiShelf.addClass('d-none');
    });

    $btnAiSuggest.on('click', function () {
        var cid = Chat.contactId || 0;
        if (!cid) {
            APP.toast('Please select a conversation first', 'warning');
            return;
        }

        var $btn = $(this);
        var oldHtml = $btn.html();
        $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i>');
        $aiShelf.removeClass('d-none');
        $aiList.html('<span class="text-muted small"><i class="fas fa-spinner fa-spin me-1"></i> Gemini generating reply suggestions…</span>');

        APP.post(base() + '/chat/ai-suggest', {
            contact_id: cid,
            message: $('#chatInput').val()
        })
            .done(function (res) {
                var suggestions = (res && res.data && res.data.suggestions) || [];
                if (!suggestions.length) {
                    $aiList.html('<span class="text-muted small">No suggestions generated. Make sure AI is enabled in Settings.</span>');
                    return;
                }
                var html = suggestions.map(function (s) {
                    return '<button type="button" class="btn btn-sm btn-outline-success rounded-pill px-3 py-1 text-start ai-suggestion-pill" data-text="' + esc(s) + '">'
                        + '<i class="fas fa-magic me-1 small"></i> ' + esc(s) + '</button>';
                }).join('');
                $aiList.html(html);
            })
            .fail(function (xhr) {
                var msg = apiError(xhr, 'Failed to fetch AI suggestions');
                $aiList.html('<span class="text-danger small">' + esc(msg) + '</span>');
                APP.toast(msg, 'error');
            })
            .always(function () {
                $btn.prop('disabled', false).html(oldHtml);
            });
    });

    $aiList.on('click', '.ai-suggestion-pill', function () {
        var text = $(this).data('text');
        if (text) {
            $('#chatInput').val(text).focus();
            $aiShelf.addClass('d-none');
            APP.toast('Suggestion copied to composer', 'info');
        }
    });

    $btnAiSummary.on('click', function () {
        var cid = Chat.contactId || 0;
        if (!cid) {
            APP.toast('Please select a conversation first', 'warning');
            return;
        }

        var modalInstance = bootstrap.Modal.getOrCreateInstance($summaryModal[0]);
        $summaryBody.html('<div class="text-center py-4 text-muted"><i class="fas fa-spinner fa-spin fa-2x mb-2 text-primary"></i><div>Analyzing chat history with Gemini…</div></div>');
        modalInstance.show();

        APP.post(base() + '/chat/ai-summary', { contact_id: cid })
            .done(function (res) {
                var summary = (res && res.data && res.data.summary) || 'No summary generated.';
                var formatted = esc(summary).replace(/\n/g, '<br>');
                $summaryBody.html('<div class="p-3 bg-light rounded border text-dark" style="white-space:pre-wrap;line-height:1.6;" id="aiSummaryContent">' + formatted + '</div>');
            })
            .fail(function (xhr) {
                var msg = apiError(xhr, 'Could not generate summary');
                $summaryBody.html('<div class="alert alert-danger mb-0">' + esc(msg) + '</div>');
            });
    });

    $('#btnCopyAiSummary').on('click', function () {
        var text = $('#aiSummaryContent').text() || '';
        if (!text) {
            APP.toast('Nothing to copy', 'warning');
            return;
        }
        navigator.clipboard.writeText(text).then(function () {
            APP.toast('Summary copied to clipboard', 'success');
        });
    });
})(window, jQuery);

