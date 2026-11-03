(function ($) {
    'use strict';

    var base = (APP.baseUrl || '') + '/ads';

    function errorMessage(xhr, fallback) {
        return (xhr && xhr.responseJSON && xhr.responseJSON.message) || fallback;
    }

    function money(minor) {
        return 'INR ' + (Number(minor || 0) / 100).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function splitList(text) {
        return String(text || '').split(',').map(function (s) { return $.trim(s); }).filter(function (s) { return s !== ''; });
    }

    /* ---------- Campaign list: launch / pause / end ---------- */

    var $list = $('#adsListPage');
    if ($list.length) {
        $list.on('click', '.js-ad-launch', function () {
            // The review and launch step lives in the wizard summary; reuse the same confirm here.
            launchCampaign($(this).data('id'), $(this).data('name'), function () { window.location.reload(); });
        });
        $list.on('click', '.js-ad-pause', function () { stopCampaign($(this).data('id'), $(this).data('name'), 'pause', 'Pause campaign?'); });
        $list.on('click', '.js-ad-end', function () { stopCampaign($(this).data('id'), $(this).data('name'), 'end', 'End campaign? It cannot be started again.'); });
    }

    function stopCampaign(id, name, action, title) {
        Swal.fire({ title: title, text: name, icon: 'question', showCancelButton: true, confirmButtonText: 'Yes' }).then(function (r) {
            if (!r.isConfirmed) { return; }
            APP.post(base + '/' + id + '/' + action, {})
                .done(function (res) {
                    APP.toast((res && res.message) || 'Done', 'success');
                    window.setTimeout(function () { window.location.reload(); }, 600);
                })
                .fail(function (xhr) { APP.toast(errorMessage(xhr, 'Could not do that'), 'error'); });
        });
    }

    /** Shows the summary, asks for an explicit confirm, then launches. */
    function launchCampaign(id, name, done) {
        $.getJSON(base + '/' + id + '/summary').done(function (res) {
            var s = (res && res.data) || {};
            if (s.problems && s.problems.length) {
                Swal.fire({ title: 'Not ready to launch', html: $('<ul class="text-start small mb-0">').append(s.problems.map(function (p) { return $('<li>').text(p); })).prop('outerHTML'), icon: 'warning' });
                return;
            }
            var html = $('<div class="text-start small">')
                .append($('<div>').text('Daily budget: ' + money(s.daily_budget)))
                .append($('<div>').text(s.max_total_minor ? 'Most you can spend in total: ' + money(s.max_total_minor) + ' (Meta may spend up to 25% more on a single day)' : 'No end date: it keeps spending the daily budget until you pause it.'))
                .append($('<div>').text(s.mode === 'live' ? 'This creates a real ad. Meta charges your ad account.' : 'Test mode: nothing is sent to Meta.'));
            Swal.fire({ title: 'Launch "' + name + '"?', html: html.prop('outerHTML'), icon: 'question', showCancelButton: true, confirmButtonText: 'Launch' }).then(function (r) {
                if (!r.isConfirmed) { return; }
                APP.post(base + '/' + id + '/launch', {})
                    .done(function (out) {
                        APP.toast((out && out.message) || 'Launched', 'success');
                        window.setTimeout(done, 600);
                    })
                    .fail(function (xhr) { APP.toast(errorMessage(xhr, 'Could not launch'), 'error'); });
            });
        }).fail(function (xhr) { APP.toast(errorMessage(xhr, 'Could not load the campaign'), 'error'); });
    }

    /* ---------- Settings ---------- */

    var $settings = $('#adsSettingsForm');
    if ($settings.length) {
        $settings.on('submit', function (e) {
            e.preventDefault();
            var $btn = $settings.find('[type="submit"]').prop('disabled', true);
            $settings.find('.is-invalid').removeClass('is-invalid');
            APP.post(base + '/settings', $settings.serialize())
                .done(function (res) {
                    APP.toast((res && res.message) || 'Saved', 'success');
                    $settings.find('[name="token"]').val('');
                })
                .fail(function (xhr) {
                    var errs = (xhr.responseJSON && xhr.responseJSON.errors) || {};
                    $.each(errs, function (field, msg) {
                        $settings.find('[name="' + field + '"]').addClass('is-invalid').siblings('.invalid-feedback').text(msg);
                    });
                    APP.toast(errorMessage(xhr, 'Could not save'), 'error');
                })
                .always(function () { $btn.prop('disabled', false); });
        });
    }

    /* ---------- Wizard ---------- */

    var $w = $('#adsWizard');
    if (!$w.length) { return; }

    var state = { page: null, defaults: null, variants: [], selected: 0, special: null, notes: '', draftId: null };

    function showStep(n) {
        $w.find('[data-panel]').addClass('d-none');
        $w.find('[data-panel="' + n + '"]').removeClass('d-none');
        $('#adsSteps li').each(function () {
            var step = Number($(this).data('step'));
            $(this).toggleClass('text-muted', step > n);
            $(this).find('.badge').toggleClass('bg-primary', step <= n).toggleClass('bg-secondary', step > n);
        });
        window.scrollTo(0, 0);
    }

    function busy($btn, on, label) {
        if (on) { $btn.data('label', $btn.html()).prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span>' + label); }
        else { $btn.prop('disabled', false).html($btn.data('label')); }
    }

    // Step 1: read the website, pre-fill the rest.
    $('#adBtnBrief').on('click', function () {
        var $btn = $(this), url = $.trim($('#adUrl').val());
        if (!url) { APP.toast('Enter your website link first.', 'error'); return; }
        busy($btn, true, 'Reading...');
        APP.post(base + '/brief', { url: url })
            .done(function (res) {
                var d = res.data;
                state.page = d.page;
                state.defaults = d.defaults;
                $('#adName').val(d.defaults.name);
                $('#adBudget').val(d.defaults.daily_budget_minor ? d.defaults.daily_budget_minor / 100 : '');
                $('#adBudgetNote').text(d.defaults.cap_minor ? 'Your limit is ' + money(d.defaults.cap_minor) + ' a day.' : 'Set your daily limit in Ad settings before launching.');
                if (d.page.lang) {
                    var map = { hi: 'Hindi', mr: 'Marathi' };
                    $('#adLanguage').val(map[d.page.lang.substr(0, 2)] || 'English');
                }
                var note = 'We read "' + (d.page.title || d.page.url) + '".';
                if (d.defaults.based_on) {
                    note += ' Your campaign "' + d.defaults.based_on.name + '" worked best' + (d.defaults.based_on.cost_per_conversation_minor ? ' (' + money(d.defaults.based_on.cost_per_conversation_minor) + ' per chat)' : '') + ', so we start from its audience and budget.';
                } else if (d.defaults.audience_source === 'contacts') {
                    note += ' We suggest your audience from where your contacts are.';
                }
                $('#adBriefNote').text(note);
                $('#adBriefFields').removeClass('d-none');
            })
            .fail(function (xhr) { APP.toast(errorMessage(xhr, 'Could not read that page'), 'error'); })
            .always(function () { busy($btn, false); });
    });

    // Step 2: generate with AI.
    $('#adBtnGenerate').on('click', function () {
        var $btn = $(this);
        if (!$.trim($('#adName').val()) || !Number($('#adBudget').val())) { APP.toast('Enter a campaign name and a daily budget.', 'error'); return; }
        busy($btn, true, 'AI is writing your ads...');
        APP.post(base + '/generate', {
            page: JSON.stringify(state.page),
            language: $('#adLanguage').val(),
            city: $('#adCity').val(),
            audience_hint: JSON.stringify(state.defaults && state.defaults.audience ? state.defaults.audience : null)
        })
            .done(function (res) {
                var d = res.data;
                state.variants = d.variants;
                state.selected = 0;
                state.special = d.special_category;
                state.notes = d.notes;
                renderVariants();
                fillAudience(d.audience);
                $('#adAudienceNote').text(d.notes || '');
                if (d.special_category) {
                    $('#adSpecialNote').removeClass('d-none').text('This looks like a special ad category (' + d.special_category.replace('_', ' ') + '). Meta limits targeting for these ads, so some audience choices may be ignored.');
                } else { $('#adSpecialNote').addClass('d-none'); }
                showStep(2);
            })
            .fail(function (xhr) { APP.toast(errorMessage(xhr, 'The AI could not write your ads. Try again.'), 'error'); })
            .always(function () { busy($btn, false); });
    });

    function renderVariants() {
        var $box = $('#adVariants').empty();
        $.each(state.variants, function (i, v) {
            var $row = $('<div class="border rounded p-2 mb-2">').toggleClass('border-primary', i === state.selected);
            var $radio = $('<div class="form-check mb-2">')
                .append($('<input class="form-check-input" type="radio" name="adVariant">').attr('id', 'adVar' + i).prop('checked', i === state.selected).val(i))
                .append($('<label class="form-check-label small fw-medium">').attr('for', 'adVar' + i).text('Option ' + (i + 1)));
            var $head = $('<input type="text" class="form-control form-control-sm mb-1 js-var-head" maxlength="40">').val(v.headline).data('i', i);
            var $text = $('<textarea class="form-control form-control-sm js-var-text" rows="2" maxlength="125"></textarea>').val(v.primary_text).data('i', i);
            $row.append($radio, $head, $text);
            if (v.needs_edit) { $row.append('<div class="small text-warning mt-1">Replace the [placeholder] before launching.</div>'); }
            $box.append($row);
        });
        updatePreview();
    }

    $('#adVariants').on('change', 'input[name="adVariant"]', function () { state.selected = Number($(this).val()); renderVariants(); });
    $('#adVariants').on('input', '.js-var-head', function () { state.variants[$(this).data('i')].headline = $(this).val(); state.selected = $(this).data('i'); updatePreview(); });
    $('#adVariants').on('input', '.js-var-text', function () { state.variants[$(this).data('i')].primary_text = $(this).val(); state.selected = $(this).data('i'); updatePreview(); });

    function updatePreview() {
        var v = state.variants[state.selected] || { headline: '', primary_text: '' };
        $('#adPrevText').text(v.primary_text);
        $('#adPrevHeadline').text(v.headline);
    }

    function fillAudience(a) {
        $('#audCountries').val((a.countries || []).join(', '));
        $('#audCities').val((a.cities || []).join(', '));
        $('#audAgeMin').val(a.age_min);
        $('#audAgeMax').val(a.age_max);
        $('#audGender').val(a.gender || 'all');
        $('#audInterests').val((a.interests || []).join(', '));
    }

    $('#adBtnBack').on('click', function () { showStep(1); });

    // Save the draft, then show the review step.
    $('#adBtnDraft').on('click', function () {
        var $btn = $(this);
        // The chosen variant goes first: the server marks the first one as the ad to run.
        var ordered = state.variants.slice();
        var chosen = ordered.splice(state.selected, 1);
        ordered = chosen.concat(ordered);
        var plan = {
            variants: ordered,
            special_category: state.special,
            audience: {
                countries: splitList($('#audCountries').val()),
                cities: splitList($('#audCities').val()),
                age_min: Number($('#audAgeMin').val()) || 18,
                age_max: Number($('#audAgeMax').val()) || 65,
                gender: $('#audGender').val(),
                interests: splitList($('#audInterests').val())
            }
        };
        busy($btn, true, 'Saving...');
        APP.post(base + '/draft', {
            name: $('#adName').val(),
            url: state.page ? state.page.url : '',
            daily_budget: $('#adBudget').val(),
            starts_on: $('#adStart').val(),
            ends_on: $('#adEnd').val(),
            language: $('#adLanguage').val(),
            city: $('#adCity').val(),
            page: JSON.stringify(state.page),
            plan: JSON.stringify(plan)
        })
            .done(function (res) {
                state.draftId = res.data.id;
                loadSummary();
            })
            .fail(function (xhr) { APP.toast(errorMessage(xhr, 'Could not save the draft'), 'error'); })
            .always(function () { busy($btn, false); });
    });

    function loadSummary() {
        $.getJSON(base + '/' + state.draftId + '/summary').done(function (res) {
            var s = res.data, c = s.campaign, cr = s.creative || {};
            var aud = {};
            try { aud = JSON.parse(c.audience || '{}'); } catch (e) { aud = {}; }
            var rows = [
                ['Campaign', c.name],
                ['Ad', (cr.headline || '') + ' - ' + (cr.primary_text || '')],
                ['Where', (aud.cities && aud.cities.length ? aud.cities.join(', ') + ' (' : '') + (aud.countries || []).join(', ') + (aud.cities && aud.cities.length ? ')' : '')],
                ['Who', 'Age ' + aud.age_min + '-' + aud.age_max + ', ' + (aud.gender === 'all' ? 'everyone' : aud.gender === 'male' ? 'men' : 'women')],
                ['Daily budget', money(s.daily_budget)],
                ['Most you can spend', s.max_total_minor ? money(s.max_total_minor) : 'No end date set. It keeps spending daily until you pause it.'],
                ['Mode', s.mode === 'live' ? 'Live: creates a real ad, charged by Meta' : 'Test: nothing is sent to Meta']
            ];
            var $dl = $('#adSummary').empty();
            $.each(rows, function (_, r) {
                $dl.append($('<dt class="col-sm-3">').text(r[0]), $('<dd class="col-sm-9">').text(r[1]));
            });
            var $p = $('#adProblems');
            if (s.problems && s.problems.length) {
                $p.removeClass('d-none').empty().append($('<ul class="mb-0">').append(s.problems.map(function (m) { return $('<li>').text(m); })));
                $('#adBtnLaunch').prop('disabled', true);
            } else {
                $p.addClass('d-none');
                $('#adBtnLaunch').prop('disabled', false);
            }
            showStep(3);
        }).fail(function (xhr) { APP.toast(errorMessage(xhr, 'Could not load the review'), 'error'); });
    }

    $('#adBtnLaunch').on('click', function () {
        launchCampaign(state.draftId, $('#adName').val(), function () { window.location.href = base; });
    });
})(jQuery);
