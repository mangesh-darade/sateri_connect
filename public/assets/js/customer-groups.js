/**
 * Customer Groups — list, add contact, remove from group
 */
(function (window, $) {
    'use strict';

    var FIELD_MAP = {
        group_name: '#newGroupName',
        label_name: '#newGroupName',
        group_id: '#existingGroupId',
        tag_id: '#existingGroupId',
        name: '#contactName',
        mobile: '#contactMobile',
        email: '#contactEmail',
        mode: 'input[name="mode"]'
    };

    function base() {
        return (window.APP && APP.baseUrl) || '';
    }

    function toggleModeFields() {
        var mode = $('input[name="mode"]:checked').val();
        if (mode === 'existing') {
            $('#newGroupFields').addClass('d-none');
            $('#existingGroupFields').removeClass('d-none');
            $('#newGroupName').prop('required', false);
            $('#existingGroupId').prop('required', true);
        } else {
            $('#existingGroupFields').addClass('d-none');
            $('#newGroupFields').removeClass('d-none');
            $('#newGroupName').prop('required', true);
            $('#existingGroupId').prop('required', false);
        }
        clearFieldErrors();
    }

    function updateNameCount() {
        var len = ($('#newGroupName').val() || '').length;
        $('#groupNameCount').text(len + '/30');
    }

    function clearFieldErrors() {
        $('#addContactGroupErrors').addClass('d-none').empty();
        $('#addContactGroupForm .is-invalid').removeClass('is-invalid');
        $('#addContactGroupForm .invalid-feedback').text('');
    }

    function showFieldErrors(errors, message) {
        clearFieldErrors();
        errors = errors || {};

        var lines = [];
        if (message) {
            lines.push(message);
        }

        Object.keys(errors).forEach(function (key) {
            var msg = errors[key];
            if (!msg) return;
            if (typeof msg !== 'string') {
                msg = Array.isArray(msg) ? msg.join(' ') : String(msg);
            }

            var selector = FIELD_MAP[key];
            if (selector) {
                var $el = $(selector).first();
                $el.addClass('is-invalid');
                var $fb = $('#err_' + key);
                if (!$fb.length) {
                    $fb = $el.siblings('.invalid-feedback').first();
                }
                if ($fb.length) {
                    $fb.text(msg);
                }
            }
            if (lines.indexOf(msg) === -1) {
                lines.push(msg);
            }
        });

        if (lines.length) {
            $('#addContactGroupErrors')
                .removeClass('d-none')
                .html(lines.map(function (t) {
                    return $('<div>').text(t).html();
                }).join('<br>'));
        }
    }

    function digitsOnly(value) {
        return String(value || '').replace(/\D+/g, '');
    }

    function clientValidate(payload) {
        var errors = {};

        if (payload.mode === 'existing') {
            if (!payload.group_id) {
                errors.group_id = 'Select an existing customer group.';
            }
        } else {
            var gname = (payload.group_name || '').trim();
            if (!gname) {
                errors.group_name = 'Group name is required.';
            } else if (gname.length < 2) {
                errors.group_name = 'Group name must be at least 2 characters.';
            } else if (gname.length > 30) {
                errors.group_name = 'Group name must be 30 characters or less.';
            }
        }

        var mobile = digitsOnly(payload.mobile);
        if (!String(payload.mobile || '').trim()) {
            errors.mobile = 'Mobile number is required.';
        } else if (mobile.length < 10 || mobile.length > 15) {
            errors.mobile = 'Enter a valid mobile number (10–15 digits, with country code).';
        }

        var email = String(payload.email || '').trim();
        if (email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
            errors.email = 'Enter a valid email address.';
        }

        if (String(payload.name || '').length > 150) {
            errors.name = 'Name must be 150 characters or less.';
        }

        return errors;
    }

    function openAddModal(preselectGroupId) {
        var $form = $('#addContactGroupForm');
        if (!$form.length) return;

        $form[0].reset();
        clearFieldErrors();
        updateNameCount();

        if (preselectGroupId) {
            $('#modeExistingGroup').prop('checked', true);
            $('#existingGroupId').val(String(preselectGroupId));
        } else {
            $('#modeNewGroup').prop('checked', true);
        }
        toggleModeFields();
        APP.showModal('#addContactGroupModal');
        setTimeout(function () {
            if (preselectGroupId) {
                $('#contactMobile').trigger('focus');
            } else {
                $('#newGroupName').trigger('focus');
            }
        }, 250);
    }

    function saveContact(e) {
        e.preventDefault();

        var mode = $('input[name="mode"]:checked').val() || 'new';
        var payload = {
            mode: mode,
            name: ($('#contactName').val() || '').trim(),
            mobile: ($('#contactMobile').val() || '').trim(),
            email: ($('#contactEmail').val() || '').trim()
        };

        if (mode === 'existing') {
            payload.group_id = $('#existingGroupId').val() || '';
        } else {
            payload.group_name = ($('#newGroupName').val() || '').trim();
        }

        var localErrors = clientValidate(payload);
        if (Object.keys(localErrors).length) {
            showFieldErrors(localErrors, 'Please fix the highlighted fields.');
            APP.toast(Object.values(localErrors)[0], 'warning');
            return;
        }

        var $btn = $('#btnSaveContactGroup').prop('disabled', true);
        var original = $btn.html();
        $btn.html('<i class="fas fa-spinner fa-spin me-1"></i> Saving…');

        APP.post(base() + '/customer-groups', payload)
            .done(function (res) {
                if (res && res.success === false) {
                    showFieldErrors(res.errors || {}, res.message || 'Validation failed.');
                    APP.toast(res.message || 'Validation failed', 'error');
                    return;
                }

                clearFieldErrors();
                var data = (res && res.data) || {};
                var msg = (res && res.message) || 'Contact saved';
                var toastType = 'success';

                if (data.already_in_group) {
                    toastType = 'warning';
                    showFieldErrors(
                        { mobile: msg },
                        msg
                    );
                    APP.toast(msg, toastType);
                    $('#contactMobile').trigger('focus');
                    return;
                }

                if (data.already_exists) {
                    toastType = 'info';
                }

                APP.toast(msg, toastType);
                APP.hideModal('#addContactGroupModal');
                window.location.reload();
            })
            .fail(function (xhr) {
                var body = xhr.responseJSON || {};
                var msg = body.message || 'Unable to save contact';
                showFieldErrors(body.errors || {}, msg);
                APP.toast(msg, 'error');
            })
            .always(function () {
                $btn.prop('disabled', false).html(original);
            });
    }

    function removeFromGroup() {
        var groupId = $(this).data('group-id');
        var contactId = $(this).data('contact-id');
        var $row = $(this).closest('tr');

        APP.confirm({
            title: 'Remove from group?',
            text: 'This contact will stay in All Contacts, but leave this group.',
            confirmText: 'Remove'
        }).then(function (result) {
            if (!result || !result.isConfirmed) return;
            APP.post(base() + '/customer-groups/' + groupId + '/contacts/' + contactId + '/remove', {})
                .done(function (res) {
                    if (res && res.success === false) {
                        APP.toast(res.message || 'Remove failed', 'error');
                        return;
                    }
                    APP.toast((res && res.message) || 'Removed from group');
                    $row.fadeOut(150, function () { $(this).remove(); });
                })
                .fail(function (xhr) {
                    APP.toast((xhr.responseJSON && xhr.responseJSON.message) || 'Remove failed', 'error');
                });
        });
    }

    function initDataTable() {
        var $table = $('#customerGroupsTable');
        if (!$table.length || !$.fn.DataTable) return;
        if ($table.find('tbody tr td[colspan]').length) return;

        $table.DataTable({
            order: [[2, 'desc']],
            pageLength: 100,
            lengthMenu: [[25, 50, 100, -1], [25, 50, 100, 'All']],
            language: {
                search: '_INPUT_',
                searchPlaceholder: 'Search in groups',
                lengthMenu: 'Rows per page _MENU_',
                info: '_START_–_END_ of _TOTAL_',
                paginate: { previous: '‹', next: '›' }
            },
            columnDefs: [
                { targets: 3, orderable: false, searchable: false }
            ]
        });
    }

    $(function () {
        $(document).on('change', 'input[name="mode"]', toggleModeFields);
        $(document).on('input', '#newGroupName', updateNameCount);
        $(document).on('input change', '#addContactGroupForm input, #addContactGroupForm select', function () {
            $(this).removeClass('is-invalid');
            $(this).siblings('.invalid-feedback').text('');
            var $alert = $('#addContactGroupErrors');
            if ($alert.length && !$('#addContactGroupForm .is-invalid').length) {
                $alert.addClass('d-none').empty();
            }
        });
        $('#btnAddContactToGroup').on('click', function () { openAddModal(); });
        $('#addContactGroupForm').on('submit', saveContact);
        $(document).on('click', '.btn-remove-from-group', removeFromGroup);

        initDataTable();
        toggleModeFields();
        updateNameCount();

        var params = new URLSearchParams(window.location.search || '');
        if (params.get('add') === '1') {
            openAddModal(params.get('group_id') || '');
        }
    });
})(window, jQuery);
