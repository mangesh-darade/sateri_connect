/**
 * Campaigns hub + multi-step New Campaign wizard (WhatsApp / Email).
 */
(function (window, $) {
    'use strict';

    var Campaigns = {};
    var state = {
        channel: 'whatsapp',
        step: 1,
        data: null,
        labelId: 0,
        labelName: '',
        templateId: 0,
        template: null,
        builderId: 0,
        attributes: [],
        audience: { total: 0, phone_count: 0, email_count: 0, contact_ids: [] },
        campaignId: 0,
        mediaUrl: '',
        mediaId: '',
        mediaMime: '',
        variables: {}
    };

    function base() {
        return (window.APP && APP.baseUrl) || '';
    }

    function esc(s) {
        return $('<div>').text(s == null ? '' : String(s)).html();
    }

    function toast(msg, type) {
        if (window.APP && typeof APP.toast === 'function') {
            APP.toast(msg, type || 'info');
        } else {
            window.alert(msg);
        }
    }

    function clearWizardErrors() {
        $('#cwFormError').addClass('d-none').text('');
        $('#campaignWizardModal .is-invalid').removeClass('is-invalid');
        $('#cwMediaUrlError').addClass('d-none');
    }

    function showWizardError(message, $field) {
        var msg = String(message || 'Please fix the highlighted fields.');
        $('#cwFormError').removeClass('d-none').text(msg);
        toast(msg, 'error');
        if ($field && $field.length) {
            $field.addClass('is-invalid').trigger('focus');
        }
        return false;
    }

    function apiErrorMessage(xhr, fallback) {
        if (xhr && xhr.responseJSON) {
            if (xhr.responseJSON.message) {
                return String(xhr.responseJSON.message);
            }
            if (xhr.responseJSON.errors && typeof xhr.responseJSON.errors === 'object') {
                var first = Object.values(xhr.responseJSON.errors)[0];
                if (Array.isArray(first) && first[0]) return String(first[0]);
                if (first) return String(first);
            }
        }
        return fallback || 'Request failed.';
    }

    function isBadMediaUrl(url) {
        url = String(url || '').trim();
        if (!url) return true;
        return url.indexOf('must be of type') !== -1
            || url.indexOf('Argument #') !== -1
            || url.indexOf('App\\Controllers') !== -1
            || url.indexOf('<') !== -1;
    }

    function showModal() {
        var el = document.getElementById('campaignWizardModal');
        if (!el) return;
        if (window.bootstrap && bootstrap.Modal) {
            bootstrap.Modal.getOrCreateInstance(el).show();
        } else if (window.APP && typeof APP.showModal === 'function') {
            APP.showModal('#campaignWizardModal');
        } else {
            $(el).modal('show');
        }
    }

    function hideModal() {
        var el = document.getElementById('campaignWizardModal');
        if (!el) return;
        if (window.bootstrap && bootstrap.Modal) {
            var inst = bootstrap.Modal.getInstance(el);
            if (inst) inst.hide();
        } else {
            $(el).modal('hide');
        }
    }

    function setStep(step) {
        state.step = step;
        $('#cwStep').val(String(step));
        clearWizardErrors();
        $('.campaign-wizard-step').removeClass('is-active');
        $('.campaign-wizard-step[data-step="' + step + '"]').addClass('is-active');
        $('#cwBackBtn').toggleClass('d-none', step <= 1);

        var titles = {
            1: 'New Campaign',
            2: 'New Campaign',
            3: state.channel === 'email' ? 'Choose email content' : 'Choose campaign template',
            4: state.channel === 'email' ? 'Enter Email Details' : 'Enter Custom Template Details',
            5: state.channel === 'email' ? 'Share Email Campaign' : 'Share Custom Template'
        };
        $('#cwTitle').text(titles[step] || 'New Campaign');
        $('#campaignWizardDialog').toggleClass('modal-xl', step === 3 || step === 4);

        $('#cwNextBtn').toggleClass('d-none', step >= 5);
        $('#cwScheduleBtn, #cwRunBtn').toggleClass('d-none', step < 5);
        // Schedule picker stays hidden until user clicks "Schedule Campaign".
        if (step === 5) {
            $('#cwScheduleWrap').addClass('d-none');
            $('#cwScheduledAt').val('').removeClass('is-invalid');
            $('#cwScheduleBtn').html('<i class="fas fa-clock me-1"></i> Schedule Campaign');
        } else {
            $('#cwScheduleWrap').addClass('d-none');
        }

        if (step === 3) {
            renderTemplateStep();
        }
        if (step === 4) {
            renderDetailsStep();
        }
        if (step === 5) {
            renderShareStep();
        }
    }

    function loadWizardData(force) {
        if (state.data && !force) {
            return $.Deferred().resolve(state.data).promise();
        }
        return APP.get(base() + '/campaigns/wizard-data').then(function (res) {
            state.data = (res && res.data) ? res.data : res;
            populateLabels();
            populateEmailBuilders();
            return state.data;
        });
    }

    function populateLabels() {
        var $sel = $('#cwLabel');
        var current = $sel.val();
        $sel.empty().append('<option value="">Select segment</option>');
        (state.data.labels || []).forEach(function (label) {
            $sel.append(
                $('<option>')
                    .val(label.id)
                    .text(label.name + ' (' + (label.contact_count || 0) + ')')
                    .attr('data-name', label.name)
            );
        });
        if (current) {
            $sel.val(current);
        }
    }

    function populateEmailBuilders() {
        var $sel = $('#cwEmailBuilder');
        $sel.empty().append('<option value="">Custom HTML</option>');
        (state.data.email_builders || []).forEach(function (b) {
            $sel.append(
                $('<option>')
                    .val(b.id)
                    .text(b.name)
                    .attr('data-subject', b.subject || '')
                    .attr('data-html', b.html_content || '')
                    .attr('data-cheerio', b.cheerio_builder_id || '')
            );
        });
    }

    function selectedLabelName() {
        var $opt = $('#cwLabel option:selected');
        return $opt.data('name') || $opt.text() || '';
    }

    function collectAttributes() {
        var rows = [];
        $('#cwAttrRows .cw-attr-row').each(function () {
            var name = String($(this).find('.cw-attr-name').val() || '').trim();
            var condition = String($(this).find('.cw-attr-condition').val() || 'equals').trim();
            var value = String($(this).find('.cw-attr-value').val() || '').trim();
            if (name && value) {
                rows.push({ name: name, condition: condition, value: value });
            }
        });
        state.attributes = rows;
        return rows;
    }

    function addAttrRow(prefill) {
        prefill = prefill || {};
        var fields = (state.data && state.data.attribute_fields) || [
            { value: 'name', label: 'Name' },
            { value: 'mobile', label: 'Phone' },
            { value: 'email', label: 'Email' },
            { value: 'status', label: 'Status' }
        ];
        var conditions = (state.data && state.data.conditions) || [
            { value: 'equals', label: 'Equals' },
            { value: 'contains', label: 'Contains' }
        ];
        var fieldOpts = fields.map(function (f) {
            return '<option value="' + esc(f.value) + '"' + (prefill.name === f.value ? ' selected' : '') + '>' + esc(f.label) + '</option>';
        }).join('');
        var condOpts = conditions.map(function (c) {
            return '<option value="' + esc(c.value) + '"' + (prefill.condition === c.value ? ' selected' : '') + '>' + esc(c.label) + '</option>';
        }).join('');
        var html =
            '<div class="cw-attr-row">' +
            '<select class="form-select form-select-sm cw-attr-name"><option value="">Select</option>' + fieldOpts + '</select>' +
            '<select class="form-select form-select-sm cw-attr-condition">' + condOpts + '</select>' +
            '<input type="text" class="form-control form-control-sm cw-attr-value" placeholder="Attribute Value" value="' + esc(prefill.value || '') + '">' +
            '<button type="button" class="btn btn-link text-danger p-0 cw-attr-remove" title="Remove"><i class="fas fa-trash"></i></button>' +
            '</div>';
        $('#cwAttrRows').append(html);
    }

    function previewAudience() {
        var tagId = parseInt($('#cwLabel').val() || '0', 10);
        if (!tagId) {
            toast('Select a label first.', 'error');
            return $.Deferred().reject().promise();
        }
        collectAttributes();
        return APP.post(base() + '/campaigns/audience-preview', {
            tag_id: tagId,
            attributes: state.attributes
        }).done(function (res) {
            var data = (res && res.data) ? res.data : {};
            state.audience = {
                total: data.total || 0,
                phone_count: data.phone_count || 0,
                email_count: data.email_count || 0,
                contact_ids: data.contact_ids || []
            };
            $('#cwAudienceCounts').text(
                'Phone Numbers fetched: ' + state.audience.phone_count +
                ' | Emails fetched: ' + state.audience.email_count
            );
            $('#cwShareCounts').text(state.audience.total + ' contacts');
        }).fail(function (xhr) {
            showWizardError(apiErrorMessage(xhr, 'Audience preview failed'));
        });
    }

    function renderTemplateStep() {
        var isEmail = state.channel === 'email';
        $('#cwTplSyncAlert, #cwTemplateSearchWrap, #cwTemplateGrid').toggleClass('d-none', isEmail);
        $('#cwEmailTemplateWrap').toggleClass('d-none', !isEmail);
        if (!isEmail) {
            renderTemplateCards($('#cwTemplateSearch').val() || '');
        }
    }

    function renderTemplateCards(query) {
        query = String(query || '').toLowerCase();
        var html = '';
        (state.data.templates || []).forEach(function (tpl) {
            var hay = (tpl.name + ' ' + (tpl.body || '') + ' ' + (tpl.category || '')).toLowerCase();
            if (query && hay.indexOf(query) === -1) return;
            var selected = String(state.templateId) === String(tpl.id) ? ' is-selected' : '';
            html +=
                '<div class="cw-template-card' + selected + '" data-id="' + tpl.id + '">' +
                '<div class="d-flex flex-wrap gap-1">' +
                '<span class="badge text-bg-success">WhatsApp</span>' +
                '<span class="badge text-bg-light border">' + esc(tpl.status || 'APPROVED') + '</span>' +
                '<span class="badge text-bg-light border">' + esc(tpl.category || '') + '</span>' +
                '</div>' +
                '<div class="cw-tpl-name">' + esc(tpl.name) + '</div>' +
                '<div class="cw-tpl-body">' + esc(tpl.body || '') + '</div>' +
                '<button type="button" class="btn btn-wa btn-sm cw-use-template">Use Template</button>' +
                '</div>';
        });
        if (!html) {
            html = '<div class="text-muted">No approved templates found. Sync from Template Library.</div>';
        }
        $('#cwTemplateGrid').html(html);
    }

    function selectTemplate(id) {
        var tpl = (state.data.templates || []).find(function (t) { return String(t.id) === String(id); });
        if (!tpl) {
            toast('Template not found', 'error');
            return;
        }
        state.templateId = parseInt(id, 10);
        state.template = tpl;
        renderTemplateCards($('#cwTemplateSearch').val() || '');
        setStep(4);
    }

    function renderVariableMap(variables) {
        var $wrap = $('#cwVariableMap');
        $wrap.empty();
        if (!variables || !variables.length) {
            $wrap.html('<p class="text-muted small mb-0">This template has no variables.</p>');
            return;
        }
        variables.forEach(function (v) {
            var key = String(v);
            var selected = state.variables[key] || 'name';
            var row =
                '<div class="row g-2 mb-2 align-items-center">' +
                '<div class="col-4"><code>{{' + esc(key) + '}}</code></div>' +
                '<div class="col-8">' +
                '<select class="form-select form-select-sm cw-var-source" data-var="' + esc(key) + '">' +
                '<option value="name"' + (selected === 'name' ? ' selected' : '') + '>Contact Name</option>' +
                '<option value="mobile"' + (selected === 'mobile' ? ' selected' : '') + '>Mobile</option>' +
                '<option value="email"' + (selected === 'email' ? ' selected' : '') + '>Email</option>' +
                '<option value="custom"' + (selected === 'custom' || (selected && selected.indexOf('{{') === -1 && ['name','mobile','email'].indexOf(selected) === -1) ? ' selected' : '') + '>Custom value…</option>' +
                '</select>' +
                '<input type="text" class="form-control form-control-sm mt-1 cw-var-custom' +
                (selected === 'custom' || (selected && ['name','mobile','email','custom'].indexOf(selected) === -1) ? '' : ' d-none') +
                '" data-var="' + esc(key) + '" placeholder="Custom value" value="' +
                esc((selected && ['name','mobile','email','custom'].indexOf(selected) === -1) ? selected : '') + '">' +
                '</div></div>';
            $wrap.append(row);
        });
    }

    function collectVariables() {
        var map = {};
        $('#cwVariableMap .cw-var-source').each(function () {
            var key = String($(this).data('var'));
            var val = $(this).val();
            if (val === 'custom') {
                map[key] = $('#cwVariableMap .cw-var-custom[data-var="' + key + '"]').val() || '';
            } else {
                map[key] = val;
            }
        });
        state.variables = map;
        return map;
    }

    function renderDetailsStep() {
        var isEmail = state.channel === 'email';
        $('#cwPreviewChannelLabel').text(isEmail ? 'Email' : 'WhatsApp');
        if (isEmail) {
            $('#cwUploadBox, #cwVarMapWrap').addClass('d-none');
            var subject = $('#cwEmailSubject').val() || '';
            var html = $('#cwEmailHtml').val() || '';
            $('#cwPreviewBody').html('<strong>' + esc(subject) + '</strong><hr class="border-secondary my-2">' + (html || '<em>No HTML</em>'));
            return;
        }
        $('#cwVarMapWrap').removeClass('d-none');
        var tpl = state.template || {};
        var needsMedia = !!tpl.needs_media;
        var mediaOptional = !!tpl.media_optional;
        var showMedia = needsMedia || mediaOptional;
        // IMAGE/VIDEO/DOCUMENT: show upload. If approved sample exists, upload is optional.
        $('#cwUploadBox').toggleClass('d-none', !showMedia);
        $('#cwMediaCol').toggleClass('d-none', !showMedia);
        if (!showMedia) {
            state.mediaUrl = '';
            state.mediaId = '';
            state.mediaMime = '';
            $('#cwMediaUrl').val('');
            $('#cwMediaFile').val('');
            $('#cwMediaStatus').addClass('d-none').text('');
        } else if (mediaOptional) {
            // Drop stale override from a previous template/upload so sample can be used.
            state.mediaUrl = '';
            state.mediaId = '';
            state.mediaMime = '';
            $('#cwMediaUrl').val('');
            $('#cwMediaFile').val('');
            $('#cwMediaStatus').addClass('d-none').text('');
            $('#cwMediaUrlError').addClass('d-none');
            $('#cwMediaUrl').removeClass('is-invalid');
            $('#cwUploadTitle').text('Replace header media (optional)');
            $('#cwUploadHint').text('Approved template already has ' + (tpl.header_type || 'media')
                + ' sample. Leave empty to use that sample, or upload to override.');
        } else {
            $('#cwUploadTitle').text('Upload media here');
            $('#cwUploadHint').text('Drag & drop or click — required for this template header (' + (tpl.header_type || 'media') + ')');
        }
        renderVariableMap(tpl.variables || []);
        updateWaPreview();
    }

    function updateWaPreview() {
        var tpl = state.template || {};
        var body = String(tpl.body || 'Select a template to preview.');
        var footer = String(tpl.footer || '');
        var media = $('#cwMediaUrl').val() || state.mediaUrl || '';
        var html = '';
        if (media) {
            html += '<div class="mb-2 small text-info">[Media attached]</div>';
        } else if (tpl.has_sample_media || tpl.media_optional) {
            html += '<div class="mb-2 small text-muted">[Using approved template sample media]</div>';
        }
        html += esc(body).replace(/\n/g, '<br>');
        if (footer) {
            html += '<div class="small text-muted mt-2">' + esc(footer) + '</div>';
        }
        $('#cwPreviewBody').html(html);
    }

    function renderShareStep() {
        $('#cwSelectedLabelChip, #cwShareLabelChip').text(state.labelName || selectedLabelName() || '—');
        var countLabel = state.channel === 'email'
            ? (state.audience.email_count + ' emails')
            : (state.audience.phone_count + ' phones / ' + state.audience.total + ' contacts');
        $('#cwShareCounts').text(countLabel);
        if (state.channel === 'email') {
            $('#cwShareTplName').text($('#cwEmailSubject').val() || 'Email campaign');
            $('#cwShareTplBody').text(($('#cwEmailHtml').val() || '').replace(/<[^>]+>/g, ' ').slice(0, 160));
        } else {
            $('#cwShareTplName').text((state.template && state.template.name) || '—');
            $('#cwShareTplBody').text((state.template && state.template.body) || '');
        }
    }

    function validateStep(step) {
        clearWizardErrors();

        if (step === 1) {
            var name = String($('#cwName').val() || '').trim();
            var labelId = parseInt($('#cwLabel').val() || '0', 10);
            if (!name) {
                return showWizardError('Enter a campaign name.', $('#cwName'));
            }
            if (name.length > 30) {
                return showWizardError('Campaign name must be 30 characters or less.', $('#cwName'));
            }
            if (!labelId) {
                return showWizardError('Select a label / segment.', $('#cwLabel'));
            }
            state.labelId = labelId;
            state.labelName = selectedLabelName();
            return true;
        }
        if (step === 2) {
            collectAttributes();
            var incomplete = false;
            $('#cwAttrRows .cw-attr-row').each(function () {
                var attrName = String($(this).find('.cw-attr-name').val() || '').trim();
                var value = String($(this).find('.cw-attr-value').val() || '').trim();
                if (attrName && !value) {
                    $(this).find('.cw-attr-value').addClass('is-invalid');
                    incomplete = true;
                }
            });
            if (incomplete) {
                return showWizardError('Enter a value for each selected attribute, or clear the attribute row.');
            }
            if (!state.audience.total) {
                return showWizardError('No contacts found for this label. Click Verify attribute or choose another label.');
            }
            if (state.channel === 'whatsapp' && !state.audience.phone_count) {
                return showWizardError('No phone numbers found for this audience. Add mobiles to the label contacts.');
            }
            if (state.channel === 'email' && !state.audience.email_count) {
                return showWizardError('No emails found for this audience. Add emails to the label contacts.');
            }
            return true;
        }
        if (step === 3) {
            if (state.channel === 'whatsapp') {
                if (!state.templateId) {
                    return showWizardError('Select a WhatsApp template (click Use Template).');
                }
                return true;
            }
            var subject = String($('#cwEmailSubject').val() || '').trim();
            if (!subject) {
                return showWizardError('Email subject is required.', $('#cwEmailSubject'));
            }
            return true;
        }
        if (step === 4) {
            if (state.channel === 'whatsapp' && state.template && state.template.needs_media && !state.template.has_sample_media) {
                var url = String($('#cwMediaUrl').val() || state.mediaUrl || '').trim();
                if (isBadMediaUrl(url) && !state.mediaId) {
                    $('#cwMediaUrl').addClass('is-invalid');
                    $('#cwMediaUrlError').removeClass('d-none');
                    return showWizardError('Upload or paste a valid media URL for this template header.', $('#cwMediaUrl'));
                }
                state.mediaUrl = url;
            }
            var missingVar = null;
            $('#cwVariableMap .cw-var-source').each(function () {
                if ($(this).val() === 'custom') {
                    var key = $(this).data('var');
                    var $custom = $('#cwVariableMap .cw-var-custom[data-var="' + key + '"]');
                    if (!String($custom.val() || '').trim()) {
                        $custom.addClass('is-invalid');
                        missingVar = $custom;
                    }
                }
            });
            if (missingVar) {
                return showWizardError('Enter custom values for all mapped variables.', missingVar);
            }
            collectVariables();
            return true;
        }
        return true;
    }

    function goNext() {
        if (!validateStep(state.step)) {
            return;
        }
        if (state.step === 1) {
            $('#cwSelectedLabelChip').text(state.labelName);
            var $next = $('#cwNextBtn').prop('disabled', true);
            previewAudience().done(function () {
                // Attribute rows appear only when user clicks "Add attribute".
                setStep(2);
            }).always(function () {
                $next.prop('disabled', false);
            });
            return;
        }
        if (state.step === 2) {
            var $btn2 = $('#cwNextBtn').prop('disabled', true);
            previewAudience().done(function () {
                if (!validateStep(2)) return;
                setStep(3);
            }).always(function () {
                $btn2.prop('disabled', false);
            });
            return;
        }
        if (state.step === 3) {
            if (state.channel === 'whatsapp' && !state.templateId) {
                showWizardError('Click Use Template on a card, or select one.');
                return;
            }
            setStep(4);
            return;
        }
        if (state.step === 4) {
            saveDraft().done(function () {
                setStep(5);
            });
        }
    }

    function goBack() {
        if (state.step <= 1) return;
        clearWizardErrors();
        setStep(state.step - 1);
    }

    function saveDraft() {
        clearWizardErrors();
        var payload = {
            channel: state.channel,
            name: String($('#cwName').val() || '').trim(),
            tag_id: state.labelId,
            attributes: state.attributes
        };
        if (state.channel === 'whatsapp') {
            payload.template_id = state.templateId;
            payload.variables = collectVariables();
            if (state.template && state.template.needs_media) {
                payload.header_media_url = String($('#cwMediaUrl').val() || state.mediaUrl || '').trim();
                if (state.mediaId) {
                    payload.header_media_id = String(state.mediaId).trim();
                }
                if (state.mediaMime) {
                    payload.header_media_mime = String(state.mediaMime).trim();
                }
            } else if (state.template && state.template.media_optional) {
                // Only treat as override when the URL field has a value (fresh upload/paste).
                // Orphan mediaId from an earlier PNG attempt must not force DOCUMENT validation.
                var overrideUrl = String($('#cwMediaUrl').val() || '').trim();
                if (overrideUrl) {
                    payload.header_media_url = overrideUrl;
                    if (state.mediaId && state.mediaUrl === overrideUrl) {
                        payload.header_media_id = String(state.mediaId).trim();
                    }
                    if (state.mediaMime && state.mediaUrl === overrideUrl) {
                        payload.header_media_mime = String(state.mediaMime).trim();
                    }
                }
                // Empty field → approved template sample is used automatically.
            }
        } else {
            payload.subject = String($('#cwEmailSubject').val() || '').trim();
            payload.html_content = String($('#cwEmailHtml').val() || '');
            payload.builder_id = parseInt($('#cwEmailBuilder').val() || '0', 10) || null;
            var $opt = $('#cwEmailBuilder option:selected');
            payload.cheerio_builder_id = $opt.data('cheerio') || '';
        }

        var $btn = $('#cwNextBtn').prop('disabled', true);
        var deferred = $.Deferred();
        APP.post(base() + '/campaigns/wizard', payload)
            .done(function (res) {
                if (!res || res.success === false) {
                    showWizardError((res && res.message) || 'Could not save campaign.');
                    deferred.reject(res);
                    return;
                }
                var data = res.data || {};
                state.campaignId = parseInt(data.id || 0, 10);
                if (!state.campaignId) {
                    showWizardError('Campaign was saved but no ID was returned.');
                    deferred.reject(res);
                    return;
                }
                $('#cwCampaignId').val(String(state.campaignId));
                toast(res.message || 'Draft saved.');
                deferred.resolve(res);
            })
            .fail(function (xhr) {
                showWizardError(apiErrorMessage(xhr, 'Could not save campaign.'));
                deferred.reject(xhr);
            })
            .always(function () {
                $btn.prop('disabled', false);
            });
        return deferred.promise();
    }

    function runCampaign() {
        clearWizardErrors();
        if (!state.campaignId) {
            showWizardError('Save the campaign draft first.');
            return;
        }
        var $btn = $('#cwRunBtn').prop('disabled', true);
        APP.post(base() + '/campaigns/wizard/' + state.channel + '/' + state.campaignId + '/run', {})
            .done(function (res) {
                if (!res || res.success === false) {
                    showWizardError((res && res.message) || 'Run failed.');
                    return;
                }
                toast(res.message || 'Campaign started.');
                hideModal();
                var redirect = res.data && res.data.redirect ? res.data.redirect : (base() + '/campaigns');
                window.location.href = redirect;
            })
            .fail(function (xhr) {
                showWizardError(apiErrorMessage(xhr, 'Run failed.'));
            })
            .always(function () {
                $btn.prop('disabled', false);
            });
    }

    function scheduleCampaign() {
        clearWizardErrors();
        if (!state.campaignId) {
            showWizardError('Save the campaign draft first.');
            return;
        }

        // First click reveals the schedule picker; second click submits.
        if ($('#cwScheduleWrap').hasClass('d-none')) {
            $('#cwScheduleWrap').removeClass('d-none');
            $('#cwScheduleBtn').html('<i class="fas fa-check me-1"></i> Confirm Schedule');
            $('#cwScheduledAt').trigger('focus');
            return;
        }

        var when = String($('#cwScheduledAt').val() || '').trim();
        if (!when) {
            return showWizardError('Pick a schedule date/time.', $('#cwScheduledAt'));
        }
        var whenTs = Date.parse(when);
        if (!whenTs || whenTs < Date.now() - 60000) {
            return showWizardError('Schedule time must be in the future.', $('#cwScheduledAt'));
        }
        var $btn = $('#cwScheduleBtn').prop('disabled', true);
        APP.post(base() + '/campaigns/wizard/' + state.channel + '/' + state.campaignId + '/schedule', {
            scheduled_at: when
        }).done(function (res) {
            if (!res || res.success === false) {
                showWizardError((res && res.message) || 'Schedule failed.');
                return;
            }
            toast(res.message || 'Campaign scheduled.');
            hideModal();
            window.location.href = (res.data && res.data.redirect) || (base() + '/campaigns');
        }).fail(function (xhr) {
            showWizardError(apiErrorMessage(xhr, 'Schedule failed.'));
        }).always(function () {
            $btn.prop('disabled', false);
        });
    }

    function openWizard(channel) {
        channel = channel === 'email' ? 'email' : 'whatsapp';
        if (channel === 'whatsapp' && $('#campaignHub').data('can-wa') === 0) {
            toast('You do not have permission to create WhatsApp campaigns.', 'error');
            return;
        }
        if (channel === 'email' && $('#campaignHub').data('can-email') === 0) {
            toast('You do not have permission to create Email campaigns.', 'error');
            return;
        }

        state.channel = channel;
        state.step = 1;
        state.templateId = 0;
        state.template = null;
        state.builderId = 0;
        state.campaignId = 0;
        state.mediaUrl = '';
        state.variables = {};
        state.attributes = [];
        state.audience = { total: 0, phone_count: 0, email_count: 0, contact_ids: [] };

        $('#cwChannel').val(channel);
        $('#cwCampaignId').val('');
        $('#cwName').val('');
        $('#cwNameCount').text('0');
        $('#cwLabel').val('');
        $('#cwAttrRows').empty();
        $('#cwMediaUrl').val('');
        $('#cwMediaFile').val('');
        $('#cwMediaStatus').addClass('d-none').text('');
        $('#cwEmailSubject').val('');
        $('#cwEmailHtml').val('');
        $('#cwTemplateSearch').val('');
        $('#cwScheduledAt').val('');
        $('#cwAudienceCounts').text('Phone Numbers fetched: 0 | Emails fetched: 0');

        loadWizardData(true).done(function () {
            setStep(1);
            showModal();
        }).fail(function () {
            toast('Could not load campaign wizard data.', 'error');
        });
    }

    // ---- Legacy edit-form helpers (campaigns/form.php) ----
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
                '<div class="col-md-4"><label class="mb-0 fw-semibold">{{' + esc(label) + '}}</label></div>' +
                '<div class="col-md-8">' +
                '<select class="form-select form-select-sm var-source" name="variables[' + esc(key) + ']" data-var="' + esc(key) + '">' +
                '<option value="name">Contact Name</option>' +
                '<option value="mobile">Mobile</option>' +
                '<option value="email">Email</option>' +
                '<option value="custom">Custom value…</option>' +
                '</select>' +
                '<input type="text" class="form-control form-control-sm mt-1 var-custom d-none" name="variables_custom[' + esc(key) + ']" placeholder="Custom value" data-var="' + esc(key) + '">' +
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
            $('#templatePreviewBody').text(tpl.body || '');
            $('#templatePreviewCard').removeClass('d-none');
        }).fail(function () {
            toast('Could not load template', 'error');
        });
    };

    Campaigns.openWizard = openWizard;

    $(function () {
        $(document).on('click', '.js-open-campaign-wizard', function (e) {
            e.preventDefault();
            openWizard($(this).data('channel') || 'whatsapp');
        });

        var open = String($('#campaignHub').data('open-channel') || '');
        if (open === 'whatsapp' || open === 'email') {
            openWizard(open);
        }

        $('#cwName').on('input', function () {
            $('#cwNameCount').text(String($(this).val() || '').length);
            $(this).removeClass('is-invalid');
            clearWizardErrors();
        });
        $('#cwLabel, #cwEmailSubject, #cwMediaUrl, #cwScheduledAt').on('change input', function () {
            $(this).removeClass('is-invalid');
        });

        $('#cwBackBtn').on('click', function (e) {
            e.preventDefault();
            goBack();
        });
        $('#cwNextBtn').on('click', function (e) {
            e.preventDefault();
            goNext();
        });
        $('#cwRunBtn').on('click', function (e) {
            e.preventDefault();
            runCampaign();
        });
        $('#cwScheduleBtn').on('click', function (e) {
            e.preventDefault();
            scheduleCampaign();
        });

        $('#cwCreateLabelBtn').on('click', function () {
            $('#cwNewLabelWrap').toggleClass('d-none');
        });
        $('#cwSaveLabelBtn').on('click', function () {
            var name = String($('#cwNewLabelName').val() || '').trim();
            if (!name) {
                toast('Enter a label name.', 'error');
                return;
            }
            APP.post(base() + '/campaigns/labels', { name: name }).done(function (res) {
                var label = (res && res.data) ? res.data : {};
                toast(res.message || 'Label created.');
                loadWizardData(true).done(function () {
                    $('#cwLabel').val(String(label.id || ''));
                    $('#cwNewLabelWrap').addClass('d-none');
                    $('#cwNewLabelName').val('');
                });
            }).fail(function (xhr) {
                toast((xhr.responseJSON && xhr.responseJSON.message) || 'Could not create label.', 'error');
            });
        });

        $('#cwAddAttrBtn').on('click', function () {
            addAttrRow();
            $('#cwAttrRows .cw-attr-row').last().find('.cw-attr-name').trigger('focus');
        });
        $(document).on('click', '.cw-attr-remove', function () {
            $(this).closest('.cw-attr-row').remove();
        });
        $('#cwVerifyAttrBtn').on('click', function () {
            clearWizardErrors();
            var incomplete = false;
            $('#cwAttrRows .cw-attr-row').each(function () {
                var attrName = String($(this).find('.cw-attr-name').val() || '').trim();
                var value = String($(this).find('.cw-attr-value').val() || '').trim();
                if (attrName && !value) {
                    $(this).find('.cw-attr-value').addClass('is-invalid');
                    incomplete = true;
                }
            });
            if (incomplete) {
                showWizardError('Enter a value for each selected attribute before verifying.');
                return;
            }
            var $btn = $(this).prop('disabled', true);
            previewAudience().done(function () {
                toast('Audience verified: ' + state.audience.total + ' contacts.', 'success');
            }).always(function () {
                $btn.prop('disabled', false);
            });
        });

        $('#cwTemplateSearch').on('input', function () {
            renderTemplateCards($(this).val());
        });
        $(document).on('click', '.cw-use-template, .cw-template-card', function (e) {
            if ($(e.target).closest('button').length || $(this).hasClass('cw-use-template') || $(this).hasClass('cw-template-card')) {
                var $card = $(this).closest('.cw-template-card');
                if (!$card.length && $(this).hasClass('cw-template-card')) {
                    $card = $(this);
                }
                if ($card.length) {
                    e.preventDefault();
                    selectTemplate($card.data('id'));
                }
            }
        });

        $('#cwSyncTemplatesBtn').on('click', function () {
            var $btn = $(this).prop('disabled', true).text('Syncing…');
            APP.post(base() + '/templates/sync', {}).done(function (res) {
                toast(res.message || 'Templates synced.');
                loadWizardData(true).done(function () {
                    renderTemplateCards($('#cwTemplateSearch').val() || '');
                });
            }).fail(function (xhr) {
                toast((xhr.responseJSON && xhr.responseJSON.message) || 'Sync failed.', 'error');
            }).always(function () {
                $btn.prop('disabled', false).text('SYNC');
            });
        });

        $('#cwEmailBuilder').on('change', function () {
            var $opt = $(this).find('option:selected');
            if ($opt.val()) {
                if ($opt.data('subject')) $('#cwEmailSubject').val($opt.data('subject'));
                if ($opt.data('html')) $('#cwEmailHtml').val($opt.data('html'));
            }
        });

        $('#cwMediaUrl').on('input', function () {
            $('#cwMediaUrlError').addClass('d-none');
            $('#cwMediaUrl').removeClass('is-invalid');
            var v = String($(this).val() || '').trim();
            if (!v) {
                // Cleared → fall back to approved sample; drop stale override ids.
                state.mediaUrl = '';
                state.mediaId = '';
                state.mediaMime = '';
                $('#cwMediaFile').val('');
                $('#cwMediaStatus').addClass('d-none').text('');
            } else {
                state.mediaUrl = v;
            }
            updateWaPreview();
        });

        function uploadCampaignMediaFile(file) {
            if (!file) return;
            var allowed = ['image/png', 'image/jpeg', 'image/jpg', 'image/webp', 'video/mp4', 'application/pdf'];
            var mime = String(file.type || '').toLowerCase();
            if (mime && allowed.indexOf(mime) === -1) {
                toast('Unsupported file type. Use PNG, JPEG, WEBP, MP4, or PDF.', 'error');
                return;
            }
            if (file.size > 16 * 1024 * 1024) {
                toast('File exceeds 16MB limit.', 'error');
                return;
            }

            var $status = $('#cwMediaStatus').removeClass('d-none text-danger text-success').addClass('text-muted').text('Uploading ' + file.name + '…');
            var fd = new FormData();
            fd.append('file', file);
            $.ajax({
                url: base() + '/templates/header-media',
                method: 'POST',
                data: fd,
                processData: false,
                contentType: false,
                headers: (function () {
                    var h = {};
                    if (window.APP) {
                        h[APP.csrfHeader || 'X-CSRF-TOKEN'] = APP.csrfToken || $('meta[name="csrf-token"]').attr('content') || '';
                    }
                    return h;
                })()
            }).done(function (res) {
                if (!res || res.success === false) {
                    var err = (res && res.message) ? res.message : 'Upload failed';
                    $status.removeClass('text-muted').addClass('text-danger').text(err);
                    toast(err, 'error');
                    return;
                }
                var data = (res && res.data) ? res.data : res;
                var url = String(data.preview_url || data.url || data.source || '').trim();
                if (!url || url.indexOf('must be of type') !== -1 || url.indexOf('Argument #') !== -1) {
                    $status.removeClass('text-muted').addClass('text-danger').text('Upload did not return a media URL.');
                    toast('Upload did not return a media URL.', 'error');
                    return;
                }
                state.mediaUrl = url;
                state.mediaId = String(data.wa_media_id || data.media_id || '').trim();
                state.mediaMime = String(data.mime_type || file.type || '').trim();
                $('#cwMediaUrl').val(url);
                $('#cwMediaUrlError').addClass('d-none');
                $('#cwMediaUrl').removeClass('is-invalid');
                $status.removeClass('text-muted').addClass('text-success').text('Uploaded: ' + (data.filename || file.name));
                updateWaPreview();
                toast('Media uploaded.', 'success');
            }).fail(function (xhr) {
                var err = (xhr.responseJSON && xhr.responseJSON.message) || 'Upload failed';
                $status.removeClass('text-muted').addClass('text-danger').text(err);
                toast(err, 'error');
            });
        }

        $('#cwChooseFileBtn').on('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            $('#cwMediaFile').trigger('click');
        });

        $('#cwUploadBox').on('click', function (e) {
            if ($(e.target).closest('#cwMediaUrl, #cwChooseFileBtn, #cwMediaFile, .btn').length) {
                return;
            }
            $('#cwMediaFile').trigger('click');
        });

        $('#cwMediaFile').on('change', function () {
            var file = this.files && this.files[0] ? this.files[0] : null;
            uploadCampaignMediaFile(file);
        });

        // Drag & drop onto upload box
        $('#cwUploadBox').on('dragenter dragover', function (e) {
            e.preventDefault();
            e.stopPropagation();
            $(this).addClass('is-dragover');
        }).on('dragleave dragend', function (e) {
            e.preventDefault();
            e.stopPropagation();
            $(this).removeClass('is-dragover');
        }).on('drop', function (e) {
            e.preventDefault();
            e.stopPropagation();
            $(this).removeClass('is-dragover');
            var dt = e.originalEvent && e.originalEvent.dataTransfer;
            var file = dt && dt.files && dt.files[0] ? dt.files[0] : null;
            if (!file) {
                toast('No file dropped.', 'error');
                return;
            }
            uploadCampaignMediaFile(file);
        });

        // Prevent browser from opening the file when dropped outside the box but inside the modal
        $('#campaignWizardModal').on('dragover drop', function (e) {
            e.preventDefault();
        });

        $(document).on('change', '.cw-var-source', function () {
            var key = $(this).data('var');
            var $custom = $('#cwVariableMap .cw-var-custom[data-var="' + key + '"]');
            $custom.toggleClass('d-none', $(this).val() !== 'custom');
        });

        $('#campaignRefreshBtn').on('click', function () {
            window.location.reload();
        });

        // Legacy form page
        if ($('#campaignForm').length && $('#variableMap').length) {
            $('#templateId').on('change', function () {
                Campaigns.loadTemplateVars($(this).val());
            });
            if ($('#templateId').val()) {
                Campaigns.loadTemplateVars($('#templateId').val());
            }
            $(document).on('change', '.var-source', function () {
                var key = $(this).data('var');
                var $custom = $('#variableMap .var-custom[data-var="' + key + '"]');
                $custom.toggleClass('d-none', $(this).val() !== 'custom').prop('required', $(this).val() === 'custom');
            });
            $('#scheduleToggle').on('change', function () {
                $('#scheduleFields').toggleClass('d-none', !this.checked);
            });
            $('#audienceAll').on('change', function () {
                $('#contactIds, #tagIds').prop('disabled', this.checked);
            });
        }
    });

    window.CampaignsApp = Campaigns;
})(window, jQuery);
