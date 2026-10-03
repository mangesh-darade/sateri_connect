/**
 * Sateri Connect - Global Context-Aware AI Copilot.
 * Integrates with Gemini AI and UI screens (Workflow Builder, Keywords, Campaigns, Contacts).
 */
(function (window, $) {
    'use strict';

    var Copilot = {
        screen: 'general',
        screenName: 'General',
        history: [],

        init: function () {
            this.detectScreen();
            this.renderPromptChips();
            this.bindEvents();
            this.checkPendingFlowOnForm();
        },

        checkPendingFlowOnForm: function () {
            if ($('#automationBuilder form').length && !$('#flowBuilder').length) {
                try {
                    var pending = sessionStorage.getItem('ai_pending_flow');
                    if (pending) {
                        var p = JSON.parse(pending);
                        if (p && typeof p === 'object') {
                            if (p.workflow_name && !$('input[name="name"]').val()) {
                                $('input[name="name"]').val(String(p.workflow_name).slice(0, 100));
                            }
                            var trg = p.trigger_type;
                            if (!trg && Array.isArray(p.nodes)) {
                                var tNode = p.nodes.find(function (n) { return n && n.type === 'trigger'; });
                                if (tNode && tNode.data && tNode.data.trigger_type) {
                                    trg = tNode.data.trigger_type;
                                }
                            }
                            if (trg && !$('#triggerType').val()) {
                                $('#triggerType').val(String(trg)).trigger('change');
                            }
                        }
                    }
                } catch (e) {
                    sessionStorage.removeItem('ai_pending_flow');
                }
            }
        },

        detectScreen: function () {
            var path = window.location.pathname;
            var href = window.location.href;

            if ($('#flowBuilder').length || path.indexOf('/automations/') >= 0 && path.indexOf('/builder') >= 0 || path.indexOf('/automations/create') >= 0) {
                this.screen = 'workflow_builder';
                this.screenName = 'Workflow Builder';
            } else if (path.indexOf('/keywords') >= 0) {
                this.screen = 'keywords';
                this.screenName = 'Keywords Bot';
            } else if (path.indexOf('/campaigns') >= 0) {
                this.screen = 'campaigns';
                this.screenName = 'Broadcasts';
            } else if (path.indexOf('/attributes') >= 0) {
                this.screen = 'attributes';
                this.screenName = 'Attributes';
            } else if (path.indexOf('/contacts') >= 0) {
                this.screen = 'contacts';
                this.screenName = 'Contacts';
            } else if (path.indexOf('/chat') >= 0) {
                this.screen = 'chat';
                this.screenName = 'Live Chat';
            } else if (path.indexOf('/templates') >= 0) {
                this.screen = 'templates';
                this.screenName = 'Templates';
            } else if (path.indexOf('/settings') >= 0) {
                this.screen = 'settings';
                this.screenName = 'Settings';
            } else {
                this.screen = 'general';
                this.screenName = 'Sateri Connect';
            }

            $('#copilotScreenBadge').text(this.screenName);
            $('#copilotActivePageName').text(this.screenName);

            var welcomeTips = {
                workflow_builder: 'You are on the <strong>Workflow Builder</strong> screen. Tell me what flow to create or edit (e.g. *"Create a welcome flow for incoming WhatsApp messages with 2 min follow-up"*) — I will render it directly on the canvas!',
                keywords: 'You are on the <strong>Keywords</strong> screen. Ask me to suggest keywords or auto-replies (e.g. *"Suggest 3 keywords for product inquiries and pricing"*).',
                campaigns: 'You are on the <strong>Broadcasts</strong> screen. Ask me to draft high-converting WhatsApp marketing copies!',
                attributes: 'You are on the <strong>Attributes</strong> screen. Ask me which custom attributes and fields are best for your business.',
                contacts: 'You are on the <strong>Contacts</strong> screen. Need assistance with customer tags, segmentation, or import mapping?',
                general: 'I am ready to assist you on this screen. How can I help today?'
            };

            $('#copilotWelcomeText').html(welcomeTips[this.screen] || welcomeTips.general);
        },

        renderPromptChips: function () {
            var chips = [];
            if (this.screen === 'workflow_builder') {
                var hasNodes = window.WorkflowFlow && Array.isArray(window.WorkflowFlow.nodes) && window.WorkflowFlow.nodes.length > 0;
                if (hasNodes) {
                    chips = [
                        'Add a 5 min delay to this flow',
                        'Handover to human agent at the end',
                        'Change welcome message to polite tone',
                        'Add condition: continue only if user says YES'
                    ];
                } else {
                    chips = [
                        'Create Welcome Flow for incoming messages',
                        'Send follow-up message with 5 min delay',
                        'Ask user for name and save as attribute',
                        'Campaign promotion reply flow'
                    ];
                }
            } else if (this.screen === 'keywords') {
                chips = [
                    'Create 3 keywords for Price & Menu',
                    'Suggest Offer & Discount keywords',
                    'What are the rules for Exact vs Contains?'
                ];
            } else if (this.screen === 'campaigns') {
                chips = [
                    'Draft a festival promotional WhatsApp message',
                    'Write a polite payment reminder copy',
                    'New product launch announcement message'
                ];
            } else if (this.screen === 'attributes' || this.screen === 'contacts') {
                chips = [
                    'Essential attributes for Retail / E-commerce',
                    'Key fields needed for Real Estate leads',
                    'Best practices for tagging VIP customers'
                ];
            } else {
                chips = [
                    'What are WhatsApp automation rules?',
                    'How to configure AI auto-reply?',
                    'What is the 24-hour service window?'
                ];
            }

            var html = '';
            chips.forEach(function (c) {
                html += '<button type="button" class="copilot-chip js-copilot-chip">' + APP.escapeHtml(c) + '</button>';
            });
            $('#copilotPromptChips').html(html);
        },

        bindEvents: function () {
            var self = this;

            // Open/close drawer
            $(document).on('click', '#btnOpenAiCopilot, .js-open-ai-copilot, #btnOpenAiCopilotBuilder', function () {
                self.open();
            });

            $(document).on('click', '#btnCloseAiCopilot, #aiCopilotBackdrop', function () {
                self.close();
            });

            // Prompt chips click
            $(document).on('click', '.js-copilot-chip', function () {
                var text = $(this).text();
                $('#copilotInput').val(text);
                self.sendPrompt(text);
            });

            // Send button
            $('#copilotSendBtn').on('click', function () {
                var val = $.trim($('#copilotInput').val());
                if (val) {
                    self.sendPrompt(val);
                }
            });

            // Enter key to send (Shift+Enter for newline)
            $('#copilotInput').on('keydown', function (e) {
                if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    var val = $.trim($(this).val());
                    if (val) {
                        self.sendPrompt(val);
                    }
                }
            });

            // Clear chat
            $('#btnCopilotClearChat').on('click', function () {
                $('#copilotMessages').find('.copilot-msg:not(:first)').remove();
            });

            // History toggle & close
            $('#btnCopilotHistory').on('click', function () {
                var $panel = $('#copilotHistoryPanel');
                if ($panel.hasClass('d-none')) {
                    $panel.removeClass('d-none');
                    self.loadHistory();
                } else {
                    $panel.addClass('d-none');
                }
            });

            $('#btnCloseHistoryPanel').on('click', function () {
                $('#copilotHistoryPanel').addClass('d-none');
            });

            // Re-use past prompt from history
            $(document).on('click', '.js-load-history-prompt', function () {
                var promptText = $(this).attr('data-prompt');
                $('#copilotInput').val(promptText);
                $('#copilotHistoryPanel').addClass('d-none');
                $('#copilotInput').focus();
            });

            // Apply workflow action
            $(document).on('click', '.js-apply-workflow-btn', function () {
                var $btn = $(this);
                try {
                    var workflowData = JSON.parse($btn.attr('data-workflow'));
                    if (!workflowData || typeof workflowData !== 'object') {
                        return;
                    }
                    if (window.WorkflowFlow && typeof window.WorkflowFlow.setGraph === 'function') {
                        window.WorkflowFlow.setGraph(workflowData.nodes, workflowData.edges, workflowData.workflow_name);
                        if (typeof toastr !== 'undefined') {
                            toastr.success('Workflow canvas updated with AI flow!', 'Success');
                        } else {
                            alert('Workflow successfully applied to canvas!');
                        }
                        $btn.prop('disabled', true).html('<i class="fas fa-check me-1"></i> Applied to Canvas');
                    } else if ($('#automationBuilder form').length) {
                        // Store pending graph for visual canvas
                        sessionStorage.setItem('ai_pending_flow', JSON.stringify(workflowData));
                        if (workflowData.workflow_name) {
                            $('input[name="name"]').val(workflowData.workflow_name);
                        }
                        var trg = workflowData.trigger_type;
                        if (!trg && Array.isArray(workflowData.nodes)) {
                            var tNode = workflowData.nodes.find(function (n) { return n.type === 'trigger'; });
                            if (tNode && tNode.data && tNode.data.trigger_type) {
                                trg = tNode.data.trigger_type;
                            }
                        }
                        if (trg) {
                            $('#triggerType').val(trg).trigger('change');
                        }
                        $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin me-1"></i> Creating & Opening Builder...');
                        $('#automationBuilder form').submit();
                    } else {
                        sessionStorage.setItem('ai_pending_flow', JSON.stringify(workflowData));
                        window.location.href = APP.baseUrl + '/automations/create';
                    }
                } catch (e) {
                    console.error('Failed to apply workflow', e);
                    alert('Error applying workflow data.');
                }
            });

            // Copy text action
            $(document).on('click', '.js-copilot-copy-btn', function () {
                var text = $(this).attr('data-copy');
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(text);
                } else {
                    var ta = document.createElement('textarea');
                    ta.value = text;
                    document.body.appendChild(ta);
                    ta.select();
                    document.execCommand('copy');
                    document.body.removeChild(ta);
                }
                $(this).html('<i class="fas fa-check me-1"></i> Copied!');
                var $t = $(this);
                setTimeout(function () {
                    $t.html('<i class="fas fa-copy me-1"></i> Copy');
                }, 2000);
            });
        },

        open: function () {
            this.detectScreen();
            this.renderPromptChips();
            $('#aiCopilotBackdrop').addClass('show');
            $('#aiCopilotDrawer').addClass('show');
            setTimeout(function () {
                $('#copilotInput').focus();
            }, 200);
        },

        close: function () {
            $('#aiCopilotBackdrop').removeClass('show');
            $('#aiCopilotDrawer').removeClass('show');
        },

        scrollToBottom: function () {
            var $b = $('#copilotMessages');
            $b.scrollTop($b.prop('scrollHeight'));
        },

        sendPrompt: function (promptText) {
            var self = this;
            $('#copilotInput').val('');

            // Append user bubble
            var userHtml =
                '<div class="copilot-msg copilot-msg-user">' +
                    '<div class="copilot-avatar"><i class="fas fa-user"></i></div>' +
                    '<div class="copilot-bubble">' + APP.escapeHtml(promptText) + '</div>' +
                '</div>';
            $('#copilotMessages').append(userHtml);

            // Typing indicator
            var typingId = 'copilot_typing_' + Date.now();
            var typingHtml =
                '<div class="copilot-msg copilot-msg-ai" id="' + typingId + '">' +
                    '<div class="copilot-avatar"><i class="fas fa-robot"></i></div>' +
                    '<div class="copilot-bubble">' +
                        '<div class="copilot-typing"><span></span><span></span><span></span></div>' +
                    '</div>' +
                '</div>';
            $('#copilotMessages').append(typingHtml);
            self.scrollToBottom();

            $('#copilotSendBtn').prop('disabled', true);

            var currentFlow = null;
            if (window.WorkflowFlow && typeof window.WorkflowFlow.getGraph === 'function') {
                currentFlow = window.WorkflowFlow.getGraph();
            } else if (window.WorkflowFlow && Array.isArray(window.WorkflowFlow.nodes) && window.WorkflowFlow.nodes.length > 0) {
                currentFlow = {
                    workflow_name: $('#flowName').val() || '',
                    selected_node_id: window.WorkflowFlow.selectedId || null,
                    nodes: window.WorkflowFlow.nodes,
                    edges: window.WorkflowFlow.edges || []
                };
            }

            var postData = {
                prompt: promptText,
                context: {
                    screen: self.screen,
                    page_url: window.location.href,
                    page_title: document.title,
                    current_flow: currentFlow
                }
            };

            APP.post(APP.baseUrl + '/copilot/ask', postData)
                .done(function (res) {
                    if (res && res.data) {
                        self.renderAiResponse(res.data);
                    } else {
                        self.renderAiResponse({ reply: (res && res.message) || 'Done', action_type: 'none' });
                    }
                })
                .fail(function (xhr) {
                    var errMsg = 'Error connecting to AI Copilot.';
                    try {
                        var json = JSON.parse(xhr.responseText);
                        if (json.message) errMsg = json.message;
                    } catch (e) {}

                    var errHtml =
                        '<div class="copilot-msg copilot-msg-ai">' +
                            '<div class="copilot-avatar bg-danger"><i class="fas fa-exclamation"></i></div>' +
                            '<div class="copilot-bubble text-danger">' + APP.escapeHtml(errMsg) + '</div>' +
                        '</div>';
                    $('#copilotMessages').append(errHtml);
                    self.scrollToBottom();
                })
                .always(function () {
                    $('#' + typingId).remove();
                    $('#copilotSendBtn').prop('disabled', false);
                });
        },

        renderAiResponse: function (data) {
            data = data || {};
            var self = this;
            var reply = data.reply || '';
            var actionType = data.action_type || 'none';
            var actionData = data.action_data || null;

            var thinkingHtml = '';
            if (data.thinking) {
                thinkingHtml =
                    '<div class="mb-2 p-2 rounded border bg-light" style="font-size: 0.73rem; color: #334155; border-left: 3px solid #10b981 !important;">' +
                        '<div class="fw-bold text-success mb-1 d-flex align-items-center gap-1"><i class="fas fa-brain"></i> AI Reasoning & Strategy:</div>' +
                        '<div style="white-space: pre-wrap; line-height: 1.4;">' + APP.escapeHtml(data.thinking) + '</div>' +
                    '</div>';
            }

            var actionCardHtml = '';

            // Handle Workflow Action
            if (actionType === 'apply_workflow' && actionData && actionData.nodes) {
                var nodeCount = Array.isArray(actionData.nodes) ? actionData.nodes.length : 0;
                var edgeCount = Array.isArray(actionData.edges) ? actionData.edges.length : 0;
                var wfName = actionData.workflow_name || 'Generated Flow';
                var jsonStr = APP.escapeHtml(JSON.stringify(actionData));

                var nodePipeline = '';
                if (Array.isArray(actionData.nodes)) {
                    var labels = actionData.nodes.map(function (n) {
                        return (n.data && n.data.label) ? n.data.label : (n.type || 'Node');
                    }).slice(0, 6);
                    nodePipeline = '<div class="small text-muted mb-2 font-monospace" style="font-size: 0.7rem; line-height: 1.4;">' +
                        labels.map(function (s) { return APP.escapeHtml(s); }).join(' <i class="fas fa-arrow-right text-success mx-1"></i> ') +
                        (actionData.nodes.length > 6 ? ' <i class="fas fa-arrow-right text-success mx-1"></i> …' : '') +
                        '</div>';
                }

                var isOnBuilder = $('#flowBuilder').length > 0;
                var isCreateForm = $('#automationBuilder form').length > 0;

                var buttonHtml = '';
                if (isOnBuilder) {
                    buttonHtml = '<button type="button" class="btn btn-sm btn-success w-100 js-apply-workflow-btn" data-workflow="' + jsonStr + '"><i class="fas fa-magic me-1"></i> Apply to Flow Canvas</button>';
                } else if (isCreateForm) {
                    buttonHtml = '<button type="button" class="btn btn-sm btn-success w-100 js-apply-workflow-btn" data-workflow="' + jsonStr + '"><i class="fas fa-save me-1"></i> Save & Open Visual Canvas</button>';
                } else {
                    buttonHtml = '<a href="' + APP.baseUrl + '/automations/create" class="btn btn-sm btn-outline-success w-100"><i class="fas fa-external-link-alt me-1"></i> Open Flow Builder</a>';
                }

                actionCardHtml =
                    '<div class="copilot-action-card">' +
                        '<h6><i class="fas fa-sitemap me-1 text-success"></i> ' + APP.escapeHtml(wfName) + '</h6>' +
                        '<p class="mb-1"><span class="badge bg-white text-dark border">' + nodeCount + ' Nodes</span> <span class="badge bg-white text-dark border ms-1">' + edgeCount + ' Connections</span></p>' +
                        nodePipeline +
                        buttonHtml +
                    '</div>';
            }
            // Handle Copy Text Action (Campaigns, Templates, etc.)
            else if (actionType === 'copy_text' && actionData && actionData.text) {
                var copyText = actionData.text;
                actionCardHtml =
                    '<div class="copilot-action-card">' +
                        '<h6><i class="fas fa-file-lines me-1 text-primary"></i> ' + APP.escapeHtml(actionData.title || 'Draft Copy') + '</h6>' +
                        '<div class="p-2 bg-white rounded border mb-2 small font-monospace" style="white-space: pre-wrap;">' + APP.escapeHtml(copyText) + '</div>' +
                        '<button type="button" class="btn btn-sm btn-outline-primary w-100 js-copilot-copy-btn" data-copy="' + APP.escapeHtml(copyText) + '"><i class="fas fa-copy me-1"></i> Copy Message Text</button>' +
                    '</div>';
            }
            // Handle Keywords Action
            else if (actionType === 'suggest_keywords' && actionData && Array.isArray(actionData.keywords)) {
                actionCardHtml = '<div class="copilot-action-card"><h6><i class="fas fa-key me-1 text-warning"></i> Suggested Keywords</h6>';
                actionData.keywords.forEach(function (k) {
                    actionCardHtml +=
                        '<div class="p-2 bg-white rounded border mb-1 small d-flex justify-content-between align-items-center">' +
                            '<div><strong>' + APP.escapeHtml(k.keyword) + '</strong> <span class="badge bg-light text-muted">' + APP.escapeHtml(k.matching_type || 'contains') + '</span><br><span class="text-muted">' + APP.escapeHtml((k.reply_text || '').slice(0, 45)) + '…</span></div>' +
                            '<button type="button" class="btn btn-xs btn-outline-secondary js-copilot-copy-btn" data-copy="' + APP.escapeHtml(k.reply_text || k.keyword) + '"><i class="fas fa-copy"></i></button>' +
                        '</div>';
                });
                actionCardHtml += '</div>';
            }

            var aiHtml =
                '<div class="copilot-msg copilot-msg-ai">' +
                    '<div class="copilot-avatar"><i class="fas fa-robot"></i></div>' +
                    '<div class="copilot-bubble">' +
                        thinkingHtml +
                        '<div style="white-space: pre-wrap;">' + APP.escapeHtml(reply) + '</div>' +
                        actionCardHtml +
                    '</div>' +
                '</div>';

            $('#copilotMessages').append(aiHtml);
            self.scrollToBottom();
        },

        loadHistory: function () {
            var $list = $('#copilotHistoryList');
            $list.html('<div class="text-muted text-center py-2" style="font-size: 0.75rem;"><i class="fas fa-spinner fa-spin me-1"></i> Loading history...</div>');

            APP.get(APP.baseUrl + '/copilot/history')
                .done(function (res) {
                    var items = (res && res.data) || [];
                    if (!items.length) {
                        $list.html('<div class="text-muted text-center py-2" style="font-size: 0.75rem;">No previous prompts yet.</div>');
                        return;
                    }
                    var html = '';
                    items.forEach(function (h) {
                        var promptStr = h.prompt || '';
                        var created = h.created_at || '';
                        var screenBadge = h.screen || 'general';
                        html +=
                            '<div class="p-2 mb-1 bg-white border rounded d-flex justify-content-between align-items-center gap-2">' +
                                '<div class="overflow-hidden">' +
                                    '<div class="fw-semibold text-truncate text-dark" style="font-size: 0.76rem; max-width: 250px;" title="' + APP.escapeHtml(promptStr) + '">' +
                                        APP.escapeHtml(promptStr) +
                                    '</div>' +
                                    '<div class="text-muted" style="font-size: 0.68rem;">' +
                                        '<span class="badge bg-light text-secondary border me-1">' + APP.escapeHtml(screenBadge) + '</span>' +
                                        APP.escapeHtml(created) +
                                    '</div>' +
                                '</div>' +
                                '<button type="button" class="btn btn-xs btn-outline-success js-load-history-prompt px-2 py-1" style="font-size: 0.7rem; white-space: nowrap;" data-prompt="' + APP.escapeHtml(promptStr) + '" title="Use this prompt">' +
                                    '<i class="fas fa-arrow-turn-up me-1"></i> Use' +
                                '</button>' +
                            '</div>';
                    });
                    $list.html(html);
                })
                .fail(function () {
                    $list.html('<div class="text-danger text-center py-2" style="font-size: 0.75rem;">Failed to load history.</div>');
                });
        }
    };

    $(function () {
        Copilot.init();
        window.AICopilot = Copilot;
    });

})(window, jQuery);
