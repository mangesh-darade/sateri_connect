/**
 * Online wallet recharge: create a Razorpay order on the server, open Checkout, then send the payment proof
 * back so the server can verify the signature and credit the wallet. Mswipe redirects to its own page.
 *
 * Every failure is shown twice: a toast, and a message that stays in the card (#walletRechargeAlert)
 * so it cannot be missed. Messages say plainly whether money was taken.
 */
(function (window, $) {
    'use strict';

    var $form = $('#walletRechargeForm');
    if (!$form.length) return;

    var $amount = $('#walletRechargeAmount');
    var $btn = $('#walletRechargeBtn');
    var $summary = $('#walletRechargeSummary');
    var $alert = $('#walletRechargeAlert');
    var gst = parseFloat($form.data('gst')) || 0;
    var min = parseFloat($form.data('min')) || 1;
    var max = parseFloat($form.data('max')) || 100000;
    var symbol = String($form.data('symbol') || '');
    var label = $btn.html();
    var inFlight = false;

    // A per-request beforeSend replaces the global one in app.js, so the CSRF header is set here as well.
    function csrf(xhr) {
        xhr.setRequestHeader(
            $('meta[name="csrf-header"]').attr('content') || 'X-CSRF-TOKEN',
            $('meta[name="csrf-token"]').attr('content') || (window.APP && APP.csrfToken) || ''
        );
    }

    function busy(on, text) {
        inFlight = on;
        $btn.prop('disabled', on).html(on ? '<i class="fas fa-spinner fa-spin me-1"></i> ' + (text || 'Please wait') : label);
    }

    function money(n) {
        return symbol + n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    /** kind: error | warning | info | success. extra (optional) is appended as plain text. */
    function show(kind, title, text, withReload) {
        var icon = { error: 'circle-xmark', warning: 'triangle-exclamation', info: 'circle-info', success: 'circle-check' }[kind] || 'circle-info';
        $alert.attr('class', 'wallet-alert is-' + kind).empty()
            .append($('<i>').addClass('fas fa-' + icon))
            .append($('<div>').append($('<strong>').text(title)).append($('<span>').text(text || '')));
        if (withReload) {
            $alert.find('div').append(' ', $('<a href="#" class="wallet-alert-link">Reload page</a>').on('click', function (e) {
                e.preventDefault();
                window.location.reload();
            }));
        }
        if (window.APP && APP.toast) APP.toast(title + (text ? ' ' + text : ''), kind === 'warning' ? 'error' : kind);
    }

    function clearAlert() {
        $alert.attr('class', 'wallet-alert d-none').empty();
    }

    /** Turn a failed request into a message the customer can act on. */
    function describe(xhr, fallback) {
        var msg = xhr.responseJSON && xhr.responseJSON.message;
        if (xhr.status === 0 || xhr.statusText === 'timeout') {
            return { title: 'Connection problem.', text: 'We could not reach the server. Check your internet and try again.', reload: false };
        }
        if (xhr.status === 419 || xhr.status === 401 || (xhr.status === 403 && !msg)) {
            return { title: 'Your session has expired.', text: 'Reload the page and sign in again if asked, then retry.', reload: true };
        }
        if (xhr.status === 403) {
            return { title: 'Not allowed.', text: msg, reload: false };
        }
        if (xhr.status >= 500 && !msg) {
            return { title: 'Something went wrong on our side.', text: 'Please try again in a moment.', reload: false };
        }

        return { title: msg || fallback, text: '', reload: false };
    }

    function refreshSummary() {
        var a = parseFloat($amount.val());
        clearAlert();
        if (!a || a <= 0) { $summary.removeClass('is-ready is-invalid'); return; }
        if (a < min || a > max) {
            $summary.removeClass('is-ready').addClass('is-invalid')
                .text('Enter an amount between ' + money(min) + ' and ' + money(max) + '.');
            return;
        }
        var tax = Math.round(a * gst) / 100;
        $summary.removeClass('is-invalid').addClass('is-ready').text(
            gst > 0
                ? 'You pay ' + money(a + tax) + ' (' + money(a) + ' + ' + money(tax) + ' GST). ' + money(a) + ' will be added to your wallet.'
                : 'You pay ' + money(a) + '. The full ' + money(a) + ' will be added to your wallet.'
        );
    }

    $form.on('click', '[data-amount]', function () {
        $amount.val($(this).data('amount')).trigger('input');
    });
    $amount.on('input', refreshSummary);

    /** Money may have left the customer's account: never suggest paying again. */
    function verify(resp) {
        var ref = resp && resp.razorpay_payment_id ? ' Payment reference: ' + resp.razorpay_payment_id + '.' : '';
        $.ajax({
            url: String($form.data('verifyUrl')),
            method: 'POST',
            dataType: 'json',
            timeout: 30000,
            data: {
                razorpay_order_id: resp.razorpay_order_id,
                razorpay_payment_id: resp.razorpay_payment_id,
                razorpay_signature: resp.razorpay_signature
            },
            beforeSend: function (xhr) { csrf(xhr); busy(true, 'Confirming payment'); },
            success: function (res) {
                show('success', (res && res.message) || 'Wallet recharged.', '');
                setTimeout(function () { window.location.reload(); }, 1500);
            },
            error: function (xhr) {
                var d = describe(xhr, 'We could not confirm your payment yet.');
                show('warning', d.title, (d.text ? d.text + ' ' : '') +
                    'If money was deducted, do not pay again: it is credited automatically within a few minutes.' + ref, d.reload);
                busy(false);
            }
        });
    }

    function openCheckout(d) {
        if (!window.Razorpay) {
            show('error', 'Razorpay could not be loaded.', 'Check your connection or ad blocker and reload the page. You were not charged.', true);
            busy(false);
            return;
        }
        var rz = new window.Razorpay({
            key: d.key_id,
            order_id: d.order_id,
            amount: d.amount,
            currency: d.currency,
            name: d.name,
            description: 'WhatsApp wallet recharge',
            theme: { color: '#e11d48' },
            handler: verify,
            modal: {
                ondismiss: function () {
                    if (!inFlight) return;
                    busy(false);
                    show('info', 'Payment cancelled.', 'You were not charged.');
                }
            }
        });
        rz.on('payment.failed', function (r) {
            var err = (r && r.error) || {};
            busy(false);
            show('error', 'Payment failed.', (err.description ? err.description + ' ' : '') + 'You were not charged. Try again or use another payment method.');
        });
        rz.open();
    }

    $form.on('submit', function (e) {
        e.preventDefault();
        if (inFlight) return;
        var a = parseFloat($amount.val());
        if (!a || a < min || a > max) {
            refreshSummary();
            show('error', 'Check the amount.', 'Enter an amount between ' + money(min) + ' and ' + money(max) + '.');
            $amount.trigger('focus');
            return;
        }
        clearAlert();
        $.ajax({
            url: String($form.data('orderUrl')),
            method: 'POST',
            dataType: 'json',
            timeout: 30000,
            data: { amount: a, gateway: String($form.find('[name=gateway]:checked, input[type=hidden][name=gateway]').first().val() || 'razorpay') },
            beforeSend: function (xhr) { csrf(xhr); busy(true, 'Starting payment'); },
            success: function (res) {
                var d = res && res.data;
                if (!d) { show('error', 'Could not start the payment.', 'Please try again. You were not charged.'); busy(false); return; }
                if (d.gateway === 'mswipe') {
                    // Mswipe pay-by-link: the customer pays on Mswipe's page and returns here.
                    busy(true, 'Opening Mswipe');
                    window.location.href = d.redirect_url;
                    return;
                }
                openCheckout(d);
            },
            error: function (xhr) {
                var d = describe(xhr, 'Could not start the payment.');
                show('error', d.title, (d.text ? d.text + ' ' : '') + 'You were not charged.', d.reload);
                busy(false);
            }
        });
    });
})(window, jQuery);
