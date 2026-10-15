/**
 * Automation setup form — trigger hint, keyword filter, and linear steps.
 */
(function (window, $) {
    'use strict';

    var Automations = { stepIndex: 0, catalog: { tags: [], templates: [], agents: [] } };

    var HINTS = {
        incoming_message: 'Runs when a WhatsApp message arrives.',
        keyword_matched: 'Runs when an inbound message matches a keyword.',
        messenger: 'Runs when a Messenger message arrives.',
        instagram: 'Runs when an Instagram message arrives.',
        contact_created: 'Runs when a new contact is created.',
        tag_added: 'Runs when a tag is added to a contact.',
        attribute_updated: 'Runs when a contact attribute changes.',
        form_response: 'Runs when a form response is submitted.',
        birthday: 'Runs from the daily birthday check.',
        campaign_sent: 'Runs after a campaign message is sent.',
        campaign_replied: 'Runs when a contact replies to a campaign.',
        schedule: 'Runs on the scheduler. Birthday checks also use this trigger.',
        shopify_event: 'Runs when a Shopify event is posted to the automation API.',
        facebook_lead: 'Runs when someone submits a Facebook Lead Ad. Needs the Page ID and Page Access Token in Settings → Meta.',
        kylas_event_create: 'Runs when a Kylas create event is posted to the automation API.',
        kylas_event_update: 'Runs when a Kylas update event is posted to the automation API.',
        pabbly_event: 'Runs when a Pabbly event is posted to the automation API.',
        incoming_webhook: 'Runs when your webhook posts to the automation API.',
        commerce_event: 'Runs when a commerce event is posted to the automation API.',
        cheerio_workflow: 'Imported workflow. Open the builder so it can run on incoming WhatsApp.'
    };

    var CONDITIONS = [
        { value: 'message_contains', label: 'Message contains' },
        { value: 'message_equals', label: 'Message equals' },
        { value: 'has_tag', label: 'Contact has tag' },
        { value: 'within_window', label: 'Within 24h window' },
        { value: 'contact_status', label: 'Contact status is' }
    ];

    var ACTIONS = [
        { value: 'send_text', label: 'Send text message' },
        { value: 'send_template', label: 'Send template' },
        { value: 'add_tag', label: 'Add tag' },
        { value: 'remove_tag', label: 'Remove tag' },
        { value: 'assign_agent', label: 'Assign agent' },
        { value: 'set_attribute', label: 'Update attribute' },
        { value: 'add_note', label: 'Add internal note' },
        { value: 'webhook', label: 'Call webhook' },
        { value: 'delay', label: 'Wait' },
        { value: 'collect_images', label: 'Collect images' }
    ];

    function esc(value) {
        return $('<div>').text(value == null ? '' : String(value)).html();
    }

    function optionsHtml(list, selected) {
        return list.map(function (item) {
            return '<option value="' + esc(item.value) + '"' + (item.value === selected ? ' selected' : '') + '>' + esc(item.label) + '</option>';
        }).join('');
    }

    function catalogOptions(list, selected, valueKey, labelKey, placeholder) {
        var html = '<option value="">' + esc(placeholder) + '</option>';
        (list || []).forEach(function (item) {
            var value = String(item[valueKey] == null ? '' : item[valueKey]);
            if (!value) return;
            html += '<option value="' + esc(value) + '"' + (value === String(selected || '') ? ' selected' : '') + '>' + esc(item[labelKey] || value) + '</option>';
        });
        return html;
    }

    function asConfig(step) {
        var config = (step && step.config) || {};
        if (typeof config === 'string') {
            try { config = JSON.parse(config); } catch (e) { config = { value: config }; }
        }
        return config && typeof config === 'object' ? config : {};
    }

    function field(idx, key, label, control) {
        return '<div class="col-md-6"><label class="form-label">' + esc(label) + '</label>' + control + '</div>';
    }

    function textInput(idx, key, value, placeholder) {
        return '<input type="text" class="form-control" name="rules[' + idx + '][config][' + key + ']" value="' + esc(value || '') + '" placeholder="' + esc(placeholder || '') + '">';
    }

    function fieldsHtml(idx, ruleType, actionType, config) {
        var used = {};
        var html = '';
        var mark = function (key) { used[key] = 1; };

        if (ruleType === 'condition') {
            if (actionType === 'within_window') {
                html = '<div class="col-12"><div class="form-text">Passes when the contact replied in the last 24 hours.</div></div>';
            } else if (actionType === 'has_tag') {
                mark('tag_id'); mark('value');
                html = field(idx, 'tag_id', 'Tag', '<select class="form-select" name="rules[' + idx + '][config][tag_id]">' + catalogOptions(Automations.catalog.tags, config.tag_id || config.value, 'id', 'name', 'Select tag…') + '</select>');
            } else if (actionType === 'contact_status') {
                mark('value');
                html = field(idx, 'value', 'Status', '<select class="form-select" name="rules[' + idx + '][config][value]">' +
                    ['active', 'inactive', 'blocked'].map(function (status) {
                        return '<option value="' + status + '"' + ((config.value || 'active') === status ? ' selected' : '') + '>' + status + '</option>';
                    }).join('') + '</select>');
            } else {
                mark('value');
                html = field(idx, 'value', 'Text', textInput(idx, 'value', config.value, 'hello'));
            }
        } else if (actionType === 'send_template' || actionType === 'system_initiated') {
            mark('template_name'); mark('language'); mark('name');
            var selectedName = config.template_name || config.name || '';
            html = field(idx, 'template_name', 'Template', '<select class="form-select" name="rules[' + idx + '][config][template_name]">' + catalogOptions(Automations.catalog.templates, selectedName, 'name', 'name', 'Select template…') + '</select>') +
                field(idx, 'language', 'Language', textInput(idx, 'language', config.language || 'en', 'en'));
        } else if (actionType === 'add_tag' || actionType === 'remove_tag') {
            mark('tag_id'); mark('tag_name');
            html = field(idx, 'tag_id', 'Tag', '<select class="form-select" name="rules[' + idx + '][config][tag_id]">' + catalogOptions(Automations.catalog.tags, config.tag_id, 'id', 'name', 'Select tag…') + '</select>');
        } else if (actionType === 'assign_agent') {
            mark('user_id'); mark('agent_id');
            html = field(idx, 'user_id', 'Agent', '<select class="form-select" name="rules[' + idx + '][config][user_id]">' + catalogOptions(Automations.catalog.agents, config.user_id || config.agent_id, 'id', 'name', 'Select agent…') + '</select>');
        } else if (actionType === 'set_attribute') {
            mark('attribute'); mark('value'); mark('text');
            html = field(idx, 'attribute', 'Attribute', textInput(idx, 'attribute', config.attribute, 'city')) +
                field(idx, 'value', 'Value', textInput(idx, 'value', config.value || config.text, 'Mumbai'));
        } else if (actionType === 'webhook') {
            mark('url');
            html = '<div class="col-12"><label class="form-label">Webhook URL</label>' + textInput(idx, 'url', config.url, 'https://example.com/hook') + '</div>';
        } else if (actionType === 'delay') {
            mark('minutes'); mark('seconds');
            var minutes = config.minutes || (config.seconds ? Math.round(config.seconds / 60) : '');
            html = field(idx, 'minutes', 'Wait (minutes)', '<input type="number" min="1" class="form-control" name="rules[' + idx + '][config][minutes]" value="' + esc(minutes) + '" placeholder="5">');
        } else if (actionType === 'collect_images') {
            mark('count'); mark('prompt'); mark('text');
            html = field(idx, 'count', 'How many images', '<input type="number" min="1" class="form-control" name="rules[' + idx + '][config][count]" value="' + esc(config.count || config.max_images || 1) + '">') +
                field(idx, 'prompt', 'Ask the contact', textInput(idx, 'prompt', config.prompt || config.text, 'Please send the photo'));
        } else if (actionType === 'add_note') {
            mark('text'); mark('note'); mark('value');
            html = '<div class="col-12"><label class="form-label">Note</label><textarea class="form-control" rows="2" name="rules[' + idx + '][config][text]">' + esc(config.text || config.note || config.value || '') + '</textarea></div>';
        } else {
            mark('text'); mark('body'); mark('value');
            html = '<div class="col-12"><label class="form-label">Message</label><textarea class="form-control" rows="2" name="rules[' + idx + '][config][text]" placeholder="Hi {{name}}">' + esc(config.text || config.body || config.value || '') + '</textarea></div>';
        }

        Object.keys(config).forEach(function (key) {
            if (used[key]) return;
            var value = config[key];
            if (value == null || typeof value === 'object') return;
            html += '<input type="hidden" name="rules[' + idx + '][config][' + esc(key) + ']" value="' + esc(value) + '">';
        });

        return html;
    }

    function refreshTrigger() {
        var type = $('#triggerType').val() || '';
        $('#triggerHint').text(HINTS[type] || 'Runs when this event reaches the automation engine.');
    }

    Automations.stepHtml = function (step) {
        step = step || {};
        var idx = Automations.stepIndex++;
        var type = step.rule_type === 'condition' ? 'condition' : 'action';
        var actionType = step.action_type || (type === 'condition' ? 'message_contains' : 'send_text');
        var config = asConfig(step);
        if (type === 'action' && (actionType === 'response_message' || actionType === 'system_initiated')) {
            actionType = actionType === 'system_initiated' ? 'send_template' : 'send_text';
        }

        return (
            '<div class="rule-step ' + type + '" data-index="' + idx + '">' +
            '<div class="rule-step-top">' +
            '<span class="rule-step-index"></span>' +
            '<strong class="step-title">' + (type === 'condition' ? 'Condition' : 'Action') + '</strong>' +
            '<button type="button" class="btn btn-sm btn-outline-danger btn-remove-step" aria-label="Remove step"><i class="fas fa-times"></i></button>' +
            '</div>' +
            '<input type="hidden" name="rules[' + idx + '][rule_type]" value="' + type + '">' +
            '<input type="hidden" name="rules[' + idx + '][step_order]" class="step-order" value="' + esc(step.step_order || idx + 1) + '">' +
            '<div class="row g-2">' +
            '<div class="col-md-4"><label class="form-label">Type</label>' +
            '<select class="form-select step-action-type" name="rules[' + idx + '][action_type]">' +
            optionsHtml(type === 'condition' ? CONDITIONS : ACTIONS, actionType) +
            '</select></div>' +
            '<div class="col-md-8"><div class="row g-2 step-fields">' + fieldsHtml(idx, type, actionType, config) + '</div></div>' +
            '</div>' +
            (type === 'condition'
                ? '<div class="row g-2 mt-1"><div class="col-md-6"><label class="form-label">If true, go to step</label>' +
                  '<input type="number" min="1" class="form-control" name="rules[' + idx + '][next_on_true]" value="' + esc(step.next_on_true || '') + '" placeholder="Next step"></div>' +
                  '<div class="col-md-6"><label class="form-label">If false, go to step</label>' +
                  '<input type="number" min="1" class="form-control" name="rules[' + idx + '][next_on_false]" value="' + esc(step.next_on_false || '') + '" placeholder="Next step"></div></div>'
                : '') +
            '</div>'
        );
    };

    Automations.addStep = function (type, data) {
        data = data || {};
        data.rule_type = type;
        $('#rulesList').append(Automations.stepHtml(data));
        Automations.reindex();
    };

    Automations.reindex = function () {
        $('#rulesList .rule-step').each(function (i) {
            $(this).find('.step-order').val(i + 1);
            $(this).find('.rule-step-index').text(i + 1);
        });
        $('#rulesEmpty').toggleClass('d-none', $('#rulesList .rule-step').length > 0);
    };

    Automations.loadExisting = function () {
        var raw = $('#existingRules').val();
        if (!raw) return;
        try {
            var rules = JSON.parse(raw);
            if (!Array.isArray(rules)) return;
            rules.forEach(function (rule) {
                Automations.addStep(rule.rule_type || 'action', rule);
            });
        } catch (e) {
            // Keep the empty state when stored rules are not a list.
        }
    };

    $(function () {
        if (!$('#automationBuilder').length) return;

        var catalogNode = document.getElementById('automationCatalog');
        if (catalogNode) {
            try { Automations.catalog = JSON.parse(catalogNode.textContent || '{}'); } catch (e) { /* defaults */ }
        }

        refreshTrigger();
        $('#triggerType').on('change', refreshTrigger);
        $('#automationBuilder').on('submit', function (event) {
            var raw = $.trim($('#triggerConfig').val() || '');
            var $error = $('#triggerConfigError');
            if (!raw) {
                $error.addClass('d-none').text('');
                return;
            }
            try {
                var parsed = JSON.parse(raw);
                if (!parsed || typeof parsed !== 'object' || Array.isArray(parsed)) {
                    throw new Error('object');
                }
                $error.addClass('d-none').text('');
            } catch (e) {
                event.preventDefault();
                $error.removeClass('d-none').text('Enter a JSON object, for example {"query":"content contains hello"}.');
                $('#triggerConfig').trigger('focus');
            }
        });

        if ($('#rulesList').length) {
            Automations.loadExisting();
            Automations.reindex();
        }

        $('#btnAddCondition').on('click', function () { Automations.addStep('condition'); });
        $('#btnAddAction').on('click', function () { Automations.addStep('action'); });

        $(document).on('click', '.btn-remove-step', function () {
            $(this).closest('.rule-step').remove();
            Automations.reindex();
        });

        $(document).on('change', '.step-action-type', function () {
            var $step = $(this).closest('.rule-step');
            var idx = $step.data('index');
            var ruleType = $step.hasClass('condition') ? 'condition' : 'action';
            $step.find('.step-fields').html(fieldsHtml(idx, ruleType, $(this).val(), {}));
        });
    });

    window.AutomationsApp = Automations;
})(window, jQuery);
