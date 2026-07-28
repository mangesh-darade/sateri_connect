/**
 * Campaigns — variable mapping, preview, schedule helpers
 */
(function (window, $) {
    'use strict';

    var Campaigns = {};

    function base() {
        return (window.APP && APP.baseUrl) || '';
    }

    Campaigns.renderVariableMap = function (variables) {
        var $wrap = $('#variableMap');
        if (!$wrap.length) return;
        $wrap.empty();
        if (!variables || !variables.length) {
            $wrap.html('<p class="text-muted mb-0">This template has no variables.</p>');
            return;
        }
        variables.forEach(function (v, idx) {
            var key = typeof v === 'string' ? v : (v.name || v.key || ('var' + (idx + 1)));
            var label = typeof v === 'string' ? v : (v.label || key);
            var row =
                '<div class="row var-map-row">' +
                '<div class="col-md-4"><label class="mb-0 fw-semibold">{{' + $('<div>').text(label).html() + '}}</label></div>' +
                '<div class="col-md-8">' +
                '<select class="form-select form-select-sm var-source" name="variables[' + $('<div>').text(key).html() + ']" data-var="' + $('<div>').text(key).html() + '">' +
                '<option value="name">Contact Name</option>' +
                '<option value="mobile">Mobile</option>' +
                '<option value="email">Email</option>' +
                '<option value="custom">Custom value…</option>' +
                '</select>' +
                '<input type="text" class="form-control form-control-sm mt-1 var-custom d-none" name="variables_custom[' + $('<div>').text(key).html() + ']" placeholder="Custom value" data-var="' + $('<div>').text(key).html() + '">' +
                '</div></div>';
            $wrap.append(row);
        });
    };

    Campaigns.loadTemplateVars = function (templateId) {
        if (!templateId) {
            Campaigns.renderVariableMap([]);
            return;
        }
        APP.get(base() + '/templates/' + templateId + '/preview').done(function (res) {
            var tpl = res.data || res.template || res;
            var vars = tpl.variables || [];
            if (typeof vars === 'string') {
                try { vars = JSON.parse(vars); } catch (e) { vars = []; }
            }
            Campaigns.renderVariableMap(vars);
            var body = tpl.body || '';
            $('#templatePreviewBody').text(body);
            $('#templatePreviewCard').removeClass('d-none');
        }).fail(function () {
            APP.toast('Could not load template', 'error');
        });
    };

    Campaigns.collectAudience = function () {
        return {
            contact_ids: ($('#contactIds').val() || []).map(String),
            tag_ids: ($('#tagIds').val() || []).map(String),
            all_active: $('#audienceAll').is(':checked') ? 1 : 0
        };
    };

    Campaigns.preview = function () {
        var id = $('#campaignId').val();
        var url = id ? (base() + '/campaigns/' + id + '/preview') : '';
        if (!url) {
            APP.toast('Save the campaign first to preview audience.', 'info');
            return;
        }
        var data = {
            template_id: $('#templateId').val(),
            name: $('#campaignName').val(),
            variables: {},
            audience: Campaigns.collectAudience()
        };
        $('#variableMap .var-source').each(function () {
            var key = $(this).data('var');
            var val = $(this).val();
            if (val === 'custom') {
                data.variables[key] = $('#variableMap .var-custom[data-var="' + key + '"]').val() || '';
            } else {
                data.variables[key] = '{{' + val + '}}';
            }
        });
        APP.post(url, data).done(function (res) {
            var html = (res.data && res.data.html) || res.html || res.preview || JSON.stringify(res, null, 2);
            $('#campaignPreviewContent').html(typeof html === 'string' ? html : $('<pre>').text(JSON.stringify(html, null, 2)));
            if (window.APP && typeof APP.showModal === 'function') {
                APP.showModal('#campaignPreviewModal');
            } else {
                var el = document.getElementById('campaignPreviewModal');
                if (el && window.bootstrap) {
                    bootstrap.Modal.getOrCreateInstance(el).show();
                }
            }
        }).fail(function (xhr) {
            APP.toast((xhr.responseJSON && xhr.responseJSON.message) || 'Preview failed', 'error');
        });
    };

    $(function () {
        if (!$('#campaignForm').length && !$('#variableMap').length) return;

        $('#templateId').on('change', function () {
            Campaigns.loadTemplateVars($(this).val());
        });
        if ($('#templateId').val()) {
            Campaigns.loadTemplateVars($('#templateId').val());
        }

        $(document).on('change', '.var-source', function () {
            var key = $(this).data('var');
            var $custom = $('#variableMap .var-custom[data-var="' + key + '"]');
            if ($(this).val() === 'custom') {
                $custom.removeClass('d-none').prop('required', true);
            } else {
                $custom.addClass('d-none').prop('required', false);
            }
        });

        $('#btnPreviewCampaign').on('click', function (e) {
            e.preventDefault();
            Campaigns.preview();
        });

        $('#scheduleToggle').on('change', function () {
            $('#scheduleFields').toggleClass('d-none', !this.checked);
        });

        $('#audienceAll').on('change', function () {
            var on = this.checked;
            $('#contactIds, #tagIds').prop('disabled', on);
        });
    });

    window.CampaignsApp = Campaigns;
})(window, jQuery);
