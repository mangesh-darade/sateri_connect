/**
 * Contacts — bulk actions, import helpers
 */
(function (window, $) {
    'use strict';

    var Contacts = {
        table: null
    };

    function base() {
        return (window.APP && APP.baseUrl) || '';
    }

    Contacts.selectedIds = function () {
        var ids = [];
        $('.contact-check:checked').each(function () {
            ids.push($(this).val());
        });
        return ids;
    };

    Contacts.initDataTable = function () {
        var $table = $('#contactsTable');
        if (!$table.length || !$.fn.DataTable) return;

        Contacts.table = $table.DataTable({
            processing: true,
            serverSide: true,
            /* Global DT defaults already set scrollX/length/dom — keep contacts-specific bits */
            scrollX: false,
            pageLength: 25,
            lengthMenu: [[10, 25, 50, 100], [10, 25, 50, 100]],
            language: {
                search: '_INPUT_',
                searchPlaceholder: 'Search contacts…',
                lengthMenu: 'Show _MENU_',
                emptyTable: 'No contacts found',
                info: '_START_–_END_ of _TOTAL_',
                infoEmpty: '0 contacts',
                zeroRecords: 'No matching contacts',
                paginate: { previous: '‹', next: '›' }
            },
            ajax: {
                url: base() + '/contacts',
                data: function (d) {
                    d.datatable = 1;
                    d.status = $('#filterStatus').val();
                    d.tag_id = $('#filterTag').val();
                    d.assigned_to = $('#filterAssigned').val();
                }
            },
            columnDefs: [
                { targets: 0, width: '42px', className: 'dt-check-col', orderable: false, searchable: false },
                { targets: 4, className: 'dt-tags-col', orderable: false },
                { targets: 7, width: '108px', className: 'text-end', orderable: false, searchable: false }
            ],
            columns: [
                {
                    data: 'id',
                    orderable: false,
                    searchable: false,
                    render: function (id) {
                        return '<input type="checkbox" class="form-check-input contact-check" value="' + id + '" aria-label="Select contact">';
                    }
                },
                { data: 'name', defaultContent: '—' },
                { data: 'mobile', defaultContent: '—' },
                { data: 'email', defaultContent: '—' },
                {
                    data: 'tags',
                    orderable: false,
                    render: function (tags) {
                        var list = [];
                        if (!tags) return '—';
                        if (typeof tags === 'string') {
                            list = tags.split(',').map(function (s) { return s.trim(); }).filter(Boolean)
                                .map(function (name) { return { name: name }; });
                        } else if ($.isArray(tags)) {
                            list = tags;
                        }
                        if (!list.length) return '—';
                        return list.map(function (t) {
                            var name = String(t.name || t || '');
                            var color = t.color || '#667085';
                            var short = name.length > 18 ? name.slice(0, 16) + '…' : name;
                            return '<span class="badge contact-tag-badge me-1" style="background:' + color + '" title="' +
                                $('<div>').text(name).html() + '">' + $('<div>').text(short).html() + '</span>';
                        }).join('');
                    }
                },
                {
                    data: 'status',
                    render: function (s) {
                        var map = { active: 'success', inactive: 'secondary', blocked: 'danger' };
                        return '<span class="badge bg-' + (map[s] || 'secondary') + '">' + (s || '') + '</span>';
                    }
                },
                {
                    data: 'last_message_at',
                    defaultContent: '—',
                    render: function (v) {
                        if (!v) return '—';
                        var s = String(v);
                        // Keep date+time on one line when possible
                        return '<span class="text-nowrap text-muted small">' + $('<div>').text(s).html() + '</span>';
                    }
                },
                {
                    data: 'id',
                    orderable: false,
                    searchable: false,
                    render: function (id) {
                        var html = '<div class="table-actions justify-content-end">';
                        html += '<a class="btn btn-sm btn-outline-secondary" href="' + base() + '/contacts/' + id + '" title="View"><i class="fas fa-eye"></i></a>';
                        html += '<a class="btn btn-sm btn-outline-secondary" href="' + base() + '/contacts/' + id + '/edit" title="Edit"><i class="fas fa-edit"></i></a>';
                        html += '<button type="button" class="btn btn-sm btn-outline-danger" data-confirm-delete data-url="' + base() + '/contacts/' + id + '/delete" title="Delete"><i class="fas fa-trash"></i></button>';
                        html += '</div>';
                        return html;
                    }
                }
            ],
            order: [[6, 'desc']]
        });

        Contacts.table.on('draw', function () {
            $('#checkAllContacts').prop('checked', false);
        });

        $('#btnFilterContacts').on('click', function () {
            Contacts.table.ajax.reload();
        });
        $('#filterStatus, #filterTag, #filterAssigned').on('change', function () {
            Contacts.table.ajax.reload();
        });
    };

    Contacts.bulkDelete = function () {
        var ids = Contacts.selectedIds();
        if (!ids.length) {
            APP.toast('Select at least one contact', 'warning');
            return;
        }
        APP.confirm({ title: 'Delete selected?', text: ids.length + ' contact(s) will be deleted.', confirmText: 'Delete' })
            .then(function (r) {
                if (!r.isConfirmed) return;
                APP.post(base() + '/contacts/bulk-delete', { ids: ids }).done(function (res) {
                    APP.toast(res.message || 'Deleted');
                    if (Contacts.table) Contacts.table.ajax.reload(null, false);
                }).fail(function (xhr) {
                    APP.toast((xhr.responseJSON && xhr.responseJSON.message) || 'Bulk delete failed', 'error');
                });
            });
    };

    Contacts.bulkTags = function () {
        var ids = Contacts.selectedIds();
        var tagIds = $('#bulkTagIds').val() || [];
        if (!ids.length) {
            APP.toast('Select at least one contact', 'warning');
            return;
        }
        if (!tagIds.length) {
            APP.toast('Select tags to apply', 'warning');
            return;
        }
        APP.post(base() + '/contacts/bulk-tags', { ids: ids, tag_ids: tagIds, mode: $('#bulkTagAction').val() || 'add' })
            .done(function (res) {
                APP.toast(res.message || 'Groups updated');
                if (window.bootstrap && bootstrap.Modal) {
                    var el = document.getElementById('bulkTagsModal');
                    if (el) bootstrap.Modal.getOrCreateInstance(el).hide();
                } else {
                    $('#bulkTagsModal').removeClass('show').hide();
                }
                if (Contacts.table) Contacts.table.ajax.reload(null, false);
            })
            .fail(function (xhr) {
                APP.toast((xhr.responseJSON && xhr.responseJSON.message) || 'Group update failed', 'error');
            });
    };

    Contacts.initImport = function () {
        var $form = $('#importContactsForm');
        if (!$form.length) return;

        $('#importFile').on('change', function () {
            var file = this.files && this.files[0];
            if (file) {
                $('#importFileName').text(file.name + ' (' + Math.round(file.size / 1024) + ' KB)');
            }
        });

        $form.on('submit', function () {
            $('#btnImportSubmit').prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Importing…');
        });
    };

    $(function () {
        $(document).on('change', '#checkAllContacts', function () {
            $('.contact-check').prop('checked', this.checked);
        });
        $(document).on('change', '.contact-check', function () {
            var total = $('.contact-check').length;
            var checked = $('.contact-check:checked').length;
            $('#checkAllContacts').prop('checked', total > 0 && total === checked);
        });
        $('#btnBulkDelete').on('click', function () { Contacts.bulkDelete(); });
        $('#btnBulkTags').on('click', function () { APP.showModal('#bulkTagsModal'); });
        $('#btnApplyBulkTags').on('click', function () { Contacts.bulkTags(); });
        $('#btnDetectDuplicates').on('click', function () {
            APP.get(base() + '/contacts/duplicates').done(function (res) {
                var rows = res.data || res.duplicates || [];
                var html = !rows.length ? '<p class="text-muted mb-0">No duplicates found.</p>' :
                    '<ul class="mb-0">' + rows.map(function (r) {
                        return '<li>' + $('<div>').text((r.mobile || '') + ' — ' + (r.cnt || r.count || '') + ' records').html() + '</li>';
                    }).join('') + '</ul>';
                $('#duplicatesModalBody').html(html);
                APP.showModal('#duplicatesModal');
            });
        });

        $('#formSyncCheerioContacts').on('submit', function () {
            var $btn = $('#btnSyncCheerioContacts').prop('disabled', true);
            $btn.data('html', $btn.html());
            $btn.html('<i class="fas fa-spinner fa-spin me-1"></i> Syncing…');
        });

        Contacts.initDataTable();
        Contacts.initImport();
    });

    window.ContactsApp = Contacts;
})(window, jQuery);
