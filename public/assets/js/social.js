/**
 * Social scheduler pages: calendar, media library (Google Drive) and accounts.
 * CSRF headers are added by the global $.ajaxSetup in app.js.
 */
(function ($) {
    'use strict';

    function msg(xhr, fallback) {
        return (xhr && xhr.responseJSON && xhr.responseJSON.message) || fallback;
    }

    function post(url, data, $btn, done) {
        // Do not pass beforeSend/complete here: they would replace the global handlers in app.js that
        // add the CSRF header and refresh the token. Use promise callbacks instead.
        if ($btn) { $btn.prop('disabled', true); }
        $.ajax({
            url: APP.baseUrl + url,
            method: 'POST',
            data: data || {},
            dataType: 'json'
        }).done(function (res) {
            APP.toast(res.message || 'Done', 'success');
            if (done) { done(res); }
        }).fail(function (xhr) {
            APP.toast(msg(xhr, 'Something went wrong. Please try again.'), 'error');
        }).always(function () {
            if ($btn) { $btn.prop('disabled', false); }
        });
    }

    function confirmThen(text, go) {
        Swal.fire({ title: 'Are you sure?', text: text, icon: 'warning', showCancelButton: true, confirmButtonText: 'Yes' })
            .then(function (r) { if (r.isConfirmed) { go(); } });
    }

    function pad(n) { return (n < 10 ? '0' : '') + n; }
    function ymd(d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); }

    /* ---------- Accounts ---------- */
    $('#socYtTokenForm').on('submit', function (e) {
        e.preventDefault();
        var $f = $(this);
        post('/social/accounts/youtube-token', $f.serialize(), $f.find('[type=submit]'), function () {
            $f.find('[name=refresh_token]').val('');
            setTimeout(function () { location.reload(); }, 700);
        });
    });
    $('#socTokenForm').on('submit', function (e) {
        e.preventDefault();
        var $f = $(this);
        post('/social/accounts/token', $f.serialize(), $f.find('[type=submit]'), function () {
            $f.find('[name=token]').val('');
            setTimeout(function () { location.reload(); }, 700);
        });
    });

    $(document).on('click', '[data-social-disconnect]', function () {
        var $b = $(this);
        confirmThen('Disconnect ' + $b.data('name') + '?', function () {
            post('/social/accounts/' + $b.data('social-disconnect') + '/disconnect', {}, $b, function () { location.reload(); });
        });
    });

    /* ---------- Setup ---------- */
    var $setup = $('[data-social-setup]');
    if ($setup.length) {
        $('#socSetupForm').on('submit', function (e) {
            e.preventDefault();
            var $f = $(this);
            post('/social/setup', $f.serialize(), $f.find('[type=submit]'), function () {
                setTimeout(function () { location.reload(); }, 700);
            });
        });
        var showPanel = function (id) {
            $('[data-soc-panel]').each(function () { this.hidden = this.id !== id; });
            $('#socPlatform').val(id);
            try { sessionStorage.setItem('socPanel', id); } catch (err) { /* optional */ }
        };
        var panelIds = $('[data-soc-panel]').map(function () { return this.id; }).get(), saved = null;
        var startPanel = (location.hash || '').slice(1);
        try { saved = sessionStorage.getItem('socPanel'); } catch (err) { /* optional */ }
        if (panelIds.indexOf(startPanel) < 0) { startPanel = panelIds.indexOf(saved) >= 0 ? saved : 'soc-meta'; }
        showPanel(startPanel);
        $('#socPlatform').on('change', function () { showPanel(this.value); });
        $setup.on('click', '[data-soc-pick]', function (e) { e.preventDefault(); showPanel($(this).data('soc-pick')); window.scrollTo({ top: 0, behavior: 'smooth' }); });
        $setup.on('click', '[data-soc-check]', function () {
            var $b = $(this);
            post('/social/setup/check/' + $b.data('soc-check'), {}, $b);
        });
        var syncAiBoxes = function () {
            var v = $('#ai_caption_provider').val();
            $('[data-ai-box]').each(function () { $(this).toggleClass('d-none', $(this).data('ai-box') !== v); });
            $('[data-ai-note="gemini"]').toggleClass('d-none', v !== 'gemini');
        };
        $('#ai_caption_provider').on('change', syncAiBoxes);
        syncAiBoxes();
        $setup.on('click', '[data-soc-reveal]', function () {
            var $i = $(this).siblings('input'), show = $i.attr('type') === 'password';
            $i.attr('type', show ? 'text' : 'password');
            $(this).find('i').toggleClass('fa-eye', !show).toggleClass('fa-eye-slash', show);
        });
        $('#socSetupForm').on('input change', 'input, textarea', function () { $('#socSetupDirty').removeClass('d-none'); });
        $setup.on('click', '[data-soc-copy]', function () {
            var $i = $(this).siblings('input');
            $i.trigger('select');
            try { navigator.clipboard.writeText($i.val()); } catch (err) { document.execCommand('copy'); }
            APP.toast('Copied', 'success');
        });
    }

    /* ---------- Library ---------- */
    var $lib = $('[data-social-library]');
    if ($lib.length) {
        $('#socFolderForm').on('submit', function (e) {
            e.preventDefault();
            post('/social/folders', $(this).serialize(), $(this).find('[type=submit]'), function () { location.reload(); });
        });
        $lib.on('click', '[data-soc-scan]', function () {
            var $b = $(this);
            post('/social/folders/' + $b.data('soc-scan') + '/scan', {}, $b, function () { setTimeout(function () { location.reload(); }, 800); });
        });
        $lib.on('click', '[data-soc-remove]', function () {
            var $b = $(this);
            confirmThen('Remove this folder? Files already scheduled stay.', function () {
                post('/social/folders/' + $b.data('soc-remove') + '/delete', {}, $b, function () { location.reload(); });
            });
        });

        var selected = function () {
            return $('.soc-asset:checked').map(function () { return this.value; }).get();
        };
        var refresh = function () {
            var n = selected().length;
            $('#socSelCount, #socModalCount').text(n);
            $('#socOpenSchedule').prop('disabled', n === 0);
            // Keep the grid's checkboxes (and highlight) in step with the list's.
            $('.soc-asset').each(function () {
                $('.soc-asset-g[value="' + this.value + '"]').prop('checked', this.checked).closest('.soc-card').toggleClass('is-selected', this.checked);
            });
        };
        $lib.on('change', '.soc-asset-g', function () {
            $('.soc-asset[value="' + this.value + '"]').prop('checked', this.checked);
            refresh();
        });
        // "Select all" (list header or grid toolbar): every file that is currently shown and not already scheduled.
        $('#socSelAll, #socSelAllG').on('change', function () {
            var on = this.checked;
            $('#socList tbody tr:not(.d-none) .soc-asset:not(:disabled)').prop('checked', on);
            $('#socSelAll, #socSelAllG').prop('checked', on);
            refresh();
        });

        /* ----- View switch (grid / list), type filter and the preview player ----- */
        var items = [];
        try { items = JSON.parse($('#socMediaData').text() || '[]'); } catch (err) { items = []; }
        var visibleIds = function () {
            var f = $('#socTypeFilter').val();
            return items.filter(function (it) { return !f || it.type === f; }).map(function (it) { return it.id; });
        };
        var setView = function (v) {
            if (v !== 'list') { v = 'grid'; }
            $('#socGrid').toggleClass('d-none', v !== 'grid');
            $('#socList').toggleClass('d-none', v !== 'list');
            $('[data-soc-view]').removeClass('active').filter('[data-soc-view="' + v + '"]').addClass('active');
            try { localStorage.setItem('socLibView', v); } catch (err) { /* private mode: not remembered */ }
        };
        var saved = 'grid';
        try { saved = localStorage.getItem('socLibView') || 'grid'; } catch (err) { saved = 'grid'; }
        setView(saved);
        $('[data-soc-view]').on('click', function () { setView($(this).data('soc-view')); });

        $('#socTypeFilter').on('change', function () {
            var f = this.value;
            $('#socGrid .soc-card, #socList tbody tr').each(function () { $(this).toggleClass('d-none', !!f && $(this).data('type') !== f); });
            $('#socMediaCount').text(visibleIds().length);
        });

        var pvModalEl = document.getElementById('socPreviewModal');
        var pvModal = pvModalEl ? new bootstrap.Modal(pvModalEl) : null;
        var pvPos = 0;
        var showPreview = function (pos) {
            var ids = visibleIds();
            if (!ids.length) { return; }
            pvPos = (pos + ids.length) % ids.length;
            var it = items.filter(function (x) { return x.id === ids[pvPos]; })[0];
            if (!it) { return; }
            var $stage = $('#socPvStage').empty();
            if (it.type === 'video') {
                $stage.append($('<video controls playsinline preload="metadata" autoplay>').attr('src', it.file));
            } else {
                $stage.append($('<img alt="">').attr('src', it.file));
            }
            $('#socPvTitle').text(it.name);
            $('#socPvDetails').text(it.details);
            $('#socPvCheck').html(it.tips.length
                ? '<span class="badge bg-warning text-dark">' + $('<i>').text(it.tips[0]).html() + '</span>'
                : '<span class="badge bg-success">' + (it.type === 'video' ? 'Reel ready' : 'OK') + '</span>');
            $('#socPvPos').text((pvPos + 1) + ' / ' + ids.length);
            $('#socPvPrev, #socPvNext').prop('disabled', ids.length < 2);
        };
        $lib.on('click', '[data-soc-preview]', function (e) {
            e.preventDefault();
            var id = parseInt($(this).data('soc-preview'), 10);
            var pos = visibleIds().indexOf(id);
            showPreview(pos < 0 ? 0 : pos);
            if (pvModal) { pvModal.show(); }
        });
        $lib.on('keydown', '.soc-thumb', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); $(this).trigger('click'); } });
        $('#socPvPrev').on('click', function () { showPreview(pvPos - 1); });
        $('#socPvNext').on('click', function () { showPreview(pvPos + 1); });
        $(pvModalEl).on('keydown', function (e) {
            if (e.key === 'ArrowLeft') { showPreview(pvPos - 1); }
            if (e.key === 'ArrowRight') { showPreview(pvPos + 1); }
        });
        // Closing must stop the video and the download behind it.
        $(pvModalEl).on('hidden.bs.modal', function () {
            var v = $('#socPvStage video')[0];
            if (v) { v.pause(); v.removeAttribute('src'); v.load(); }
            $('#socPvStage').empty();
        });
        $lib.on('change', '.soc-asset', refresh);

        var parseTimes = function () {
            return $('#socTimes').val().split(',').map(function (t) { return $.trim(t); }).filter(Boolean);
        };
        var preview = function () {
            var n = selected().length, t = parseTimes().length || 1;
            var days = Math.ceil(n / t);
            $('#socPreview').text(n ? n + ' file(s) will be posted over ' + days + ' day(s).' : '');
        };
        $('#socOpenSchedule').on('click', function () {
            var tomorrow = new Date(); tomorrow.setDate(tomorrow.getDate() + 1);
            if (!$('#socStart').val()) { $('#socStart').val(ymd(tomorrow)); }
            $('#socModalCount').text(selected().length);
            preview();
            bootstrap.Modal.getOrCreateInstance(document.getElementById('socScheduleModal')).show();
        });
        $('#socTimes').on('input', preview);
        $('#socScheduleForm').on('change', 'input[name="accounts[]"]', function () {
            $('#socWaBox').toggleClass('d-none', $('input[name="accounts[]"][data-platform="whatsapp"]:checked').length === 0);
        });
        $('#socWaAll').on('change', function () { $('#socWaTags').prop('disabled', this.checked); });

        $('#socAiGo').on('click', function () {
            var $b = $(this), $list = $('#socAiOptions');
            post('/social/ai/captions', { topic: $('#socAiTopic').val(), platform: $('#socAiPlatform').val(), tone: $('#socAiTone').val(), language: $('#socAiLang').val() }, $b, function (res) {
                $list.empty();
                ((res.data && res.data.options) || []).forEach(function (o) {
                    var $item = $('<button type="button" class="list-group-item list-group-item-action small text-start">');
                    $item.append($('<strong class="d-block">').text(o.hook || 'Option'))
                        .append($('<span class="d-block">').text(o.caption))
                        .append($('<span class="text-muted d-block">').text(o.hashtags))
                        .data('option', o);
                    $list.append($item);
                });
            });
        });
        $('#socAiOptions').on('click', '.list-group-item', function () {
            var o = $(this).data('option');
            $('#socCaption').val(o.caption);
            $('#socTags').val(o.hashtags);
            APP.toast('Caption applied. You can edit it.', 'success');
        });

        /* ----- AI: reel / video script and caption rewrite ----- */
        $('#socScGo').on('click', function () {
            var $b = $(this), $out = $('#socScResult');
            post('/social/ai/script', {
                topic: $('#socAiTopic').val(), platform: $('#socScPlatform').val(), seconds: $('#socScSeconds').val(),
                tone: $('#socAiTone').val(), language: $('#socAiLang').val()
            }, $b, function (res) {
                var s = res.data && res.data.script;
                if (!s) { return; }
                $out.empty().removeClass('d-none');
                $out.append($('<div class="fw-semibold small">').text(s.title));
                $out.append($('<div class="small mb-1">').append($('<strong>').text('Hook: ')).append(document.createTextNode(s.hook)));
                var $ol = $('<ol class="small ps-3 mb-1">');
                (s.scenes || []).forEach(function (sc) {
                    var $li = $('<li>').append($('<strong>').text(sc.seconds + 's: ')).append(document.createTextNode(sc.visual));
                    if (sc.voiceover) { $li.append($('<div class="text-muted">').text('Say: ' + sc.voiceover)); }
                    if (sc.on_screen_text) { $li.append($('<div class="text-muted">').text('On screen: ' + sc.on_screen_text)); }
                    $ol.append($li);
                });
                $out.append($ol);
                if (s.cta) { $out.append($('<div class="small mb-2">').append($('<strong>').text('Call to action: ')).append(document.createTextNode(s.cta))); }
                $out.append($('<button type="button" class="btn btn-outline-secondary btn-sm">').text('Use this caption and hashtags').on('click', function () {
                    $('#socCaption').val(s.caption);
                    $('#socTags').val(s.hashtags);
                    APP.toast('Caption applied. You can edit it.', 'success');
                }));
            });
        });
        $('#socRwGo').on('click', function () {
            var $b = $(this), text = $.trim($('#socCaption').val());
            if (!text) { APP.toast('Write or generate a caption first.', 'error'); return; }
            post('/social/ai/rewrite', {
                text: text, action: $('#socRwAction').val(), platform: (aiPlatforms()[0] || 'instagram'),
                tone: $('#socAiTone').val(), language: $('#socAiLang').val()
            }, $b, function (res) {
                if (res.data && res.data.text) { $('#socCaption').val(res.data.text); }
            });
        });

        /* ----- AI: a title, hashtags and one caption per file and platform, with a live preview ----- */
        var aiCaptions = {};      // aiCaptions[assetId] = { title, about, warnings, pf: { caption, hashtags } }
        var aiBusy = false;
        var aiAutoTried = false;   // the automatic write on Schedule is tried once, so a failure never traps the form
        var aiLimits = { instagram: 2200, facebook: 5000, youtube: 5000, linkedin: 3000 };
        var aiPlatforms = function () {
            var seen = {};
            $('#socScheduleForm input[name="accounts[]"]:checked').each(function () {
                var p = $(this).data('platform');
                if (p && p !== 'whatsapp') { seen[p] = true; }
            });
            return Object.keys(seen);
        };
        var assetInfo = function (id) {
            return items.filter(function (x) { return x.id === parseInt(id, 10); })[0] || { name: 'File ' + id, thumb: '' };
        };
        var accountName = function (pf) {
            var $c = $('#socScheduleForm input[name="accounts[]"][data-platform="' + pf + '"]:checked').first();
            return $.trim($c.closest('.form-check').find('label').clone().children().remove().end().text()) || pf;
        };
        var composeText = function (c) {
            var t = $.trim(c.caption || ''), h = $.trim(c.hashtags || '');
            return h ? (t ? t + '\n\n' + h : h) : t;
        };
        var tagHtml = function (text) {
            return $('<div>').text(text).html().replace(/(#[\p{L}\p{N}_]+)/gu, '<span class="soc-tag">$1</span>');
        };
        var fillPreview = function ($pf) {
            var id = $pf.attr('data-asset'), pf = $pf.attr('data-platform'), c = (aiCaptions[id] || {})[pf] || {};
            var text = composeText(c);
            $pf.find('.soc-pvc-b').html(tagHtml(text));
            var $cnt = $pf.find('.soc-count').text(text.length + ' / ' + (aiLimits[pf] || 2200));
            $cnt.toggleClass('is-over', text.length > (aiLimits[pf] || 2200));
        };
        var platformBlock = function (id, pf, info) {
            var c = (aiCaptions[id] || {})[pf];
            var name = accountName(pf);
            var $pf = $('<div class="row g-2 soc-ai-pf">').attr('data-asset', id).attr('data-platform', pf);
            var $left = $('<div class="col-12 col-lg-7">');
            $left.append($('<label class="small fw-semibold d-block mb-1">').html('<i class="fab fa-' + pf + ' me-1"></i>').append(document.createTextNode(pf.charAt(0).toUpperCase() + pf.slice(1) + ' caption')))
                .append($('<textarea class="form-control form-control-sm mb-1" rows="4" maxlength="2200" data-field="caption">').val(c.caption))
                .append($('<label class="small text-muted d-block mb-1">').text('Hashtags (made by AI, you can edit)'))
                .append($('<input class="form-control form-control-sm" maxlength="300" data-field="hashtags">').val(c.hashtags))
                .append($('<div class="soc-count">'));
            var $card = $('<div class="soc-pvc">')
                .append($('<div class="soc-pvc-h">')
                    .append($('<span class="soc-pvc-av">').text(name.charAt(0).toUpperCase()))
                    .append($('<div>').append($('<strong class="d-block">').text(name)).append($('<span class="text-muted">').text('Preview, as it will be posted'))));
            if (pf === 'youtube') { $card.append($('<div class="soc-pvc-t">').text((aiCaptions[id] || {}).title || info.name)); }
            $card.append($('<img alt="">').attr('src', info.thumb).on('error', function () { $(this).remove(); }))
                .append($('<div class="soc-pvc-b">'));
            $pf.append($left).append($('<div class="col-12 col-lg-5">').append($card));
            fillPreview($pf);

            return $pf;
        };
        var drawAiFile = function (id, platforms) {
            var info = assetInfo(id), data = aiCaptions[id] || {};
            var $file = $('<div class="soc-ai-file">').attr('data-asset', id);
            var $head = $('<div class="d-flex align-items-start gap-2">')
                .append($('<img class="soc-ai-thumb" alt="">').attr('src', info.thumb).on('error', function () { $(this).css('visibility', 'hidden'); }));
            var $meta = $('<div class="flex-grow-1 min-w-0">')
                .append($('<div class="d-flex align-items-center gap-2">')
                    .append($('<strong class="small text-truncate me-auto">').text(info.name))
                    .append($('<button type="button" class="btn btn-outline-secondary btn-sm py-0" data-ai-redo title="Write again"><i class="fas fa-rotate"></i></button>').attr('data-asset', id)));
            if (data.error) {
                $meta.append($('<div class="small text-danger">').text(data.error));
            } else {
                $meta.append($('<input class="form-control form-control-sm mt-1" maxlength="100" data-field="title" placeholder="Post title">').val(data.title || ''));
                if (data.about) { $meta.append($('<div class="soc-ai-about mt-1">').text('AI sees: ' + data.about)); }
                (data.warnings || []).forEach(function (w) { $meta.append($('<span class="badge bg-warning text-dark me-1 mt-1">').text(w)); });
            }
            $file.append($head.append($meta));
            if (!data.error) { platforms.forEach(function (pf) { if (data[pf]) { $file.append(platformBlock(id, pf, info)); } }); }

            return $file;
        };
        var drawAiReview = function (platforms) {
            var $box = $('#socAiReview').empty();
            Object.keys(aiCaptions).forEach(function (id) { $box.append(drawAiFile(id, platforms)); });
            $box.toggleClass('d-none', !Object.keys(aiCaptions).length);
        };
        $('#socAiReview').on('input', '[data-field]', function () {
            var $f = $(this), $pf = $f.closest('.soc-ai-pf'), id = $f.closest('.soc-ai-file').attr('data-asset'), field = $f.attr('data-field');
            if (field === 'title') { aiCaptions[id].title = this.value; return; }
            var pf = $pf.attr('data-platform');
            aiCaptions[id][pf][field] = this.value;
            fillPreview($pf);
        });
        var askAi = function (id, platforms) {
            return $.ajax({
                url: APP.baseUrl + '/social/ai/file-captions', method: 'POST', dataType: 'json',
                data: { asset_id: id, platforms: platforms, tone: $('#socAiTone').val(), language: $('#socAiLang').val(), topic: $('#socAiTopic').val() }
            }).done(function (res) {
                var d = res.data || {}, caps = d.captions || {};
                aiCaptions[id] = { title: d.title || '', about: d.about || '', warnings: d.warnings || [] };
                Object.keys(caps).forEach(function (pf) { aiCaptions[id][pf] = { caption: caps[pf].caption, hashtags: caps[pf].hashtags }; });
            }).fail(function (xhr) {
                aiCaptions[id] = { error: msg(xhr, 'The AI could not write this one.') };
            });
        };
        // Returns a promise that resolves when every selected file has been tried.
        var writeAllCaptions = function () {
            var dfd = $.Deferred();
            var ids = selected(), platforms = aiPlatforms();
            if (!ids.length) { APP.toast('Select files first.', 'error'); return dfd.reject(); }
            if (!platforms.length) { APP.toast('Tick at least one Facebook, Instagram, YouTube or LinkedIn account above first.', 'error'); return dfd.reject(); }
            if (aiBusy) { return dfd.reject(); }
            aiBusy = true;
            aiCaptions = {};
            var i = 0, $b = $('#socAiAll').prop('disabled', true), $p = $('#socAiProgress');
            var next = function () {
                if (i >= ids.length) {
                    aiBusy = false; $b.prop('disabled', false);
                    var ok = ids.filter(function (id) { return !aiCaptions[id].error; }).length;
                    $p.text(ok + ' of ' + ids.length + ' file(s) done. Check each one below, then press Schedule.');
                    drawAiReview(platforms);
                    if (!ok) { APP.toast('The AI could not write captions. ' + (aiCaptions[ids[0]].error || ''), 'error'); return dfd.reject(); }
                    return dfd.resolve();
                }
                $p.text('Looking at file ' + (i + 1) + ' of ' + ids.length + ' and writing...');
                askAi(ids[i], platforms).always(function () { i++; next(); });
            };
            next();

            return dfd.promise();
        };
        $('#socAiReview').on('click', '[data-ai-redo]', function () {
            var $b = $(this), id = $b.attr('data-asset'), platforms = aiPlatforms();
            if (!platforms.length || aiBusy) { return; }
            $b.prop('disabled', true);
            askAi(id, platforms).always(function () { drawAiReview(platforms); });
        });
        $('#socAiAll').on('click', function () { writeAllCaptions(); });
        $('#socOpenSchedule').on('click', function () { aiCaptions = {}; aiAutoTried = false; $('#socAiReview').addClass('d-none').empty(); });

        $('#socScheduleForm').on('submit', function (e) {
            e.preventDefault();
            var $f = $(this);
            // No caption at all: let the AI write them first, and show them for review instead of posting unseen text.
            if (String($f.data('ai-ready')) === '1' && !aiAutoTried && !$.trim($('#socCaption').val()) && !Object.keys(aiCaptions).length && aiPlatforms().length) {
                aiAutoTried = true;
                writeAllCaptions().done(function () { APP.toast('Captions written by AI. Review them, then press Schedule.', 'success'); });
                return;
            }
            var waOn = $f.find('input[name="accounts[]"][data-platform="whatsapp"]:checked').length > 0;
            var data = { start_date: $('#socStart').val(), caption: $('#socCaption').val(), hashtags: $('#socTags').val(), assets: selected(), times: parseTimes(), accounts: [], captions: {} };
            Object.keys(aiCaptions).forEach(function (id) {
                var c = aiCaptions[id];
                if (c.error) { return; }
                Object.keys(aiLimits).forEach(function (pf) {
                    if (c[pf] && $.trim(composeText(c[pf]))) { (data.captions[id] = data.captions[id] || {})[pf] = composeText(c[pf]); }
                });
                if (data.captions[id] && $.trim(c.title || '')) { data.captions[id]._title = $.trim(c.title); }
            });
            $f.find('input[name="accounts[]"]:checked').each(function () { data.accounts.push(this.value); });
            if (!data.accounts.length) { APP.toast('Select at least one account.', 'error'); return; }
            if (waOn) {
                data.wa_template_id = $('#socWaTemplate').val();
                data.wa_tag_ids = $('#socWaTags').val() || [];
                data.wa_all = $('#socWaAll').is(':checked') ? 1 : 0;
                data.wa_caption_var = $('#socWaCaption').is(':checked') ? 1 : 0;
                if (!data.wa_template_id) { APP.toast('Choose a WhatsApp template.', 'error'); return; }
                if (!data.wa_all && !data.wa_tag_ids.length) { APP.toast('Choose who receives the WhatsApp broadcast.', 'error'); return; }
            }
            post('/social/schedule', data, $f.find('[type=submit]'), function () { window.location.href = APP.baseUrl + '/social'; });
        });
    }

    /* ---------- Calendar ---------- */
    var $root = $('[data-social-calendar]');
    if ($root.length) {
        var canEdit = String($root.data('can-edit')) === '1';
        var cur = new Date(); cur.setDate(1);
        var events = {};
        var active = null;
        var modal = new bootstrap.Modal(document.getElementById('socEventModal'));
        var labels = { scheduled: 'Scheduled', publishing: 'Publishing', published: 'Published', failed: 'Failed', skipped: 'Cancelled' };
        var loading = false;
        var lastStatus = {};

        // silent = background refresh: no error toasts, and a status change is announced instead.
        function render(silent) {
            if (silent && loading) { return; }
            loading = true;
            var first = new Date(cur.getFullYear(), cur.getMonth(), 1);
            var start = new Date(first); start.setDate(1 - first.getDay());
            var days = Math.ceil((first.getDay() + new Date(cur.getFullYear(), cur.getMonth() + 1, 0).getDate()) / 7) * 7;
            var end = new Date(start); end.setDate(start.getDate() + days - 1);
            $('#socCalTitle').text(cur.toLocaleString('en', { month: 'long', year: 'numeric' }));

            $.getJSON(APP.baseUrl + '/social/calendar-data', { from: ymd(start), to: ymd(end) }, function (res) {
                events = {};
                (res.data || []).forEach(function (ev) {
                    var d = ev.scheduled_at.substr(0, 10);
                    (events[d] = events[d] || []).push(ev);
                    var was = lastStatus[ev.id];
                    if (silent && was && was !== ev.status) {
                        if (ev.status === 'published') { APP.toast('Published: ' + (ev.title || 'post') + ' (' + ev.platform + ')', 'success'); }
                        if (ev.status === 'failed') { APP.toast('Failed: ' + (ev.title || 'post') + ' (' + ev.platform + '). Open it on the calendar to see why.', 'error'); }
                    }
                    lastStatus[ev.id] = ev.status;
                });
                draw(start, days);
                if (active && $('#socEventModal').hasClass('show')) {
                    var fresh = findEvent(active.id);
                    if (fresh) { active = fresh; fillModal(fresh); }
                }
            }).fail(function (xhr) {
                if (!silent) { APP.toast(msg(xhr, 'Could not load the calendar.'), 'error'); }
            }).always(function () { loading = false; });
        }

        function draw(start, days) {
            var html = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'].map(function (d) { return '<div class="soc-dow">' + d + '</div>'; }).join('');
            var today = ymd(new Date());
            for (var i = 0; i < days; i++) {
                var d = new Date(start); d.setDate(start.getDate() + i);
                var key = ymd(d);
                var cls = 'soc-day' + (d.getMonth() !== cur.getMonth() ? ' is-out' : '') + (key === today ? ' is-today' : '');
                html += '<div class="' + cls + '"><div class="soc-num">' + d.getDate() + '</div>';
                (events[key] || []).forEach(function (ev) {
                    var icon = { instagram: 'IG', facebook: 'FB', youtube: 'YT', linkedin: 'LI', whatsapp: 'WA' }[ev.platform] || ev.platform;
                    var pctTxt = ev.status === 'publishing' ? ' ' + (parseInt(ev.progress, 10) || 0) + '%' : '';
                    html += '<button type="button" class="soc-chip st-' + ev.status + '" data-id="' + ev.id + '">' +
                        $('<span>').text(ev.scheduled_at.substr(11, 5) + ' ' + icon + pctTxt + ' ' + (ev.title || '')).html() + '</button>';
                });
                html += '</div>';
            }
            $('#socCal').html(html);
        }

        function findEvent(id) {
            var found = null;
            Object.keys(events).forEach(function (k) {
                events[k].forEach(function (ev) { if (String(ev.id) === String(id)) { found = ev; } });
            });
            return found;
        }

        function fillModal(ev) {
            $('#socEventTitle').text(ev.title || 'Post');
            $('#socEvAccount').text(ev.account_name + ' (' + ev.platform + ')');
            $('#socEvType').text(ev.type);
            $('#socEvStatus').text(labels[ev.status] || ev.status);
            var pub = ev.status === 'publishing', pct = Math.max(0, Math.min(100, parseInt(ev.progress, 10) || 0));
            $('#socEvProgLabel, #socEvProg').toggleClass('d-none', !pub);
            $('#socEvProgBar').css('width', pct + '%').attr('aria-valuenow', pct).text(pct + '%');
            $('#socEvProgStage').text(ev.progress_stage || '');
            $('#socEvWhen').text(ev.scheduled_at);
            $('#socEvErr, #socEvErrLabel').toggleClass('d-none', !ev.last_error);
            $('#socEvErr').text(ev.last_error || '');
            $('#socEvLink').toggleClass('d-none', !ev.external_url).attr('href', ev.external_url || '#');
            var editable = canEdit && (ev.status === 'scheduled' || ev.status === 'failed' || ev.status === 'skipped');
            $('#socEvEdit, #socEvSave').toggleClass('d-none', !editable);
            if (!$('#socEventModal').hasClass('show')) { $('#socEvWhenInput').val(ev.scheduled_at.replace(' ', 'T').substr(0, 16)); }
            $('#socEvCancel').toggleClass('d-none', !(canEdit && (ev.status === 'scheduled' || ev.status === 'failed')));
            $('#socEvRetry').toggleClass('d-none', !(canEdit && ev.status === 'failed'));
            $('#socEvPublishNow').toggleClass('d-none', !(canEdit && (ev.status === 'scheduled' || ev.status === 'failed')));
            // Delete: any post that is not being published right now. "Everywhere" also removes it on YouTube / Facebook.
            var remote = canEdit && ev.status === 'published' && (ev.platform === 'youtube' || ev.platform === 'facebook');
            $('#socEvDelete').toggleClass('d-none', !(canEdit && ev.status !== 'publishing'));
            $('#socEvDeleteRemote').toggleClass('d-none', !remote).text('Delete from ' + (ev.platform === 'youtube' ? 'YouTube' : 'Facebook') + ' too');
        }

        $('#socCal').on('click', '.soc-chip', function () {
            var ev = findEvent($(this).data('id'));
            if (!ev) { return; }
            active = ev;
            fillModal(ev);
            modal.show();
        });

        function act(path, data, $b) {
            if (!active) { return; }
            post('/social/targets/' + active.id + '/' + path, data, $b, function () { modal.hide(); render(); });
        }
        $('#socEvCancel').on('click', function () { var $b = $(this); confirmThen('Cancel this post?', function () { act('cancel', {}, $b); }); });
        $('#socEvDelete').on('click', function () {
            var $b = $(this), pub = active && active.status === 'published';
            confirmThen(pub ? 'Remove it from the calendar? The post stays live on the platform.' : 'Delete this post?', function () { act('delete', {}, $b); });
        });
        $('#socEvDeleteRemote').on('click', function () {
            var $b = $(this);
            confirmThen('This deletes the live post from ' + (active ? active.platform : 'the platform') + ' and removes it from the calendar. It cannot be undone.', function () { act('delete', { remote: 1 }, $b); });
        });
        $('#socEvRetry').on('click', function () { act('retry', {}, $(this)); });
        $('#socEvPublishNow').on('click', function () { act('publish-now', {}, $(this)); });
        $('#socEvSave').on('click', function () { act('reschedule', { when: $('#socEvWhenInput').val().replace('T', ' ') }, $(this)); });

        // Text-only post
        $('#socTextForm').on('change', 'input[name="accounts[]"]', function () {
            $('#socTpWaBox').toggleClass('d-none', $('#socTextForm input[data-platform="whatsapp"]:checked').length === 0);
        });
        $('#socTpWaAll').on('change', function () { $('#socTpWaTags').prop('disabled', this.checked); });
        $('#socTextForm').on('submit', function (e) {
            e.preventDefault();
            var $f = $(this);
            var data = { when: $('#socTpWhen').val().replace('T', ' '), caption: $('#socTpCaption').val(), hashtags: $('#socTpTags').val(), accounts: [] };
            $f.find('input[name="accounts[]"]:checked').each(function () { data.accounts.push(this.value); });
            if (!data.accounts.length) { APP.toast('Select at least one account.', 'error'); return; }
            if ($f.find('input[data-platform="whatsapp"]:checked').length) {
                data.wa_template_id = $('#socTpWaTemplate').val();
                data.wa_tag_ids = $('#socTpWaTags').val() || [];
                data.wa_all = $('#socTpWaAll').is(':checked') ? 1 : 0;
                data.wa_caption_var = $('#socTpWaCaption').is(':checked') ? 1 : 0;
                if (!data.wa_template_id) { APP.toast('Choose a WhatsApp template.', 'error'); return; }
            }
            post('/social/text-post', data, $f.find('[type=submit]'), function () {
                bootstrap.Modal.getInstance(document.getElementById('socTextModal')).hide();
                $f[0].reset();
                $('#socTpWaBox').addClass('d-none');
                render();
            });
        });

        // The background scheduler tick may have published posts: refresh the calendar.
        $(document).on('app:tick', function (_e, d) { if (d && d.social > 0) { render(true); } });

        // Live status: while the calendar is open, poll quietly so Scheduled > Publishing > Published/Failed changes show without a refresh.
        // Fast (5s) only while a post is publishing or due within 2 minutes, otherwise every 30s, so an idle calendar costs almost nothing.
        function busyNow() {
            var soon = Date.now() + 120000, busy = false;
            Object.keys(events).forEach(function (k) {
                events[k].forEach(function (ev) {
                    if (ev.status === 'publishing') { busy = true; }
                    if (ev.status === 'scheduled' && new Date(ev.scheduled_at.replace(' ', 'T')).getTime() <= soon) { busy = true; }
                });
            });
            return busy;
        }
        var pollAt = 0;
        window.setInterval(function () {
            if (document.hidden || Date.now() < pollAt) { return; }
            pollAt = Date.now() + (busyNow() ? 0 : 25000);
            render(true);
        }, 5000);

        $('#socCalPrev').on('click', function () { cur.setMonth(cur.getMonth() - 1); render(); });
        $('#socCalNext').on('click', function () { cur.setMonth(cur.getMonth() + 1); render(); });
        $('#socCalToday').on('click', function () { cur = new Date(); cur.setDate(1); render(); });
        render();
    }
})(jQuery);
