/**
 * Global app helpers — CSRF, DataTables, SweetAlert, toasts, chat utils
 */
(function (window, $) {
    'use strict';

    var APP = window.APP || {};
    window.APP = APP;

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
        pollMsVisible: 4000,
        pollMsHidden: 12000,
        titleBase: document.title,
        flashTimer: null,
        soundReady: false
    };

    function notifEsc(str) {
        return $('<div>').text(str == null ? '' : String(str)).html();
    }

    function notifIconClass(type) {
        type = (type || 'info').toLowerCase();
        if (type === 'error') return 'exclamation-circle text-danger';
        if (type === 'chat') return 'comment text-success';
        return 'info-circle text-primary';
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
            $list.html('<span class="dropdown-item text-muted nav-notif-empty">No new notifications</span><div class="dropdown-divider"></div>');
            return;
        }
        var html = '';
        items.slice(0, 8).forEach(function (n) {
            var id = parseInt(n.id, 10) || 0;
            html +=
                '<a href="' + notifEsc(n.link || '#') + '" class="dropdown-item nav-notif-item" data-id="' + id + '">' +
                '<i class="fas fa-' + notifIconClass(n.type) + ' me-2"></i>' +
                notifEsc(n.title || 'Notification') +
                (n.message ? ('<div class="small text-muted text-truncate" style="max-width:16rem">' + notifEsc(n.message) + '</div>') : '') +
                '<span class="float-end text-muted text-sm">' + notifEsc(n.created_at || '') + '</span>' +
                '</a><div class="dropdown-divider"></div>';
            if (id > 0) LiveNotif.knownIds[id] = true;
        });
        $list.html(html);
    };

    LiveNotif.setBadge = function (count) {
        count = parseInt(count, 10) || 0;
        var $badge = $('#navNotifBadge');
        var $header = $('#navNotifHeader');
        if ($badge.length) {
            if (count > 0) {
                $badge.text(String(count)).removeClass('d-none');
            } else {
                $badge.text('0').addClass('d-none');
            }
        }
        if ($header.length) {
            $header.text(count + ' Notification' + (count === 1 ? '' : 's'));
        }
        if (count > 0) {
            document.title = '(' + count + ') ' + LiveNotif.titleBase;
        } else {
            document.title = LiveNotif.titleBase;
            LiveNotif.stopTitleFlash();
        }
        LiveNotif.setInboxBadges(count);
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
            $btn.text('Browser alerts blocked — allow in browser settings').prop('disabled', true);
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
        // Prefer when tab is in background; still allow soft toast when focused
        var title = note.title || (APP.appName || 'Notification');
        var body = note.message || '';
        var n;
        try {
            n = new Notification(title, {
                body: body,
                icon: APP.favicon || undefined,
                tag: 'whstapp-n-' + (note.id || Date.now()),
                renotify: true
            });
        } catch (e) {
            return;
        }
        n.onclick = function () {
            window.focus();
            if (note.link) {
                window.location.href = note.link;
            }
            try { n.close(); } catch (e2) { /* ignore */ }
        };
        setTimeout(function () {
            try { n.close(); } catch (e3) { /* ignore */ }
        }, 8000);
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
        });

        var last = reallyNew[reallyNew.length - 1];
        LiveNotif.playBeep();
        if (document.hidden) {
            LiveNotif.flashTitle(last.title || 'New notification');
        } else if (window.Swal) {
            APP.toast(last.title || 'New notification', 'info');
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
                var cur = parseInt($('#navNotifBadge').text(), 10) || 0;
                if (!$('#navNotifBadge').hasClass('d-none') && cur > 0) {
                    LiveNotif.setBadge(cur - 1);
                }
                $item.next('.dropdown-divider').remove();
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

    $(function () {
        initNavTimezoneClock();
        LiveNotif.start();

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
