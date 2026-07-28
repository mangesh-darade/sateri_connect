/**
 * Automations builder — add/remove condition & action steps
 */
(function (window, $) {
    'use strict';

    var Automations = {
        stepIndex: 0
    };

    var TRIGGERS = [
        { value: 'incoming_message', label: 'Incoming WhatsApp' },
        { value: 'campaign_sent', label: 'Campaign Sent' },
        { value: 'shopify_event', label: 'Shopify Events' },
        { value: 'facebook_lead', label: 'Facebook Lead' },
        { value: 'kylas_event_create', label: 'Kylas Event Create' },
        { value: 'kylas_event_update', label: 'Kylas Event Update' },
        { value: 'pabbly_event', label: 'Pabbly Event' },
        { value: 'incoming_webhook', label: 'Incoming Webhook' },
        { value: 'messenger', label: 'Messenger' },
        { value: 'instagram', label: 'Instagram' },
        { value: 'commerce_event', label: 'Commerce Event' },
        { value: 'contact_created', label: 'New contact' },
        { value: 'form_response', label: 'New form response' },
        { value: 'keyword_matched', label: 'Keyword matched' },
        { value: 'campaign_replied', label: 'Campaign reply received' },
        { value: 'tag_added', label: 'Tag added' },
        { value: 'schedule', label: 'Scheduled / cron' },
        { value: 'cheerio_workflow', label: 'Imported Cheerio workflow' }
    ];

    var CONDITIONS = [
        { value: 'message_contains', label: 'Message contains' },
        { value: 'message_equals', label: 'Message equals' },
        { value: 'has_tag', label: 'Contact has tag' },
        { value: 'within_window', label: 'Within 24h window' },
        { value: 'contact_status', label: 'Contact status is' }
    ];

    var ACTIONS = [
        { value: 'system_initiated', label: 'System initiated' },
        { value: 'response_message', label: 'Response message' },
        { value: 'collect_images', label: 'Collect Images' },
        { value: 'send_template', label: 'Send WA template' },
        { value: 'send_text', label: 'Send text message' },
        { value: 'set_attribute', label: 'Update attribute' },
        { value: 'add_tag', label: 'Add tag' },
        { value: 'remove_tag', label: 'Remove tag' },
        { value: 'assign_agent', label: 'Assign agent' },
        { value: 'add_note', label: 'Add internal note' },
        { value: 'webhook', label: 'Call webhook URL' },
        { value: 'delay', label: 'Delay (minutes)' }
    ];

    function optionsHtml(list, selected) {
        return list.map(function (i) {
            return '<option value="' + i.value + '"' + (i.value === selected ? ' selected' : '') + '>' + i.label + '</option>';
        }).join('');
    }

    Automations.stepHtml = function (step) {
        step = step || {};
        var idx = Automations.stepIndex++;
        var type = step.rule_type || 'action';
        var actionType = step.action_type || (type === 'condition' ? 'message_contains' : 'send_text');
        var config = step.config || {};
        var configVal = typeof config === 'string' ? config : (config.value || config.text || config.url || JSON.stringify(config) || '');

        return (
            '<div class="rule-step ' + type + '" data-index="' + idx + '">' +
            '<div class="d-flex justify-content-between align-items-start mb-2">' +
            '<div><i class="fas fa-grip-vertical step-handle me-2"></i>' +
            '<strong class="step-title">' + (type === 'condition' ? 'Condition' : 'Action') + '</strong></div>' +
            '<button type="button" class="btn btn-sm btn-outline-danger btn-remove-step"><i class="fas fa-times"></i></button>' +
            '</div>' +
            '<input type="hidden" name="rules[' + idx + '][rule_type]" value="' + type + '">' +
            '<input type="hidden" name="rules[' + idx + '][step_order]" class="step-order" value="' + (step.step_order || idx + 1) + '">' +
            '<div class="row g-2">' +
            '<div class="col-md-4">' +
            '<label class="form-label">Type</label>' +
            '<select class="form-select step-action-type" name="rules[' + idx + '][action_type]">' +
            optionsHtml(type === 'condition' ? CONDITIONS : ACTIONS, actionType) +
            '</select></div>' +
            '<div class="col-md-8">' +
            '<label class="form-label">Config / value</label>' +
            '<input type="text" class="form-control" name="rules[' + idx + '][config][value]" value="' + $('<div>').text(configVal === '{}' ? '' : configVal).html() + '" placeholder="Text, tag id, template id, URL…">' +
            '</div></div>' +
            (type === 'condition'
                ? '<div class="row g-2 mt-1"><div class="col-md-6"><label class="form-label">Next if true (step #)</label>' +
                  '<input type="number" class="form-control" name="rules[' + idx + '][next_on_true]" value="' + (step.next_on_true || '') + '"></div>' +
                  '<div class="col-md-6"><label class="form-label">Next if false (step #)</label>' +
                  '<input type="number" class="form-control" name="rules[' + idx + '][next_on_false]" value="' + (step.next_on_false || '') + '"></div></div>'
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
        });
    };

    Automations.loadExisting = function () {
        var raw = $('#existingRules').val();
        if (!raw) return;
        try {
            var rules = JSON.parse(raw);
            if (!Array.isArray(rules)) return;
            rules.forEach(function (r) {
                Automations.addStep(r.rule_type || 'action', r);
            });
        } catch (e) {
            // ignore
        }
    };

    $(function () {
        if (!$('#automationBuilder').length && !$('#rulesList').length) return;

        Automations.loadExisting();
        if (!$('#rulesList .rule-step').length) {
            Automations.addStep('condition');
            Automations.addStep('action');
        }

        $('#btnAddCondition').on('click', function () { Automations.addStep('condition'); });
        $('#btnAddAction').on('click', function () { Automations.addStep('action'); });

        $(document).on('click', '.btn-remove-step', function () {
            $(this).closest('.rule-step').remove();
            Automations.reindex();
        });

        // Populate trigger select labels if empty options besides placeholder
        var $trigger = $('#triggerType');
        if ($trigger.length && $trigger.find('option').length <= 1) {
            TRIGGERS.forEach(function (t) {
                $trigger.append('<option value="' + t.value + '">' + t.label + '</option>');
            });
        }
    });

    window.AutomationsApp = Automations;
})(window, jQuery);
