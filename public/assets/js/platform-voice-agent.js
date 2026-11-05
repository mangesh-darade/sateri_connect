(function () {
    'use strict';

    var page = document.getElementById('vaPage');
    if (!page) { return; }

    var base = page.getAttribute('data-base');
    var tokenMeta = document.querySelector('meta[name="csrf-token"]');
    var headerMeta = document.querySelector('meta[name="csrf-header"]');

    function toast(message, ok) {
        var el = document.createElement('div');
        el.className = 'platform-alert ' + (ok ? 'platform-alert-ok' : 'platform-alert-err');
        el.setAttribute('role', 'status');
        el.style.cssText = 'position:fixed;top:16px;right:16px;z-index:2000;max-width:420px;box-shadow:0 6px 24px rgba(0,0,0,.18)';
        el.textContent = message;
        document.body.appendChild(el);
        window.setTimeout(function () { el.remove(); }, ok ? 3200 : 6500);
    }

    function send(method, path, body, button) {
        var headers = { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' };
        if (tokenMeta && headerMeta) { headers[headerMeta.content] = tokenMeta.content; }
        var opts = { method: method, headers: headers, credentials: 'same-origin' };
        if (body !== null && body !== undefined) {
            headers['Content-Type'] = 'application/json';
            opts.body = JSON.stringify(body);
        }
        if (button) { button.disabled = true; }
        return fetch(base + path, opts)
            .then(function (r) { return r.json().catch(function () { return { success: false, message: 'Unexpected answer from the server.' }; }); })
            .catch(function () { return { success: false, message: 'The request failed. Check your connection.' }; })
            .then(function (res) {
                if (button) { button.disabled = false; }
                return res;
            });
    }

    function done(res, okText) {
        toast(res.message || (res.success ? okText : 'Something went wrong.'), !!res.success);
        return res;
    }

    function formData(form) {
        var out = {};
        Array.prototype.forEach.call(form.elements, function (el) {
            if (!el.name) { return; }
            out[el.name] = el.type === 'checkbox' ? (el.checked ? '1' : '0') : el.value;
        });
        return out;
    }

    // 1. connection
    document.getElementById('vaConnForm').addEventListener('submit', function (e) {
        e.preventDefault();
        var btn = e.submitter || null;
        send('POST', '/connection', formData(this), btn).then(function (res) {
            done(res, 'Saved.');
            if (res.success) { document.getElementById('vaPass').value = ''; document.getElementById('vaLeads').value = ''; }
        });
    });
    document.getElementById('vaPing').addEventListener('click', function () {
        send('POST', '/ping', {}, this).then(function (res) { done(res, 'Connected.'); });
    });
    document.getElementById('vaGenKey').addEventListener('click', function () {
        if (!window.confirm('Create a new leads key and apply it on the voice agent? Clients using the old key stop working until they use this one (clients without their own key switch automatically).')) { return; }
        send('POST', '/leads-key', {}, this).then(function (res) { done(res, 'Key created.'); });
    });

    // load
    function fieldHtml(f) {
        var id = 'vaS_' + f.key;
        var label = '<label class="platform-label" for="' + id + '">' + esc(f.label) + '</label>';
        var input;
        if (f.secret) {
            input = '<input class="platform-input" id="' + id + '" type="password" data-key="' + esc(f.key) + '" autocomplete="new-password" placeholder="' + (f.set ? '•••• ' + esc((f.preview || '').replace(/^•+/, '')) + ' saved (leave blank to keep)' : '') + '">';
        } else {
            input = '<input class="platform-input" id="' + id + '" data-key="' + esc(f.key) + '" value="' + esc(f.value || '') + '"' + (f.from_env ? ' placeholder="from server .env"' : '') + '>';
        }
        var hint = f.hint ? '<div class="platform-help">' + esc(f.hint) + '</div>' : '';
        var test = /^N8N_/.test(f.key) ? ' <button type="button" class="btn-pf va-test" data-key="' + esc(f.key) + '">Send test</button>' : '';
        return '<div>' + label + '<div style="display:flex;gap:6px;align-items:center">' + input + test + '</div>' + hint + '</div>';
    }

    function esc(s) {
        return String(s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; });
    }

    function render(data) {
        var a = data.agent || {}, t = data.tenant || {};
        var f = document.getElementById('vaAgentForm').elements;
        f.name.value = a.name || '';
        f.language.value = a.language || '';
        f.prompt.value = a.prompt || '';
        f.stt_provider.value = a.stt_provider || '';
        f.tts_provider.value = a.tts_provider || '';
        f.speaker.value = a.speaker || '';
        f.llm_model.value = a.llm_model || '';
        f.phone_number.value = a.phone_number || '';
        f.calling_hours.value = t.calling_hours || '09:00-20:00';
        f.country.value = t.country || 'IN';
        f.timezone.value = t.timezone || 'Asia/Kolkata';
        f.recording_enabled.checked = !!t.recording_enabled;

        var groups = {}, order = [];
        (data.settings || []).forEach(function (s) {
            if (!groups[s.group]) { groups[s.group] = []; order.push(s.group); }
            groups[s.group].push(s);
        });
        var html = '';
        order.forEach(function (g) {
            html += '<h3 class="platform-subhead">' + esc(g) + '</h3><div class="platform-form-grid">' + groups[g].map(fieldHtml).join('') + '</div>';
        });
        document.getElementById('vaServerFields').innerHTML = html;
        document.getElementById('vaLoaded').hidden = false;

        var acc = data.accounts || [];
        document.getElementById('vaAccounts').innerHTML = acc.length
            ? '<strong>Voice agent accounts:</strong> ' + acc.map(function (a) { return esc(a.name) + ' (' + esc(a.username) + ', ' + esc(a.calls || 0) + ' calls)'; }).join('; ')
            : '';
    }

    function load() {
        var btn = document.getElementById('vaLoad');
        send('GET', '/load', null, btn).then(function (res) {
            if (!res.success) { done(res); return; }
            render(res.data || {});
            toast('Loaded from the voice agent.', true);
        });
    }
    document.getElementById('vaLoad').addEventListener('click', load);

    // 2. agent
    document.getElementById('vaAgentForm').addEventListener('submit', function (e) {
        e.preventDefault();
        send('POST', '/agent', formData(this), e.submitter || null).then(function (res) { done(res, 'Saved.'); });
    });

    // 3. server settings: only fields that were typed in (a blank secret keeps the saved one)
    document.getElementById('vaServerForm').addEventListener('submit', function (e) {
        e.preventDefault();
        var values = {};
        document.querySelectorAll('#vaServerFields [data-key]').forEach(function (el) {
            var secret = el.type === 'password';
            if (secret && el.value === '') { return; }
            values[el.getAttribute('data-key')] = el.value;
        });
        send('POST', '/settings', { values: values }, e.submitter || null).then(function (res) {
            done(res, 'Saved.');
            if (res.success) { load(); }
        });
    });
    // 4. a client with its own account
    document.getElementById('vaClientForm').addEventListener('submit', function (e) {
        e.preventDefault();
        var client = this.elements.client.value;
        if (!client) { toast('Choose a client first.', false); return; }
        if (!window.confirm('Create a voice agent account for this client and save its address and key in the client Settings? Calling stays off until the client turns it on.')) { return; }
        send('POST', '/client-account', { client: client }, e.submitter || null).then(function (res) {
            done(res, 'Created.');
            var box = document.getElementById('vaClientResult');
            if (res.data && res.data.key) {
                var d = res.data;
                box.hidden = false;
                box.innerHTML = '<strong>Shown once. Save it now.</strong><br>Voice agent dashboard login for this client (to write its script): <code>' + esc(d.username) + '</code>'
                    + (d.password ? ' / <code>' + esc(d.password) + '</code>' : ' (the account already existed, its password is unchanged)')
                    + '<br>Leads key (already saved in the client Settings): <code>' + esc(d.key) + '</code>';
                load();
            }
        });
    });
    document.getElementById('vaSip').addEventListener('click', function () {
        send('POST', '/sip-setup', {}, this).then(function (res) {
            done(res, 'SIP trunks created.');
            if (res.success) { load(); }
        });
    });
    document.getElementById('vaServerFields').addEventListener('click', function (e) {
        var b = e.target.closest('.va-test');
        if (!b) { return; }
        send('POST', '/test-webhook', { key: b.getAttribute('data-key') }, b).then(function (res) { done(res, 'Sent.'); });
    });
})();
