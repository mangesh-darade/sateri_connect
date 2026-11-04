/**
 * Team Inbox → Export chats (CSV download) / Import chats (upload → map columns → import).
 */
(function ($) {
    'use strict';

    function base() { return APP.baseUrl || ''; }
    function esc(v) { return APP.escapeHtml(v); }
    function apiError(xhr, fallback) { return (xhr && xhr.responseJSON && xhr.responseJSON.message) || fallback; }

    /* ---------------- Export ---------------- */

    var $export = $('#chatExportModal');
    if ($export.length) {
        var $form = $('#chatExportForm');

        $export.on('show.bs.modal', function () {
            var id = window.Chat && Chat.contactId ? parseInt(Chat.contactId, 10) : 0;
            var conv = id && window.Chat && Chat.currentConversations
                ? Chat.currentConversations.find(function (c) { return parseInt(c.contact_id || c.id, 10) === id; })
                : null;
            $form.find('[name="contact_id"]').val(id || '');
            $('#chatExportScopeContact').prop('disabled', !id);
            $('#chatExportContactName').text(id ? '(' + ((conv && (conv.name || conv.mobile)) || ('#' + id)) + ')' : '(open a chat first)');
        });

        $form.on('change', '[name="format"]', function () {
            var format = $form.find('[name="format"]:checked').val();
            $form.find('[data-format-hint]').each(function () {
                $(this).toggleClass('d-none', $(this).data('formatHint') !== format);
            });
            $('#chatExportSubmit').html('<i class="fas fa-download me-1"></i> ' + (format === 'backup' ? 'Download backup' : 'Download CSV'));
        });

        $form.on('change', '[name="scope"]', function () {
            var scope = $form.find('[name="scope"]:checked').val();
            $form.find('[data-scope-panel]').each(function () {
                $(this).toggleClass('d-none', $(this).data('scopePanel') !== scope);
            });
        });

        $form.on('submit', function (e) {
            e.preventDefault();
            var scope = $form.find('[name="scope"]:checked').val();
            if (scope === 'numbers' && !$.trim($form.find('[name="numbers"]').val())) {
                APP.toast('Paste at least one phone number.', 'error');
                return;
            }
            if (scope === 'group' && !$form.find('[name="tag_id"]').val()) {
                APP.toast('Choose a customer group.', 'error');
                return;
            }
            var backup = $form.find('[name="format"]:checked').val() === 'backup';
            var params = $form.serializeArray().filter(function (p) {
                return p.value !== '' && p.name !== 'format' && (p.name !== 'numbers' || scope === 'numbers') && (p.name !== 'tag_id' || scope === 'group')
                    && (p.name !== 'contact_id' || scope === 'contact');
            });
            window.location.href = base() + '/chat/transfer/' + (backup ? 'backup' : 'export') + '?' + $.param(params);
            APP.toast(backup ? 'Preparing backup… the download starts when it is ready.' : 'Preparing export…', 'success');
            APP.hideModal($export[0]);
        });
    }

    /* ---------------- Import ---------------- */

    var $import = $('#chatImportModal');
    if (!$import.length) {
        return;
    }
    var CHUNK_BYTES = 1024 * 1024;
    var state = { step: 'upload', preview: null, backup: null };
    var $next = $('#chatImportNext');
    var $progress = $('#chatImportProgress');

    function setStep(step) {
        state.step = step;
        $import.find('[data-import-step]').each(function () {
            $(this).toggleClass('d-none', $(this).data('importStep') !== step);
        });
        var label = { upload: 'Continue', map: 'Import chats', backup: 'Restore backup', done: 'Import another file' }[step];
        $next.text(label).prop('disabled', step === 'upload' && !$('#chatImportFile')[0].files.length);
    }

    function reset() {
        state.preview = null;
        state.backup = null;
        $('#chatImportFile').val('');
        $('#chatImportMapping').empty();
        $('#chatImportStats').empty();
        $('#chatImportResult').empty();
        $('#chatBackupSummary').empty();
        $progress.addClass('d-none').find('.progress-bar').css('width', '0');
        setStep('upload');
    }

    function refreshInbox() {
        if (window.Chat && Chat.loadConversations) {
            Chat._convSig = '';
            Chat.loadConversations();
        }
    }

    function resultGrid(items) {
        return '<div class="row g-2 text-center small">' + items.map(function (x) {
            return '<div class="col-6 col-md-3"><div class="border rounded py-2"><div class="fs-5 fw-semibold">' + (x[1] || 0) + '</div>' + esc(x[0]) + '</div></div>';
        }).join('') + '</div>';
    }

    function errorList(title, errors) {
        return (errors || []).length
            ? '<div class="small fw-semibold mt-3">' + esc(title) + '</div><ul class="small text-muted ps-3 mb-0">' + errors.map(function (e) { return '<li>' + esc(e) + '</li>'; }).join('') + '</ul>'
            : '';
    }

    /* Backup ZIPs are uploaded in 1 MB slices so the server upload limit never blocks a restore. */
    function uploadBackup(file) {
        var uploadId = '';
        var $bar = $progress.removeClass('d-none').find('.progress-bar').css('width', '0');
        $next.prop('disabled', true).html('<i class="fas fa-spinner fa-spin me-1"></i> Uploading… 0%');

        function fail(msg) {
            APP.toast(msg || 'Upload failed.', 'error');
            $progress.addClass('d-none');
            $next.prop('disabled', false).text('Continue');
        }

        function send(offset) {
            var end = Math.min(offset + CHUNK_BYTES, file.size);
            var fd = new FormData();
            fd.append('chunk', file.slice(offset, end), 'backup.part');
            fd.append('offset', offset);
            fd.append('upload_id', uploadId);
            fd.append('last', end >= file.size ? 1 : 0);
            $.ajax({ url: base() + '/chat/transfer/restore/upload', method: 'POST', data: fd, processData: false, contentType: false, dataType: 'json' })
                .done(function (res) {
                    if (!res || res.success === false) { fail(res && res.message); return; }
                    var d = res.data || {};
                    var pct = Math.round(end / file.size * 100);
                    uploadId = d.upload_id;
                    $bar.css('width', pct + '%');
                    $next.html('<i class="fas fa-spinner fa-spin me-1"></i> Uploading… ' + pct + '%');
                    if (!d.done) { send(end); return; }
                    state.backup = { token: d.token };
                    renderBackup(file.name, d.summary || {});
                    setStep('backup');
                })
                .fail(function (xhr) { fail(apiError(xhr, 'Upload failed.')); });
        }

        if (!file.size) { fail('The file is empty.'); return; }
        send(0);
    }

    function renderBackup(name, s) {
        var c = s.counts || {};
        $('#chatBackupFileName').text(name);
        $('#chatBackupSummary').html(
            '<div class="small text-muted mb-2">Backup from <strong>' + esc(s.source || 'unknown server') + '</strong>'
            + (s.created_at ? ' · ' + esc(s.created_at) : '') + ' · ' + esc(s.scope === 'all' ? 'all chats' : s.scope) + '</div>'
            + resultGrid([['Contacts', c.contacts], ['Messages', c.messages], ['Media files', c.media], ['Groups', c.tags],
                ['Chats', c.conversations], ['Notes', c.notes]])
            + (c.media_missing ? '<div class="small text-warning mt-2">' + c.media_missing + ' media message(s) had no file on the old server and will keep their link only.</div>' : '')
        );
    }

    function restoreBackup() {
        var html = $next.html();
        $next.prop('disabled', true).html('<i class="fas fa-spinner fa-spin me-1"></i> Restoring…');
        APP.post(base() + '/chat/transfer/restore', { token: state.backup.token })
            .done(function (res) {
                var r = (res && res.data) || {};
                $('#chatImportResult').html(
                    '<div class="alert alert-success mb-2"><i class="fas fa-circle-check me-1"></i> ' + esc(res.message || 'Backup restored.') + '</div>'
                    + resultGrid([['Messages restored', r.messages_restored], ['Already here', r.messages_duplicate], ['New contacts', r.contacts_created],
                        ['Existing contacts', r.contacts_matched], ['Media files', r.media_restored], ['New groups', r.tags_created],
                        ['Chats created', r.conversations_restored], ['Notes', r.notes_restored]])
                    + errorList('Notes from the restore', r.errors)
                );
                state.backup = null;
                setStep('done');
                refreshInbox();
            })
            .fail(function (xhr) {
                APP.toast(apiError(xhr, 'Restore failed.'), 'error');
                $next.prop('disabled', false).html(html);
            });
    }

    function currentMapping() {
        var map = {};
        $('#chatImportMapping select').each(function () { map[$(this).data('header')] = $(this).val(); });
        return map;
    }

    function renderStats(stats) {
        var $s = $('#chatImportStats');
        if (!stats) { $s.empty(); return; }
        if (!stats.ready) {
            $s.html('<div class="alert alert-warning py-2 mb-0">Map these columns first: <strong>' + esc((stats.missing || []).join(', ')) + '</strong></div>');
            $next.prop('disabled', true);
            return;
        }
        var html = '<div class="alert ' + (stats.valid > 0 ? 'alert-success' : 'alert-danger') + ' py-2 mb-0">'
            + '<strong>' + stats.valid + '</strong> message(s) ready for <strong>' + stats.contacts + '</strong> number(s)'
            + (stats.from ? ' · ' + esc(stats.from.slice(0, 10)) + ' → ' + esc(stats.to.slice(0, 10)) : '')
            + (stats.invalid ? ' · <span class="text-danger">' + stats.invalid + ' row(s) will be skipped</span>' : '')
            + '</div>';
        if (stats.errors && stats.errors.length) {
            html += '<ul class="small text-muted mb-0 mt-1 ps-3">' + stats.errors.map(function (e) { return '<li>' + esc(e) + '</li>'; }).join('') + '</ul>';
        }
        $s.html(html);
        $next.prop('disabled', stats.valid === 0);
    }

    function renderMapping(p) {
        var fields = p.fields || {};
        $('#chatImportFileName').text(p.filename);
        $('#chatImportRowCount').text(p.row_count + ' row(s)' + (p.truncated ? ' (first 50,000 only)' : ''));
        $('#chatImportMapping').html(p.headers.map(function (h, i) {
            var sample = (p.sample_rows || []).map(function (r) { return r[i] || ''; }).filter(Boolean).slice(0, 2).join(' · ');
            var opts = Object.keys(fields).map(function (k) {
                return '<option value="' + esc(k) + '"' + (p.mapping[h] === k ? ' selected' : '') + '>' + esc(fields[k]) + '</option>';
            }).join('');
            return '<tr><td class="fw-medium text-break">' + esc(h) + '</td>'
                + '<td><select class="form-select form-select-sm" data-header="' + esc(h) + '">' + opts + '</select></td>'
                + '<td class="small text-muted text-truncate" style="max-width:260px" title="' + esc(sample) + '">' + esc(sample) + '</td></tr>';
        }).join(''));
        renderStats(p.stats);
    }

    $import.on('show.bs.modal', function () { if (state.step !== 'map' && state.step !== 'backup') reset(); });
    $('#chatImportFile').on('change', function () { $next.prop('disabled', !this.files.length); });
    $import.on('click', '[data-import-restart]', reset);

    $('#chatImportMapping').on('change', 'select', function () {
        APP.post(base() + '/chat/transfer/preview', { token: state.preview.token, mapping: currentMapping() })
            .done(function (res) { renderStats(res && res.data ? res.data.stats : null); })
            .fail(function (xhr) { APP.toast(apiError(xhr, 'Could not check the file.'), 'error'); });
    });

    $next.on('click', function () {
        if (state.step === 'done') { reset(); return; }
        var html = $next.html();

        if (state.step === 'backup') { restoreBackup(); return; }

        if (state.step === 'upload') {
            var file = $('#chatImportFile')[0].files[0];
            if (!file) return;
            if (/\.zip$/i.test(file.name)) { uploadBackup(file); return; }
            var fd = new FormData();
            fd.append('file', file);
            $next.prop('disabled', true).html('<i class="fas fa-spinner fa-spin me-1"></i> Reading…');
            $.ajax({ url: base() + '/chat/transfer/preview', method: 'POST', data: fd, processData: false, contentType: false, dataType: 'json' })
                .done(function (res) {
                    if (!res || res.success === false) { APP.toast((res && res.message) || 'Could not read file.', 'error'); return; }
                    state.preview = res.data;
                    renderMapping(res.data);
                    setStep('map');
                })
                .fail(function (xhr) { APP.toast(apiError(xhr, 'Could not read file.'), 'error'); })
                .always(function () { $next.html(state.step === 'map' ? 'Import chats' : html); if (state.step === 'upload') $next.prop('disabled', false); });
            return;
        }

        $next.prop('disabled', true).html('<i class="fas fa-spinner fa-spin me-1"></i> Importing…');
        APP.post(base() + '/chat/transfer/import', {
            token: state.preview.token,
            mapping: currentMapping(),
            create_contacts: $('#chatImportCreateContacts').is(':checked') ? 1 : 0
        }).done(function (res) {
            var r = (res && res.data) || {};
            $('#chatImportResult').html(
                '<div class="alert alert-success mb-2"><i class="fas fa-circle-check me-1"></i> ' + esc(res.message || 'Import finished.') + '</div>'
                + resultGrid([['Imported', r.imported], ['Already imported', r.duplicates], ['Skipped', r.skipped], ['New contacts', r.contacts_created]])
                + errorList('Skipped rows', r.errors)
            );
            setStep('done');
            state.preview = null;
            refreshInbox();
        }).fail(function (xhr) {
            APP.toast(apiError(xhr, 'Import failed.'), 'error');
            $next.prop('disabled', false).html(html);
        });
    });
})(jQuery);
