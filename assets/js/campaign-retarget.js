(function ($) {
    'use strict';

    $(function () {
        var $fn = $('#rptFunnel');
        if (!$fn.length) {
            return;
        }

        $fn.on('click', '.js-retarget', function (e) {
            e.preventDefault();
            var $btn = $(this);
            var $items = $fn.find('.js-retarget');

            APP.post($fn.data('url'), {
                segment: $btn.data('segment'),
                from: $fn.data('from'),
                to: $fn.data('to'),
                campaign_id: $fn.data('campaign')
            }, {
                beforeSend: function () {
                    $items.prop('disabled', true);
                },
                success: function (res) {
                    if (res && res.success && res.data && res.data.url) {
                        APP.toast(res.message || 'Contacts added to a customer group.', 'success');
                        window.location.href = res.data.url;
                        return;
                    }
                    APP.toast((res && res.message) || 'Could not build the retarget group.', 'error');
                },
                error: function (xhr) {
                    APP.toast((xhr.responseJSON && xhr.responseJSON.message) || 'Could not build the retarget group.', 'error');
                },
                complete: function () {
                    $items.prop('disabled', false);
                }
            });
        });
    });
}(jQuery));
