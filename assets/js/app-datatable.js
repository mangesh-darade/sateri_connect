/**
 * AppDT — one place to build a server-side DataTable (search, sort, filters, paging run in SQL).
 * Works in the main app (global DataTables defaults come from app.js) and on platform pages
 * (see app/Views/partials/platform_dt_assets.php).
 *
 *   AppDT.table('#queueTable', {
 *       url: '<?= site_url('queue/data') ?>',
 *       order: [[0, 'desc']],
 *       filters: { status: '#queueStatus' },      // request param => select/input selector; change reloads the table
 *       columns: [
 *           { data: 'id', title: '#' },
 *           { data: null, render: function (r) { return AppDT.esc(r.name); } },
 *           { data: null, orderable: false, render: function (r) { return '<a href="' + AppDT.esc(r.url) + '">Open</a>'; } }
 *       ]
 *   });
 *
 * Always escape with AppDT.esc() when building HTML in render().
 */
(function (window, $) {
    'use strict';

    if (!$ || !$.fn.DataTable) return;

    // Safe for element text AND attribute values (quotes are escaped too).
    function esc(s) {
        return $('<div>').text(s == null ? '' : String(s)).html()
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function money(v, symbol) {
        return esc(symbol || '') + esc(v);
    }

    function table(selector, opts) {
        var $t = $(selector);
        if (!$t.length) return null;
        opts = opts || {};
        var filters = opts.filters || {};
        var errId = 'appdt-error-' + String($t.attr('id') || Math.random()).replace(/\W/g, '');

        function clearError() { $('#' + errId).remove(); }

        var cfg = $.extend(true, {
            serverSide: true,
            processing: true,
            searchDelay: 350,
            autoWidth: false,
            pageLength: 25,
            lengthMenu: [10, 25, 50, 100],
            language: {
                processing: 'Loading…',
                emptyTable: opts.emptyText || 'No records found',
                zeroRecords: 'No matching records',
                paginate: { previous: '‹', next: '›' }
            }
        }, opts);
        delete cfg.filters;
        delete cfg.emptyText;
        delete cfg.url;

        cfg.ajax = {
            url: opts.url,
            data: function (d) {
                $.each(filters, function (param, sel) {
                    var v = $(sel).val();
                    if (v !== null && v !== undefined && v !== '') d[param] = v;
                });
            },
            error: function () {
                clearError();
                $t.closest('.dataTables_wrapper, .table-responsive, .platform-table-wrap').first()
                    .before('<div id="' + errId + '" class="appdt-error" role="alert">Could not load the data. Reload the page and try again.</div>');
            }
        };

        var userDraw = cfg.drawCallback;
        cfg.drawCallback = function (settings) {
            clearError();
            if (typeof userDraw === 'function') userDraw.call(this, settings);
        };

        var dt = $t.DataTable(cfg);
        $.each(filters, function (param, sel) {
            var timer = null;
            // Debounced so typing in a text filter does not fire one request per keystroke.
            $(document).on('change input', sel, function () {
                clearTimeout(timer);
                timer = setTimeout(function () { dt.ajax.reload(); }, 300);
            });
        });

        return dt;
    }

    window.AppDT = { table: table, esc: esc, escAttr: esc, money: money };
})(window, window.jQuery);
