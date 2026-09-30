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

        // Deep link from Attributes page: /contacts?attr_key=city&attr_op=not_empty
        var qs = new URLSearchParams(window.location.search);
        if (qs.get('attr_key') && $('#filterAttrKey option[value="' + qs.get('attr_key').replace(/"/g, '') + '"]').length) {
            $('#filterAttrKey').val(qs.get('attr_key'));
            if (qs.get('attr_op')) $('#filterAttrOp').val(qs.get('attr_op'));
            if (qs.get('attr_value')) $('#filterAttrValue').val(qs.get('attr_value'));
            if (Contacts.syncAttrValueInput) Contacts.syncAttrValueInput();
        }

        var attrColumns = $table.data('attr-columns') || [];
        var attrColumnDefs = attrColumns.map(function (col) {
            return {
                data: null,
                name: col.key,
                defaultContent: '—',
                render: function (v, type, row) {
                    var cf = (row && row.custom_fields) || {};
                    var val = cf[col.key];
                    if (val === undefined || val === null || String(val) === '') return '<span class="text-muted">—</span>';
                    var s = String(val);
                    var short = s.length > 28 ? s.slice(0, 26) + '…' : s;
                    return '<span class="text-nowrap" title="' + escHtml(s) + '">' + escHtml(short) + '</span>';
                }
            };
        });

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
                    d.consent = $('#filterConsent').val();
                    $.extend(d, Contacts.attributeFilter());
                }
            },
            columnDefs: [
                { targets: 0, width: '42px', className: 'dt-check-col', orderable: false, searchable: false },
                { targets: 4, className: 'dt-tags-col', orderable: false },
                { targets: -1, width: '108px', className: 'text-end', orderable: false, searchable: false }
            ],
            columns: [].concat([
                {
                    data: 'id',
                    orderable: false,
                    searchable: false,
                    render: function (id) {
                        return '<input type="checkbox" class="form-check-input contact-check" value="' + id + '" aria-label="Select contact">';
                    }
                },
                { data: 'name', defaultContent: '—', render: function (v) { return v ? escHtml(v) : '—'; } },
                { data: 'mobile', defaultContent: '—', render: function (v) { return v ? escHtml(v) : '—'; } },
                { data: 'email', defaultContent: '—', render: function (v) { return v ? escHtml(v) : '—'; } },
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
                            return '<span class="badge contact-tag-badge me-1" style="background:' + escHtml(color) + '" title="' +
                                escHtml(name) + '">' + escHtml(short) + '</span>';
                        }).join('');
                    }
                },
                {
                    data: 'status',
                    render: function (s, type, row) {
                        var map = { active: 'success', inactive: 'secondary', blocked: 'danger' };
                        return '<span class="badge bg-' + (map[s] || 'secondary') + '">' + escHtml(s || '') + '</span> '
                            + consentBadge(row);
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
                }
            ], attrColumnDefs, [
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
            ]),
            order: [],
            createdRow: function (row) {
                $(row).addClass('contact-row-clickable').css('cursor', 'pointer');
            }
        });

        if (!$table.parent().hasClass('contacts-table-scroll')) {
            $table.wrap('<div class="contacts-table-scroll"></div>');
        }

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
        $('#filterStatus, #filterTag, #filterAssigned, #filterConsent, #filterAttrOp').on('change', function () {
            Contacts.table.ajax.reload();
        });
        $('#filterAttrKey').on('change', function () {
            var on = !!$(this).val();
            $('#filterAttrOp').toggleClass('d-none', !on);
            Contacts.syncAttrValueInput();
            Contacts.table.ajax.reload();
        });
        $('#filterAttrOp').on('change', Contacts.syncAttrValueInput);
        $('#filterAttrValue').on('keydown', function (e) {
            if (e.key === 'Enter') Contacts.table.ajax.reload();
        });
        $('#btnExportContacts').on('click', function () {
            var params = $.extend({
                status: $('#filterStatus').val(),
                tag_id: $('#filterTag').val(),
                assigned_to: $('#filterAssigned').val(),
                consent: $('#filterConsent').val(),
                search: Contacts.table ? Contacts.table.search() : ''
            }, Contacts.attributeFilter());
            Object.keys(params).forEach(function (k) { if (!params[k]) delete params[k]; });
            this.href = base() + '/contacts/export' + ($.isEmptyObject(params) ? '' : '?' + $.param(params));
        });
    };

    Contacts.attributeFilter = function () {
        var key = $('#filterAttrKey').val();
        if (!key) return {};
        return { attr_key: key, attr_op: $('#filterAttrOp').val(), attr_value: $('#filterAttrValue').val() };
    };

    Contacts.syncAttrValueInput = function () {
        var needsValue = !!$('#filterAttrKey').val() && ['is_empty', 'not_empty'].indexOf($('#filterAttrOp').val()) === -1;
        $('#filterAttrValue').toggleClass('d-none', !needsValue);
    };

    function escHtml(value) {
        return APP.escapeHtml(value);
    }

    function consentState(row) {
        if (!row) return 'none';
        if (row.wa_opted_out_at) return 'opted_out';
        if (row.wa_suppressed_until && new Date(String(row.wa_suppressed_until).replace(' ', 'T')) > new Date()) return 'paused';
        if (String(row.wa_opt_in) === '1') return 'opted_in';
        return 'none';
    }

    function consentBadge(row) {
        var map = {
            opted_in: ['success', 'Opted in', 'WhatsApp opt-in recorded'],
            opted_out: ['danger', 'Opted out', 'Customer sent STOP'],
            paused: ['warning', 'Paused', row && row.wa_suppress_reason ? row.wa_suppress_reason : 'Delivery failed'],
            none: ['light text-dark border', 'No opt-in', 'Campaigns skip contacts without WhatsApp consent']
        };
        var m = map[consentState(row)];
        return '<span class="badge bg-' + m[0] + '" title="' + escHtml(m[2]) + '"><i class="fab fa-whatsapp me-1"></i>' + m[1] + '</span>';
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
            +     detailRow('WhatsApp consent', consentBadge(row)
                    + (row.wa_opt_in_source ? ' <span class="small text-muted">via ' + escHtml(row.wa_opt_in_source) + '</span>' : ''))
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

    Contacts.bulkConsent = function () {
        var ids = Contacts.selectedIds();
        var action = $('#bulkConsentAction').val() || 'opt_in';
        var source = $('#bulkConsentSource').val() || '';
        if (!ids.length) {
            APP.toast('Select at least one contact', 'warning');
            return;
        }
        if (action === 'opt_in' && !source) {
            APP.toast('Choose how these contacts gave WhatsApp consent', 'warning');
            return;
        }
        APP.post(base() + '/contacts/bulk-consent', { ids: ids, action: action, source: source })
            .done(function (res) {
                APP.toast(res.message || 'Consent updated');
                if (window.bootstrap && bootstrap.Modal) {
                    var el = document.getElementById('bulkConsentModal');
                    if (el) bootstrap.Modal.getOrCreateInstance(el).hide();
                }
                if (Contacts.table) Contacts.table.ajax.reload(null, false);
            })
            .fail(function (xhr) {
                APP.toast((xhr.responseJSON && xhr.responseJSON.message) || 'Consent update failed', 'error');
            });
    };

    Contacts.bulkAttributeValue = function () {
        var $opt = $('#bulkAttrKey option:selected');
        return $opt.data('type') === 'dropdown' || $opt.data('type') === 'boolean'
            ? $('#bulkAttrValueSelect').val() || ''
            : $('#bulkAttrValue').val() || '';
    };

    Contacts.syncBulkAttributeInput = function () {
        var $opt = $('#bulkAttrKey option:selected');
        var type = $opt.data('type') || 'text';
        var options = type === 'boolean' ? ['Yes', 'No'] : ($opt.data('options') || []);
        var useSelect = type === 'dropdown' || type === 'boolean';
        $('#bulkAttrValue').toggleClass('d-none', useSelect)
            .attr('type', type === 'date' ? 'date' : (type === 'number' ? 'number' : 'text'));
        $('#bulkAttrValueSelect').toggleClass('d-none', !useSelect).html(
            '<option value="">(clear value)</option>' + options.map(function (o) {
                return '<option value="' + escHtml(o) + '">' + escHtml(o) + '</option>';
            }).join('')
        );
    };

    Contacts.bulkAttribute = function () {
        var ids = Contacts.selectedIds();
        var key = $('#bulkAttrKey').val();
        if (!ids.length) {
            APP.toast('Select at least one contact', 'warning');
            return;
        }
        if (!key) {
            APP.toast('Choose an attribute', 'warning');
            return;
        }
        var value = Contacts.bulkAttributeValue();
        var run = function () {
            APP.post(base() + '/contacts/bulk-attribute', { ids: ids, attribute: key, value: value })
                .done(function (res) {
                    APP.toast(res.message || 'Attribute updated');
                    APP.hideModal('#bulkAttributeModal');
                    if (Contacts.table) Contacts.table.ajax.reload(null, false);
                })
                .fail(function (xhr) {
                    APP.toast((xhr.responseJSON && xhr.responseJSON.message) || 'Attribute update failed', 'error');
                });
        };
        if (String(value).trim() === '') {
            APP.confirm({ title: 'Clear this attribute?', text: 'The value will be removed from ' + ids.length + ' contact(s).', confirmText: 'Clear' })
                .then(function (r) { if (r.isConfirmed) run(); });
            return;
        }
        run();
    };

    Contacts.initImport = function () {
        var $form = $('#importContactsForm');
        if (!$form.length) return;

        var preview = null;

        $('#importOptIn').on('change', function () {
            $('#importOptInSource').prop('disabled', !this.checked);
        });

        function esc(s) {
            return APP.escapeHtml(s);
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

            var optIn = $('#importOptIn').is(':checked');
            if (optIn && !$('#importOptInSource').val()) {
                APP.toast('Choose how WhatsApp consent was collected.', 'error');
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
                    wa_opt_in: optIn ? 1 : 0,
                    wa_opt_in_source: optIn ? $('#importOptInSource').val() : '',
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
        $('#btnBulkConsent').on('click', function () {
            if (!Contacts.selectedIds().length) {
                APP.toast('Select at least one contact', 'warning');
                return;
            }
            APP.showModal('#bulkConsentModal');
        });
        $('#bulkConsentAction').on('change', function () {
            $('#bulkConsentSourceWrap').toggleClass('d-none', $(this).val() !== 'opt_in');
        });
        $('#btnApplyBulkConsent').on('click', function () { Contacts.bulkConsent(); });
        $('#btnBulkAttribute').on('click', function () {
            if (!Contacts.selectedIds().length) {
                APP.toast('Select at least one contact', 'warning');
                return;
            }
            Contacts.syncBulkAttributeInput();
            APP.showModal('#bulkAttributeModal');
        });
        $('#bulkAttrKey').on('change', Contacts.syncBulkAttributeInput);
        $('#btnApplyBulkAttribute').on('click', function () { Contacts.bulkAttribute(); });
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

        $('#formSyncElintOmContacts').on('submit', function (e) {
            e.preventDefault();
            var $form = $(this);
            var $btn = $('#btnSyncElintOmContacts').prop('disabled', true);
            $btn.data('html', $btn.html());
            $btn.html('<i class="fas fa-spinner fa-spin me-1"></i> Syncing…');

            APP.post(base() + '/contacts/sync-elintom', $form.serialize())
                .done(function (res) {
                    var msg = (res && res.message) || 'ElintOm sync complete.';
                    APP.toast(msg, res && res.success === false ? 'error' : 'success');
                    if (Contacts.table) {
                        Contacts.table.ajax.reload(null, false);
                    }
                })
                .fail(function (xhr) {
                    var msg = (xhr.responseJSON && xhr.responseJSON.message)
                        || 'ElintOm sync failed. Set elintom_base_url and elintom_api_private_key in settings.';
                    APP.toast(msg, 'error');
                })
                .always(function () {
                    $btn.prop('disabled', false).html($btn.data('html') || 'Sync ElintOm customers');
                });
        });

        Contacts.initDataTable();
        Contacts.initImport();
    });

    window.ContactsApp = Contacts;
})(window, jQuery);
