/**
 * "Connect WhatsApp" button in the top-bar profile menu (tenant level Embedded Signup).
 * Needs meta-embedded-signup.js. Reads app id / config id from the button's data attributes.
 */
(function (window, $) {
    'use strict';

    var $btn = $('#btnConnectWhatsApp');
    if (!$btn.length || !window.APP || !APP.MetaEmbeddedSignup) return;

    var appId = String($btn.data('appId') || '');
    var configId = String($btn.data('configId') || '');
    var apiVersion = String($btn.data('apiVersion') || 'v25.0');
    var connectUrl = String($btn.data('connectUrl') || '');
    var label = $btn.html();
    var pendingCode = null;
    var pendingRedirect = '';
    var pendingSession = null;
    var completing = false;
    var localHost = /^(localhost|127\.0\.0\.1)$/i.test(window.location.hostname);

    // A per-request beforeSend replaces the global one in app.js, so the CSRF header is set here as well.
    function csrf(xhr) {
        xhr.setRequestHeader(
            $('meta[name="csrf-header"]').attr('content') || 'X-CSRF-TOKEN',
            $('meta[name="csrf-token"]').attr('content') || (window.APP && APP.csrfToken) || ''
        );
    }

    function busy(on) {
        $btn.prop('disabled', on).html(on ? '<i class="fas fa-spinner fa-spin me-1"></i> Connecting…' : label);
    }

    function tryComplete() {
        if (completing || !pendingCode || !pendingSession) return;
        completing = true;
        var data = {
            code: pendingCode,
            redirect_uri: pendingRedirect,
            waba_id: pendingSession.waba_id,
            phone_number_id: pendingSession.phone_number_id,
            business_id: pendingSession.business_id
        };
        pendingCode = null;
        pendingRedirect = '';
        pendingSession = null;

        $.ajax({
            url: connectUrl,
            method: 'POST',
            dataType: 'json',
            data: data,
            beforeSend: function (xhr) { csrf(xhr); busy(true); },
            success: function (res) {
                if (res && res.success) {
                    APP.toast(res.message || 'WhatsApp connected', 'success');
                    setTimeout(function () { window.location.reload(); }, 1200);
                } else {
                    APP.toast((res && res.message) || 'Could not connect WhatsApp', 'error');
                }
            },
            error: function (xhr) {
                var msg = xhr.responseJSON && xhr.responseJSON.message;
                APP.toast(msg || 'Could not connect WhatsApp', 'error');
            },
            complete: function () {
                completing = false;
                busy(false);
            }
        });
    }

    function onSession(msg) {
        var finish = { FINISH: 1, FINISH_ONLY_WABA: 1, FINISH_WHATSAPP_BUSINESS_APP_ONBOARDING: 1, FINISH_OBO_MIGRATION: 1, FINISH_GRANT_ONLY_API_ACCESS: 1 };
        if (!finish[String((msg && msg.event) || '')]) return;
        var d = msg.data || {};
        pendingSession = {
            phone_number_id: String(d.phone_number_id || ''),
            waba_id: String(d.waba_id || ''),
            business_id: String(d.business_id || '')
        };
        tryComplete();
    }

    APP.MetaEmbeddedSignup.preload({ appId: appId, apiVersion: apiVersion }).catch(function () {});

    $btn.on('click', function (e) {
        e.preventDefault();
        if (window.location.protocol !== 'https:' && !localHost) {
            APP.toast('Open this app over HTTPS before connecting WhatsApp.', 'error');
            return;
        }
        APP.MetaEmbeddedSignup.launch({
            appId: appId,
            configId: configId,
            apiVersion: apiVersion,
            onSession: onSession,
            onCode: function (code, response, redirectUri) {
                pendingCode = code;
                pendingRedirect = String(redirectUri || '');
                tryComplete();
            },
            onCancel: function () {
                if (!pendingSession) APP.toast('Meta login was cancelled.', 'error');
            }
        }).catch(function (err) {
            APP.toast((err && err.message) || 'Could not launch Embedded Signup', 'error');
        });
    });
})(window, window.jQuery);
