/**
 * Amazon SES sending identities — domain setup, DNS records modal, verification check,
 * sender emails. Shared by Settings → Email Settings and Email Manager → Sender Identities.
 *
 * Markup contract:
 *   #sesIdentityForm            domain setup form (domain, email, name, dmarc_policy)
 *   #sesSenderForm              sender email form (email, name, is_default)
 *   #sesDnsModal                partials/ses_dns_modal
 *   .em-ses-dns                 button inside [data-sender] row → show stored DNS records
 *   .em-ses-check[data-domain]  button → re-check verification with AWS + live DNS
 *   .em-ses-default[data-id]    button → make sender the default From address
 *   .em-ses-delete[data-id]     button → delete local identity row
 */
(function ($) {
  'use strict';

  if (!$ || !window.APP) return;

  var APP = window.APP;
  var base = (APP.baseUrl || '').replace(/\/$/, '');
  var esc = APP.escapeHtml;
  var dirty = false;
  var currentDomain = '';

  function url(path) {
    return base + '/' + path.replace(/^\//, '');
  }

  function toast(text, icon) {
    APP.toast(text, icon || 'success');
  }

  /** Resolves with the JSON body for both 2xx and error responses. */
  function post(path, data) {
    return new Promise(function (resolve) {
      APP.post(url(path), data || {}).done(resolve).fail(function (xhr) {
        resolve(xhr && xhr.responseJSON ? xhr.responseJSON : { success: false, message: 'Request failed (' + (xhr ? xhr.status : 0) + ').' });
      });
    });
  }

  function errorText(res) {
    var errs = res && res.errors ? Object.keys(res.errors).map(function (k) { return res.errors[k]; }).join(' ') : '';
    return ((res && res.message) || 'Something went wrong.') + (errs ? ' ' + errs : '');
  }

  function busy(btn, on, text) {
    if (!btn) return;
    if (on) {
      btn.setAttribute('data-label', btn.innerHTML);
      btn.disabled = true;
      btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>' + (text || 'Please wait…');
    } else {
      btn.disabled = false;
      btn.innerHTML = btn.getAttribute('data-label') || btn.innerHTML;
    }
  }

  function setMsg(el, text, ok) {
    if (!el) return;
    el.textContent = text || '';
    el.className = (el.getAttribute('data-base-class') || 'small mt-2') + ' ' + (ok ? 'text-success' : 'text-danger');
  }

  function copyValue(value) {
    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(value).then(function () { toast('Copied'); });
      return;
    }
    var ta = document.createElement('textarea');
    ta.value = value;
    document.body.appendChild(ta);
    ta.select();
    try { document.execCommand('copy'); toast('Copied'); } catch (e) { /* ignore */ }
    document.body.removeChild(ta);
  }

  /** Accepts either an API identity payload or a raw email_senders row. */
  function normalize(src) {
    src = src || {};
    var dns = src.dns_records;
    if (Array.isArray(dns)) {
      return { domain: src.domain, status: src.status, records: dns, verification: src.verification || {}, steps: src.next_steps || [], checked: src.last_checked_at || '' };
    }
    dns = dns || {};
    return { domain: src.domain, status: src.status, records: dns.records || [], verification: dns.verification || {}, steps: [], checked: dns.checked_at || src.last_checked_at || '' };
  }

  function statusClass(st) {
    return st === 'verified' ? 'bg-success' : (st === 'failed' ? 'bg-danger' : 'bg-warning text-dark');
  }

  function awsBadge(st) {
    if (!st) return '<span class="text-muted">—</span>';
    var cls = st === 'SUCCESS' ? 'text-success' : (st === 'FAILED' || st === 'NOT_FOUND' ? 'text-danger' : 'text-warning');
    return '<span class="fw-semibold ' + cls + '">' + esc(st) + '</span>';
  }

  function copyBtn(value, title) {
    return ' <button type="button" class="btn btn-link btn-sm p-0 align-baseline em-ses-copy" data-copy="' + esc(value) + '" title="' + esc(title) + '"><i class="far fa-copy"></i></button>';
  }

  function recordRow(r) {
    var found = r.dns_found === true ? '<span class="text-success"><i class="fas fa-check-circle"></i> Found</span>'
      : (r.dns_found === false ? '<span class="text-danger"><i class="fas fa-times-circle"></i> Missing</span>' : '<span class="text-muted">—</span>');

    return '<tr>' +
      '<td><div class="fw-semibold small">' + esc(r.category) + '</div><div class="text-muted" style="font-size:.72rem;max-width:220px">' + esc(r.purpose) + '</div></td>' +
      '<td><span class="badge bg-dark">' + esc(r.type) + '</span></td>' +
      '<td class="font-monospace small"><div>' + esc(r.host) + copyBtn(r.host, 'Copy host') + '</div>' +
        '<div class="text-muted" style="font-size:.7rem">' + esc(r.name) + copyBtn(r.name, 'Copy full name') + '</div></td>' +
      '<td class="font-monospace small text-break" style="max-width:320px">' + esc(r.value) + copyBtn(r.value, 'Copy value') +
        (r.priority ? '<div class="text-muted" style="font-size:.7rem">Priority: ' + esc(r.priority) + '</div>' : '') + '</td>' +
      '<td>' + (r.required ? '<span class="badge bg-primary">Required</span>' : '<span class="badge bg-secondary">Optional</span>') + '</td>' +
      '<td class="small">' + found + '</td>' +
      '</tr>';
  }

  function showDns(src) {
    var modal = document.getElementById('sesDnsModal');
    if (!modal) return;
    var v = normalize(src);
    currentDomain = v.domain || '';

    document.getElementById('sesDnsDomain').textContent = v.domain || '';
    var st = document.getElementById('sesDnsStatus');
    st.className = 'badge ms-2 text-capitalize ' + statusClass(v.status);
    st.textContent = v.status || 'pending';

    var ver = v.verification || {};
    var summary = [['Domain', ver.domain_status], ['DKIM', ver.dkim_status], ['MAIL FROM', ver.mail_from_status]];
    if (ver.email_status) summary.push(['From Email', ver.email_status]);
    document.getElementById('sesDnsSummary').innerHTML = summary.map(function (p) {
      return '<div class="col-6 col-md-3"><div class="border rounded-2 px-2 py-1"><span class="text-muted">' + p[0] + ':</span> ' + awsBadge(p[1]) + '</div></div>';
    }).join('');

    var rows = (v.records || []).map(recordRow);
    document.getElementById('sesDnsRows').innerHTML = rows.length ? rows.join('')
      : '<tr><td colspan="6" class="text-center text-muted py-4">No records yet — click "Check Verification".</td></tr>';

    document.getElementById('sesDnsSteps').innerHTML = (v.steps || []).map(function (s) { return '<li>' + esc(s) + '</li>'; }).join('');
    document.getElementById('sesDnsChecked').textContent = v.checked ? ('Last checked: ' + v.checked) : '';

    APP.showModal(modal);
  }

  function check(domain, btn) {
    if (!domain) return Promise.resolve(null);
    busy(btn, true, 'Checking…');
    return post('email-manager/ses-identities/check', { domain: domain }).then(function (res) {
      busy(btn, false);
      if (!res.success) {
        toast(errorText(res), 'error');
        return res;
      }
      dirty = true;
      toast(res.message || 'Updated', res.data && res.data.status === 'verified' ? 'success' : 'info');
      showDns(res.data || {});
      return res;
    });
  }

  function rowData(el) {
    var row = el.closest('[data-sender]');
    try { return JSON.parse(row ? row.getAttribute('data-sender') : '{}') || {}; } catch (e) { return {}; }
  }

  $(document).on('click', '#sesDnsModal .em-ses-copy', function () {
    copyValue(this.getAttribute('data-copy') || '');
  });

  $(document).on('hidden.bs.modal', '#sesDnsModal', function () {
    if (dirty) location.reload();
  });

  $(document).on('click', '.em-ses-dns', function () {
    showDns(rowData(this));
  });

  $(document).on('click', '.em-ses-check', function () {
    check(this.getAttribute('data-domain'), this);
  });

  $(document).on('click', '#sesDnsRecheck', function () {
    check(currentDomain, this);
  });

  $(document).on('submit', '#sesIdentityForm', function (e) {
    e.preventDefault();
    var form = this;
    var btn = form.querySelector('button[type="submit"]');
    var out = form.querySelector('.js-ses-msg');
    setMsg(out, '', true);
    busy(btn, true, 'Connecting to Amazon SES…');
    post('email-manager/ses-identities', {
      domain: form.domain.value,
      email: form.email ? form.email.value : '',
      name: form.name ? form.name.value : '',
      dmarc_policy: form.dmarc_policy ? form.dmarc_policy.value : 'none'
    }).then(function (res) {
      busy(btn, false);
      if (!res.success) {
        setMsg(out, errorText(res), false);
        return;
      }
      dirty = true;
      form.reset();
      var parentModal = form.closest('.modal');
      if (parentModal) APP.hideModal(parentModal);
      toast(res.message || 'Domain added');
      showDns(res.data || {});
    });
  });

  $(document).on('submit', '#sesSenderForm', function (e) {
    e.preventDefault();
    var form = this;
    var btn = form.querySelector('button[type="submit"]');
    var out = form.querySelector('.js-ses-msg');
    setMsg(out, '', true);
    busy(btn, true, 'Saving…');
    post('email-manager/ses-senders', {
      email: form.email.value,
      name: form.name.value,
      is_default: form.is_default && form.is_default.checked ? 1 : 0
    }).then(function (res) {
      busy(btn, false);
      if (!res.success) {
        setMsg(out, errorText(res), false);
        return;
      }
      toast(res.message || 'Sender saved');
      location.reload();
    });
  });

  $(document).on('click', '.em-ses-default', function () {
    var btn = this;
    busy(btn, true, '');
    post('email-manager/ses-senders/' + btn.getAttribute('data-id') + '/default', {}).then(function (res) {
      busy(btn, false);
      if (!res.success) {
        toast(errorText(res), 'error');
        return;
      }
      toast(res.message || 'Default sender updated');
      location.reload();
    });
  });

  $(document).on('click', '.em-ses-delete', function () {
    var btn = this;
    APP.confirm({
      title: 'Remove ' + (btn.getAttribute('data-label') || 'this identity') + '?',
      text: btn.getAttribute('data-confirm-text') || 'It will be removed from this app.',
      confirmText: 'Yes, remove'
    }).then(function (result) {
      if (!result.isConfirmed) return;
      post('email-manager/senders/' + btn.getAttribute('data-id') + '/delete', {}).then(function (res) {
        if (!res.success) {
          toast(errorText(res), 'error');
          return;
        }
        toast('Removed');
        location.reload();
      });
    });
  });

  // Bounce & delivery tracking (SES → SNS → webhook)
  var BOUNCE_STATES = {
    connected:      ['Connected', 'bg-success-subtle text-success border-success-subtle'],
    pending:        ['Waiting for Amazon confirmation', 'bg-warning-subtle text-warning-emphasis border-warning-subtle'],
    not_subscribed: ['Webhook not subscribed', 'bg-danger-subtle text-danger border-danger-subtle'],
    unknown:        ['Saved — check status', 'bg-light text-muted'],
    not_connected:  ['Not connected', 'bg-secondary-subtle text-secondary border-secondary-subtle']
  };

  function renderBounceState(state) {
    var s = BOUNCE_STATES[state] || BOUNCE_STATES.unknown;
    $('#sesBounceCard').attr('data-state', state);
    $('#sesBounceBadge').attr('class', 'badge border ' + s[1]).text(s[0]);
  }

  function checkBounce(btn, quiet) {
    busy(btn, true, 'Checking…');
    APP.get(url('email-manager/ses-bounce-tracking'), {}, { global: false }).done(function (res) {
      var d = (res && res.data) || {};
      renderBounceState(d.state || 'not_connected');
      if (d.webhook) $('#sesBounceWebhook').text(d.webhook);
      if (!quiet) setMsg(document.querySelector('.js-bounce-msg'), d.state === 'connected' ? 'Amazon is sending bounce & delivery events to this app.' : '', true);
    }).fail(function (xhr) {
      if (!quiet) setMsg(document.querySelector('.js-bounce-msg'), errorText(xhr && xhr.responseJSON), false);
    }).always(function () {
      busy(btn, false);
    });
  }

  if ($('#sesBounceCard').length) {
    renderBounceState($('#sesBounceCard').attr('data-state'));
    if ($('#sesBounceCard').attr('data-state') !== 'not_connected') checkBounce(null, true);
  }

  $(document).on('click', '#sesBounceCheck', function () {
    checkBounce(this, false);
  });

  $(document).on('click', '#sesBounceConnect', function () {
    var btn = this;
    var msg = document.querySelector('.js-bounce-msg');
    busy(btn, true, 'Connecting…');
    post('email-manager/ses-bounce-tracking', {}).then(function (res) {
      busy(btn, false);
      setMsg(msg, res.success ? res.message : errorText(res), !!res.success);
      if (!res.success) return;
      toast('Bounce tracking connected');
      renderBounceState((res.data && res.data.state) || 'unknown');
      setTimeout(function () { checkBounce(null, false); }, 8000);
    });
  });

  // Marketing footer postal address (Settings → Email Settings).
  $(document).on('submit', '#emailComplianceForm', function (e) {
    e.preventDefault();
    var form = this;
    var btn = form.querySelector('button[type="submit"]');
    var msg = form.querySelector('.js-compliance-msg');
    busy(btn, true, 'Saving…');
    post('settings/save', $(form).serialize()).then(function (res) {
      busy(btn, false);
      setMsg(msg, res.success ? 'Address saved.' : errorText(res), !!res.success);
      if (res.success) toast('Footer address saved');
    });
  });

  APP.emailIdentities = { showDns: showDns, check: check };
})(window.jQuery);
