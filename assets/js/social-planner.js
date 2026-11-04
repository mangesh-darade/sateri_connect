/**
 * AI Content Planner: create a plan, make the pictures one by one, edit, then approve.
 * CSRF headers are added by the global $.ajaxSetup in app.js.
 */
(function ($) {
    'use strict';

    var canEdit = !!window.PL_CAN_EDIT;
    var planId = 0;
    var busy = false;

    function msg(xhr, fallback) {
        return (xhr && xhr.responseJSON && xhr.responseJSON.message) || fallback;
    }

    function call(url, data, method) {
        return $.ajax({ url: APP.baseUrl + url, method: method || 'POST', data: data || {}, dataType: 'json' });
    }

    function itemEl(it) {
        var $card = $('<div class="pl-item">').addClass('is-' + it.status).attr('data-id', it.id).attr('data-status', it.status);
        var $pic = $('<div class="pl-pic">');
        if (it.image_file) {
            $pic.append($('<img alt="">').attr('src', APP.baseUrl + '/social/planner/items/' + it.id + '/image?v=' + encodeURIComponent(it.image_file)));
        } else {
            $pic.text(it.status === 'failed' ? (it.error || 'Picture failed') : 'Picture not made yet');
        }
        var $body = $('<div>');
        var locked = it.status === 'approved' || it.status === 'rejected';
        $body.append($('<div class="small text-muted mb-1">').text('Day ' + it.day_no + ' - ' + it.post_date + (it.status === 'approved' ? ' - scheduled' : it.status === 'rejected' ? ' - removed' : '')));
        $body.append($('<input class="form-control form-control-sm fw-semibold mb-2" data-f="topic" maxlength="191">').val(it.topic).prop('disabled', !canEdit || locked));
        $body.append($('<textarea class="form-control form-control-sm mb-2" data-f="caption" rows="4" maxlength="2000">').val(it.caption).prop('disabled', !canEdit || locked));
        $body.append($('<input class="form-control form-control-sm mb-2" data-f="hashtags" maxlength="500">').val(it.hashtags).prop('disabled', !canEdit || locked));
        $body.append($('<label class="form-label small text-muted mb-0">').text('Picture description (English)'));
        $body.append($('<textarea class="form-control form-control-sm mb-2" data-f="image_prompt" rows="2" maxlength="1500">').val(it.image_prompt).prop('disabled', !canEdit || locked));
        if (it.image_file) {
            $body.append($('<button type="button" class="btn btn-outline-secondary btn-sm me-1" data-act="preview">').html('<i class="fas fa-eye me-1"></i>').append(document.createTextNode('Preview')));
        }
        if (canEdit && !locked) {
            $body.append($('<button type="button" class="btn btn-outline-secondary btn-sm me-1" data-act="save">').text('Save text'));
            $body.append($('<button type="button" class="btn btn-outline-secondary btn-sm me-1" data-act="image">').html('<i class="fas fa-rotate me-1"></i>').append(document.createTextNode(it.image_file ? 'Remake picture' : 'Make picture')));
            $body.append($('<button type="button" class="btn btn-outline-danger btn-sm" data-act="reject">').text('Remove'));
        }

        return $card.append($pic, $body);
    }

    function render(data) {
        planId = data.plan.id;
        $('#plTitle').text(data.plan.title + ' (' + data.plan.status + ')');
        var $list = $('#plItems').empty();
        data.items.forEach(function (it) { $list.append(itemEl(it)); });
        $('#plReview').removeClass('d-none');
        $('#plApprove').prop('disabled', data.plan.status === 'approved');
    }

    function load(id) {
        return call('/social/planner/' + id + '/data', null, 'GET').done(function (res) { render(res.data); })
            .fail(function (xhr) { APP.toast(msg(xhr, 'Could not load the plan.'), 'error'); });
    }

    // Makes every missing picture, one request at a time.
    function makeMissing(done) {
        if (busy) { return; }
        var ids = $('#plItems .pl-item').filter(function () {
            var s = $(this).attr('data-status');
            return s === 'planned' || s === 'failed';
        }).map(function () { return $(this).attr('data-id'); }).get();
        var i = 0;
        busy = true;
        $('#plMakeMissing, #plApprove, #plGo').prop('disabled', true);
        (function next() {
            if (i >= ids.length) {
                busy = false;
                $('#plMakeMissing, #plApprove, #plGo').prop('disabled', false);
                $('#plProgress').text('Review the posts, then approve.');
                load(planId).always(function () { if (done) { done(); } });
                return;
            }
            $('#plProgress').text('Making picture ' + (i + 1) + ' of ' + ids.length + '...');
            call('/social/planner/items/' + ids[i] + '/image').fail(function (xhr) {
                APP.toast(msg(xhr, 'A picture failed.'), 'error');
            }).always(function () { i++; next(); });
        })();
    }

    $('#plStart').val(function () {
        var d = new Date(); d.setDate(d.getDate() + 1);
        return d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2) + '-' + ('0' + d.getDate()).slice(-2);
    });

    $('#plForm').on('submit', function (e) {
        e.preventDefault();
        if (busy) { return; }
        var $b = $('#plGo').prop('disabled', true);
        $('#plProgress').text('Claude is planning the posts...');
        call('/social/planner', $(this).serialize()).done(function (res) {
            planId = res.data.plan_id;
            load(planId).done(function () { makeMissing(); });
        }).fail(function (xhr) {
            APP.toast(msg(xhr, 'Could not make the plan.'), 'error');
            $('#plProgress').text('Nothing is posted until you review and approve.');
            $b.prop('disabled', false);
        });
    });

    $('#plMakeMissing').on('click', function () { makeMissing(); });

    /* ----- Post something today: a short chat; Claude asks, then the plan and picture are made ----- */
    var chat = [];
    function bubble(who, text) {
        $('#plChat').append($('<div class="mb-1">').append($('<strong>').text(who + ': ')).append(document.createTextNode(text)));
    }
    $('#plQuickForm').on('submit', function (e) {
        e.preventDefault();
        var text = $.trim($('#plQuickText').val());
        if (!text || busy) { return; }
        var accounts = $('#plForm input[name="accounts[]"]:checked').map(function () { return this.value; }).get();
        bubble('You', text);
        $('#plQuickText').val('');
        var $b = $('#plQuickGo').prop('disabled', true);
        $('#plProgress').text('Thinking...');
        call('/social/planner/quick', { message: text, history: JSON.stringify(chat), accounts: accounts }).done(function (res) {
            chat.push({ role: 'user', text: text });
            if (res.data.action === 'ask') {
                chat.push({ role: 'assistant', text: res.data.question });
                bubble('AI', res.data.question);
                $b.prop('disabled', false);
                return;
            }
            bubble('AI', 'Got it. Designing your post...');
            chat = [];
            planId = res.data.plan_id;
            load(planId).done(function () { makeMissing(); });
        }).fail(function (xhr) {
            APP.toast(msg(xhr, 'Something went wrong. Please try again.'), 'error');
            $b.prop('disabled', false);
        });
    });

    $('#plItems').on('click', '[data-act]', function () {
        var $btn = $(this), $card = $btn.closest('.pl-item'), id = $card.attr('data-id'), act = $btn.data('act');
        if (act === 'preview') {
            var pic = $card.find('.pl-pic img').attr('src');
            var text = $.trim($card.find('[data-f=caption]').val() + '\n\n' + $card.find('[data-f=hashtags]').val());
            var $wrap = $('<div class="text-start mx-auto" style="max-width:340px;border:1px solid #dbdbdb;border-radius:.6rem;overflow:hidden;background:#fff;color:#262626;font-size:.85rem">');
            $wrap.append($('<div class="p-2 fw-semibold">').text('Your business'));
            $wrap.append($('<img alt="" style="width:100%;display:block">').attr('src', pic));
            $wrap.append($('<div class="p-2" style="white-space:pre-wrap">').text(text));
            Swal.fire({ title: 'How it will look', html: $wrap[0], showConfirmButton: true, confirmButtonText: 'Close', width: 420 });
        } else if (act === 'save') {
            $btn.prop('disabled', true);
            call('/social/planner/items/' + id + '/update', {
                topic: $card.find('[data-f=topic]').val(), caption: $card.find('[data-f=caption]').val(),
                hashtags: $card.find('[data-f=hashtags]').val(), image_prompt: $card.find('[data-f=image_prompt]').val()
            }).done(function (res) { APP.toast(res.message || 'Saved.', 'success'); })
              .fail(function (xhr) { APP.toast(msg(xhr, 'Could not save.'), 'error'); })
              .always(function () { $btn.prop('disabled', false); });
        } else if (act === 'image') {
            $btn.prop('disabled', true);
            call('/social/planner/items/' + id + '/update', { image_prompt: $card.find('[data-f=image_prompt]').val() }).always(function () {
                call('/social/planner/items/' + id + '/image').done(function () { load(planId); })
                    .fail(function (xhr) { APP.toast(msg(xhr, 'The picture failed.'), 'error'); load(planId); });
            });
        } else if (act === 'reject') {
            Swal.fire({ title: 'Remove this post from the plan?', icon: 'warning', showCancelButton: true, confirmButtonText: 'Remove' }).then(function (r) {
                if (!r.isConfirmed) { return; }
                call('/social/planner/items/' + id + '/reject').done(function () { load(planId); })
                    .fail(function (xhr) { APP.toast(msg(xhr, 'Could not remove.'), 'error'); });
            });
        }
    });

    $('#plApprove').on('click', function () {
        Swal.fire({ title: 'Approve and schedule?', text: 'Every post will be scheduled and published automatically at its time.', icon: 'question', showCancelButton: true, confirmButtonText: 'Approve' }).then(function (r) {
            if (!r.isConfirmed) { return; }
            var $b = $('#plApprove').prop('disabled', true);
            call('/social/planner/' + planId + '/approve').done(function (res) {
                APP.toast(res.message || 'Scheduled.', 'success');
                load(planId);
            }).fail(function (xhr) {
                APP.toast(msg(xhr, 'Could not approve.'), 'error');
                $b.prop('disabled', false);
            });
        });
    });

    $('.js-pl-open').on('click', function (e) { e.preventDefault(); load($(this).data('id')).done(function () { $('html,body').animate({ scrollTop: $('#plReview').offset().top - 70 }); }); });
})(jQuery);
