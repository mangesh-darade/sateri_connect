/**
 * Meta WhatsApp Embedded Signup via Facebook JS SDK.
 * Official flow: FB.init → FB.login({ config_id, response_type: 'code' }) → WA_EMBEDDED_SIGNUP message.
 *
 * @see https://developers.facebook.com/docs/whatsapp/embedded-signup/implementation/
 */
(function (window, $) {
    'use strict';

    var APP = window.APP || (window.APP = {});
    var sdkScriptPromise = null;
    var messageBound = false;
    var sessionHandler = null;

    function normalizeVersion(v) {
        v = String(v || 'v21.0').trim();
        if (!v) return 'v21.0';
        return v.indexOf('v') === 0 ? v : ('v' + v);
    }

    function isSdkReady() {
        return !!(window.FB && typeof window.FB.login === 'function' && typeof window.FB.init === 'function');
    }

    function loadSdkScript() {
        if (isSdkReady()) {
            return Promise.resolve();
        }
        if (sdkScriptPromise) {
            return sdkScriptPromise;
        }

        sdkScriptPromise = new Promise(function (resolve, reject) {
            var existing = document.querySelector('script[data-meta-fb-sdk="1"]');
            if (existing && isSdkReady()) {
                resolve();
                return;
            }

            var settled = false;
            var prevInit = window.fbAsyncInit;
            window.fbAsyncInit = function () {
                try {
                    if (typeof prevInit === 'function') prevInit();
                } catch (e) { /* ignore previous init errors */ }
                if (!settled) {
                    settled = true;
                    resolve();
                }
            };

            if (!existing) {
                var s = document.createElement('script');
                s.async = true;
                s.defer = true;
                s.crossOrigin = 'anonymous';
                s.src = 'https://connect.facebook.net/en_US/sdk.js';
                s.setAttribute('data-meta-fb-sdk', '1');
                s.onerror = function () {
                    sdkScriptPromise = null;
                    if (!settled) {
                        settled = true;
                        reject(new Error('Could not load Facebook SDK. Check network / HTTPS.'));
                    }
                };
                document.body.appendChild(s);
            }

            var tries = 0;
            var t = setInterval(function () {
                tries += 1;
                if (isSdkReady()) {
                    clearInterval(t);
                    if (!settled) {
                        settled = true;
                        resolve();
                    }
                } else if (tries > 80) {
                    clearInterval(t);
                    sdkScriptPromise = null;
                    if (!settled) {
                        settled = true;
                        reject(new Error('Facebook SDK failed to load.'));
                    }
                }
            }, 100);
        });

        return sdkScriptPromise;
    }

    function initFb(appId, apiVersion) {
        if (!isSdkReady()) {
            throw new Error('Facebook SDK is not ready.');
        }
        appId = String(appId || '').trim();
        if (!appId) {
            throw new Error('Meta App ID is required to initialize the Facebook SDK.');
        }
        window.FB.init({
            appId: appId,
            autoLogAppEvents: true,
            xfbml: true,
            version: normalizeVersion(apiVersion)
        });
    }

    function bindSessionListener() {
        if (messageBound) return;
        messageBound = true;
        window.addEventListener('message', function (event) {
            var origin = String(event.origin || '');
            if (origin.indexOf('facebook.com') === -1) return;

            var data = event.data;
            try {
                if (typeof data === 'string') data = JSON.parse(data);
            } catch (e) {
                return;
            }
            if (!data || data.type !== 'WA_EMBEDDED_SIGNUP') return;
            if (typeof sessionHandler === 'function') {
                sessionHandler(data);
            }
        });
    }

    /**
     * @param {object} opts
     * @param {string} opts.appId
     * @param {string} opts.configId
     * @param {string} [opts.apiVersion]
     * @param {function} [opts.onSession] WA_EMBEDDED_SIGNUP payload handler
     * @param {function} [opts.onCode] auth code callback
     * @param {function} [opts.onCancel] no-code / cancel callback
     * @returns {Promise<void>}
     */
    function launch(opts) {
        opts = opts || {};
        var appId = String(opts.appId || '').trim();
        var configId = String(opts.configId || '').trim();
        var apiVersion = normalizeVersion(opts.apiVersion);

        if (!appId) {
            return Promise.reject(new Error('Meta App ID is required.'));
        }
        if (!configId) {
            return Promise.reject(new Error('Embedded Signup Config ID is required.'));
        }

        sessionHandler = typeof opts.onSession === 'function' ? opts.onSession : null;
        bindSessionListener();

        return loadSdkScript().then(function () {
            try {
                initFb(appId, apiVersion);
            } catch (e) {
                // FB.init throws if already initialized with different options; still try login.
            }

            return new Promise(function (resolve) {
                window.FB.login(function (response) {
                    var code = response && response.authResponse && response.authResponse.code
                        ? String(response.authResponse.code)
                        : '';
                    if (code) {
                        if (typeof opts.onCode === 'function') opts.onCode(code, response);
                        resolve({ ok: true, code: code, response: response });
                        return;
                    }
                    if (typeof opts.onCancel === 'function') opts.onCancel(response);
                    resolve({ ok: false, code: '', response: response || null });
                }, {
                    config_id: configId,
                    response_type: 'code',
                    override_default_response_type: true,
                    extras: {
                        setup: {},
                        featureType: '',
                        sessionInfoVersion: '3'
                    }
                });
            });
        });
    }

    /**
     * Preload SDK (and optionally init) so Connect WhatsApp is instant.
     * @param {object} [opts]
     * @param {string} [opts.appId]
     * @param {string} [opts.apiVersion]
     * @returns {Promise<boolean>}
     */
    function preload(opts) {
        opts = opts || {};
        return loadSdkScript().then(function () {
            var appId = String(opts.appId || '').trim();
            if (appId) {
                try { initFb(appId, opts.apiVersion); } catch (e) { /* already inited */ }
            }
            return isSdkReady();
        });
    }

    APP.MetaEmbeddedSignup = {
        isSdkReady: isSdkReady,
        preload: preload,
        launch: launch,
        bindSessionListener: bindSessionListener
    };
})(window, window.jQuery);
