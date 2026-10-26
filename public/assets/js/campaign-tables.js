/**
 * Campaign screens: every table is a server-side DataTable (search, sort, status filter and paging run in SQL).
 *   - #campaignsTable          campaigns hub (one channel at a time), filters #campaignStatus / #campaignSearch / #campaignSort
 *   - #campaignContactsTable   WhatsApp campaign recipients (campaigns/show)
 *   - #recipientsTable         email campaign recipients (campaigns/show_email)
 *   - #emCampaignsTable        Email Manager > Campaign Broadcasts
 * All HTML built here is escaped with AppDT.esc(). Row buttons use delegated handlers because DataTables renders rows.
 */
(function (window, $) {
    'use strict';

    if (!$ || !window.AppDT) return;

    var esc = window.AppDT.esc;

    function toast(msg, type) {
        if (window.APP && typeof window.APP.toast === 'function') {
            window.APP.toast(msg, type || 'success');
        } else {
            window.alert(msg);
        }
    }

    function dash(v) {
        return v ? esc(v) : '<span class="text-muted">—</span>';
    }

    // ---- Campaigns hub --------------------------------------------------------------------------
    function hub() {
        var $t = $('#campaignsTable');
        if (!$t.length) return null;
        var channel = String($t.data('channel') || 'whatsapp');
        var sortMap = { latest: [8, 'desc'], oldest: [8, 'asc'], name: [0, 'asc'] };
        var order = sortMap[String($t.data('sort') || 'latest')] || sortMap.latest;

        var dt = window.AppDT.table('#campaignsTable', {
            // Channel is fixed per page (the tabs navigate), so it travels in the URL.
            url: String($t.data('url')) + '?channel=' + encodeURIComponent(channel),
            search: { search: $.trim($('#campaignSearch').val()) },
            filters: { status: '#campaignStatus' },
            order: [order],
            emptyText: String($t.data('emptyText') || 'No campaigns found'),
            columns: [
                { data: null, render: function (r) {
                    var h = '<a class="fw-semibold text-decoration-none" href="' + esc(r.view_url) + '">' + esc(r.name) + '</a>';
                    if (r.created_at) h += '<div class="text-muted small">' + esc(r.created_at) + '</div>';
                    return h;
                } },
                { data: null, orderable: false, render: function (r) {
                    return r.label
                        ? '<span class="badge rounded-pill bg-light text-dark border px-2 py-1"><i class="fas fa-tag me-1 text-muted"></i>' + esc(r.label) + '</span>'
                        : '<span class="text-muted">—</span>';
                } },
                { data: null, className: 'text-muted small', render: function (r) { return esc(r.type || '—'); } },
                { data: null, orderable: false, render: function (r) {
                    return '<span class="badge text-bg-light border">' + (r.channel === 'email' ? 'Email' : 'WhatsApp') + '</span>';
                } },
                { data: null, render: function (r) { return r.status_badge || esc(r.status); } },
                { data: 'contacts' },
                { data: 'sent' },
                { data: null, orderable: false, className: 'text-end', render: function (r) {
                    var h = '<div class="table-actions justify-content-end">'
                        + '<a class="btn btn-sm btn-outline-secondary" href="' + esc(r.view_url) + '" title="View"><i class="fas fa-eye"></i></a>';
                    if (r.can_edit) {
                        h += '<a class="btn btn-sm btn-outline-secondary" href="' + esc(r.edit_url) + '" title="Edit"><i class="fas fa-edit"></i></a>';
                    }
                    if (r.can_delete) {
                        h += '<button type="button" class="btn btn-sm btn-outline-danger" data-confirm-delete data-url="' + esc(r.delete_url) + '" title="Delete"><i class="fas fa-trash"></i></button>';
                    }
                    return h + '</div>';
                } },
                { data: 'created_at', visible: false, searchable: false }
            ],
            drawCallback: function () {
                var info = this.api().page.info();
                $('#campaignTotal').text(info.recordsDisplay + ' total');
                $('#campaignEmptyCta').toggleClass('d-none', info.recordsTotal > 0);
            }
        });
        if (!dt) return null;

        // Extra filters: status select, search box and sort select (the form no longer reloads the page).
        $('#campaignFilterForm').on('submit', function (e) {
            e.preventDefault();
            dt.search($.trim($('#campaignSearch').val()));
            dt.ajax.reload();
        });
        $('#campaignSort').on('change', function () {
            dt.order(sortMap[$(this).val()] || sortMap.latest).draw();
        });

        return dt;
    }

    // ---- WhatsApp recipients --------------------------------------------------------------------
    function waRecipients(selector) {
        var $t = $(selector);
        if (!$t.length) return null;
        return window.AppDT.table(selector, {
            url: String($t.data('url')),
            order: [[3, 'desc']],
            emptyText: 'No recipients yet',
            createdRow: function (row, r) { $(row).attr('data-recipient-id', r.id); },
            columns: [
                { data: null, render: function (r) { return esc(r.name); } },
                { data: null, render: function (r) { return esc(r.mobile); } },
                { data: null, className: 'col-status', render: function (r) { return r.status_badge || esc(r.status); } },
                { data: null, className: 'col-sent text-muted small', render: function (r) { return esc(r.sent_at); } },
                { data: null, className: 'col-delivered text-muted small', render: function (r) { return esc(r.delivered_at); } },
                { data: null, className: 'col-read text-muted small', render: function (r) { return esc(r.read_at); } },
                { data: null, orderable: false, className: 'col-error small text-danger', render: function (r) { return esc(r.error); } }
            ]
        });
    }

    // ---- Email recipients -----------------------------------------------------------------------
    function badgeCell(n, kind) {
        n = Number(n || 0);
        if (n <= 0) return '<span class="text-muted">—</span>';
        return kind === 'clicks'
            ? '<span class="badge bg-warning-subtle text-warning border px-2 py-1"><i class="fas fa-mouse-pointer me-1"></i> ' + n + '</span>'
            : '<span class="badge bg-info-subtle text-info border px-2 py-1"><i class="fas fa-eye me-1"></i> ' + n + '</span>';
    }

    function emailRecipients(selector) {
        var $t = $(selector);
        if (!$t.length) return null;
        return window.AppDT.table(selector, {
            url: String($t.data('url')),
            order: [[0, 'asc']],
            emptyText: 'No individual recipient addresses logged.',
            createdRow: function (row, r) { $(row).addClass('recipient-row').attr('data-email', r.email); },
            columns: [
                { data: null, className: 'ps-3', render: function (r) {
                    var shown = r.name || r.email;
                    var h = '<div class="d-flex align-items-center gap-2">'
                        + '<div class="rounded-circle bg-light border d-flex align-items-center justify-content-center text-secondary fw-bold" style="width:32px;height:32px;font-size:0.75rem;">'
                        + esc(String(shown).charAt(0).toUpperCase()) + '</div><div>'
                        + '<div class="fw-semibold text-dark">' + esc(shown) + '</div>';
                    if (r.name) {
                        h += '<div class="text-muted text-truncate" style="font-size:0.74rem;">' + esc(r.email) + (r.mobile ? ' · ' + esc(r.mobile) : '') + '</div>';
                    }
                    return h + '</div></div>';
                } },
                { data: null, orderable: false, className: 'col-status', render: function (r) { return r.status_badge || ''; } },
                { data: null, orderable: false, className: 'text-center col-opens', render: function (r) { return badgeCell(r.opens, 'opens'); } },
                { data: null, orderable: false, className: 'text-center col-clicks', render: function (r) { return badgeCell(r.clicks, 'clicks'); } }
            ]
        });
    }

    // ---- Email Manager: Campaign Broadcasts -----------------------------------------------------
    function emCampaigns() {
        var $t = $('#emCampaignsTable');
        if (!$t.length) return null;

        var dt = window.AppDT.table('#emCampaignsTable', {
            url: String($t.data('url')),
            order: [],
            pageLength: 10,
            emptyText: String($t.data('emptyText') || 'No campaigns found'),
            columns: [
                { data: null, render: function (r) {
                    var h = '<span class="fw-semibold text-dark">' + esc(r.name) + '</span>'
                        + '<div class="text-muted small text-truncate" style="max-width:200px;">' + esc(r.subject || 'No subject') + '</div>';
                    if (r.attachment_name) {
                        h += '<div class="mt-0.5"><span class="badge bg-light text-secondary border" style="font-size:0.65rem;"><i class="fas fa-paperclip text-primary me-1"></i>' + esc(r.attachment_name) + '</span></div>';
                    }
                    return h;
                } },
                { data: null, render: function (r) {
                    var cls = { sent: 'bg-success-subtle text-success border border-success-subtle', completed: 'bg-success-subtle text-success border border-success-subtle',
                        failed: 'bg-danger-subtle text-danger border border-danger-subtle', sending: 'bg-info-subtle text-info border border-info-subtle',
                        paused: 'bg-warning-subtle text-warning-emphasis border border-warning-subtle' }[r.status] || 'bg-secondary-subtle text-secondary border border-secondary-subtle';
                    var retry = r.status === 'sent' && r.failed_count > 0;
                    var label = retry ? 'Sent with errors' : (r.status.charAt(0).toUpperCase() + r.status.slice(1));
                    var h = '<span class="badge ' + cls + ' rounded-pill px-2 py-1">' + esc(label) + '</span>';
                    if ((r.status === 'paused' || r.status === 'failed') && r.last_error) {
                        h += '<div class="text-muted small text-truncate" style="max-width:220px;" title="' + esc(r.last_error) + '">' + esc(r.last_error) + '</div>';
                    }
                    return h;
                } },
                { data: null, render: function (r) {
                    return '<span class="text-success fw-semibold">' + Number(r.sent_count) + '</span> <span class="text-muted">/</span> <span class="text-danger">' + Number(r.failed_count) + '</span>';
                } },
                { data: null, orderable: false, className: 'text-end text-nowrap', render: function (r) {
                    var id = Number(r.id);
                    var h = '<a href="' + esc(r.view_url) + '" class="btn btn-xs btn-outline-secondary me-1" title="View details"><i class="fas fa-eye me-1"></i> View</a>';
                    if (r.can_send && id > 0) {
                        var retry = r.status === 'sent' && r.failed_count > 0;
                        if (['draft', 'failed', 'paused'].indexOf(r.status) !== -1) {
                            h += '<button type="button" class="btn btn-xs btn-primary em-send-camp" data-id="' + id + '" title="'
                                + (r.status === 'draft' ? 'Broadcast campaign now' : 'Send to recipients who have not received it yet') + '"><i class="fas fa-paper-plane me-1"></i> '
                                + (r.status === 'draft' ? 'Send' : (r.status === 'paused' ? 'Resume' : 'Retry')) + '</button>';
                        } else if (retry) {
                            h += '<button type="button" class="btn btn-xs btn-outline-primary em-send-camp" data-id="' + id + '" title="Send only to recipients that failed"><i class="fas fa-redo me-1"></i> Retry failed</button>';
                        }
                        h += '<button type="button" class="btn btn-xs btn-outline-danger em-del-camp ms-1" data-id="' + id + '" title="Delete"><i class="fas fa-trash-alt"></i></button>';
                    }
                    return h;
                } }
            ],
            drawCallback: function () {
                $('#emCampaignsCount').text(this.api().page.info().recordsTotal + ' campaigns');
            }
        });
        if (!dt) return null;

        function act(url, okFallback) {
            return $.ajax({ url: url, method: 'POST', dataType: 'json', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .done(function (res) {
                    if (res && res.success) { toast(res.message || okFallback, 'success'); dt.ajax.reload(null, false); }
                    else { toast((res && res.message) || 'Failed', 'error'); }
                })
                .fail(function (xhr) { toast((xhr.responseJSON && xhr.responseJSON.message) || 'Request failed.', 'error'); });
        }
        var base = String($t.data('url')).replace(/campaigns-data$/, 'campaigns/');

        $(document).on('click', '#emCampaignsTable .em-send-camp', function () {
            if (!window.confirm('Send this HTML campaign now via active email provider?')) return;
            act(base + $(this).data('id') + '/send', 'Sent');
        });
        $(document).on('click', '#emCampaignsTable .em-del-camp', function () {
            if (!window.confirm('Delete campaign?')) return;
            act(base + $(this).data('id') + '/delete', 'Campaign deleted.');
        });

        return dt;
    }

    window.CampaignTables = { waRecipients: waRecipients, emailRecipients: emailRecipients };

    $(function () {
        hub();
        emCampaigns();
    });
})(window, window.jQuery);
