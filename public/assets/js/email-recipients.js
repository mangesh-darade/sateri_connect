/**
 * Per-recipient status of an email send (partials/email_recipients_modal).
 * Trigger: <button class="js-email-recipients" data-log-id="123">.
 */
(function ($) {
  'use strict';

  if (!$ || !window.APP) return;

  var APP = window.APP;
  var esc = APP.escapeHtml;
  var rows = [];

  var STATUS = {
    sent:      ['Sent', 'bg-secondary-subtle text-secondary-emphasis', 'fa-paper-plane'],
    delivered: ['Delivered', 'bg-success-subtle text-success', 'fa-check'],
    opened:    ['Opened', 'bg-info-subtle text-info-emphasis', 'fa-eye'],
    clicked:   ['Clicked', 'bg-primary-subtle text-primary', 'fa-mouse-pointer'],
    bounced:   ['Bounced', 'bg-danger-subtle text-danger', 'fa-times-circle'],
    complaint: ['Spam complaint', 'bg-danger text-white', 'fa-flag'],
    failed:    ['Failed', 'bg-danger-subtle text-danger', 'fa-exclamation-triangle'],
    unknown:   ['Unknown', 'bg-light text-muted', 'fa-question']
  };

  function badge(status) {
    var s = STATUS[status] || STATUS.unknown;
    return '<span class="badge border ' + s[1] + '"><i class="fas ' + s[2] + ' me-1"></i>' + s[0] + '</span>';
  }

  function details(r) {
    var parts = [];
    if (r.error) parts.push('<span class="text-danger">' + esc(r.error) + '</span>');
    if (r.bounce) parts.push('<span class="text-danger">' + esc(r.bounce) + '</span>');
    if (r.complaint_at) parts.push('Marked as spam · ' + esc(r.complaint_at));
    if (r.delivered_at && !r.bounce) parts.push('Delivered · ' + esc(r.delivered_at));
    if (r.opened_at) parts.push('First open · ' + esc(r.opened_at));
    if (r.send === 'unknown') parts.push('<span class="text-muted">Sent before per-recipient tracking</span>');
    if (!parts.length && r.send === 'sent') parts.push('<span class="text-muted">Accepted by mail server</span>');
    return '<div class="small">' + parts.join('<br>') + '</div>';
  }

  function render() {
    var q = String($('#erSearch').val() || '').toLowerCase();
    var f = String($('#erFilter').val() || '');
    var list = rows.filter(function (r) {
      return (!q || r.email.indexOf(q) !== -1) && (!f || r.status === f);
    });

    $('#erRows').html(list.length ? list.map(function (r) {
      return '<tr>' +
        '<td class="fw-semibold text-break">' + esc(r.email) + '</td>' +
        '<td class="text-nowrap">' + badge(r.status) + '</td>' +
        '<td style="max-width:420px">' + details(r) + '</td>' +
        '<td class="text-center">' + (r.opens || '<span class="text-muted">—</span>') + '</td>' +
        '<td class="text-center">' + (r.clicks || '<span class="text-muted">—</span>') + '</td>' +
        '</tr>';
    }).join('') : '<tr><td colspan="5" class="text-center text-muted py-4">No recipients match.</td></tr>');
  }

  function load(logId) {
    rows = [];
    $('#erSubject').text('');
    $('#erMeta, #erCounts').empty();
    $('#erNote').addClass('d-none');
    $('#erSearch').val('');
    $('#erFilter').val('');
    $('#erRows').html('<tr><td colspan="5" class="text-center text-muted py-4"><i class="fas fa-spinner fa-spin me-1"></i>Loading…</td></tr>');
    APP.showModal('#emailRecipientsModal');

    APP.get((APP.baseUrl || '').replace(/\/$/, '') + '/emails/logs/' + logId + '/recipients', {}, { global: false }).done(function (res) {
      if (!res || !res.success) {
        $('#erRows').html('<tr><td colspan="5" class="text-center text-danger py-4">' + esc((res && res.message) || 'Could not load recipients.') + '</td></tr>');
        return;
      }
      var d = res.data || {};
      var log = d.log || {};
      rows = d.recipients || [];

      $('#erSubject').text(log.subject || '(No subject)');
      $('#erMeta').text([log.kind ? log.kind.charAt(0).toUpperCase() + log.kind.slice(1) : '', (log.provider || '').toUpperCase(), log.created_at, rows.length + ' recipient(s)'].filter(Boolean).join(' · '));

      var counts = d.counts || {};
      $('#erCounts').html(Object.keys(STATUS).filter(function (k) { return counts[k]; }).map(function (k) {
        return '<button type="button" class="btn btn-sm btn-light border js-er-filter" data-status="' + k + '">' + badge(k) + ' <span class="fw-bold ms-1">' + counts[k] + '</span></button>';
      }).join(''));

      if (!d.tracked) {
        $('#erNote').removeClass('d-none').html('<i class="fas fa-info-circle me-1"></i>This email was sent before per-recipient tracking was added, so the status shown is the overall send result. Later bounces cannot be matched to these addresses — new sends track sent / failed / bounced per recipient.');
      }
      render();
    }).fail(function (xhr) {
      var msg = (xhr && xhr.responseJSON && xhr.responseJSON.message) || 'Could not load recipients.';
      $('#erRows').html('<tr><td colspan="5" class="text-center text-danger py-4">' + esc(msg) + '</td></tr>');
    });
  }

  $(document).on('click', '.js-email-recipients', function (e) {
    e.preventDefault();
    load($(this).data('log-id'));
  });
  $(document).on('input change', '#erSearch, #erFilter', render);
  $(document).on('click', '.js-er-filter', function () {
    var s = $(this).data('status');
    $('#erFilter').val($('#erFilter').val() === s ? '' : s);
    render();
  });
})(window.jQuery);
