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
            order: [[0, 'desc']],
            createdRow: function (row) {
                $(row).addClass('contact-row-clickable').css('cursor', 'pointer');
            }
        });

        Contacts.table.on('draw', function () {
            $('#checkAllContacts').prop('checked', false);
        });

        $table.on('click', 'tbody tr', function (e) {
            if ($(e.target).closest('a, button, input, .table-actions, .dt-check-col').length) {
                return;
            }
            var data = Contacts.table.row(this).data();
            if (!data || !data.id) {
                return;
            }
            Contacts.showDetail(data);
        });

        $('#btnFilterContacts').on('click', function () {
            Contacts.table.ajax.reload();
        });
        $('#filterStatus, #filterTag, #filterAssigned').on('change', function () {
            Contacts.table.ajax.reload();
        });
    };

    function escHtml(value) {
        return $('<div>').text(value == null ? '' : String(value)).html();
    }

    function detailValue(value) {
        if (value == null || value === '') {
            return '<span class="text-muted">—</span>';
        }
        return escHtml(value);
    }

    function detailRow(label, valueHtml) {
        return '<tr><th class="text-muted fw-normal" style="width:34%">' + escHtml(label)
            + '</th><td>' + valueHtml + '</td></tr>';
    }

    Contacts.showDetail = function (row) {
        if (!row || !row.id) {
            return;
        }

        var tags = Array.isArray(row.tags) ? row.tags : [];
        var tagsHtml = tags.length
            ? tags.map(function (t) {
                var name = String(t.name || t || '');
                var color = t.color || '#667085';
                return '<span class="badge me-1 mb-1" style="background:' + escHtml(color) + '">'
                    + escHtml(name) + '</span>';
            }).join('')
            : '<span class="text-muted">No groups</span>';

        var custom = row.custom_fields;
        if (typeof custom === 'string' && custom !== '') {
            try { custom = JSON.parse(custom); } catch (err) { custom = {}; }
        }
        if (!custom || typeof custom !== 'object' || Array.isArray(custom)) {
            custom = {};
        }

        var customKeys = Object.keys(custom).filter(function (k) {
            return String(k).trim() !== '' && String(k).charAt(0) !== '_';
        }).sort(function (a, b) {
            return a.localeCompare(b, undefined, { sensitivity: 'base' });
        });

        var customHtml = customKeys.length
            ? '<table class="table table-sm mb-0"><tbody>'
                + customKeys.map(function (key) {
                    var val = custom[key];
                    if (val != null && typeof val === 'object') {
                        val = JSON.stringify(val);
                    }
                    return detailRow(key, detailValue(val));
                }).join('')
                + '</tbody></table>'
            : '<div class="text-muted">No custom fields</div>';

        var title = row.name || row.mobile || ('Contact #' + row.id);
        $('#contactDetailTitle').text(title);
        $('#contactDetailViewLink').attr('href', base() + '/contacts/' + row.id);
        $('#contactDetailEditLink').attr('href', base() + '/contacts/' + row.id + '/edit');

        var html = ''
            + '<div class="mb-3">'
            +   '<div class="fw-semibold mb-1">Contact columns</div>'
            +   '<div class="table-responsive"><table class="table table-sm mb-0"><tbody>'
            +     detailRow('ID', detailValue(row.id))
            +     detailRow('Name', detailValue(row.name))
            +     detailRow('Mobile', detailValue(row.mobile))
            +     detailRow('Email', detailValue(row.email))
            +     detailRow('Country', detailValue(row.country))
            +     detailRow('Status', detailValue(row.status))
            +     detailRow('Birthday', detailValue(row.birthday_display || row.birthday))
            +     detailRow('Channel', detailValue(row.channel))
            +     detailRow('External ID', detailValue(row.external_id))
            +     detailRow('Assigned to', detailValue(row.assigned_to))
            +     detailRow('Last message', detailValue(row.last_message_at_display || row.last_message_at))
            +     detailRow('Last reply', detailValue(row.last_reply_at_display || row.last_reply_at))
            +     detailRow('Created', detailValue(row.created_at_display || row.created_at))
            +     detailRow('Updated', detailValue(row.updated_at_display || row.updated_at))
            +     detailRow('Notes', detailValue(row.notes))
            +     detailRow('Groups', tagsHtml)
            +   '</tbody></table></div>'
            + '</div>'
            + '<div>'
            +   '<div class="fw-semibold mb-1">Custom fields</div>'
            +   customHtml
            + '</div>';

        $('#contactDetailBody').html(html);
        if (window.APP && typeof APP.showModal === 'function') {
            APP.showModal('#contactDetailModal');
        } else if (window.bootstrap && bootstrap.Modal) {
            bootstrap.Modal.getOrCreateInstance(document.getElementById('contactDetailModal')).show();
        }
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

        var preview = null;

        function esc(s) {
            return $('<div>').text(s == null ? '' : String(s)).html();
        }

        function csrfHeaders() {
            var h = {};
            if (window.APP) {
                h[APP.csrfHeader || 'X-CSRF-TOKEN'] = APP.csrfToken || $('meta[name="csrf-token"]').attr('content') || '';
            }
            return h;
        }

        function destinationOptions(header, selected) {
            var opts = '';
            var destinations = (preview && preview.destinations) ? preview.destinations.slice() : [];
            var newKey = String(selected || '').indexOf('new:') === 0
                ? String(selected).slice(4)
                : String(header || '').toLowerCase().replace(/[^a-z0-9_]+/g, '_').replace(/^_+|_+$/g, '') || 'custom';
            var hasNew = false;
            destinations.forEach(function (d) {
                if (d.value === ('new:' + newKey)) hasNew = true;
            });
            if (!hasNew) {
                destinations.push({
                    value: 'new:' + newKey,
                    label: 'Create new custom field: ' + newKey
                });
            }
            destinations.forEach(function (d) {
                opts += '<option value="' + esc(d.value) + '"'
                    + (String(d.value) === String(selected) ? ' selected' : '') + '>'
                    + esc(d.label) + '</option>';
            });
            return opts;
        }

        function sampleForHeader(headerIndex) {
            var rows = (preview && preview.sample_rows) ? preview.sample_rows : [];
            var samples = [];
            rows.forEach(function (row) {
                var v = row[headerIndex];
                if (v != null && String(v).trim() !== '') {
                    samples.push(String(v).trim());
                }
            });
            return samples.slice(0, 2).join(' · ') || '—';
        }

        function renderMapping() {
            var headers = preview.headers || [];
            var suggested = preview.suggested_mapping || {};
            var $tb = $('#importMappingTable tbody').empty();
            headers.forEach(function (header, idx) {
                var selected = suggested[header] || 'skip';
                $tb.append(
                    '<tr data-header="' + esc(header) + '">'
                    + '<td class="fw-semibold">' + esc(header) + '</td>'
                    + '<td class="small text-muted">' + esc(sampleForHeader(idx)) + '</td>'
                    + '<td><select class="form-select form-select-sm import-map-dest">'
                    + destinationOptions(header, selected)
                    + '</select></td>'
                    + '</tr>'
                );
            });
            $('#importMapMeta').text((preview.filename || 'file') + ' · ' + (preview.row_count || 0) + ' row(s)');
            $('#importSampleNote').text('Showing up to 5 sample rows for mapping hints.');
            var $warn = $('#importMapWarning');
            if (preview.warning) {
                $warn.text(preview.warning).removeClass('d-none');
            } else {
                $warn.addClass('d-none').text('');
            }
        }

        function collectMapping() {
            var mapping = {};
            $('#importMappingTable tbody tr').each(function () {
                var header = $(this).attr('data-header') || '';
                var dest = $(this).find('.import-map-dest').val() || 'skip';
                if (header) mapping[header] = dest;
            });
            return mapping;
        }

        function allowedImportFile(file) {
            if (!file || !file.name) return false;
            var name = String(file.name).toLowerCase();
            return /\.csv$/i.test(name) || /\.xlsx$/i.test(name);
        }

        $('#importFile').on('change', function () {
            var file = this.files && this.files[0];
            if (file) {
                if (!allowedImportFile(file)) {
                    APP.toast('Please choose a CSV or XLSX file.', 'error');
                    this.value = '';
                    $('#importFileName').text('Max size: 5 MB · up to 5,000 rows · .csv or .xlsx');
                    return;
                }
                if (file.size > 5 * 1024 * 1024) {
                    APP.toast('File exceeds 5MB limit.', 'error');
                    this.value = '';
                    $('#importFileName').text('Max size: 5 MB · up to 5,000 rows · .csv or .xlsx');
                    return;
                }
                $('#importFileName').text(file.name + ' (' + Math.round(file.size / 1024) + ' KB)');
            }
        });

        $form.on('submit', function (e) {
            e.preventDefault();
            var fileInput = document.getElementById('importFile');
            var file = fileInput && fileInput.files && fileInput.files[0] ? fileInput.files[0] : null;
            if (!file) {
                APP.toast('Please choose a CSV or XLSX file.', 'error');
                return;
            }
            if (!allowedImportFile(file)) {
                APP.toast('Please choose a CSV or XLSX file.', 'error');
                return;
            }
            if (file.size > 5 * 1024 * 1024) {
                APP.toast('File exceeds 5MB limit.', 'error');
                return;
            }

            var $btn = $('#btnImportContinue').prop('disabled', true);
            $btn.data('html', $btn.html()).html('<i class="fas fa-spinner fa-spin me-1"></i> Reading…');

            var fd = new FormData();
            fd.append('file', file);

            $.ajax({
                url: base() + '/contacts/import/preview',
                method: 'POST',
                data: fd,
                processData: false,
                contentType: false,
                headers: csrfHeaders()
            }).done(function (res) {
                if (!res || res.success === false) {
                    APP.toast((res && res.message) || 'Could not read file.', 'error');
                    return;
                }
                preview = res.data || res;
                renderMapping();
                if (preview.warning) {
                    APP.toast(preview.warning, 'warning');
                }
                $('#importStepUpload').addClass('d-none');
                $('#importStepMap').removeClass('d-none');
            }).fail(function (xhr) {
                var msg = (xhr.responseJSON && xhr.responseJSON.message) || 'Could not read file.';
                APP.toast(msg, 'error');
            }).always(function () {
                $btn.prop('disabled', false).html($btn.data('html') || 'Continue to mapping');
            });
        });

        $('#btnImportBack').on('click', function () {
            $('#importStepMap').addClass('d-none');
            $('#importStepUpload').removeClass('d-none');
        });

        $('#btnImportCommit').on('click', function () {
            if (!preview || !preview.token) {
                APP.toast('Upload the file again.', 'error');
                return;
            }
            var mapping = collectMapping();
            var hasMobile = Object.keys(mapping).some(function (k) { return mapping[k] === 'mobile'; });
            if (!hasMobile) {
                APP.toast('Map at least one column to Mobile / Phone.', 'error');
                return;
            }

            var $btn = $(this).prop('disabled', true);
            $btn.data('html', $btn.html()).html('<i class="fas fa-spinner fa-spin me-1"></i> Importing…');

            $.ajax({
                url: base() + '/contacts/import/commit',
                method: 'POST',
                data: {
                    token: preview.token,
                    group_id: $('#importGroupId').val() || '',
                    skip_duplicates: $('#skipDup').is(':checked') ? 1 : 0,
                    mapping: JSON.stringify(mapping)
                },
                headers: csrfHeaders()
            }).done(function (res) {
                if (!res || res.success === false) {
                    APP.toast((res && res.message) || 'Import failed.', 'error');
                    return;
                }
                var data = res.data || {};
                var toastType = (data.errors && data.errors.length) ? 'warning' : 'success';
                APP.toast(res.message || 'Import complete.', toastType);
                var redirect = data.redirect || (base() + '/contacts');
                setTimeout(function () { window.location.href = redirect; }, 900);
            }).fail(function (xhr) {
                var msg = (xhr.responseJSON && xhr.responseJSON.message) || 'Import failed.';
                APP.toast(msg, 'error');
            }).always(function () {
                $btn.prop('disabled', false).html($btn.data('html') || 'Import contacts');
            });
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
