/**
 * Emails — single + bulk compose AJAX helpers
 */
(function (window, $) {
    'use strict';

    function showResult($el, ok, message) {
        if (!$el.length) {
            return;
        }
        $el.html(
            '<div class="alert alert-' + (ok ? 'success' : 'danger') + ' mb-0 py-2">' +
            $('<div>').text(message || (ok ? 'Done.' : 'Failed.')).html() +
            (ok
                ? '<div class="small mt-1 opacity-75">Provider accepted the request. Delivery depends on the provider (check Spam/Promotions if needed).</div>'
                : '') +
            '</div>'
        );
    }

    function postJson(url, payload) {
        return $.ajax({
            url: url,
            method: 'POST',
            data: JSON.stringify(payload),
            contentType: 'application/json; charset=UTF-8',
            dataType: 'json'
        });
    }

    function bindSingle() {
        var $card = $('#emailSingleCard');
        var $form = $('#emailSingleForm');
        if (!$form.length) {
            return;
        }

        $form.on('submit', function (e) {
            e.preventDefault();
            var $btn = $('#btnSendSingle').prop('disabled', true);
            var url = $card.data('send-url') || $form.attr('action');
            var payload = {
                to: $.trim($('#emailTo').val() || ''),
                subject: $.trim($('#emailSubject').val() || ''),
                body: $('#emailBody').val() || '',
                is_html: $('#emailIsHtml').is(':checked') ? 1 : 0,
                campaign_name: $('#emailCampaign').val() || ''
            };

            postJson(url, payload)
                .done(function (res) {
                    var ok = !!(res && res.success);
                    showResult($('#emailSingleResult'), ok, (res && res.message) || '');
                    if (window.APP && APP.toast) {
                        APP.toast((res && res.message) || (ok ? 'Sent' : 'Failed'), ok ? 'success' : 'error');
                    }
                })
                .fail(function (xhr) {
                    var res = xhr.responseJSON || {};
                    var msg = res.message || 'Request failed.';
                    if (res.errors) {
                        msg += ' ' + Object.values(res.errors).join(' ');
                    }
                    showResult($('#emailSingleResult'), false, msg);
                })
                .always(function () {
                    $btn.prop('disabled', false);
                });
        });
    }

    function bindBulk() {
        var $card = $('#emailBulkCard');
        var $form = $('#emailBulkForm');
        if (!$form.length) {
            return;
        }

        function syncMode() {
            var mode = $('input[name="mode"]:checked').val() || 'recipients';
            if (mode === 'label') {
                $('#bulkRecipientsPanel').addClass('d-none');
                $('#bulkLabelPanel').removeClass('d-none');
            } else {
                $('#bulkRecipientsPanel').removeClass('d-none');
                $('#bulkLabelPanel').addClass('d-none');
            }
        }

        $form.on('change', 'input[name="mode"]', syncMode);
        syncMode();

        $form.on('submit', function (e) {
            e.preventDefault();

            var mode = $('input[name="mode"]:checked').val() || 'recipients';
            var confirmText = mode === 'label'
                ? 'Send this Cheerio label campaign now?'
                : 'Send this bulk email now?';

            var proceed = function () {
                var $btn = $('#btnSendBulk').prop('disabled', true);
                var url = $card.data('send-url') || $form.attr('action');
                var contactIds = ($('#bulkContacts').val() || []).map(function (v) {
                    return parseInt(v, 10);
                }).filter(Boolean);

                var payload = {
                    mode: mode,
                    subject: $.trim($('#bulkSubject').val() || ''),
                    body: $('#bulkBody').val() || '',
                    is_html: $('#bulkIsHtml').is(':checked') ? 1 : 0,
                    campaign_name: $('#bulkCampaign').val() || '',
                    recipients: $('#bulkRecipients').val() || '',
                    contact_ids: contactIds,
                    label_name: $.trim($('#bulkLabelName').val() || '')
                };

                postJson(url, payload)
                    .done(function (res) {
                        var ok = !!(res && res.success);
                        showResult($('#emailBulkResult'), ok, (res && res.message) || '');
                        if (window.APP && APP.toast) {
                            APP.toast((res && res.message) || (ok ? 'Sent' : 'Failed'), ok ? 'success' : 'error');
                        }
                    })
                    .fail(function (xhr) {
                        var res = xhr.responseJSON || {};
                        var msg = res.message || 'Request failed.';
                        if (res.errors) {
                            msg += ' ' + Object.values(res.errors).join(' ');
                        }
                        showResult($('#emailBulkResult'), false, msg);
                    })
                    .always(function () {
                        $btn.prop('disabled', false);
                    });
            };

            if (window.APP && APP.confirm) {
                APP.confirm({
                    title: 'Send bulk email?',
                    text: confirmText,
                    confirmText: 'Send now'
                }).then(function (result) {
                    if (result.isConfirmed) {
                        proceed();
                    }
                });
            } else if (window.confirm(confirmText)) {
                proceed();
            }
        });
    }

    $(function () {
        bindSingle();
        bindBulk();
    });
})(window, jQuery);
