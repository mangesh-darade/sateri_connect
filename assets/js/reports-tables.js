/**
 * Report screen tables: server-side DataTables (search, sort and paging run in SQL).
 * Each table carries data-dt-report="<key>", data-url="<json endpoint incl. date/campaign filters>"
 * and optional data-symbol (currency). Requires AppDT (app-datatable.js).
 */
(function (window, $) {
    'use strict';

    if (!$ || !window.AppDT) return;

    var esc = AppDT.esc;
    var STATUS = {
        pending: 'warning', sent: 'primary', delivered: 'success', read: 'success', failed: 'danger',
        queued: 'info', processing: 'info', completed: 'success', cancelled: 'dark', canceled: 'dark'
    };

    function num(v) { return Number(v || 0).toLocaleString(); }
    function zero(v, cls) {
        v = Number(v || 0);
        if (v > 0) return cls ? '<span class="' + cls + '">' + num(v) + '</span>' : num(v);
        return '<span class="rpt-zero">0</span>';
    }
    function badge(status, label) {
        var key = String(status || 'unknown').toLowerCase().replace(/[\s-]+/g, '_');
        return '<span class="badge bg-' + (STATUS[key] || 'secondary') + '">' + esc(label || (key.charAt(0).toUpperCase() + key.slice(1).replace(/_/g, ' '))) + '</span>';
    }
    function rate(r, withBar, color) {
        var v = Number(r || 0);
        return '<span class="rpt-rate-bar"><i style="width:' + Math.min(100, v).toFixed(1) + '%' + (color ? ';--c:' + color : '') + '"></i></span><small>' + esc(v) + '%</small>';
    }
    function col(data, opts) { return $.extend({ data: data }, opts || {}); }

    var DEFS = {
        'wa-campaigns': {
            order: [[1, 'desc']], empty: 'No campaign sends in this date range.',
            columns: [
                { data: null, className: 'fw-semibold', render: function (r) { return '<a href="' + esc(r.url) + '">' + esc(r.name) + '</a>'; } },
                { data: 'sent_count', className: 'num', render: function (v) { return num(v); } },
                { data: null, className: 'num', render: function (r) { return '<div class="rpt-rate">' + num(r.delivered_count) + ' ' + rate(r.delivery_rate) + '</div>'; } },
                { data: 'read_count', className: 'num', render: function (v) { return zero(v); } },
                { data: 'failed_count', className: 'num', render: function (v) { return zero(v, 'rpt-bad'); } },
                { data: 'pending_count', className: 'num', render: function (v) { return zero(v); } },
                { data: 'reply_count', className: 'num', orderable: false, render: function (v) { return zero(v); } },
                { data: null, orderable: false, className: 'text-end text-nowrap', render: function (r) { return '<a href="' + esc(r.focus_url) + '" class="btn btn-sm btn-outline-secondary py-0">Focus</a>'; } }
            ]
        },
        'wa-recipients': {
            order: [[4, 'desc']], empty: 'No recipients in this date range.',
            columns: [
                { data: null, className: 'fw-semibold', render: function (r) { return esc(r.name !== '' ? r.name : 'Unknown'); } },
                { data: null, render: function (r) { return esc(r.mobile !== '' ? r.mobile : '—'); } },
                { data: null, render: function (r) { return badge(r.status); } },
                { data: null, render: function (r) {
                    if (!(r.campaign_id > 0)) return '—';
                    return '<a href="' + esc(r.campaign_url) + '">' + esc(r.campaign_name !== '' ? r.campaign_name : 'Campaign #' + r.campaign_id) + '</a>';
                } },
                { data: null, className: 'small text-muted text-nowrap', render: function (r) { return esc(r.sent_at); } },
                { data: null, className: 'small text-muted text-nowrap', render: function (r) { return esc(r.delivered_at); } },
                { data: null, className: 'small text-muted text-nowrap', render: function (r) { return esc(r.read_at); } },
                { data: null, orderable: false, className: 'small text-danger', render: function (r) { return esc(r.error_message); } }
            ]
        },
        'email-campaigns': {
            order: [[1, 'desc']], empty: 'No emails in this date range.',
            columns: [
                { data: null, className: 'fw-semibold', render: function (r) { return esc(r.name); } },
                { data: 'sent_count', className: 'num', render: function (v) { return num(v); } },
                { data: 'queued_count', className: 'num', render: function (v) { return zero(v); } },
                { data: 'failed_count', className: 'num', render: function (v) { return zero(v, 'rpt-bad'); } },
                { data: 'opened_count', className: 'num', render: function (v) { return zero(v); } },
                { data: 'clicked_count', className: 'num', render: function (v) { return zero(v); } },
                { data: null, className: 'num', render: function (r) { return '<div class="rpt-rate">' + rate(r.open_rate, true, '#0284c7') + '</div>'; } },
                { data: null, orderable: false, className: 'text-end text-nowrap', render: function (r) {
                    return r.focus_url ? '<a href="' + esc(r.focus_url) + '" class="btn btn-sm btn-outline-secondary py-0">Focus</a>' : '';
                } }
            ]
        },
        'email-logs': {
            order: [[5, 'desc']], empty: 'No email sends in this date range.',
            columns: [
                { data: null, render: function (r) {
                    var h = '<span class="fw-semibold">' + esc(r.to_email !== '' ? r.to_email : '—') + '</span>';
                    if (r.kind) h += '<div class="small text-muted">' + esc(r.kind) + '</div>';
                    if (r.id > 0) h += '<a href="#" class="js-email-recipients small text-decoration-none" data-log-id="' + esc(r.id) + '"><i class="fas fa-users me-1"></i>Recipients</a>';
                    return h;
                } },
                { data: null, className: 'small', render: function (r) {
                    return '<div class="text-truncate" style="max-width:200px" title="' + esc(r.subject) + '">' + esc(r.subject !== '' ? r.subject : '—') + '</div>';
                } },
                { data: null, render: function (r) { return badge(r.status, r.status_label); } },
                { data: 'open_count', className: 'num', render: function (v) { return zero(v); } },
                { data: 'click_count', className: 'num', render: function (v) { return zero(v); } },
                { data: null, className: 'small text-muted text-nowrap', render: function (r) { return esc(r.created_at); } }
            ]
        },
        'templates': {
            order: [[4, 'desc']], empty: 'No template broadcasts in this period.',
            columns: [
                { data: null, render: function (r) { return '<strong>' + esc(r.name) + '</strong> <span class="text-muted small">' + esc(r.language) + '</span>'; } },
                { data: null, render: function (r) { return esc(r.category); } },
                { data: null, render: function (r) { return esc(r.status); } },
                { data: 'campaigns', className: 'text-end', render: function (v) { return num(v); } },
                { data: 'sent', className: 'text-end', render: function (v) { return num(v); } },
                { data: 'delivered', className: 'text-end', render: function (v) { return num(v); } },
                { data: 'read', className: 'text-end', render: function (v) { return num(v); } },
                { data: 'failed', className: 'text-end', render: function (v) { return num(v); } },
                { data: null, className: 'text-end', render: function (r) { return esc(r.delivery_rate) + '%'; } },
                { data: null, className: 'text-end', render: function (r) { return esc(r.read_rate) + '%'; } }
            ]
        },
        'deposits': {
            order: [[0, 'desc']], empty: 'No deposits in this period.',
            columns: [
                { data: null, className: 'text-nowrap', render: function (r) { return esc(r.created_at); } },
                { data: null, render: function (r) { return esc(r.description); } },
                { data: null, className: 'text-end text-success', render: function (r) { return '+' + esc(r.amount); } },
                { data: null, className: 'text-end', render: function (r) { return esc(r.balance_after); } }
            ]
        },
        'transactions': {
            order: [[0, 'desc']], empty: 'No transactions match these filters.',
            columns: [
                { data: null, className: 'text-nowrap', render: function (r) { return esc(r.created_at); } },
                { data: null, render: function (r) { return esc(r.type_label); } },
                { data: null, render: function (r) { return esc(r.category); } },
                { data: null, render: function (r) { return esc(r.source); } },
                { data: null, className: 'text-nowrap', render: function (r) {
                    if (!r.recipient_mobile) return '—';
                    return esc(r.recipient_name) + ' <span class="text-muted small">' + esc(r.recipient_mobile) + '</span>';
                } },
                { data: null, render: function (r) {
                    if (!r.campaign_name) return '—';
                    return esc(r.campaign_name) + (r.template_name ? ' <span class="text-muted small">· ' + esc(r.template_name) + '</span>' : '');
                } },
                { data: null, render: function (r) { return r.msg_status ? esc(r.msg_status) : '—'; } },
                { data: null, render: function (r) {
                    return esc(r.description) + (r.country_code ? ' <span class="text-muted small">· +' + esc(r.country_code) + '</span>' : '');
                } },
                { data: null, className: 'text-end', render: function (r) {
                    return '<span class="' + (r['in'] ? 'text-success' : '') + '">' + (r['in'] ? '+' : '−') + esc(r.amount) + '</span>';
                } },
                { data: null, className: 'text-end', render: function (r) { return esc(r.balance_after); } }
            ]
        }
    };

    $('table[data-dt-report]').each(function () {
        var $t = $(this);
        var def = DEFS[String($t.data('dt-report'))];
        if (!def) return;
        AppDT.table($t, {
            url: String($t.data('url')),
            order: def.order,
            emptyText: def.empty,
            pageLength: 25,
            columns: def.columns
        });
    });
})(window, window.jQuery);
