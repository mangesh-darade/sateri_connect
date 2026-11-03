(function ($) {
    'use strict';

    var $input = $('#gsearchInput');
    if (!$input.length) { return; }

    var $box = $('#gsearchResults');
    var base = (window.APP && APP.baseUrl) || '';
    var timer = null;
    var xhr = null;

    function esc(s) {
        return $('<div>').text(s == null ? '' : String(s)).html();
    }

    function group(title, rows) {
        if (!rows.length) { return ''; }
        return '<div class="gsearch-group">' + esc(title) + '</div>' + rows.join('');
    }

    function item(href, title, sub) {
        return '<a class="gsearch-item" href="' + esc(href) + '"><span>' + esc(title) + '</span>' +
            (sub ? '<small>' + esc(sub) + '</small>' : '') + '</a>';
    }

    function render(d) {
        var html = group('Contacts', (d.contacts || []).map(function (c) {
            return item(base + '/contacts/' + c.id, c.name || c.mobile, c.mobile || c.email);
        })) + group('Templates', (d.templates || []).map(function (t) {
            return item(base + '/templates/' + t.id, t.name, t.language + ' · ' + t.status);
        })) + group('Messages', (d.messages || []).map(function (m) {
            var text = String(m.content || '');
            return item(base + '/chat?contact_id=' + m.contact_id, m.contact_name || m.mobile,
                text.length > 60 ? text.slice(0, 60) + '…' : text);
        }));
        $box.html(html || '<div class="gsearch-empty">No results</div>').removeClass('d-none');
    }

    function run(q) {
        if (xhr) { xhr.abort(); }
        xhr = $.ajax({
            url: base + '/search',
            data: { q: q },
            dataType: 'json',
            global: false,
            success: function (res) { if (res && res.success) { render(res.data || {}); } },
            error: function (x, status) {
                if (status !== 'abort') { $box.html('<div class="gsearch-empty">Search failed</div>').removeClass('d-none'); }
            }
        });
    }

    $input.on('input', function () {
        var q = $.trim($input.val());
        clearTimeout(timer);
        if (q.length < 2) { $box.addClass('d-none'); return; }
        timer = setTimeout(function () { run(q); }, 250);
    });

    $input.on('keydown', function (e) {
        if (e.key === 'Escape') { $box.addClass('d-none'); $input.blur(); }
    });

    $(document).on('click', function (e) {
        if (!$(e.target).closest('#gsearch').length) { $box.addClass('d-none'); }
    });

    $(document).on('keydown', function (e) {
        if (e.key === '/' && !$(e.target).is('input, textarea, select, [contenteditable]')) {
            e.preventDefault();
            $input.trigger('focus');
        }
    });
})(jQuery);
