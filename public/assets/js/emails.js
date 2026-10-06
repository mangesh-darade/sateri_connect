/**
 * Emails — single + bulk compose AJAX helpers
 */
(function (window, $) {
    'use strict';

    function showResult($el, ok, message) {
        if (!$el.length) {
            return;
        }
        $el.html(
            '<div class="alert alert-' + (ok ? 'success' : 'danger') + ' mb-0 py-2">' +
            $('<div>').text(message || (ok ? 'Done.' : 'Failed.')).html() +
            (ok
                ? '<div class="small mt-1 opacity-75">Provider accepted the request. Delivery depends on the provider (check Spam/Promotions if needed).</div>'
                : '') +
            '</div>'
        );
    }

    function postJson(url, payload) {
        return $.ajax({
            url: url,
            method: 'POST',
            data: JSON.stringify(payload),
            contentType: 'application/json; charset=UTF-8',
            dataType: 'json'
        });
    }

    function bindSingle() {
        var $card = $('#emailSingleCard');
        var $form = $('#emailSingleForm');
        if (!$form.length) {
            return;
        }

        $form.on('submit', function (e) {
            e.preventDefault();
            var $btn = $('#btnSendSingle').prop('disabled', true);
            var url = $card.data('send-url') || $form.attr('action');
            var payload = {
                to: $.trim($('#emailTo').val() || ''),
                subject: $.trim($('#emailSubject').val() || ''),
                body: $('#emailBody').val() || '',
                is_html: $('#emailIsHtml').is(':checked') ? 1 : 0,
                campaign_name: $('#emailCampaign').val() || ''
            };

            postJson(url, payload)
                .done(function (res) {
                    var ok = !!(res && res.success);
                    showResult($('#emailSingleResult'), ok, (res && res.message) || '');
                    if (window.APP && APP.toast) {
                        APP.toast((res && res.message) || (ok ? 'Sent' : 'Failed'), ok ? 'success' : 'error');
                    }
                })
                .fail(function (xhr) {
                    var res = xhr.responseJSON || {};
                    var msg = res.message || 'Request failed.';
                    if (res.errors) {
                        msg += ' ' + Object.values(res.errors).join(' ');
                    }
                    showResult($('#emailSingleResult'), false, msg);
                })
                .always(function () {
                    $btn.prop('disabled', false);
                });
        });
    }

    function bindBulk() {
        var $card = $('#emailBulkCard');
        var $form = $('#emailBulkForm');
        if (!$form.length) {
            return;
        }

        var base = (window.APP && window.APP.baseUrl) ? window.APP.baseUrl.replace(/\/$/, '') : '';

        function syncMode() {
            var mode = $('input[name="mode"]:checked').val() || 'recipients';
            if (mode === 'label') {
                $('#bulkRecipientsPanel').addClass('d-none');
                $('#bulkLabelPanel').removeClass('d-none');
            } else {
                $('#bulkRecipientsPanel').removeClass('d-none');
                $('#bulkLabelPanel').addClass('d-none');
            }
        }

        $form.on('change', 'input[name="mode"]', syncMode);
        syncMode();

        // ── Checkbox Multi-Select Picker ────────────────────────
        var $contactList = $('#bulkContactList');
        var $checkAll = $('#bulkCheckAll');
        var $selectedBadge = $('#bulkSelectedBadge');
        var $hiddenSelect = $('#bulkContacts');
        var $searchInput = $('#bulkContactSearch');
        var $groupFilter = $('#bulkFilterGroup');

        function updateSelectedState() {
            var $checked = $contactList.find('.bulk-contact-cb:checked');
            var count = $checked.length;
            $selectedBadge.text(count + ' selected');

            var groupId = parseInt($groupFilter.val(), 10) || 0;
            var groupName = $groupFilter.find('option:selected').data('name') || '';
            var visibleCount = $contactList.find('.bulk-contact-row:visible').length;

            // Clear all button in header
            if (count > 0) {
                $('#btnClearAllSelectedBtn').removeClass('d-none');
            } else {
                $('#btnClearAllSelectedBtn').addClass('d-none');
            }

            // Update Dropdown button text
            if (count === 0) {
                if (groupId && groupName) {
                    if (visibleCount === 0) {
                        $('#bulkDropdownBtnText').html('<i class="fas fa-exclamation-circle text-warning me-1"></i> 0 contacts in ' + $('<div>').text(groupName).html());
                    } else {
                        $('#bulkDropdownBtnText').html('<i class="fas fa-users text-primary me-1"></i> Choose from ' + visibleCount + ' in ' + $('<div>').text(groupName).html() + '...');
                    }
                } else {
                    $('#bulkDropdownBtnText').html('<i class="fas fa-users text-primary me-1"></i> Choose contacts (' + visibleCount + ')...');
                }
                $('#bulkEmptyPillsNotice').removeClass('d-none');
                $('#bulkSelectedChips').addClass('d-none').empty();
            } else {
                $('#bulkDropdownBtnText').html('<i class="fas fa-check-circle text-success me-1"></i> ' + count + (count === 1 ? ' contact selected' : ' contacts selected'));
                $('#bulkEmptyPillsNotice').addClass('d-none');

                // Render selected contact chips cleanly
                var $chips = $('#bulkSelectedChips').removeClass('d-none').empty();
                var maxChips = 20;
                var shown = 0;
                $checked.each(function () {
                    if (shown < maxChips) {
                        var cid = $(this).val();
                        var cname = $(this).data('name') || $(this).data('email') || 'Contact';
                        var cemail = $(this).data('email') || '';

                        var $chip = $('<span class="bulk-chip"></span>');
                        $chip.text(cname + (cemail && cname !== cemail ? ' (' + cemail + ') ' : ' '));

                        var $remove = $('<span class="bulk-chip-remove" title="Remove">✕</span>').data('id', cid);
                        $chip.append($remove);
                        $chips.append($chip);
                        shown++;
                    }
                });
                if (count > maxChips) {
                    $chips.append('<span class="badge bg-secondary-subtle text-secondary rounded-pill py-1 px-2" style="font-size: 0.7rem;">+' + (count - maxChips) + ' more</span>');
                }
            }

            // Sync hidden select
            var checkedIds = [];
            $checked.each(function () {
                checkedIds.push($(this).val());
            });
            $hiddenSelect.val(checkedIds);

            // Sync select all checkbox state
            var $visibleCbs = $contactList.find('.bulk-contact-row:visible .bulk-contact-cb');
            var visibleCbsCount = $visibleCbs.length;
            var visibleChecked = $visibleCbs.filter(':checked').length;

            if (visibleCbsCount > 0 && visibleChecked === visibleCbsCount) {
                $checkAll.prop('checked', true).prop('indeterminate', false);
            } else if (visibleChecked > 0) {
                $checkAll.prop('checked', false).prop('indeterminate', true);
            } else {
                $checkAll.prop('checked', false).prop('indeterminate', false);
            }
        }

        // Prevent dropdown from closing when clicking inside
        $('#bulkContactDropdown .dropdown-menu').on('click', function (e) {
            e.stopPropagation();
        });

        // Close dropdown on Done button
        $('#btnDoneDropdown').on('click', function (e) {
            e.stopPropagation();
            if (window.bootstrap && bootstrap.Dropdown) {
                var dd = bootstrap.Dropdown.getOrCreateInstance(document.getElementById('bulkDropdownBtn'));
                if (dd) dd.hide();
            } else {
                $('#bulkContactDropdown .dropdown-menu').removeClass('show');
            }
        });

        // Remove chip on click
        $(document).on('click', '.bulk-chip-remove', function (e) {
            e.stopPropagation();
            var id = $(this).data('id');
            var $cb = $contactList.find('.bulk-contact-cb[value="' + id + '"]');
            $cb.prop('checked', false);
            $cb.closest('.bulk-contact-row').removeClass('is-selected');
            updateSelectedState();
        });

        // Header Clear all button
        $('#btnClearAllSelectedBtn').on('click', function (e) {
            e.preventDefault();
            $contactList.find('.bulk-contact-cb').prop('checked', false);
            $contactList.find('.bulk-contact-row').removeClass('is-selected');
            $checkAll.prop('checked', false).prop('indeterminate', false);
            updateSelectedState();
        });

        // Row checkbox toggle
        $contactList.on('change', '.bulk-contact-cb', function () {
            var $cb = $(this);
            $cb.closest('.bulk-contact-row').toggleClass('is-selected', $cb.is(':checked'));
            updateSelectedState();
        });

        // Select All toggle
        $checkAll.on('change', function () {
            var isChecked = $(this).is(':checked');
            $contactList.find('.bulk-contact-row:visible').each(function () {
                var $row = $(this);
                var $cb = $row.find('.bulk-contact-cb');
                $cb.prop('checked', isChecked);
                $row.toggleClass('is-selected', isChecked);
            });
            updateSelectedState();
        });

        // Filter contacts by Search & Group
        function applyFilters(shouldOpenDropdown) {
            var q = $.trim($searchInput.val() || '').toLowerCase();
            var groupId = parseInt($groupFilter.val(), 10) || 0;
            var visible = 0;

            $contactList.find('.bulk-contact-row').each(function () {
                var $row = $(this);
                var name = String($row.data('name') || '');
                var email = String($row.data('email') || '');
                var tags = $row.data('tags');

                if (typeof tags === 'string') {
                    try { tags = JSON.parse(tags); } catch(e) { tags = []; }
                }
                if (!Array.isArray(tags)) {
                    tags = [];
                }

                var matchesQuery = !q || name.indexOf(q) !== -1 || email.indexOf(q) !== -1;
                var matchesGroup = !groupId || tags.some(function(t) { return parseInt(t, 10) === groupId; });

                if (matchesQuery && matchesGroup) {
                    $row.removeClass('d-none');
                    visible++;
                } else {
                    $row.addClass('d-none');
                }
            });

            $('#bulkVisibleCount').text(visible);
            if (visible === 0) {
                $('#bulkNoVisibleNotice').removeClass('d-none');
            } else {
                $('#bulkNoVisibleNotice').addClass('d-none');
            }
            $('#bulkSearchStatus').text(q || groupId ? visible + ' matching' : visible + ' contacts');
            updateSelectedState();

            if (shouldOpenDropdown && groupId > 0) {
                var ddBtn = document.getElementById('bulkDropdownBtn');
                if (ddBtn && window.bootstrap && bootstrap.Dropdown) {
                    var dd = bootstrap.Dropdown.getOrCreateInstance(ddBtn);
                    dd.show();
                }
            }
        }

        $searchInput.on('input', function () { applyFilters(false); });
        $groupFilter.on('change', function () { applyFilters(true); });

        // Select Visible button
        $('#btnSelectFiltered').on('click', function () {
            $contactList.find('.bulk-contact-row:visible').each(function () {
                var $row = $(this);
                $row.find('.bulk-contact-cb').prop('checked', true);
                $row.addClass('is-selected');
            });
            updateSelectedState();
        });

        // Clear Selection button
        $('#btnClearSelection').on('click', function () {
            $contactList.find('.bulk-contact-cb').prop('checked', false);
            $contactList.find('.bulk-contact-row').removeClass('is-selected');
            $checkAll.prop('checked', false).prop('indeterminate', false);
            updateSelectedState();
        });

        // Load Group Into Custom List & Review button
        $('#btnLoadGroupIntoRecipients').on('click', function () {
            var $opt = $('#bulkLabelSelect option:selected');
            var groupId = $opt.data('id');
            var groupName = $opt.val();
            if (!groupId || !groupName) {
                alert('Please select a customer group first.');
                return;
            }

            var $btn = $(this).prop('disabled', true);
            fetch(base + '/email-manager/group-emails/' + encodeURIComponent(groupId), {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                $btn.prop('disabled', false);
                if (!res.success) {
                    alert(res.message || 'Error loading group contacts.');
                    return;
                }
                var emails = (res.data && res.data.emails) || [];
                if (!emails.length) {
                    alert('No active contacts with valid email in this group.');
                    return;
                }

                // Switch back to custom recipients mode
                $('#modeRecipients').prop('checked', true).trigger('change');

                // Merge into recipients textarea
                var current = $.trim($('#bulkRecipients').val() || '');
                var existing = current ? current.split(/[\s,;]+/).filter(Boolean) : [];
                var merged = Array.from(new Set(existing.concat(emails)));
                $('#bulkRecipients').val(merged.join('\n'));

                // Also select them in the group filter dropdown and check them in list
                $groupFilter.val(groupId).trigger('change');
                $('#btnSelectFiltered').trigger('click');

                if (window.APP && APP.toast) {
                    APP.toast('Loaded ' + emails.length + ' contacts from ' + groupName, 'success');
                }
            })
            .catch(function (err) {
                $btn.prop('disabled', false);
                alert(err.message || 'Network error.');
            });
        });

        // ── Submit Form Handler ──────────────────────────────────
        $form.on('submit', function (e) {
            e.preventDefault();

            var mode = $('input[name="mode"]:checked').val() || 'recipients';
            var selectedLabel = $.trim($('#bulkLabelSelect').val() || $('#bulkLabelName').val() || '');
            var confirmText = mode === 'label'
                ? 'Send this email to all contacts in group "' + selectedLabel + '" now?'
                : 'Send this bulk email now?';

            var proceed = function () {
                var $btn = $('#btnSendBulk').prop('disabled', true);
                var url = $card.data('send-url') || $form.attr('action');

                // Collect contact IDs from checked checkboxes
                var contactIds = [];
                $contactList.find('.bulk-contact-cb:checked').each(function () {
                    var id = parseInt($(this).val(), 10);
                    if (id > 0) contactIds.push(id);
                });

                var payload = {
                    mode: mode,
                    subject: $.trim($('#bulkSubject').val() || ''),
                    body: $('#bulkBody').val() || '',
                    is_html: $('#bulkIsHtml').is(':checked') ? 1 : 0,
                    campaign_name: $('#bulkCampaign').val() || '',
                    recipients: $('#bulkRecipients').val() || '',
                    contact_ids: contactIds,
                    label_name: selectedLabel
                };

                postJson(url, payload)
                    .done(function (res) {
                        var ok = !!(res && res.success);
                        showResult($('#emailBulkResult'), ok, (res && res.message) || '');
                        if (window.APP && APP.toast) {
                            APP.toast((res && res.message) || (ok ? 'Sent' : 'Failed'), ok ? 'success' : 'error');
                        }
                    })
                    .fail(function (xhr) {
                        var res = xhr.responseJSON || {};
                        var msg = res.message || 'Request failed.';
                        if (res.errors) {
                            msg += ' ' + Object.values(res.errors).join(' ');
                        }
                        showResult($('#emailBulkResult'), false, msg);
                    })
                    .always(function () {
                        $btn.prop('disabled', false);
                    });
            };

            if (window.APP && APP.confirm) {
                APP.confirm({
                    title: 'Send bulk email?',
                    text: confirmText,
                    confirmText: 'Send now'
                }).then(function (result) {
                    if (result.isConfirmed) {
                        proceed();
                    }
                });
            } else if (window.confirm(confirmText)) {
                proceed();
            }
        });
    }

    $(function () {
        bindSingle();
        bindBulk();
    });
})(window, jQuery);
