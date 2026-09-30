/**
 * Small AJAX CRUD for settings-style tables (Attributes, Quick replies).
 *
 * Markup:
 *   <div data-crud="/attributes" data-modal="#attrModal"> … </div>
 *   <button data-crud-new>                                   open empty form
 *   <button data-crud-edit data-row='{"id":1,…}'>            open filled form
 *   <button data-crud-delete data-id="1" data-name="City">   delete after confirm
 *   Modal form fields use name="…" matching the row keys; [data-edit-lock] fields are read-only on edit.
 */
(function ($) {
    'use strict';

    function errorMessage(xhr, fallback) {
        return (xhr && xhr.responseJSON && xhr.responseJSON.message) || fallback;
    }

    $('[data-crud]').each(function () {
        var $root = $(this);
        var url = APP.baseUrl + $root.data('crud');
        var $modal = $($root.data('modal'));
        var $form = $modal.find('form');
        var $save = $form.find('[type="submit"]');

        function openForm(row) {
            $form[0].reset();
            $form.data('id', row ? row.id : null);
            $form.find('[data-edit-lock]').prop('readonly', !!row);
            if (row) {
                $form.find('[name]').each(function () {
                    var value = row[this.name];
                    if (value !== undefined && value !== null) {
                        $(this).val(Array.isArray(value) ? value.join('\n') : value);
                    }
                });
            }
            $modal.find('.modal-title').text(row ? $modal.data('titleEdit') : $modal.data('titleNew'));
            $form.trigger('crud:open', [row || null]);
            APP.showModal($modal[0]);
        }

        $(document).on('click', '[data-crud-new]', function () { openForm(null); });
        $root.on('click', '[data-crud-edit]', function () { openForm($(this).data('row')); });

        $form.on('submit', function (e) {
            e.preventDefault();
            var id = $form.data('id');
            $save.prop('disabled', true);
            APP.post(url + (id ? '/' + id : ''), $form.serialize())
                .done(function (res) {
                    APP.toast((res && res.message) || 'Saved', 'success');
                    APP.hideModal($modal[0]);
                    window.setTimeout(function () { window.location.reload(); }, 600);
                })
                .fail(function (xhr) { APP.toast(errorMessage(xhr, 'Could not save'), 'error'); })
                .always(function () { $save.prop('disabled', false); });
        });

        $root.on('click', '[data-crud-delete]', function () {
            var $btn = $(this);
            APP.confirm({
                title: 'Delete "' + $btn.data('name') + '"?',
                text: $btn.data('text') || 'This cannot be undone.',
                confirmText: 'Yes, delete'
            }).then(function (result) {
                if (!result.isConfirmed) {
                    return;
                }
                APP.post(url + '/' + $btn.data('id') + '/delete')
                    .done(function (res) {
                        APP.toast((res && res.message) || 'Deleted', 'success');
                        $btn.closest('tr').fadeOut(200, function () { $(this).remove(); });
                    })
                    .fail(function (xhr) { APP.toast(errorMessage(xhr, 'Could not delete'), 'error'); });
            });
        });
    });
})(jQuery);
