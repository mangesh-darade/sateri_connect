/**
 * Live Chat — conversations, messages, send, upload, emoji, poll, notes, assign
 */
(function (window, $) {
    'use strict';

    var Chat = {
        contactId: null,
        conversationId: null,
        conversationStatus: 'open',
        channel: 'whatsapp',
        contactChannel: 'whatsapp',
        pollTimer: null,
        pollMs: 3000,
        pollMsHidden: 10000,
        lastMessageId: 0,
        sending: false,
        within24h: true,
        inboxStatus: 'open',
        unreadOnly: false,
        assigneeFilter: 'all',
        oldChatsFirst: false,
        scopeFilter: 'all',
        currentUserId: null,
        _searchT: null,
        _convSig: '',
        pageTitle: document.title,
        currentConversations: []
    };

    function base() {
        return (window.APP && APP.baseUrl) || '';
    }

    function initials(name) {
        name = (name || '?').trim();
        var parts = name.split(/\s+/);
        if (parts.length >= 2) {
            return (parts[0][0] + parts[1][0]).toUpperCase();
        }
        return name.substring(0, 2).toUpperCase();
    }

    function escapeHtml(str) {
        return $('<div>').text(str == null ? '' : String(str)).html();
    }

    function formatTime(ts) {
        if (!ts) return '';
        var d = new Date(String(ts).replace(' ', 'T'));
        if (isNaN(d.getTime())) return ts;
        return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    }

    function statusIcon(status) {
        status = (status || '').toLowerCase();
        if (status === 'read') return '<i class="fas fa-check-double msg-status msg-status-read"></i>';
        if (status === 'delivered') return '<i class="fas fa-check-double msg-status"></i>';
        if (status === 'sent') return '<i class="fas fa-check msg-status"></i>';
        if (status === 'failed') return '<i class="fas fa-exclamation-circle text-danger msg-status"></i>';
        return '<i class="far fa-clock msg-status"></i>';
    }

    function renderMessage(msg) {
        var dir = (msg.direction === 'outbound' || msg.direction === 'out') ? 'outbound' : 'inbound';
        var body = msg.body || msg.content || msg.message || '';
        if (msg.media_url) {
            var type = (msg.message_type || msg.type || '').toLowerCase();
            if (type.indexOf('image') >= 0) {
                body = '<img src="' + escapeHtml(msg.media_url) + '" class="img-fluid rounded mb-1" alt="media"><br>' + escapeHtml(body);
            } else if (type.indexOf('video') >= 0) {
                body = '<video src="' + escapeHtml(msg.media_url) + '" controls class="w-100 rounded mb-1"></video><br>' + escapeHtml(body);
            } else if (type.indexOf('audio') >= 0) {
                body = '<audio src="' + escapeHtml(msg.media_url) + '" controls class="w-100 mb-1"></audio><br>' + escapeHtml(body);
            } else {
                body = '<a href="' + escapeHtml(msg.media_url) + '" target="_blank" rel="noopener">Attachment</a><br>' + escapeHtml(body);
            }
        } else {
            body = escapeHtml(body).replace(/\n/g, '<br>');
        }
        return (
            '<div class="msg-row ' + dir + '" data-id="' + escapeHtml(msg.id) + '" data-status="' + escapeHtml(msg.status || '') + '">' +
            '<div class="msg-bubble">' + body +
            '<span class="msg-time">' + escapeHtml(formatTime(msg.created_at || msg.timestamp)) +
            (dir === 'outbound' ? statusIcon(msg.status) : '') +
            '</span></div></div>'
        );
    }

    function scrollBottom() {
        var box = document.getElementById('chatMessages');
        if (!box) return;
        box.scrollTop = box.scrollHeight;
    }

    function setComposerLocked(locked) {
        var $composer = $('#chatComposer');
        if (!$composer.length) return;
        $composer.toggleClass('is-locked', !!locked);
        $('#chatComposerFree').toggleClass('d-none', !!locked);
        $('#chatComposerTemplateCta').toggleClass('d-none', !locked);
        // Never leave free-text controls half-disabled; hide them when locked.
        $('#chatInput, #btnChatSend, #btnAttach, #chatFile, #btnEmoji').prop('disabled', !!locked);
        var isWa = (Chat.contactChannel || 'whatsapp') === 'whatsapp';
        $('#btnComposerTemplate').toggleClass('d-none', !isWa);
        $('#btnTemplateReply').toggleClass('d-none', !isWa);
        if (!isWa) {
            $('#chatComposerLockCopy').text('Free-form replies need a recent customer message on this channel.');
        } else {
            $('#chatComposerLockCopy').text('Free-form WhatsApp messages need a recent customer reply. Send an approved template instead.');
        }
        // Template actions must stay clickable (WhatsApp only).
        $('#btnTemplateReply, #btnComposerTemplate').prop('disabled', false);
    }

    function setWindowState(within24h) {
        Chat.within24h = within24h !== false;
        var isWa = (Chat.contactChannel || 'whatsapp') === 'whatsapp';
        var $banner = $('#chatWindowBanner');
        var $channelBanner = $('#chatChannelLockBanner');
        var $input = $('#chatInput');
        if (Chat.within24h) {
            $banner.addClass('d-none');
            $channelBanner.addClass('d-none');
            $input.attr('placeholder', 'Type a message');
            $('#btnTemplateReply').removeClass('btn-wa').addClass('btn-outline-secondary');
            setComposerLocked(false);
        } else if (isWa) {
            $banner.removeClass('d-none');
            $channelBanner.addClass('d-none');
            $input.attr('placeholder', '24h window closed — use Template');
            $('#btnTemplateReply').removeClass('btn-outline-secondary').addClass('btn-wa');
            setComposerLocked(true);
        } else {
            $banner.addClass('d-none');
            $channelBanner.removeClass('d-none');
            $input.attr('placeholder', '24h window closed');
            setComposerLocked(true);
        }
        $('#btnTemplateReply').toggleClass('d-none', !isWa);
    }

    function channelBadge(channel) {
        channel = (channel || 'whatsapp').toLowerCase();
        if (channel === 'instagram') {
            return '<span class="chat-channel-badge chat-channel-ig" title="Instagram"><i class="fab fa-instagram"></i></span>';
        }
        if (channel === 'messenger') {
            return '<span class="chat-channel-badge chat-channel-fb" title="Messenger"><i class="fab fa-facebook-messenger"></i></span>';
        }
        return '<span class="chat-channel-badge chat-channel-wa" title="WhatsApp"><i class="fab fa-whatsapp"></i></span>';
    }

    function applyStatusUpdates(updates) {
        if (!Array.isArray(updates)) return;
        updates.forEach(function (u) {
            var id = u.id;
            var st = (u.status || '').toLowerCase();
            var $row = $('.msg-row[data-id="' + id + '"]');
            if (!$row.length || !$row.hasClass('outbound')) return;
            if (String($row.attr('data-status') || '') === st) return;
            $row.attr('data-status', st);
            var $time = $row.find('.msg-time');
            $time.find('.msg-status').remove();
            $time.append(statusIcon(st));
        });
    }

    function renderNotes(notes) {
        var $list = $('#chatNotesList').empty();
        if (!Array.isArray(notes) || !notes.length) {
            $list.html('<div class="note-item text-muted">No internal notes yet.</div>');
            return;
        }
        notes.forEach(function (n) {
            $list.append(
                '<div class="note-item">' +
                '<div class="text-muted small">' + escapeHtml(n.created_at || '') +
                (n.user_name ? (' · ' + escapeHtml(n.user_name)) : '') + '</div>' +
                '<div>' + escapeHtml(n.note || n.content || '') + '</div></div>'
            );
        });
    }

    function syncConversationChrome(conversation) {
        if (!conversation) return;
        Chat.conversationId = conversation.id || Chat.conversationId;
        Chat.conversationStatus = conversation.status || 'open';
        var closed = Chat.conversationStatus === 'closed';
        $('#btnChatClose').toggleClass('d-none', closed);
        $('#btnChatReopen').toggleClass('d-none', !closed);
        if ($('#chatAssignSelect').length && conversation.assigned_to !== undefined && conversation.assigned_to !== null) {
            $('#chatAssignSelect').val(String(conversation.assigned_to || ''));
        } else if ($('#chatAssignSelect').length && (conversation.assigned_to === null || conversation.assigned_to === '')) {
            $('#chatAssignSelect').val('');
        }
    }

    function collectTemplateVariables() {
        var vars = {};
        $('#templateVarsFields [data-var-key]').each(function () {
            vars[$(this).data('var-key')] = ($(this).val() || '').trim();
        });
        return vars;
    }

    function renderTemplateVariableFields(variables) {
        var $wrap = $('#templateVarsWrap');
        var $fields = $('#templateVarsFields').empty();
        var list = Array.isArray(variables) ? variables : [];
        if (!list.length) {
            $wrap.addClass('d-none');
            return;
        }
        list.forEach(function (v, idx) {
            var key = String(idx + 1);
            var label = 'Variable {{' + key + '}}';
            if (typeof v === 'string' && v !== '' && !/^\d+$/.test(v)) {
                label += ' (' + v + ')';
            }
            $fields.append(
                '<div class="mb-2">' +
                '<label class="form-label small mb-1">' + escapeHtml(label) + '</label>' +
                '<input type="text" class="form-control form-control-sm" data-var-key="' + escapeHtml(key) + '" placeholder="Value for {{' + escapeHtml(key) + '}}">' +
                '</div>'
            );
        });
        $wrap.removeClass('d-none');
    }

    Chat.loadTemplateVars = function (templateId) {
        if (!templateId) {
            renderTemplateVariableFields([]);
            return;
        }
        APP.get(base() + '/templates/' + templateId + '/preview').done(function (res) {
            var data = (res && res.data) ? res.data : res;
            renderTemplateVariableFields((data && data.variables) || []);
        }).fail(function () {
            renderTemplateVariableFields([]);
        });
    };

    function setHeader(conv) {
        $('#chatHeaderName').text(conv.name || conv.mobile || 'Contact');
        $('#chatHeaderMobile').text(conv.mobile || '');
        $('#chatHeaderAvatar').text(initials(conv.name || conv.mobile || '?'));
        $('#chatMainEmpty').addClass('d-none').hide();
        $('#chatMainActive').removeClass('d-none').css('display', 'flex');
    }

    function extractList(res) {
        if (!res) return [];
        if (Array.isArray(res)) return res;
        if (Array.isArray(res.data)) return res.data;
        if (Array.isArray(res.conversations)) return res.conversations;
        if (res.data && Array.isArray(res.data.conversations)) return res.data.conversations;
        return [];
    }

    function extractMessages(res) {
        if (!res) return [];
        if (Array.isArray(res)) return res;
        if (Array.isArray(res.messages)) return res.messages;
        if (res.data && Array.isArray(res.data.messages)) return res.data.messages;
        if (Array.isArray(res.data)) return res.data;
        return [];
    }

    function normalizeConversation(c) {
        return {
            contact_id: c.contact_id || c.id,
            name: c.name || c.contact_name || '',
            mobile: c.mobile || c.external_id || '',
            channel: (c.channel || 'whatsapp').toLowerCase(),
            external_id: c.external_id || '',
            last_message_at: c.last_message_at || c.updated_at || '',
            last_message: c.last_message || c.last_message_content || c.preview || '',
            unread_count: parseInt(c.unread_count || c.unread || 0, 10) || 0,
            last_message_direction: c.last_message_direction || '',
            status: c.status || 'open',
            assigned_to: c.assigned_to
        };
    }

    function conversationSignature(list) {
        return list.map(function (c) {
            return [c.contact_id, c.channel || '', c.unread_count, c.last_message_at, c.last_message, c.status, c.assigned_to || ''].join(':');
        }).join('|');
    }

    function scopeLabel(count) {
        var labels = {
            all: 'All Chats',
            assigned: 'Assigned to Agents',
            open: 'Open',
            closed: 'Closed',
            unread: 'Unread',
            unassigned: 'Unassigned Chats'
        };
        return (labels[Chat.scopeFilter] || 'All Chats') + ' (' + count + ')';
    }

    function exportRows(filename, rows) {
        if (!rows.length) {
            APP.toast('No conversations to export', 'warning');
            return;
        }
        var csv = rows.map(function (row) {
            return row.map(function (cell) {
                var value = cell == null ? '' : String(cell);
                return '"' + value.replace(/"/g, '""') + '"';
            }).join(',');
        }).join('\n');
        var blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
        var url = URL.createObjectURL(blob);
        var a = document.createElement('a');
        a.href = url;
        a.download = filename;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
    }

    Chat.loadConversations = function (q) {
        var assignedTo = '';
        if (Chat.assigneeFilter === 'mine' && Chat.currentUserId) {
            assignedTo = Chat.currentUserId;
        } else if (Chat.assigneeFilter === 'unassigned') {
            assignedTo = 'unassigned';
        }
        return APP.get(base() + '/chat/conversations', {
            q: q || '',
            status: Chat.inboxStatus || 'open',
            channel: Chat.channel || 'all',
            unread_only: Chat.unreadOnly ? 1 : 0,
            assigned_to: assignedTo
        }).done(function (res) {
            var list = extractList(res).map(normalizeConversation);
            if (Chat.scopeFilter === 'assigned') {
                list = list.filter(function (c) { return c.assigned_to !== null && c.assigned_to !== ''; });
            } else if (Chat.scopeFilter === 'unassigned') {
                list = list.filter(function (c) { return c.assigned_to === null || c.assigned_to === ''; });
            } else if (Chat.scopeFilter === 'unread') {
                list = list.filter(function (c) { return (c.unread_count || 0) > 0; });
            }
            list.sort(function (a, b) {
                var aTime = new Date(String(a.last_message_at || '').replace(' ', 'T')).getTime() || 0;
                var bTime = new Date(String(b.last_message_at || '').replace(' ', 'T')).getTime() || 0;
                return Chat.oldChatsFirst ? aTime - bTime : bTime - aTime;
            });
            Chat.currentConversations = list.slice();
            var sig = conversationSignature(list);
            if (sig === Chat._convSig && $('#chatConvList .chat-conv-item').length) {
                return;
            }
            Chat._convSig = sig;
            var html = '';
            var unreadTotal = 0;
            if (!list.length) {
                html = '<div class="chat-empty-inline">' +
                    '<div class="empty-orb"><i class="fab fa-whatsapp"></i></div>' +
                    '<p class="mb-0">No conversations</p>' +
                    '<span class="text-muted small">Inbound messages appear when webhooks are connected</span>' +
                    '</div>';
            } else {
                list.forEach(function (c) {
                    unreadTotal += c.unread_count > 0 ? c.unread_count : 0;
                    var active = String(c.contact_id) === String(Chat.contactId) ? ' active' : '';
                    var hasUnread = c.unread_count > 0;
                    var unreadClass = hasUnread ? ' has-unread' : '';
                    var statusBadge = c.status === 'closed'
                        ? '<span class="chat-conv-status chat-conv-status-closed">Closed</span>'
                        : '<span class="chat-conv-status chat-conv-status-open">Open</span>';
                    var flags = '';
                    if (c.unread_count > 0) {
                        flags += '<span class="chat-flag chat-flag-new">New</span>';
                    }
                    if (!c.assigned_to) {
                        flags += '<span class="chat-flag chat-flag-chatbot">Unassigned</span>';
                    } else if (String(c.assigned_to) === String(Chat.currentUserId || '')) {
                        flags += '<span class="chat-flag chat-flag-chatbot">My Chat</span>';
                    }
                    html +=
                        '<button type="button" class="chat-conv-item' + active + unreadClass + '" data-contact-id="' + escapeHtml(c.contact_id) + '"' +
                        ' data-name="' + escapeHtml(c.name) + '" data-mobile="' + escapeHtml(c.mobile) + '"' +
                        ' data-channel="' + escapeHtml(c.channel) + '"' +
                        ' data-status="' + escapeHtml(c.status) + '">' +
                        '<div class="chat-avatar">' + escapeHtml(initials(c.name || c.mobile)) + channelBadge(c.channel) + '</div>' +
                        '<div class="chat-conv-meta">' +
                        '<div class="d-flex justify-content-between"><span class="name">' + escapeHtml(c.name || c.mobile) + '</span>' +
                        '<span class="time">' + escapeHtml(formatTime(c.last_message_at)) + '</span></div>' +
                        '<div class="d-flex justify-content-between align-items-center">' +
                        '<span class="preview">' + escapeHtml(c.last_message) + '</span>' +
                        statusBadge +
                        (c.unread_count > 0 ? '<span class="chat-unread">' + c.unread_count + '</span>' : '') +
                        '</div>' +
                        '<div class="chat-conv-flags">' + flags +
                        '</div></div></button>';
                });
            }
            $('#chatConvList').html(html);
            $('#chatSidebarAllCount').text(scopeLabel(list.length));
            $('#chatSidebarUnreadCount').text('Unread ' + unreadTotal);
        }).fail(function () {
            $('#chatConvList').html('<div class="p-3 text-danger text-center">Failed to load conversations</div>');
            $('#chatSidebarAllCount').text(scopeLabel(0));
            $('#chatSidebarUnreadCount').text('Unread 0');
        });
    };

    Chat.loadMessages = function (contactId, silent) {
        Chat.contactId = contactId;
        var params = {};
        if (silent && Chat.lastMessageId > 0) {
            params.after_id = Chat.lastMessageId;
        }
        return APP.get(base() + '/chat/messages/' + contactId, params).done(function (res) {
            var payload = (res && res.data && !Array.isArray(res.data)) ? res.data : res;
            var msgs = extractMessages(res);
            if (payload && payload.contact && !silent) {
                Chat.contactChannel = (payload.contact.channel || (payload.conversation && payload.conversation.channel) || 'whatsapp').toLowerCase();
                setHeader({
                    name: payload.contact.name || payload.contact.mobile || payload.contact.external_id,
                    mobile: payload.contact.mobile || payload.contact.external_id || ''
                });
                if (payload.contact.assigned_to !== undefined && $('#chatAssignSelect').length) {
                    $('#chatAssignSelect').val(String(payload.contact.assigned_to || ''));
                }
            }
            if (payload && payload.conversation) {
                if (payload.conversation.channel) {
                    Chat.contactChannel = String(payload.conversation.channel).toLowerCase();
                }
                syncConversationChrome(payload.conversation);
            }
            if (payload && typeof payload.within_24h !== 'undefined') {
                setWindowState(!!payload.within_24h);
            }
            if (!silent) {
                var html = msgs.map(renderMessage).join('');
                $('#chatMessages').html(html || '<div class="text-center text-muted py-4">No messages yet</div>');
                Chat.lastMessageId = msgs.length ? parseInt(msgs[msgs.length - 1].id, 10) || 0 : 0;
                renderNotes(payload && payload.notes ? payload.notes : []);
                scrollBottom();
                APP.post(base() + '/chat/mark-read', { contact_id: contactId }).fail(function () {});
            } else if (msgs.length) {
                var hadInbound = false;
                msgs.forEach(function (m) {
                    var mid = parseInt(m.id, 10) || 0;
                    if (mid > Chat.lastMessageId) {
                        $('#chatMessages').append(renderMessage(m));
                        Chat.lastMessageId = mid;
                        if ((m.direction || '') === 'inbound') {
                            hadInbound = true;
                        }
                    }
                });
                scrollBottom();
                if (hadInbound) {
                    try {
                        if (window.APP && APP.LiveNotif && APP.LiveNotif.playBeep) {
                            APP.LiveNotif.playBeep();
                        }
                    } catch (e) { /* ignore */ }
                    if (!document.hidden) {
                        // Soft cue without blocking UI
                    } else if (window.APP && APP.LiveNotif && APP.LiveNotif.flashTitle) {
                        APP.LiveNotif.flashTitle('New chat message');
                    }
                }
            }
            if (payload && payload.status_updates) {
                applyStatusUpdates(payload.status_updates);
            }
        }).fail(function (xhr) {
            if (!silent) {
                var msg = (xhr.responseJSON && xhr.responseJSON.message) || 'Failed to load messages';
                APP.toast(msg, 'error');
            }
        });
    };

    Chat.openConversation = function ($item) {
        var id = $item.attr('data-contact-id') || $item.data('contact-id');
        if (!id) {
            APP.toast('Invalid conversation', 'error');
            return;
        }
        Chat.contactChannel = String($item.attr('data-channel') || $item.data('channel') || 'whatsapp').toLowerCase();
        setHeader({
            name: $item.attr('data-name') || $item.data('name'),
            mobile: $item.attr('data-mobile') || $item.data('mobile')
        });
        $('.chat-conv-item').removeClass('active');
        $item.addClass('active').find('.chat-unread').remove();
        Chat.lastMessageId = 0;
        $('#chatNotesPanel').removeClass('open');
        Chat.loadMessages(id, false);
        Chat.startPoll();
        $('#chatLayout').addClass('chat-thread-open');
    };

    Chat.closeThread = function () {
        $('#chatLayout').removeClass('chat-thread-open');
    };

    Chat.send = function () {
        if (Chat.sending || !Chat.contactId) return;
        if (!Chat.within24h) {
            if ((Chat.contactChannel || 'whatsapp') === 'whatsapp') {
                APP.toast('Outside 24h window — use Template', 'warning');
            } else {
                APP.toast('Outside 24h messaging window for this channel', 'warning');
            }
            return;
        }
        var text = ($('#chatInput').val() || '').trim();
        var fileInput = document.getElementById('chatFile');
        var hasFile = fileInput && fileInput.files && fileInput.files.length;
        if (!text && !hasFile) return;

        Chat.sending = true;
        $('#btnChatSend').prop('disabled', true);

        var fd = new FormData();
        fd.append('contact_id', Chat.contactId);
        fd.append('message', text);
        fd.append('type', hasFile ? 'media' : 'text');
        if (hasFile) {
            fd.append('file', fileInput.files[0]);
        }
        fd.append(APP.csrfName || 'csrf_test_name', $('meta[name="csrf-token"]').attr('content'));

        $.ajax({
            url: base() + '/chat/send',
            method: 'POST',
            data: fd,
            processData: false,
            contentType: false,
            dataType: 'json'
        }).done(function (res) {
            $('#chatInput').val('');
            if (fileInput) fileInput.value = '';
            var msg = res.data || res.message_row || res;
            if (msg && msg.id) {
                $('#chatMessages').append(renderMessage(msg));
                Chat.lastMessageId = Math.max(Chat.lastMessageId, parseInt(msg.id, 10) || 0);
                scrollBottom();
            } else {
                Chat.loadMessages(Chat.contactId, false);
            }
            Chat._convSig = '';
            Chat.loadConversations($('#chatSearch').val());
        }).fail(function (xhr) {
            var msg = (xhr.responseJSON && (xhr.responseJSON.message || xhr.responseJSON.error)) || 'Failed to send';
            APP.toast(msg, 'error');
        }).always(function () {
            Chat.sending = false;
            if (Chat.within24h) {
                $('#btnChatSend').prop('disabled', false);
            }
        });
    };

    Chat.sendTemplate = function (templateId, variables) {
        if (!Chat.contactId || !templateId) return;
        APP.post(base() + '/chat/send', {
            contact_id: Chat.contactId,
            type: 'template',
            template_id: templateId,
            variables: variables || {}
        }).done(function () {
            if (window.APP && typeof APP.hideModal === 'function') {
                APP.hideModal('#templateReplyModal');
            } else {
                var el = document.getElementById('templateReplyModal');
                if (el && window.bootstrap) {
                    bootstrap.Modal.getOrCreateInstance(el).hide();
                }
            }
            Chat.loadMessages(Chat.contactId, false);
            APP.toast('Template sent');
        }).fail(function (xhr) {
            APP.toast((xhr.responseJSON && xhr.responseJSON.message) || 'Template send failed', 'error');
        });
    };

    Chat.addNote = function () {
        if (!Chat.contactId) return;
        var note = ($('#chatNoteInput').val() || '').trim();
        if (!note) return;
        APP.post(base() + '/chat/note', {
            contact_id: Chat.contactId,
            note: note
        }).done(function (res) {
            $('#chatNoteInput').val('');
            var saved = (res && res.data) ? res.data : null;
            if (saved) {
                var cur = [];
                $('#chatNotesList .note-item').each(function () {
                    if (!$(this).hasClass('text-muted')) {
                        /* keep existing rendered — reload simpler */
                    }
                });
                Chat.loadMessages(Chat.contactId, false);
                $('#chatNotesPanel').addClass('open');
            }
            APP.toast('Note added');
        }).fail(function (xhr) {
            APP.toast((xhr.responseJSON && xhr.responseJSON.message) || 'Failed to add note', 'error');
        });
    };

    Chat.assign = function (userId) {
        if (!Chat.contactId) return;
        APP.post(base() + '/chat/assign', {
            contact_id: Chat.contactId,
            assigned_to: userId || ''
        }).done(function () {
            APP.toast(userId ? 'Assigned' : 'Unassigned');
        }).fail(function (xhr) {
            APP.toast((xhr.responseJSON && xhr.responseJSON.message) || 'Assign failed', 'error');
        });
    };

    Chat.setStatus = function (status) {
        if (!Chat.contactId) return;
        APP.post(base() + '/chat/status', {
            contact_id: Chat.contactId,
            status: status
        }).done(function () {
            Chat.conversationStatus = status;
            syncConversationChrome({ status: status, id: Chat.conversationId });
            Chat._convSig = '';
            Chat.loadConversations($('#chatSearch').val());
            APP.toast(status === 'closed' ? 'Conversation closed' : 'Conversation reopened');
            if (status === 'closed' && Chat.inboxStatus === 'open') {
                Chat.closeThread();
            }
        }).fail(function (xhr) {
            APP.toast((xhr.responseJSON && xhr.responseJSON.message) || 'Status update failed', 'error');
        });
    };

    Chat.startPoll = function () {
        Chat.stopPoll();
        var tick = function () {
            if (Chat.contactId) {
                Chat.loadMessages(Chat.contactId, true);
            }
            Chat.loadConversations($('#chatSearch').val());
        };
        var ms = document.hidden ? Chat.pollMsHidden : Chat.pollMs;
        Chat.pollTimer = setInterval(tick, ms);
    };

    Chat.stopPoll = function () {
        if (Chat.pollTimer) {
            clearInterval(Chat.pollTimer);
            Chat.pollTimer = null;
        }
    };

    Chat.insertEmoji = function (emoji) {
        if (!Chat.within24h) return;
        var $input = $('#chatInput');
        var el = $input[0];
        if (!el) return;
        var start = el.selectionStart || 0;
        var end = el.selectionEnd || 0;
        var val = $input.val() || '';
        $input.val(val.slice(0, start) + emoji + val.slice(end));
        el.focus();
        el.selectionStart = el.selectionEnd = start + emoji.length;
    };

    $(function () {
        if (!$('#chatLayout').length) return;

        Chat.pageTitle = document.title;
        Chat.channel = ($('#chatLayout').attr('data-channel') || 'whatsapp').toLowerCase();
        Chat.currentUserId = $('#chatLayout').attr('data-current-user-id') || null;
        Chat.loadConversations();
        Chat.startPoll();

        document.addEventListener('visibilitychange', function () {
            Chat.startPoll();
            if (!document.hidden) {
                if (Chat.contactId) {
                    Chat.loadMessages(Chat.contactId, true);
                }
                Chat.loadConversations($('#chatSearch').val());
            }
        });

        // Open contact from ?contact_id= in URL (notification deep-link)
        try {
            var params = new URLSearchParams(window.location.search || '');
            var deepId = params.get('contact_id');
            var deepChannel = params.get('channel');
            if (deepChannel) {
                Chat.channel = String(deepChannel).toLowerCase();
            }
            if (deepId) {
                setTimeout(function () {
                    var $item = $('.chat-conv-item[data-contact-id="' + deepId + '"]');
                    if ($item.length) {
                        Chat.openConversation($item);
                    } else {
                        Chat.loadMessages(parseInt(deepId, 10), false);
                    }
                }, 400);
            }
        } catch (e) { /* ignore */ }

        $(document).on('click', '.chat-conv-item', function (e) {
            e.preventDefault();
            e.stopPropagation();
            Chat.openConversation($(this));
        });

        $('.chat-status-filter').on('click', function () {
            $('.chat-status-filter').removeClass('active btn-wa').addClass('btn-outline-secondary');
            $(this).addClass('active btn-wa').removeClass('btn-outline-secondary');
            Chat.inboxStatus = $(this).data('status') || 'open';
            Chat._convSig = '';
            Chat.loadConversations($('#chatSearch').val());
        });

        $('.chat-extra-filter').on('click', function () {
            var $btn = $(this);
            var filter = String($btn.data('filter') || '');
            var isActive = $btn.hasClass('active');
            if (filter === 'unread') {
                Chat.unreadOnly = !isActive;
            } else if (filter === 'mine') {
                Chat.assigneeFilter = isActive ? 'all' : 'mine';
            } else if (filter === 'unassigned') {
                Chat.assigneeFilter = isActive ? 'all' : 'unassigned';
            }

            if (filter !== 'unread') {
                $('.chat-extra-filter[data-filter="mine"], .chat-extra-filter[data-filter="unassigned"]').removeClass('active btn-wa').addClass('btn-outline-secondary');
                if (Chat.assigneeFilter !== 'all') {
                    $('.chat-extra-filter[data-filter="' + Chat.assigneeFilter + '"]').addClass('active btn-wa').removeClass('btn-outline-secondary');
                }
            }

            if (filter === 'unread') {
                if (Chat.unreadOnly) {
                    $btn.addClass('active btn-wa').removeClass('btn-outline-secondary');
                } else {
                    $btn.removeClass('active btn-wa').addClass('btn-outline-secondary');
                }
                $('#chatUnreadToggle').prop('checked', Chat.unreadOnly);
            }

            Chat._convSig = '';
            Chat.loadConversations($('#chatSearch').val());
        });

        $('#btnChatBack').on('click', function () {
            Chat.closeThread();
        });
        $(document).on('click', '.chat-scope-dropdown .dropdown-item', function () {
            var scope = String($(this).data('scope') || 'all');
            Chat.scopeFilter = scope;
            $('.chat-scope-dropdown .dropdown-item').removeClass('active');
            $(this).addClass('active');

            if (scope === 'open' || scope === 'closed') {
                Chat.inboxStatus = scope;
            } else if (scope === 'all' || scope === 'assigned' || scope === 'unread' || scope === 'unassigned') {
                Chat.inboxStatus = 'all';
            }

            Chat.unreadOnly = scope === 'unread';
            if (scope === 'unassigned') {
                Chat.assigneeFilter = 'unassigned';
            } else if (scope === 'assigned') {
                Chat.assigneeFilter = 'all';
            } else if (scope !== 'mine') {
                Chat.assigneeFilter = 'all';
            }

            $('.chat-status-filter').removeClass('active btn-wa').addClass('btn-outline-secondary');
            $('.chat-status-filter[data-status="' + Chat.inboxStatus + '"]').addClass('active btn-wa').removeClass('btn-outline-secondary');
            $('.chat-extra-filter').removeClass('active btn-wa').addClass('btn-outline-secondary');
            if (Chat.unreadOnly) {
                $('.chat-extra-filter[data-filter="unread"]').addClass('active btn-wa').removeClass('btn-outline-secondary');
            }
            if (Chat.assigneeFilter === 'unassigned') {
                $('.chat-extra-filter[data-filter="unassigned"]').addClass('active btn-wa').removeClass('btn-outline-secondary');
            }
            $('#chatUnreadToggle').prop('checked', Chat.unreadOnly);
            Chat._convSig = '';
            Chat.loadConversations($('#chatSearch').val());
        });
        $('#chatUnreadToggle').on('change', function () {
            Chat.unreadOnly = !!this.checked;
            Chat.scopeFilter = Chat.unreadOnly ? 'unread' : 'all';
            $('.chat-scope-dropdown .dropdown-item').removeClass('active');
            $('.chat-scope-dropdown .dropdown-item[data-scope="' + Chat.scopeFilter + '"]').addClass('active');
            $('.chat-extra-filter[data-filter="unread"]').toggleClass('active btn-wa', Chat.unreadOnly).toggleClass('btn-outline-secondary', !Chat.unreadOnly);
            Chat._convSig = '';
            Chat.loadConversations($('#chatSearch').val());
        });
        $('#btnInboxFilters').on('click', function () {
            $('#chatFilterStatusSelect').val(Chat.inboxStatus || 'open');
            $('#chatFilterUnreadSelect').val(Chat.unreadOnly ? 'unread' : '');
            $('#chatFilterAssigneeSelect').val(Chat.assigneeFilter || 'all');
            $('#chatOldChatsFirst').prop('checked', !!Chat.oldChatsFirst);
            bootstrap.Offcanvas.getOrCreateInstance(document.getElementById('chatFilterCanvas')).show();
        });
        $('#btnOldChatsToggle').on('click', function () {
            Chat.oldChatsFirst = !Chat.oldChatsFirst;
            $(this).toggleClass('is-active', Chat.oldChatsFirst);
            $('#chatOldChatsFirst').prop('checked', Chat.oldChatsFirst);
            Chat._convSig = '';
            Chat.loadConversations($('#chatSearch').val());
        });
        $('#btnApplyChatFilters').on('click', function () {
            Chat.inboxStatus = $('#chatFilterStatusSelect').val() || 'open';
            Chat.unreadOnly = $('#chatFilterUnreadSelect').val() === 'unread';
            Chat.assigneeFilter = $('#chatFilterAssigneeSelect').val() || 'all';
            Chat.oldChatsFirst = $('#chatOldChatsFirst').is(':checked');
            Chat.scopeFilter = Chat.unreadOnly ? 'unread' : (Chat.assigneeFilter === 'unassigned' ? 'unassigned' : (Chat.inboxStatus === 'all' ? 'all' : Chat.inboxStatus));

            $('.chat-status-filter').removeClass('active btn-wa').addClass('btn-outline-secondary');
            $('.chat-status-filter[data-status="' + Chat.inboxStatus + '"]').addClass('active btn-wa').removeClass('btn-outline-secondary');

            $('.chat-extra-filter').removeClass('active btn-wa').addClass('btn-outline-secondary');
            if (Chat.unreadOnly) {
                $('.chat-extra-filter[data-filter="unread"]').addClass('active btn-wa').removeClass('btn-outline-secondary');
            }
            if (Chat.assigneeFilter === 'mine' || Chat.assigneeFilter === 'unassigned') {
                $('.chat-extra-filter[data-filter="' + Chat.assigneeFilter + '"]').addClass('active btn-wa').removeClass('btn-outline-secondary');
            }

            $('#chatUnreadToggle').prop('checked', Chat.unreadOnly);
            $('.chat-scope-dropdown .dropdown-item').removeClass('active');
            $('.chat-scope-dropdown .dropdown-item[data-scope="' + Chat.scopeFilter + '"]').addClass('active');
            $('#btnOldChatsToggle').toggleClass('is-active', Chat.oldChatsFirst);
            Chat._convSig = '';
            Chat.loadConversations($('#chatSearch').val());
            bootstrap.Offcanvas.getOrCreateInstance(document.getElementById('chatFilterCanvas')).hide();
        });
        $('#btnExportReport').on('click', function () {
            var rows = [['Name', 'Phone', 'Status', 'Unread', 'Last message', 'Last activity']].concat(
                Chat.currentConversations.map(function (c) {
                    return [c.name || '', c.mobile || '', c.status || '', c.unread_count || 0, c.last_message || '', c.last_message_at || ''];
                })
            );
            exportRows('chat-report.csv', rows);
        });
        $('#btnExportFormMessages').on('click', function () {
            var rows = [['Name', 'Phone', 'Preview']].concat(
                Chat.currentConversations.map(function (c) {
                    return [c.name || '', c.mobile || '', c.last_message || ''];
                })
            );
            exportRows('chat-form-messages.csv', rows);
        });
        $('#chatSearch').on('input', function () {
            clearTimeout(Chat._searchT);
            var q = $(this).val();
            Chat._searchT = setTimeout(function () {
                Chat._convSig = '';
                Chat.loadConversations(q);
            }, 300);
        });

        $('#btnChatSend').on('click', function () { Chat.send(); });
        $('#chatInput').on('keydown', function (e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                Chat.send();
            }
        });

        $('#btnEmoji').on('click', function () {
            if (!Chat.within24h) return;
            $('#emojiPicker').toggleClass('open');
        });
        $(document).on('click', '#emojiPicker button', function () {
            Chat.insertEmoji($(this).text());
        });
        $(document).on('click', function (e) {
            if (!$(e.target).closest('#btnEmoji, #emojiPicker').length) {
                $('#emojiPicker').removeClass('open');
            }
        });

        $('#btnAttach').on('click', function () {
            if (!Chat.within24h) {
                APP.toast('Outside 24h window — use Template', 'warning');
                return;
            }
            $('#chatFile').trigger('click');
        });
        $('#chatFile').on('change', function () {
            if (this.files && this.files.length) {
                APP.toast('File ready: ' + this.files[0].name, 'info');
            }
        });

        $('#btnChatNotes').on('click', function () {
            $('#chatNotesPanel').toggleClass('open');
        });
        $('#btnAddChatNote').on('click', function () { Chat.addNote(); });
        $('#chatNoteInput').on('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                Chat.addNote();
            }
        });

        $('#chatAssignSelect').on('change', function () {
            Chat.assign($(this).val());
        });
        $('#btnChatClose').on('click', function () { Chat.setStatus('closed'); });
        $('#btnChatReopen').on('click', function () { Chat.setStatus('open'); });

        $('#btnTemplateReply, #btnComposerTemplate').on('click', function () {
            if (window.APP && typeof APP.showModal === 'function') {
                APP.showModal('#templateReplyModal');
            } else {
                var el = document.getElementById('templateReplyModal');
                if (el && window.bootstrap) {
                    bootstrap.Modal.getOrCreateInstance(el).show();
                }
            }
            Chat.loadTemplateVars($('#templateSelect').val());
        });
        $('#templateSelect').on('change', function () {
            Chat.loadTemplateVars($(this).val());
        });
        $('#btnSendTemplate').on('click', function () {
            var id = $('#templateSelect').val();
            if (!id) {
                APP.toast('Select a template', 'error');
                return;
            }
            Chat.sendTemplate(id, collectTemplateVariables());
        });

        var preselect = $('#chatLayout').data('contact-id');
        Chat.currentUserId = parseInt($('#chatLayout').data('current-user-id'), 10) || null;
        if (preselect) {
            Chat.contactId = preselect;
            setHeader({
                name: $('#chatLayout').data('contact-name'),
                mobile: $('#chatLayout').data('contact-mobile')
            });
            Chat.loadMessages(preselect, false);
        }
    });

    window.Chat = Chat;
})(window, jQuery);
