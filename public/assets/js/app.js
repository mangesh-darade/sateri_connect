/**
 * Global app helpers — CSRF, DataTables, SweetAlert, toasts, chat utils
 */
(function (window, $) {
    'use strict';

    var APP = window.APP || {};
    window.APP = APP;

    /**
     * Parse a DB/API timestamp (stored UTC, naive MySQL datetime) into a Date.
     */
    APP.parseUtcDate = function (ts) {
        if (ts == null || ts === '') return null;
        if (ts instanceof Date) {
            return isNaN(ts.getTime()) ? null : ts;
        }
        var s = String(ts).trim();
        if (!s) return null;

        if (/^\d{10,13}$/.test(s)) {
            var n = parseInt(s, 10);
            if (s.length <= 10) n *= 1000;
            var fromEpoch = new Date(n);
            return isNaN(fromEpoch.getTime()) ? null : fromEpoch;
        }

        // Already has zone
        if (/[zZ]|[+-]\d{2}:?\d{2}$/.test(s)) {
            var withZone = new Date(s);
            return isNaN(withZone.getTime()) ? null : withZone;
        }

        // Naive MySQL / ISO → treat as UTC
        var normalized = s.replace(' ', 'T');
        if (/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2})?$/.test(normalized)) {
            if (normalized.length === 16) normalized += ':00';
            var asUtc = new Date(normalized + 'Z');
            return isNaN(asUtc.getTime()) ? null : asUtc;
        }

        var fallback = new Date(s);
        return isNaN(fallback.getTime()) ? null : fallback;
    };

    /**
     * Interpret datetime-local / wall-clock string as APP.timezone → Date.
     */
    APP.parseAppLocalInput = function (localStr) {
        var m = String(localStr || '').trim().match(
            /^(\d{4})-(\d{2})-(\d{2})[T ](\d{2}):(\d{2})(?::(\d{2}))?/
        );
        if (!m) return null;

        var y = +m[1];
        var mo = +m[2] - 1;
        var d = +m[3];
        var h = +m[4];
        var mi = +m[5];
        var sec = +(m[6] || 0);
        var tz = String(APP.timezone || 'UTC').trim() || 'UTC';

        var want = Date.UTC(y, mo, d, h, mi, sec);
        var fmt;
        try {
            fmt = new Intl.DateTimeFormat('en-US', {
                timeZone: tz,
                year: 'numeric',
                month: '2-digit',
                day: '2-digit',
                hour: '2-digit',
                minute: '2-digit',
                second: '2-digit',
                hour12: false
            });
        } catch (e) {
            return new Date(want);
        }

        function asUtcMs(ms) {
            var parts = {};
            fmt.formatToParts(new Date(ms)).forEach(function (p) {
                if (p.type !== 'literal') parts[p.type] = p.value;
            });
            var hour = parseInt(parts.hour, 10);
            if (hour === 24) hour = 0;
            return Date.UTC(
                parseInt(parts.year, 10),
                parseInt(parts.month, 10) - 1,
                parseInt(parts.day, 10),
                hour,
                parseInt(parts.minute, 10),
                parseInt(parts.second, 10)
            );
        }

        var guess = want;
        for (var i = 0; i < 3; i++) {
            guess = guess + (want - asUtcMs(guess));
        }
        return new Date(guess);
    };

    /**
     * Format stored UTC datetime in APP.timezone.
     * style: 'datetime' | 'time' | 'date' | Intl options object
     */
    APP.formatDateTime = function (ts, style) {
        var d = APP.parseUtcDate(ts);
        if (!d) return ts == null || ts === '' ? '' : String(ts);

        var tz = String(APP.timezone || 'UTC').trim() || 'UTC';
        var opts;

        if (style && typeof style === 'object') {
            opts = Object.assign({ timeZone: tz }, style);
        } else if (style === 'time') {
            opts = { timeZone: tz, hour: '2-digit', minute: '2-digit' };
        } else if (style === 'date') {
            opts = { timeZone: tz, day: '2-digit', month: 'short', year: 'numeric' };
        } else {
            opts = {
                timeZone: tz,
                day: '2-digit',
                month: 'short',
                year: 'numeric',
                hour: 'numeric',
                minute: '2-digit',
                hour12: true
            };
        }

        try {
            return new Intl.DateTimeFormat(undefined, opts).format(d);
        } catch (e) {
            try {
                opts.timeZone = 'UTC';
                return new Intl.DateTimeFormat(undefined, opts).format(d);
            } catch (e2) {
                return d.toISOString();
            }
        }
    };

    APP.formatTime = function (ts) {
        return APP.formatDateTime(ts, 'time');
    };

    function csrfToken() {
        return $('meta[name="csrf-token"]').attr('content') || APP.csrfToken || '';
    }

    function csrfHeader() {
        return $('meta[name="csrf-header"]').attr('content') || APP.csrfHeader || 'X-CSRF-TOKEN';
    }

    function refreshCsrfFromResponse(xhr) {
        var next = xhr.getResponseHeader(csrfHeader()) || xhr.getResponseHeader('X-CSRF-TOKEN');
        if (next) {
            APP.csrfToken = next;
            $('meta[name="csrf-token"]').attr('content', next);
        }
    }

    $.ajaxSetup({
        headers: {},
        beforeSend: function (xhr, settings) {
            var method = (settings.type || 'GET').toUpperCase();
            if (method !== 'GET' && method !== 'HEAD' && method !== 'OPTIONS') {
                xhr.setRequestHeader(csrfHeader(), csrfToken());
            }
        },
        complete: function (xhr) {
            refreshCsrfFromResponse(xhr);
        }
    });

    $(document).ajaxError(function (_event, xhr) {
        if (!xhr) return;

        refreshCsrfFromResponse(xhr);

        if (xhr.status === 403) {
            var message = 'Your form/session security token expired or is invalid. Refresh the page and try again.';
            if (xhr.responseJSON && xhr.responseJSON.message) {
                message = xhr.responseJSON.message;
            }
            APP.toast(message, 'error');
        }
    });

    // Append CSRF to forms that omit csrf_field (safety net for dynamic forms)
    $(document).on('submit', 'form[method="post"], form[method="POST"]', function () {
        var $form = $(this);
        var name = APP.csrfName || 'csrf_test_name';
        if (!$form.find('input[name="' + name + '"]').length && !$form.find('input[name^="csrf"]').length) {
            $('<input>', { type: 'hidden', name: name, value: csrfToken() }).appendTo($form);
        }
    });

    APP.toast = function (message, icon) {
        if (window.Swal) {
            Swal.fire({
                toast: true,
                position: 'top-end',
                icon: icon || 'success',
                title: message,
                showConfirmButton: false,
                timer: 2800,
                timerProgressBar: true
            });
        } else {
            alert(message);
        }
    };

    APP.escapeHtml = function (value) {
        return $('<div>').text(value == null ? '' : String(value)).html();
    };

    /**
     * Render a WhatsApp template header for preview.
     *
     * Media headers store a CDN sample URL in header_content, so printing it
     * raw shows an unreadable link instead of the media the customer will see.
     *
     * @returns {string} HTML safe to inject
     */
    APP.templateHeaderPreviewHtml = function (headerType, headerContent) {
        var type = String(headerType || '').toLowerCase();
        var content = String(headerContent || '').trim();
        if (content === '') {
            return '';
        }

        var isUrl = /^https?:\/\//i.test(content);
        if (type === 'text' || (type === '' && !isUrl)) {
            return '<div class="fw-semibold">' + APP.escapeHtml(content) + '</div>';
        }

        if (type === '' && isUrl) {
            if (/\.(jpe?g|png|webp|gif)(\?|$)/i.test(content)) {
                type = 'image';
            } else if (/\.(mp4|3gp|mov)(\?|$)/i.test(content)) {
                type = 'video';
            } else {
                type = 'document';
            }
        }

        var safeUrl = APP.escapeHtml(content);
        var label = type.charAt(0).toUpperCase() + type.slice(1) + ' header';

        if (type === 'image') {
            return '<img src="' + safeUrl + '" alt="' + APP.escapeHtml(label)
                + '" class="img-fluid rounded mb-2" style="max-height:220px">';
        }

        if (type === 'video') {
            return '<video src="' + safeUrl + '" controls preload="metadata"'
                + ' class="rounded mb-2" style="max-width:100%;max-height:220px"></video>';
        }

        return '<a href="' + safeUrl + '" target="_blank" rel="noopener"'
            + ' class="d-inline-flex align-items-center gap-2 border rounded px-2 py-1 mb-2 text-decoration-none">'
            + '<i class="fas fa-file-lines"></i><span>' + APP.escapeHtml(label) + '</span></a>';
    };

    /**
     * Wire a click/drag-drop upload box to a hidden file input.
     *
     * The file input usually lives inside the box, so a plain re-trigger would
     * bubble back into the box handler and recurse until the stack overflows.
     *
     * options: { box, input, chooseBtn, ignore, onFile, dropZone }
     */
    APP.bindUploadBox = function (options) {
        options = options || {};

        var $box = $(options.box);
        var $input = $(options.input);
        if (!$box.length || !$input.length) {
            return;
        }

        var chooseSelector = options.chooseBtn || '';
        var ignoreSelector = [options.input, chooseSelector, options.ignore, 'a, button, input, textarea, select']
            .filter(Boolean)
            .join(', ');

        function openPicker() {
            $input.trigger('click');
        }

        function handleFile(file) {
            if (file && typeof options.onFile === 'function') {
                options.onFile(file);
            }
        }

        if (chooseSelector) {
            $(chooseSelector).on('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                openPicker();
            });
        }

        $box.on('click', function (e) {
            if ($(e.target).closest(ignoreSelector).length) {
                return;
            }
            openPicker();
        });

        $input.on('change', function () {
            handleFile(this.files && this.files[0] ? this.files[0] : null);
            $(this).val('');
        });

        $box.on('dragenter dragover', function (e) {
            e.preventDefault();
            e.stopPropagation();
            $(this).addClass('is-dragover');
        }).on('dragleave dragend drop', function (e) {
            e.preventDefault();
            e.stopPropagation();
            $(this).removeClass('is-dragover');
        }).on('drop', function (e) {
            var dt = e.originalEvent && e.originalEvent.dataTransfer;
            handleFile(dt && dt.files && dt.files[0] ? dt.files[0] : null);
        });

        if (options.dropZone) {
            $(options.dropZone).on('dragover drop', function (e) {
                e.preventDefault();
            });
        }
    };

    APP.confirm = function (options) {
        options = options || {};
        return Swal.fire({
            title: options.title || 'Are you sure?',
            text: options.text || 'This action cannot be undone.',
            icon: options.icon || 'warning',
            showCancelButton: true,
            confirmButtonColor: '#8e53f7',
            cancelButtonColor: '#6c757d',
            confirmButtonText: options.confirmText || 'Yes, continue',
            cancelButtonText: options.cancelText || 'Cancel'
        });
    };

    APP.deleteConfirm = function (url, options) {
        options = options || {};
        return APP.confirm({
            title: options.title || 'Delete item?',
            text: options.text || 'This cannot be undone.',
            confirmText: options.confirmText || 'Yes, delete'
        }).then(function (result) {
            if (!result.isConfirmed) {
                return;
            }
            var $form = $('<form>', { method: 'POST', action: url });
            $form.append($('<input>', { type: 'hidden', name: APP.csrfName || 'csrf_test_name', value: csrfToken() }));
            if (options.method) {
                $form.append($('<input>', { type: 'hidden', name: '_method', value: options.method }));
            }
            $('body').append($form);
            $form.trigger('submit');
        });
    };

    $(document).on('click', '[data-confirm-delete]', function (e) {
        e.preventDefault();
        var $el = $(this);
        var url = $el.data('url') || $el.attr('href');
        APP.deleteConfirm(url, {
            title: $el.data('title') || 'Delete?',
            text: $el.data('text') || 'This cannot be undone.'
        });
    });

    $(document).on('click', '[data-confirm]', function (e) {
        e.preventDefault();
        var $el = $(this);
        var href = $el.attr('href');
        var form = $el.closest('form');
        APP.confirm({
            title: $el.data('confirm-title') || 'Confirm',
            text: $el.data('confirm') || 'Continue?',
            confirmText: $el.data('confirm-text') || 'Yes'
        }).then(function (result) {
            if (!result.isConfirmed) {
                return;
            }
            if ($el.is('button') || $el.is('input') || form.length) {
                if (form.length) {
                    form.trigger('submit');
                }
            } else if (href) {
                window.location = href;
            }
        });
    });

    if ($.fn.dataTable) {
        $.extend(true, $.fn.dataTable.defaults, {
            pageLength: 25,
            scrollX: false,
            autoWidth: false,
            lengthMenu: [[10, 25, 50, 100], [10, 25, 50, 100]],
            language: {
                search: '_INPUT_',
                searchPlaceholder: 'Search…',
                lengthMenu: 'Show _MENU_',
                emptyTable: 'No records found',
                zeroRecords: 'No matching records',
                info: '_START_–_END_ of _TOTAL_',
                infoEmpty: '0 records',
                paginate: { previous: '‹', next: '›' }
            },
            dom:
                "<'dt-toolbar'<'dt-length'l><'dt-search'f>>" +
                "<'dt-table'tr>" +
                "<'dt-footer'<'dt-info'i><'dt-paginate'p>>"
        });
    }

    APP.post = function (url, data, options) {
        options = options || {};
        return $.ajax($.extend({
            url: url,
            method: 'POST',
            data: data || {},
            dataType: 'json'
        }, options));
    };

    APP.get = function (url, data, options) {
        options = options || {};
        return $.ajax($.extend({
            url: url,
            method: 'GET',
            data: data || {},
            dataType: 'json'
        }, options));
    };

    APP.showModal = function (selector) {
        var el = typeof selector === 'string' ? document.querySelector(selector) : selector;
        if (!el) return null;
        if (window.bootstrap && bootstrap.Modal) {
            var instance = bootstrap.Modal.getOrCreateInstance(el);
            instance.show();
            return instance;
        }
        $(el).addClass('show').css('display', 'block').attr('aria-modal', 'true');
        return null;
    };

    APP.hideModal = function (selector) {
        var el = typeof selector === 'string' ? document.querySelector(selector) : selector;
        if (!el) return;
        if (window.bootstrap && bootstrap.Modal) {
            var instance = bootstrap.Modal.getInstance(el);
            if (instance) instance.hide();
            return;
        }
        $(el).removeClass('show').css('display', 'none');
    };

    // jQuery-compatible shim for Bootstrap 5 modals
    if (!$.fn.modal) {
        $.fn.modal = function (action) {
            return this.each(function () {
                if (action === 'show') APP.showModal(this);
                else if (action === 'hide') APP.hideModal(this);
            });
        };
    }

    // Password visibility toggle
    $(document).on('click', '.toggle-secret', function () {
        var $input = $($(this).data('target'));
        if (!$input.length) {
            $input = $(this).closest('.input-group').find('input');
        }
        var type = $input.attr('type') === 'password' ? 'text' : 'password';
        $input.attr('type', type);
        $(this).find('i').toggleClass('fa-eye fa-eye-slash');
    });

    // Auto-dismiss alerts
    setTimeout(function () {
        $('.alert-dismissible').not('.alert-permanent').fadeOut(400, function () {
            $(this).remove();
        });
    }, 6000);

    /**
     * Live header bell + browser Notification API (no page refresh).
     */
    var LiveNotif = {
        timer: null,
        lastId: 0,
        knownIds: {},
        pollMsVisible: 2500,
        pollMsHidden: 8000,
        titleBase: document.title,
        flashTimer: null,
        soundReady: false
    };

    function notifEsc(str) {
        return $('<div>').text(str == null ? '' : String(str)).html();
    }

    function notifDisplay(n) {
        n = n || {};
        return {
            id: parseInt(n.id, 10) || 0,
            title: n.display_title || n.contact_name || n.title || 'Notification',
            phone: n.display_subtitle || n.contact_phone || '',
            body: n.display_body || n.message || '',
            link: n.link || '#',
            initials: n.avatar_initials || 'N',
            color: n.avatar_color || '#7c3aed',
            created: n.created_at || '',
            type: (n.type || 'info').toLowerCase()
        };
    }

    function notifRelativeTime(raw) {
        if (!raw) return '';
        var t = Date.parse(String(raw).replace(' ', 'T'));
        if (!t) return APP.formatDateTime ? APP.formatDateTime(raw) : '';
        var sec = Math.max(0, Math.floor((Date.now() - t) / 1000));
        if (sec < 45) return 'Just now';
        if (sec < 3600) return Math.floor(sec / 60) + 'm';
        if (sec < 86400) return Math.floor(sec / 3600) + 'h';
        return APP.formatDateTime ? APP.formatDateTime(raw) : '';
    }

    function storageKey(suffix) {
        return 'whstapp_notif_' + suffix;
    }

    LiveNotif.loadKnown = function () {
        try {
            var raw = localStorage.getItem(storageKey('last_id'));
            LiveNotif.lastId = raw ? parseInt(raw, 10) || 0 : 0;
        } catch (e) {
            LiveNotif.lastId = 0;
        }
        // Seed from DOM so first poll does not re-alert existing items
        $('#navNotifList .nav-notif-item').each(function () {
            var id = parseInt($(this).attr('data-id'), 10) || 0;
            if (id > 0) {
                LiveNotif.knownIds[id] = true;
                if (id > LiveNotif.lastId) LiveNotif.lastId = id;
            }
        });
    };

    LiveNotif.saveLastId = function (id) {
        if (id > LiveNotif.lastId) {
            LiveNotif.lastId = id;
            try {
                localStorage.setItem(storageKey('last_id'), String(id));
            } catch (e) { /* ignore */ }
        }
    };

    LiveNotif.renderList = function (items) {
        var $list = $('#navNotifList');
        if (!$list.length) return;
        if (!Array.isArray(items) || !items.length) {
            $list.html(
                '<div class="notif-empty nav-notif-empty">' +
                '<i class="far fa-bell-slash"></i>' +
                '<div class="notif-empty-title">No new notifications</div>' +
                '<div class="notif-empty-sub">New WhatsApp replies will appear here live.</div>' +
                '</div>'
            );
            return;
        }
        var html = '';
        items.slice(0, 10).forEach(function (raw) {
            var n = notifDisplay(raw);
            html +=
                '<a href="' + notifEsc(n.link) + '" class="notif-item nav-notif-item" data-id="' + n.id + '">' +
                '<span class="notif-avatar" style="background:' + notifEsc(n.color) + '">' + notifEsc(n.initials) + '</span>' +
                '<span class="notif-item-body">' +
                '<span class="notif-item-top">' +
                '<span class="notif-item-name">' + notifEsc(n.title) + '</span>' +
                '<span class="notif-item-time">' + notifEsc(notifRelativeTime(n.created)) + '</span>' +
                '</span>' +
                (n.phone ? ('<span class="notif-item-phone">' + notifEsc(n.phone) + '</span>') : '') +
                (n.body ? ('<span class="notif-item-msg">' + notifEsc(n.body) + '</span>') : '') +
                '</span></a>';
            if (n.id > 0) LiveNotif.knownIds[n.id] = true;
        });
        $list.html(html);
    };

    LiveNotif.setBadge = function (count) {
        count = parseInt(count, 10) || 0;
        var $badge = $('#navNotifBadge');
        var $header = $('#navNotifHeader');
        var $mark = $('#navNotifMarkAll');
        if ($badge.length) {
            if (count > 0) {
                $badge.text(count > 99 ? '99+' : String(count)).removeClass('d-none');
            } else {
                $badge.text('0').addClass('d-none').removeClass('is-live');
            }
        }
        if ($header.length) {
            $header.text(count > 0 ? (count + ' unread') : "You're all caught up");
        }
        if ($mark.length) {
            $mark.prop('disabled', count <= 0);
        }
        if (count > 0) {
            document.title = '(' + count + ') ' + LiveNotif.titleBase;
        } else {
            document.title = LiveNotif.titleBase;
            LiveNotif.stopTitleFlash();
        }
        LiveNotif.setInboxBadges(count);
    };

    LiveNotif.pulseBadge = function () {
        var $badge = $('#navNotifBadge');
        if (!$badge.length) return;
        $badge.removeClass('is-live');
        // force reflow so animation can replay
        void $badge[0].offsetWidth;
        $badge.addClass('is-live');
    };

    /** Sidebar Team Inbox + mobile Inbox badge (no page refresh). */
    LiveNotif.setInboxBadges = function (count) {
        count = parseInt(count, 10) || 0;
        var label = count > 99 ? '99+' : String(count);

        var $menuLabel = $('.app-sidebar-item[data-menu-label="team inbox"] .elint-menu-label');
        if ($menuLabel.length) {
            var $menuBadge = $menuLabel.siblings('.elint-menu-badge');
            if (count > 0) {
                if (!$menuBadge.length) {
                    $menuBadge = $('<span class="elint-menu-badge" data-live-badge="inbox"></span>');
                    $menuLabel.after($menuBadge);
                }
                $menuBadge.attr('data-live-badge', 'inbox').text(label);
            } else {
                $menuBadge.remove();
            }
        }

        var $mobileLink = $('.mobile-bottom-nav a[href*="chat"]').first();
        if ($mobileLink.length) {
            var $wrap = $mobileLink.find('.mobile-bottom-nav__icon-wrap');
            if (!$wrap.length) {
                $wrap = $mobileLink;
            }
            var $mobBadge = $wrap.find('.mobile-bottom-nav__badge');
            if (count > 0) {
                if (!$mobBadge.length) {
                    $mobBadge = $('<span class="mobile-bottom-nav__badge" data-live-badge="inbox"></span>');
                    $wrap.append($mobBadge);
                }
                $mobBadge.attr('data-live-badge', 'inbox').text(label);
            } else {
                $mobBadge.remove();
            }
        }
    };

    /** Apply unread count returned from chat mark-read (immediate, no wait for poll). */
    LiveNotif.applyUnreadFromServer = function (count) {
        if (typeof count === 'undefined' || count === null) {
            LiveNotif.poll();
            return;
        }
        LiveNotif.setBadge(count);
        LiveNotif.poll();
    };

    LiveNotif.playBeep = function () {
        try {
            var Ctx = window.AudioContext || window.webkitAudioContext;
            if (!Ctx) return;
            var ctx = new Ctx();
            var o = ctx.createOscillator();
            var g = ctx.createGain();
            o.type = 'sine';
            o.frequency.value = 880;
            g.gain.value = 0.04;
            o.connect(g);
            g.connect(ctx.destination);
            o.start();
            setTimeout(function () {
                o.stop();
                ctx.close();
            }, 140);
        } catch (e) { /* ignore */ }
    };

    LiveNotif.browserSupported = function () {
        return typeof window.Notification !== 'undefined';
    };

    LiveNotif.browserAllowed = function () {
        return LiveNotif.browserSupported() && Notification.permission === 'granted';
    };

    LiveNotif.updateBrowserBtn = function () {
        var $btn = $('#navNotifBrowserBtn');
        if (!$btn.length) return;
        if (!LiveNotif.browserSupported()) {
            $btn.text('Browser alerts not supported').prop('disabled', true);
            return;
        }
        if (Notification.permission === 'granted') {
            $btn.text('Browser alerts on').prop('disabled', true);
        } else if (Notification.permission === 'denied') {
            $btn.text('Alerts blocked in browser').prop('disabled', true);
        } else {
            $btn.text('Enable browser alerts').prop('disabled', false);
        }
    };

    LiveNotif.requestBrowser = function () {
        if (!LiveNotif.browserSupported()) {
            APP.toast('This browser does not support desktop notifications', 'info');
            return;
        }
        Notification.requestPermission().then(function (perm) {
            LiveNotif.updateBrowserBtn();
            if (perm === 'granted') {
                APP.toast('Browser alerts enabled');
                try {
                    localStorage.setItem(storageKey('browser'), '1');
                } catch (e) { /* ignore */ }
            } else {
                APP.toast('Permission not granted', 'warning');
            }
        });
    };

    LiveNotif.showBrowser = function (note) {
        if (!LiveNotif.browserAllowed()) return;
        var n = notifDisplay(note);
        var body = [n.phone, n.body].filter(Boolean).join(' · ');
        var desktop;
        try {
            desktop = new Notification(n.title, {
                body: body || 'New message',
                icon: APP.favicon || undefined,
                tag: 'whstapp-n-' + (n.id || Date.now()),
                renotify: true
            });
        } catch (e) {
            return;
        }
        desktop.onclick = function () {
            window.focus();
            if (n.link && n.link !== '#') {
                window.location.href = n.link;
            }
            try { desktop.close(); } catch (e2) { /* ignore */ }
        };
        setTimeout(function () {
            try { desktop.close(); } catch (e3) { /* ignore */ }
        }, 8000);
    };

    LiveNotif.showLiveBanner = function (note) {
        var $stack = $('#notifLiveStack');
        if (!$stack.length) return;
        var n = notifDisplay(note);
        var $card = $(
            '<div class="notif-live-card" role="status">' +
            '<span class="notif-avatar" style="background:' + notifEsc(n.color) + '">' + notifEsc(n.initials) + '</span>' +
            '<div class="notif-live-copy">' +
            '<div class="notif-live-name">' + notifEsc(n.title) + '</div>' +
            (n.phone ? ('<div class="notif-live-phone">' + notifEsc(n.phone) + '</div>') : '') +
            (n.body ? ('<div class="notif-live-msg">' + notifEsc(n.body) + '</div>') : '') +
            '<div class="notif-live-meta"><i class="fab fa-whatsapp"></i> New message</div>' +
            '</div></div>'
        );
        $card.on('click', function () {
            if (n.link && n.link !== '#') {
                window.location.href = n.link;
            }
        });
        $stack.prepend($card);
        setTimeout(function () {
            $card.addClass('is-out');
            setTimeout(function () { $card.remove(); }, 220);
        }, 5200);
        // Keep stack short
        $stack.children('.notif-live-card').slice(3).remove();
    };

    LiveNotif.flashTitle = function (preview) {
        LiveNotif.stopTitleFlash();
        var alt = preview ? ('● ' + preview) : '● New message';
        var flip = false;
        LiveNotif.flashTimer = setInterval(function () {
            document.title = flip ? LiveNotif.titleBase : alt;
            flip = !flip;
        }, 1200);
    };

    LiveNotif.stopTitleFlash = function () {
        if (LiveNotif.flashTimer) {
            clearInterval(LiveNotif.flashTimer);
            LiveNotif.flashTimer = null;
        }
    };

    LiveNotif.handleFresh = function (fresh) {
        if (!Array.isArray(fresh) || !fresh.length) return;
        var reallyNew = [];
        fresh.forEach(function (n) {
            var id = parseInt(n.id, 10) || 0;
            if (id > 0 && !LiveNotif.knownIds[id]) {
                LiveNotif.knownIds[id] = true;
                reallyNew.push(n);
                LiveNotif.saveLastId(id);
            }
        });
        if (!reallyNew.length) return;

        reallyNew.forEach(function (n) {
            LiveNotif.showBrowser(n);
            LiveNotif.showLiveBanner(n);
        });

        var last = notifDisplay(reallyNew[reallyNew.length - 1]);
        LiveNotif.playBeep();
        LiveNotif.pulseBadge();
        if (document.hidden) {
            LiveNotif.flashTitle(last.title || 'New notification');
        }
    };

    LiveNotif.poll = function () {
        if (!$('#navNotifWrap').length) return;
        APP.get((APP.baseUrl || '') + '/notifications/poll', {
            since_id: LiveNotif.lastId || 0,
            limit: 12
        }).done(function (res) {
            var data = (res && res.data) ? res.data : res;
            if (!data) return;
            if (data.poll_hint_ms) {
                var hint = parseInt(data.poll_hint_ms, 10) || 0;
                if (hint >= 1500 && hint <= 10000 && hint !== LiveNotif.pollMsVisible) {
                    LiveNotif.pollMsVisible = hint;
                    if (!document.hidden) LiveNotif.schedule();
                }
            }
            LiveNotif.setBadge(data.unread_count || 0);
            if (Array.isArray(data.items)) {
                LiveNotif.renderList(data.items);
            }
            if (Array.isArray(data.fresh) && data.fresh.length) {
                LiveNotif.handleFresh(data.fresh);
            }
            if (data.max_id) {
                LiveNotif.saveLastId(parseInt(data.max_id, 10) || 0);
            }
        });
    };

    LiveNotif.schedule = function () {
        if (LiveNotif.timer) {
            clearInterval(LiveNotif.timer);
            LiveNotif.timer = null;
        }
        if (!$('#navNotifWrap').length || APP.liveNotif === false) return;
        var ms = document.hidden ? LiveNotif.pollMsHidden : LiveNotif.pollMsVisible;
        LiveNotif.timer = setInterval(LiveNotif.poll, ms);
    };

    LiveNotif.start = function () {
        if (!$('#navNotifWrap').length) return;
        LiveNotif.titleBase = document.title.replace(/^\(\d+\)\s*/, '');
        LiveNotif.loadKnown();
        LiveNotif.updateBrowserBtn();
        LiveNotif.poll();
        LiveNotif.schedule();

        document.addEventListener('visibilitychange', function () {
            if (!document.hidden) {
                LiveNotif.stopTitleFlash();
                document.title = LiveNotif.titleBase;
                LiveNotif.poll();
            }
            LiveNotif.schedule();
        });

        $(document).on('click', '#navNotifBrowserBtn', function (e) {
            e.preventDefault();
            e.stopPropagation();
            LiveNotif.requestBrowser();
        });

        $(document).on('click', '#navNotifMarkAll', function (e) {
            e.preventDefault();
            e.stopPropagation();
            APP.post((APP.baseUrl || '') + '/notifications/read-all', {}).done(function () {
                LiveNotif.setBadge(0);
                LiveNotif.renderList([]);
                APP.toast('All notifications marked read');
            });
        });

        $(document).on('click', '#navNotifList .nav-notif-item', function () {
            var id = parseInt($(this).attr('data-id'), 10) || 0;
            var $item = $(this);
            if (id > 0) {
                // Optimistic: drop count immediately (no refresh)
                var cur = parseInt(String($('#navNotifBadge').text()).replace('+', ''), 10) || 0;
                if (!$('#navNotifBadge').hasClass('d-none') && cur > 0) {
                    LiveNotif.setBadge(cur - 1);
                }
                $item.remove();
                if (!$('#navNotifList .nav-notif-item').length) {
                    LiveNotif.renderList([]);
                }
                APP.post((APP.baseUrl || '') + '/notifications/' + id + '/read', {}).done(function () {
                    LiveNotif.poll();
                }).fail(function () {});
            }
        });
    };

    function initNavTimezoneClock() {
        var $wrap = $('#navAppClock');
        if (!$wrap.length) return;

        var tz = String($wrap.attr('data-timezone') || APP.timezone || 'UTC').trim() || 'UTC';
        var $time = $('#navAppClockTime');
        var $date = $('#navAppClockDate');
        var timeFmt;
        var dateFmt;

        try {
            timeFmt = new Intl.DateTimeFormat('en-GB', {
                timeZone: tz,
                hour: '2-digit',
                minute: '2-digit',
                second: '2-digit',
                hour12: false
            });
            dateFmt = new Intl.DateTimeFormat('en-GB', {
                timeZone: tz,
                day: '2-digit',
                month: 'short',
                year: 'numeric'
            });
        } catch (e) {
            tz = 'UTC';
            $wrap.attr('data-timezone', tz).attr('title', tz);
            timeFmt = new Intl.DateTimeFormat('en-GB', {
                timeZone: 'UTC',
                hour: '2-digit',
                minute: '2-digit',
                second: '2-digit',
                hour12: false
            });
            dateFmt = new Intl.DateTimeFormat('en-GB', {
                timeZone: 'UTC',
                day: '2-digit',
                month: 'short',
                year: 'numeric'
            });
        }

        function tick() {
            var now = new Date();
            $time.text(timeFmt.format(now));
            $date.text(dateFmt.format(now));
        }

        tick();
        if ($wrap.data('clock-timer')) {
            clearInterval($wrap.data('clock-timer'));
        }
        $wrap.data('clock-timer', setInterval(tick, 1000));
    }

    function applyWaIdentity(identity) {
        if (!identity || typeof identity !== 'object') return;
        var name = String(identity.display_name || identity.verified_name || '').trim();
        var phone = String(identity.phone || identity.display_phone || '').trim();
        var pic = String(identity.profile_picture_url || '').trim();

        if (name) {
            $('.js-wa-name').text(name);
            $('.js-wa-avatar').attr('alt', name);
        }
        if (phone) {
            $('.js-wa-phone').text(phone);
            $('.js-wa-phone-row').removeClass('d-none');
        }
        if (pic) {
            $('.js-wa-avatar').attr('src', pic);
        }
        APP.waAccount = Object.assign({}, APP.waAccount || {}, identity, {
            display_name: name || (APP.waAccount && APP.waAccount.display_name) || '',
            phone: phone || (APP.waAccount && APP.waAccount.phone) || '',
            profile_picture_url: pic || (APP.waAccount && APP.waAccount.profile_picture_url) || ''
        });
    }

    function refreshWaIdentity(force) {
        if (String(APP.whatsappProvider || '') !== 'meta' && !force) return;
        APP.get((APP.baseUrl || '') + '/wa-identity/refresh', { force: force ? 1 : 0 })
            .done(function (res) {
                var data = (res && res.data) ? res.data : res;
                if (!data) return;
                applyWaIdentity(data);
                APP.waIdentityNeedsRefresh = false;
            })
            .fail(function () {
                // Keep cached chrome; Settings → Test Meta can repair.
            });
    }

    function refreshWaIdentityIfNeeded() {
        if (String(APP.whatsappProvider || '') !== 'meta') return;
        if (!APP.waIdentityNeedsRefresh && APP.waAccount && APP.waAccount.profile_picture_url) return;
        refreshWaIdentity(false);
    }

    APP.applyWaIdentity = applyWaIdentity;
    APP.refreshWaIdentity = refreshWaIdentity;

    $(function () {
        initNavTimezoneClock();
        LiveNotif.start();
        refreshWaIdentityIfNeeded();

        function refreshLucideIcons() {
            if (window.lucide && typeof window.lucide.createIcons === 'function') {
                window.lucide.createIcons();
            }
        }
        refreshLucideIcons();

        /** AdminLTE sometimes writes inline margin-left; strip it on tablet/phone. */
        function clearMobileSidebarOffset() {
            if (window.innerWidth > 991) return;
            document.querySelectorAll('.content-wrapper, .main-header, .main-footer').forEach(function (el) {
                if (el.style && el.style.marginLeft) {
                    el.style.marginLeft = '';
                }
            });
        }
        clearMobileSidebarOffset();
        $(window).on('resize.mobileSidebar', clearMobileSidebarOffset);
        $(document).on('click', '[data-widget="pushmenu"]', function () {
            window.setTimeout(clearMobileSidebarOffset, 0);
            window.setTimeout(clearMobileSidebarOffset, 220);
        });

        // Sidebar: click parent to expand/collapse submenu (Team Inbox, Contacts, …)
        $(document).on('click', '.app-sidebar-item.has-tree > .nav-link.app-sidebar-toggle', function (e) {
            var $link = $(this);
            var $item = $link.closest('.app-sidebar-item');
            var $tree = $item.children('.app-sidebar-tree');
            if (!$tree.length) return;

            e.preventDefault();
            e.stopPropagation();

            var willOpen = !$item.hasClass('menu-open');
            $item.siblings('.has-tree.menu-open').removeClass('menu-open');
            $item.toggleClass('menu-open', willOpen);
            refreshLucideIcons();
        });

        // Menu search filter
        $(document).on('input', '#sidebarMenuSearch', function () {
            var q = String($(this).val() || '').trim().toLowerCase();
            var $nav = $('#elintSidebarNav');
            if (!$nav.length) return;

            $nav.find('.app-sidebar-item').each(function () {
                var $item = $(this);
                var label = String($item.attr('data-menu-label') || '').toLowerCase();
                var childHit = false;
                $item.find('[data-menu-label]').each(function () {
                    var childLabel = String($(this).attr('data-menu-label') || '').toLowerCase();
                    if (q !== '' && childLabel.indexOf(q) !== -1) {
                        childHit = true;
                    }
                });
                var match = q === '' || label.indexOf(q) !== -1 || childHit;
                $item.toggleClass('is-filtered-out', !match);
                if (match && childHit && q !== '') {
                    $item.addClass('menu-open');
                }
            });

            $nav.find('.app-sidebar-group-title').each(function () {
                var $title = $(this);
                var $list = $title.next('.app-sidebar-list');
                var visible = $list.find('.app-sidebar-item:not(.is-filtered-out)').length > 0;
                $title.toggleClass('is-filtered-out', !visible);
            });
        });
    });

    APP.LiveNotif = LiveNotif;

})(window, jQuery);
