(function ($) {
    'use strict';

    var $page = $('#crPage');
    if (!$page.length) { return; }

    var base = $page.data('base');

    function errorMessage(xhr, fallback) {
        return (xhr && xhr.responseJSON && xhr.responseJSON.message) || fallback;
    }

    function togglePanels(open) {
        $page.find('.cr-acc-panel').each(function () {
            bootstrap.Collapse.getOrCreateInstance(this, { toggle: false })[open ? 'show' : 'hide']();
        });
    }
    $('#crExpand').on('click', function () { togglePanels(true); });
    $('#crCollapse').on('click', function () { togglePanels(false); });

    $('#crTemplate').on('change', function () {
        $('#crLang').val($(this).find('option:selected').data('lang') || 'en');
    });

    $('#crForm').on('submit', function (e) {
        e.preventDefault();
        var $btn = $('button[form="crForm"]').prop('disabled', true);
        APP.post(base, $(this).serialize())
            .done(function (res) { APP.toast(res.message || 'Saved.', 'success'); })
            .fail(function (xhr) { APP.toast(errorMessage(xhr, 'Could not save.'), 'error'); })
            .always(function () { $btn.prop('disabled', false); });
    });

    $('#crRotate').on('click', function () {
        APP.confirm({
            title: 'Create a new secret?',
            text: 'Update it in your stores, or their events will be rejected.',
            confirmText: 'Create new secret'
        }).then(function (result) {
            if (!result.isConfirmed) { return; }
            APP.post(base + '/rotate', {})
                .done(function (res) {
                    $('#crSecret').val(res.data.secret);
                    APP.toast(res.message, 'success');
                })
                .fail(function (xhr) { APP.toast(errorMessage(xhr, 'Could not create a new secret.'), 'error'); });
        });
    });
})(jQuery);
