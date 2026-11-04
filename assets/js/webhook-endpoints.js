(function ($) {
    'use strict';

    var $page = $('#whPage');
    if (!$page.length) { return; }

    var base = (APP.baseUrl || '') + '/webhook-endpoints';
    var $modal = $('#whModal');
    var $form = $modal.find('form');
    var $save = $form.find('[type="submit"]');

    function errorMessage(xhr, fallback) {
        return (xhr && xhr.responseJSON && xhr.responseJSON.message) || fallback;
    }

    function showSecret(secret) {
        return Swal.fire({
            title: 'Signing secret',
            html: '<p class="small text-muted mb-2">Copy it now. It is not shown again.</p><code class="user-select-all">' + $('<div>').text(secret).html() + '</code>',
            confirmButtonText: 'I have copied it',
            allowOutsideClick: false
        });
    }

    function openForm(row) {
        $form[0].reset();
        $form.data('id', row ? row.id : null);
        $('#whModalTitle').text(row ? 'Edit endpoint' : 'Add endpoint');
        $('#whActiveRow').toggleClass('d-none', !row);
        if (row) {
            $form.find('[name="name"]').val(row.name);
            $form.find('[name="url"]').val(row.url);
            $form.find('[name="is_active"]').val(String(row.is_active));
            $form.find('[name="events[]"]').each(function () {
                this.checked = (row.events || []).indexOf(this.value) >= 0;
            });
        }
        APP.showModal($modal[0]);
    }

    $('#btnWhNew').on('click', function () { openForm(null); });
    $page.on('click', '.js-wh-edit', function () { openForm($(this).data('row')); });

    $form.on('submit', function (e) {
        e.preventDefault();
        var id = $form.data('id');
        $save.prop('disabled', true);
        APP.post(base + (id ? '/' + id : ''), $form.serialize())
            .done(function (res) {
                APP.hideModal($modal[0]);
                var done = function () { window.location.reload(); };
                if (res && res.data && res.data.secret) {
                    showSecret(res.data.secret).then(done);
                } else {
                    APP.toast((res && res.message) || 'Saved', 'success');
                    window.setTimeout(done, 600);
                }
            })
            .fail(function (xhr) { APP.toast(errorMessage(xhr, 'Could not save'), 'error'); })
            .always(function () { $save.prop('disabled', false); });
    });

    $page.on('click', '.js-wh-test', function () {
        var $btn = $(this).prop('disabled', true);
        APP.post(base + '/' + $btn.data('id') + '/test')
            .done(function (res) { APP.toast((res && res.message) || 'Sent', 'success'); window.setTimeout(function () { window.location.reload(); }, 1200); })
            .fail(function (xhr) { APP.toast(errorMessage(xhr, 'Test failed'), 'error'); })
            .always(function () { $btn.prop('disabled', false); });
    });

    $page.on('click', '.js-wh-rotate', function () {
        var $btn = $(this);
        APP.confirm({
            title: 'Rotate the secret for "' + $btn.data('name') + '"?',
            text: 'The old secret stops working at once. Update your receiver with the new one.',
            confirmText: 'Yes, rotate'
        }).then(function (result) {
            if (!result.isConfirmed) { return; }
            APP.post(base + '/' + $btn.data('id') + '/rotate')
                .done(function (res) { showSecret(res.data.secret); })
                .fail(function (xhr) { APP.toast(errorMessage(xhr, 'Could not rotate'), 'error'); });
        });
    });

    $page.on('click', '.js-wh-delete', function () {
        var $btn = $(this);
        APP.confirm({
            title: 'Delete "' + $btn.data('name') + '"?',
            text: 'Its queued and past calls are removed too.',
            confirmText: 'Yes, delete'
        }).then(function (result) {
            if (!result.isConfirmed) { return; }
            APP.post(base + '/' + $btn.data('id') + '/delete')
                .done(function (res) { APP.toast((res && res.message) || 'Deleted', 'success'); window.setTimeout(function () { window.location.reload(); }, 600); })
                .fail(function (xhr) { APP.toast(errorMessage(xhr, 'Could not delete'), 'error'); });
        });
    });
})(jQuery);
