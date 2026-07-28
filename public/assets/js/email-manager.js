/**
 * Email Manager — AJAX for builder / drips / verifier / campaigns / senders
 */
(function () {
  'use strict';

  var root = document.getElementById('emailManager');
  if (!root) return;

  var base = (window.APP && window.APP.baseUrl) ? window.APP.baseUrl.replace(/\/$/, '') : '';

  function csrfToken() {
    var meta = document.querySelector('meta[name="csrf-token"]');
    return (meta && meta.getAttribute('content')) || (window.APP && APP.csrfToken) || '';
  }

  function csrfHeaderName() {
    var meta = document.querySelector('meta[name="csrf-header"]');
    return (meta && meta.getAttribute('content')) || (window.APP && APP.csrfHeader) || 'X-CSRF-TOKEN';
  }

  function msg(el, text, ok) {
    if (!el) return;
    el.textContent = text || '';
    el.className = 'em-msg mt-2 small ' + (ok ? 'ok' : 'err');
  }

  function post(url, data) {
    var headers = {
      'Content-Type': 'application/json',
      'X-Requested-With': 'XMLHttpRequest'
    };
    headers[csrfHeaderName()] = csrfToken();

    return fetch(base + '/' + url.replace(/^\//, ''), {
      method: 'POST',
      headers: headers,
      credentials: 'same-origin',
      body: JSON.stringify(data || {})
    }).then(function (r) {
      var next = r.headers.get(csrfHeaderName()) || r.headers.get('X-CSRF-TOKEN');
      if (next) {
        if (window.APP) APP.csrfToken = next;
        var meta = document.querySelector('meta[name="csrf-token"]');
        if (meta) meta.setAttribute('content', next);
      }
      return r.json();
    });
  }

  function parseRow(el, attr) {
    try {
      return JSON.parse(el.getAttribute(attr) || '{}');
    } catch (e) {
      return {};
    }
  }

  // ── Builder ──────────────────────────────────────────
  var builderForm = document.getElementById('builderForm');
  if (builderForm) {
    builderForm.addEventListener('submit', function (e) {
      e.preventDefault();
      var payload = {
        id: document.getElementById('builder_id').value,
        name: document.getElementById('builder_name').value,
        subject: document.getElementById('builder_subject').value,
        cheerio_builder_id: document.getElementById('builder_cheerio_id').value,
        html_content: document.getElementById('builder_html').value,
        status: document.getElementById('builder_status').value
      };
      post('email-manager/builders', payload).then(function (res) {
        msg(document.getElementById('builderMsg'), res.message || (res.success ? 'Saved' : 'Failed'), !!res.success);
        if (res.success) setTimeout(function () { location.reload(); }, 600);
      }).catch(function (err) {
        msg(document.getElementById('builderMsg'), err.message || 'Network error', false);
      });
    });

    var resetBtn = document.getElementById('builderReset');
    if (resetBtn) {
      resetBtn.addEventListener('click', function () {
        builderForm.reset();
        document.getElementById('builder_id').value = '';
        msg(document.getElementById('builderMsg'), '', true);
      });
    }

    document.querySelectorAll('.em-edit-builder').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var row = btn.closest('tr');
        var b = parseRow(row, 'data-builder');
        document.getElementById('builder_id').value = b.id || '';
        document.getElementById('builder_name').value = b.name || '';
        document.getElementById('builder_subject').value = b.subject || '';
        document.getElementById('builder_cheerio_id').value = b.cheerio_builder_id || '';
        document.getElementById('builder_html').value = b.html_content || '';
        document.getElementById('builder_status').value = b.status || 'draft';
        window.scrollTo({ top: 0, behavior: 'smooth' });
      });
    });

    document.querySelectorAll('.em-del-builder').forEach(function (btn) {
      btn.addEventListener('click', function () {
        if (!confirm('Delete this builder?')) return;
        post('email-manager/builders/' + btn.getAttribute('data-id') + '/delete', {}).then(function (res) {
          if (res.success) location.reload();
          else alert(res.message || 'Delete failed');
        });
      });
    });
  }

  // ── Drips ────────────────────────────────────────────
  var dripBuilders = [];
  var buildersEl = document.getElementById('dripBuildersJson');
  if (buildersEl) {
    try { dripBuilders = JSON.parse(buildersEl.textContent || '[]'); } catch (e) { dripBuilders = []; }
  }

  function builderOptions(selected) {
    var html = '<option value="">— HTML only —</option>';
    dripBuilders.forEach(function (b) {
      html += '<option value="' + b.id + '"' + (String(selected) === String(b.id) ? ' selected' : '') + '>' +
        (b.name || ('#' + b.id)) + '</option>';
    });
    return html;
  }

  function addDripStep(data) {
    data = data || {};
    var wrap = document.getElementById('dripSteps');
    if (!wrap) return;
    var div = document.createElement('div');
    div.className = 'em-step-row';
    div.innerHTML =
      '<div class="row g-1 align-items-end">' +
        '<div class="col-5"><label class="form-label small mb-0">Subject</label>' +
          '<input type="text" class="form-control form-control-sm step-subject" value="' + (data.subject || '').replace(/"/g, '&quot;') + '" required></div>' +
        '<div class="col-2"><label class="form-label small mb-0">Delay h</label>' +
          '<input type="number" min="0" class="form-control form-control-sm step-delay" value="' + (data.delay_hours || 0) + '"></div>' +
        '<div class="col-4"><label class="form-label small mb-0">Builder</label>' +
          '<select class="form-select form-select-sm step-builder">' + builderOptions(data.builder_id) + '</select></div>' +
        '<div class="col-1"><button type="button" class="btn btn-xs btn-outline-danger step-remove">&times;</button></div>' +
        '<div class="col-12 mt-1"><textarea class="form-control form-control-sm step-html" rows="2" placeholder="HTML (optional if builder)">' +
          (data.html_content || '') + '</textarea></div>' +
      '</div>';
    wrap.appendChild(div);
    div.querySelector('.step-remove').addEventListener('click', function () { div.remove(); });
  }

  var dripAdd = document.getElementById('dripAddStep');
  if (dripAdd) {
    dripAdd.addEventListener('click', function () { addDripStep({}); });
    if (document.getElementById('dripSteps') && !document.getElementById('dripSteps').children.length) {
      addDripStep({});
    }
  }

  var dripForm = document.getElementById('dripForm');
  if (dripForm) {
    dripForm.addEventListener('submit', function (e) {
      e.preventDefault();
      var steps = [];
      document.querySelectorAll('#dripSteps .em-step-row').forEach(function (row) {
        steps.push({
          subject: row.querySelector('.step-subject').value,
          delay_hours: parseInt(row.querySelector('.step-delay').value, 10) || 0,
          builder_id: row.querySelector('.step-builder').value || null,
          html_content: row.querySelector('.step-html').value
        });
      });
      var payload = {
        id: document.getElementById('drip_id').value,
        name: document.getElementById('drip_name').value,
        description: document.getElementById('drip_description').value,
        trigger_type: document.getElementById('drip_trigger').value,
        trigger_value: document.getElementById('drip_trigger_value').value,
        status: document.getElementById('drip_status').value,
        steps: steps
      };
      post('email-manager/drips', payload).then(function (res) {
        msg(document.getElementById('dripMsg'), res.message || '', !!res.success);
        if (res.success) setTimeout(function () { location.reload(); }, 600);
      }).catch(function (err) {
        msg(document.getElementById('dripMsg'), err.message || 'Error', false);
      });
    });

    document.getElementById('dripReset') && document.getElementById('dripReset').addEventListener('click', function () {
      dripForm.reset();
      document.getElementById('drip_id').value = '';
      document.getElementById('dripSteps').innerHTML = '';
      addDripStep({});
    });

    document.querySelectorAll('.em-edit-drip').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var card = btn.closest('.em-drip-card');
        var d = parseRow(card, 'data-drip');
        document.getElementById('drip_id').value = d.id || '';
        document.getElementById('drip_name').value = d.name || '';
        document.getElementById('drip_description').value = d.description || '';
        document.getElementById('drip_trigger').value = d.trigger_type || 'manual';
        document.getElementById('drip_trigger_value').value = d.trigger_value || '';
        document.getElementById('drip_status').value = d.status || 'draft';
        document.getElementById('dripSteps').innerHTML = '';
        (d.steps || []).forEach(function (s) { addDripStep(s); });
        if (!(d.steps || []).length) addDripStep({});
        window.scrollTo({ top: 0, behavior: 'smooth' });
      });
    });

    document.querySelectorAll('.em-del-drip').forEach(function (btn) {
      btn.addEventListener('click', function () {
        if (!confirm('Delete this drip?')) return;
        post('email-manager/drips/' + btn.getAttribute('data-id') + '/delete', {}).then(function (res) {
          if (res.success) location.reload();
          else alert(res.message || 'Failed');
        });
      });
    });

    document.querySelectorAll('.em-send-step').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var to = prompt('Send this drip step to email:');
        if (!to) return;
        post('email-manager/drips/send-step', {
          drip_id: btn.getAttribute('data-drip-id'),
          step_id: btn.getAttribute('data-step-id'),
          to: to
        }).then(function (res) {
          alert(res.message || (res.success ? 'Sent' : 'Failed'));
        });
      });
    });
  }

  // ── Verifier ─────────────────────────────────────────
  var verifyForm = document.getElementById('verifyForm');
  if (verifyForm) {
    verifyForm.addEventListener('submit', function (e) {
      e.preventDefault();
      post('email-manager/verify', { emails: document.getElementById('verify_emails').value }).then(function (res) {
        msg(document.getElementById('verifyMsg'), res.message || '', !!res.success);
        if (!res.success || !res.data || !res.data.results) return;
        var tbody = document.querySelector('#verifyTable tbody');
        if (!tbody) return;
        tbody.innerHTML = '';
        res.data.results.forEach(function (v) {
          var tr = document.createElement('tr');
          tr.innerHTML = '<td>' + (v.email || '') + '</td>' +
            '<td><span class="badge em-status-' + (v.status || '') + '">' + (v.status || '') + '</span></td>' +
            '<td>' + (v.syntax_ok ? '✓' : '✗') + '</td>' +
            '<td>' + (v.mx_ok ? '✓' : '✗') + '</td>' +
            '<td>' + (v.disposable ? 'yes' : 'no') + '</td>';
          tbody.appendChild(tr);
        });
      }).catch(function (err) {
        msg(document.getElementById('verifyMsg'), err.message || 'Error', false);
      });
    });
  }

  // ── Campaigns ────────────────────────────────────────
  var campMode = document.getElementById('camp_mode');
  function syncCampMode() {
    var label = campMode && campMode.value === 'label';
    var rw = document.getElementById('campRecipientsWrap');
    var lw = document.getElementById('campLabelWrap');
    if (rw) rw.classList.toggle('d-none', !!label);
    if (lw) lw.classList.toggle('d-none', !label);
  }
  if (campMode) {
    campMode.addEventListener('change', syncCampMode);
    syncCampMode();
  }

  var campBuilder = document.getElementById('camp_builder');
  if (campBuilder) {
    campBuilder.addEventListener('change', function () {
      var opt = campBuilder.options[campBuilder.selectedIndex];
      var cid = opt ? opt.getAttribute('data-cheerio') : '';
      if (cid) document.getElementById('camp_cheerio_id').value = cid;
    });
  }

  var campaignForm = document.getElementById('campaignForm');
  if (campaignForm) {
    campaignForm.addEventListener('submit', function (e) {
      e.preventDefault();
      var payload = {
        id: document.getElementById('camp_id').value,
        name: document.getElementById('camp_name').value,
        subject: document.getElementById('camp_subject').value,
        builder_id: document.getElementById('camp_builder').value || null,
        cheerio_builder_id: document.getElementById('camp_cheerio_id').value,
        html_content: document.getElementById('camp_html').value,
        mode: document.getElementById('camp_mode').value,
        recipients: document.getElementById('camp_recipients').value,
        label_name: document.getElementById('camp_label').value
      };
      post('email-manager/campaigns', payload).then(function (res) {
        msg(document.getElementById('campMsg'), res.message || '', !!res.success);
        if (res.success) setTimeout(function () { location.reload(); }, 600);
      }).catch(function (err) {
        msg(document.getElementById('campMsg'), err.message || 'Error', false);
      });
    });

    document.getElementById('campReset') && document.getElementById('campReset').addEventListener('click', function () {
      campaignForm.reset();
      document.getElementById('camp_id').value = '';
      syncCampMode();
    });

    document.querySelectorAll('.em-send-camp').forEach(function (btn) {
      btn.addEventListener('click', function () {
        if (!confirm('Send this HTML campaign now via active email provider?')) return;
        post('email-manager/campaigns/' + btn.getAttribute('data-id') + '/send', {}).then(function (res) {
          alert(res.message || (res.success ? 'Sent' : 'Failed'));
          if (res.success) location.reload();
        });
      });
    });

    document.querySelectorAll('.em-del-camp').forEach(function (btn) {
      btn.addEventListener('click', function () {
        if (!confirm('Delete campaign?')) return;
        post('email-manager/campaigns/' + btn.getAttribute('data-id') + '/delete', {}).then(function (res) {
          if (res.success) location.reload();
          else alert(res.message || 'Failed');
        });
      });
    });
  }

  // ── Senders ──────────────────────────────────────────
  var senderType = document.getElementById('sender_type');
  function syncSenderType() {
    var isDomain = senderType && senderType.value === 'domain';
    var ew = document.getElementById('senderEmailWrap');
    var dw = document.getElementById('senderDomainWrap');
    if (ew) ew.classList.toggle('d-none', !!isDomain);
    if (dw) dw.classList.toggle('d-none', !isDomain);
  }
  if (senderType) {
    senderType.addEventListener('change', syncSenderType);
    syncSenderType();
  }

  var senderForm = document.getElementById('senderForm');
  if (senderForm) {
    senderForm.addEventListener('submit', function (e) {
      e.preventDefault();
      var payload = {
        id: document.getElementById('sender_id').value,
        type: document.getElementById('sender_type').value,
        name: document.getElementById('sender_name').value,
        email: document.getElementById('sender_email').value,
        domain: document.getElementById('sender_domain').value,
        cheerio_id: document.getElementById('sender_cheerio_id').value,
        status: document.getElementById('sender_status').value,
        notes: document.getElementById('sender_notes').value,
        is_default: document.getElementById('sender_default').checked ? 1 : 0
      };
      post('email-manager/senders', payload).then(function (res) {
        msg(document.getElementById('senderMsg'), res.message || '', !!res.success);
        if (res.success) setTimeout(function () { location.reload(); }, 700);
      }).catch(function (err) {
        msg(document.getElementById('senderMsg'), err.message || 'Error', false);
      });
    });

    document.getElementById('senderReset') && document.getElementById('senderReset').addEventListener('click', function () {
      senderForm.reset();
      document.getElementById('sender_id').value = '';
      syncSenderType();
    });

    document.querySelectorAll('.em-edit-sender').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var row = btn.closest('tr');
        var s = parseRow(row, 'data-sender');
        document.getElementById('sender_id').value = s.id || '';
        document.getElementById('sender_type').value = s.type || 'sender';
        document.getElementById('sender_name').value = s.name || '';
        document.getElementById('sender_email').value = s.email || '';
        document.getElementById('sender_domain').value = s.domain || '';
        document.getElementById('sender_cheerio_id').value = s.cheerio_id || '';
        document.getElementById('sender_status').value = s.status || 'pending';
        document.getElementById('sender_notes').value = s.notes || '';
        document.getElementById('sender_default').checked = !!Number(s.is_default);
        syncSenderType();
        window.scrollTo({ top: 0, behavior: 'smooth' });
      });
    });

    document.querySelectorAll('.em-del-sender').forEach(function (btn) {
      btn.addEventListener('click', function () {
        if (!confirm('Delete this record?')) return;
        post('email-manager/senders/' + btn.getAttribute('data-id') + '/delete', {}).then(function (res) {
          if (res.success) location.reload();
          else alert(res.message || 'Failed');
        });
      });
    });
  }
})();
