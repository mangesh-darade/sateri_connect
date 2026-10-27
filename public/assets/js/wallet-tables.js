/**
 * Wallet page tables (ledger + online recharges). Both are server-side DataTables: search, sort,
 * type filter and paging run in SQL, so a wallet with thousands of rows loads one page at a time.
 */
(function (window, $) {
    'use strict';

    if (!$ || !$.fn.DataTable) return;

    function esc(s) {
        return $('<div>').text(s == null ? '' : String(s)).html();
    }

    var LANG = {
        search: '',
        lengthMenu: 'Show _MENU_',
        info: 'Showing _START_ to _END_ of _TOTAL_',
        infoEmpty: 'No records',
        infoFiltered: '(filtered from _MAX_)',
        processing: 'Loading…',
        paginate: { previous: '‹', next: '›' }
    };

    function fail($t) {
        return function () {
            $t.closest('.card-body').find('.wallet-dt-error').remove();
            $t.closest('.table-responsive').before('<div class="alert alert-danger py-2 small wallet-dt-error">Could not load the data. Reload the page and try again.</div>');
        };
    }

    // ---- Ledger -----------------------------------------------------------------------------
    var $tx = $('#walletTx');
    if ($tx.length) {
        var sym = String($tx.data('symbol') || '');
        var $type = $('#walletTxType');
        var tx = $tx.DataTable({
            serverSide: true,
            processing: true,
            searchDelay: 350,
            order: [[0, 'desc']],
            pageLength: 10,
            lengthMenu: [10, 25, 50, 100],
            autoWidth: false,
            ajax: {
                url: String($tx.data('url')),
                data: function (d) { d.type = $type.val(); },
                error: fail($tx)
            },
            columns: [
                { data: null, className: 'text-nowrap', render: function (r, t) {
                    return t === 'display' ? '<div class="fw-semibold">' + esc(r.date) + '</div><small class="text-muted">' + esc(r.time) + '</small>' : r.date;
                } },
                { data: null, render: function (r, t) {
                    if (t !== 'display') return r.label;
                    return '<span class="badge ' + (r.type === 'debit' ? 'text-bg-danger-subtle text-danger' : (r.type === 'refund' ? 'text-bg-info-subtle text-info' : 'text-bg-success-subtle text-success')) + '">' + esc(r.label) + '</span>';
                } },
                { data: 'details', render: function (v, t) { return t === 'display' ? esc(v) : v; } },
                { data: null, className: 'text-end text-nowrap', render: function (r, t) {
                    if (t !== 'display') return r.amount;
                    return '<span class="' + (r['in'] ? 'text-success' : 'text-danger') + ' fw-semibold">' + (r['in'] ? '+' : '−') + esc(r.symbol || sym) + esc(r.amount) + '</span>';
                } },
                { data: null, className: 'text-end text-nowrap', render: function (r, t) { return t === 'display' ? esc(r.symbol || sym) + esc(r.balance) : r.balance; } }
            ],
            language: $.extend({}, LANG, {
                searchPlaceholder: 'Search note or amount',
                emptyTable: 'No transactions yet.',
                zeroRecords: 'No transactions match your search.',
                info: 'Showing _START_ to _END_ of _TOTAL_ transactions'
            }),
            drawCallback: function () { $tx.closest('.card-body').find('.wallet-dt-error').remove(); }
        });
        $type.on('change', function () { tx.ajax.reload(); });
    }

    // ---- Online recharges / invoices ---------------------------------------------------------
    var $pay = $('#walletPay');
    if ($pay.length) {
        var psym = String($pay.data('symbol') || '');
        $pay.DataTable({
            serverSide: true,
            processing: true,
            searchDelay: 350,
            order: [[0, 'desc']],
            pageLength: 10,
            lengthMenu: [10, 25, 50],
            autoWidth: false,
            ajax: { url: String($pay.data('url')), error: fail($pay) },
            columns: [
                { data: null, className: 'text-nowrap', render: function (r, t) {
                    return t === 'display' ? '<div class="fw-semibold">' + esc(r.date) + '</div><small class="text-muted">' + esc(r.time) + '</small>' : r.date;
                } },
                { data: 'invoice', render: function (v, t) { return t === 'display' ? esc(v) : v; } },
                { data: 'credit', className: 'text-end text-nowrap', render: function (v, t) { return t === 'display' ? esc(psym) + esc(v) : v; } },
                { data: 'tax', className: 'text-end text-nowrap', render: function (v, t) { return t === 'display' ? esc(psym) + esc(v) : v; } },
                { data: 'total', className: 'text-end text-nowrap', render: function (v, t) { return t === 'display' ? '<strong>' + esc(psym) + esc(v) + '</strong>' : v; } }
            ],
            language: $.extend({}, LANG, {
                searchPlaceholder: 'Search invoice or amount',
                emptyTable: 'No online recharges yet.',
                zeroRecords: 'No invoices match your search.',
                info: 'Showing _START_ to _END_ of _TOTAL_ invoices'
            }),
            drawCallback: function () { $pay.closest('.card-body').find('.wallet-dt-error').remove(); }
        });
    }
})(window, window.jQuery);
