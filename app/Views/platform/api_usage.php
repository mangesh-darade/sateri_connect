<?= $this->extend('layouts/platform') ?>

<?= $this->section('content') ?>
<?php
$clients = $clients ?? [];
?>

<section class="platform-card">
    <div class="platform-card-head">
        <div>
            <h2>API Usage</h2>
            <p>Last 30 days. Click a count to open the day-wise report: which API ran, which failed, and the actual calls.</p>
        </div>
        <div style="display:flex;gap:0.5rem;flex-wrap:wrap">
            <button type="button" class="btn-pf" id="apiUsageRefresh"><i class="fas fa-rotate"></i> Refresh</button>
        </div>
    </div>

    <?php if ($clients === []): ?>
        <div class="platform-empty">
            <strong>No active clients</strong>
            <p>Create a client first.</p>
        </div>
    <?php else: ?>
        <div class="platform-table-wrap">
            <table class="platform-table" id="usageTable" style="width:100%">
                <thead>
                <tr>
                    <th>Client</th>
                    <th>API keys</th>
                    <th>Calls (30 days)</th>
                    <th>Last call</th>
                    <th class="is-actions">Details</th>
                </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<?= view('partials/platform_dt_assets') ?>
<script>
(function () {
    'use strict';

    var baseUrl = <?= json_encode(rtrim(site_url('platform/api-usage'), '/')) ?>;
    var reports = {};
    var filters = {};
    var openKeys = {};
    var dt = null;
    var loading = {};

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function sel(attr, key) {
        return document.querySelector('[' + attr + '="' + (window.CSS && CSS.escape ? CSS.escape(key) : key) + '"]');
    }

    function keysHtml(keys) {
        if (!keys.length) return '<span style="color:var(--pf-muted)">No API keys</span>';
        return keys.map(function (k) {
            return '<div><strong>' + esc(k.name) + '</strong> <span style="color:var(--pf-muted)">· ' + k.calls + ' call' + (k.calls === 1 ? '' : 's') +
                (k.ips ? ' · ' + k.ips + ' IP' + (k.ips === 1 ? '' : 's') : '') + '</span></div>';
        }).join('');
    }

    function countsHtml(key, d) {
        if (!d.calls) return '<span style="color:var(--pf-muted)">0</span>';
        var html = '<button type="button" class="platform-usage-count js-usage-open" data-key="' + esc(key) + '" data-status="all"><strong>' + d.calls + '</strong></button>';
        if (d.ok) html += ' <button type="button" class="platform-usage-count js-usage-open" data-key="' + esc(key) + '" data-status="ok"><span class="platform-badge platform-badge-ok">' + d.ok + ' ran</span></button>';
        if (d.failed) html += ' <button type="button" class="platform-usage-count js-usage-open" data-key="' + esc(key) + '" data-status="failed"><span class="platform-badge platform-badge-down">' + d.failed + ' failed</span></button>';
        return html;
    }

    function countBtn(n, label, status, key, day, method, endpoint) {
        if (!n && status !== 'all') return '<span class="platform-usage-zero">0 ' + label + '</span>';
        return '<button type="button" class="platform-usage-count js-usage-calls" data-key="' + esc(key) + '" data-status="' + status + '" data-date="' + esc(day) + '" data-method="' + esc(method || '') + '" data-endpoint="' + esc(endpoint || '') + '" data-page="1">' + n + ' ' + label + '</button>';
    }

    function reportHtml(key, d, filter) {
        var days = (d.days || []).filter(function (day) {
            if (filter === 'failed') return day.failed > 0;
            if (filter === 'ok') return day.ok > 0;
            return day.total > 0;
        });
        var chips = ['all', 'ok', 'failed'].map(function (name) {
            var label = name === 'all' ? 'All' : (name === 'ok' ? 'Ran' : 'Failed');
            return '<button type="button" class="btn-pf js-usage-filter' + (filter === name ? ' is-on' : '') + '" data-key="' + esc(key) + '" data-status="' + name + '">' + label + '</button>';
        }).join('');
        if (!days.length) {
            return '<div class="platform-usage-report"><div class="platform-usage-filters">' + chips + '</div><p class="platform-usage-empty">No ' + (filter === 'failed' ? 'failed' : 'recorded') + ' API calls in the last 30 days.</p></div>';
        }
        var blocks = days.map(function (day) {
            var apis = day.apis.filter(function (api) {
                if (filter === 'failed') return api.failed > 0;
                if (filter === 'ok') return api.ok > 0;
                return true;
            });
            var rows = apis.map(function (api) {
                var ran = filter === 'failed' ? '' : '<td>' + countBtn(api.ok, 'ran', 'ok', key, day.date, api.method, api.endpoint) + '</td>';
                var failed = filter === 'ok' ? '' : '<td>' + countBtn(api.failed, 'failed', 'failed', key, day.date, api.method, api.endpoint) + '</td>';
                return '<tr><td><code>' + esc(api.method) + ' ' + esc(api.endpoint) + '</code></td>' + ran + failed + '</tr>';
            }).join('');
            var head = '<th>API</th>' + (filter === 'failed' ? '' : '<th>Ran</th>') + (filter === 'ok' ? '' : '<th>Failed</th>');
            return '<section class="platform-usage-day"><div class="platform-usage-day-head"><strong>' + esc(day.date) + '</strong><span>' + day.ok + ' ran</span><span>' + day.failed + ' failed</span></div>' +
                '<table class="platform-table"><thead><tr>' + head + '</tr></thead><tbody>' + rows + '</tbody></table>' +
                '<div class="js-usage-callbox" data-callbox="' + esc(key) + '|' + esc(day.date) + '"></div></section>';
        }).join('');
        return '<div class="platform-usage-report"><div class="platform-usage-filters">' + chips + '</div>' + blocks + '</div>';
    }

    function paint(key) {
        var box = sel('data-usage-detail', key);
        if (!box || !reports[key]) return;
        box.querySelector('.js-usage-detail').innerHTML = reportHtml(key, reports[key], filters[key] || 'all');
    }

    function callRows(payload) {
        if (!payload.rows.length) return '<p class="platform-usage-empty">No calls on this page.</p>';
        var rows = payload.rows.map(function (r) {
            var bad = r.outcome === 'failed';
            return '<tr><td>' + esc(r.created_at) + '</td><td><code>' + esc(r.method) + ' ' + esc(r.endpoint) + '</code></td>' +
                '<td><span class="platform-badge ' + (bad ? 'platform-badge-down' : 'platform-badge-ok') + '">' + (bad ? 'Failed' : 'Ran') + ' ' + r.status_code + '</span></td>' +
                '<td>' + esc(r.key) + '</td><td>' + esc(r.ip_address) + '</td><td>' + r.duration_ms + ' ms</td></tr>';
        }).join('');
        var pager = '';
        if (payload.pages > 1) {
            pager = '<div class="platform-usage-pager"><button type="button" class="btn-pf js-usage-page" data-page="' + (payload.page - 1) + '"' + (payload.page <= 1 ? ' disabled' : '') + '>Previous</button><span>' + payload.page + ' / ' + payload.pages + '</span><button type="button" class="btn-pf js-usage-page" data-page="' + (payload.page + 1) + '"' + (payload.page >= payload.pages ? ' disabled' : '') + '>Next</button></div>';
        }
        return '<table class="platform-table platform-usage-calls"><thead><tr><th>Time</th><th>Actual API</th><th>Result</th><th>API key</th><th>IP</th><th>Time taken</th></tr></thead><tbody>' + rows + '</tbody></table>' + pager;
    }

    function loadCalls(btn) {
        var key = btn.getAttribute('data-key');
        var day = btn.getAttribute('data-date');
        var box = document.querySelector('[data-callbox="' + (window.CSS && CSS.escape ? CSS.escape(key + '|' + day) : key + '|' + day) + '"]');
        if (!box) return;
        box.innerHTML = '<p class="platform-usage-empty"><i class="fas fa-spinner fa-spin"></i> Loading calls…</p>';
        var qs = new URLSearchParams({
            date: day,
            status: btn.getAttribute('data-status') || 'all',
            method: btn.getAttribute('data-method') || '',
            endpoint: btn.getAttribute('data-endpoint') || '',
            page: btn.getAttribute('data-page') || '1'
        });
        fetch(baseUrl + '/' + encodeURIComponent(key) + '/calls?' + qs.toString(), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (res) { return res.json(); })
            .then(function (json) {
                if (!json.success) {
                    box.innerHTML = '<p class="platform-usage-empty">' + esc(json.message || 'Could not load calls.') + '</p>';
                    return;
                }
                box.innerHTML = callRows(json.data);
                box.querySelectorAll('.js-usage-page').forEach(function (pageBtn) {
                    pageBtn.setAttribute('data-key', key);
                    pageBtn.setAttribute('data-date', day);
                    pageBtn.setAttribute('data-status', btn.getAttribute('data-status') || 'all');
                    pageBtn.setAttribute('data-method', btn.getAttribute('data-method') || '');
                    pageBtn.setAttribute('data-endpoint', btn.getAttribute('data-endpoint') || '');
                });
            })
            .catch(function () {
                box.innerHTML = '<p class="platform-usage-empty">Network error — please retry.</p>';
            });
    }

    function detailRow(key) {
        return sel('data-usage-detail', key);
    }

    function rowFor(key) {
        var tr = sel('data-usage-row', key);
        return tr ? dt.row(tr) : null;
    }

    function setOpen(key, open) {
        var row = rowFor(key);
        if (!row) return;
        openKeys[key] = open;
        if (open) {
            row.child('<div class="platform-usage-detail-box" style="background:#f7f8f6;padding:0.75rem 0.85rem" data-usage-detail="' + esc(key) + '"><div class="js-usage-detail"></div></div>').show();
        } else {
            row.child.hide();
        }
        var label = sel('data-usage-row', key).querySelector('.js-usage-toggle span');
        if (label) label.textContent = open ? 'Hide' : 'Report';
    }

    function openReport(key, status) {
        filters[key] = status || filters[key] || 'all';
        if (!openKeys[key]) setOpen(key, true);
        var detail = detailRow(key);
        var box = detail ? detail.querySelector('.js-usage-detail') : null;
        if (!box) return;
        if (!reports[key]) {
            box.innerHTML = '<p class="platform-usage-empty"><i class="fas fa-spinner fa-spin"></i> Loading report\u2026</p>';
        }
        load(key).then(function () {
            if (reports[key]) paint(key);
            else if (box) box.innerHTML = '<p class="platform-usage-empty">Report could not be loaded. Try Refresh.</p>';
        });
    }

    function fill(key) {
        var row = sel('data-usage-row', key);
        var d = reports[key];
        if (!row || !d) return;
        row.querySelector('.js-usage-keys').innerHTML = keysHtml(d.keys);
        row.querySelector('.js-usage-cell-calls').innerHTML = countsHtml(key, d);
        row.querySelector('.js-usage-last').textContent = d.last || 'Never';
    }

    function load(key) {
        var row = sel('data-usage-row', key);
        if (!row) return Promise.resolve();
        if (loading[key]) return loading[key];
        row.querySelector('.js-usage-cell-calls').innerHTML = '<i class="fas fa-spinner fa-spin"></i>';

        loading[key] = fetch(baseUrl + '/' + encodeURIComponent(key), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (res) {
                return res.json().catch(function () { return { success: false, message: 'Unexpected response (HTTP ' + res.status + ').' }; });
            })
            .then(function (json) {
                var r = sel('data-usage-row', key);
                if (!json.success) {
                    if (r) r.querySelector('.js-usage-cell-calls').innerHTML = '<span class="platform-badge platform-badge-down">' + esc(json.message || 'Error') + '</span>';
                    return;
                }
                reports[key] = json.data;
                filters[key] = filters[key] || 'all';
                fill(key);
                if (openKeys[key]) paint(key);
            })
            .catch(function () {
                var r = sel('data-usage-row', key);
                if (r) r.querySelector('.js-usage-cell-calls').innerHTML = '<span class="platform-badge platform-badge-down">Network error</span>';
            })
            .then(function () { delete loading[key]; });

        return loading[key];
    }

    // Load the stats of the clients on the current page, one after another.
    function loadVisible(force) {
        var keys = Array.prototype.map.call(document.querySelectorAll('#usageTable [data-usage-row]'), function (r) { return r.getAttribute('data-usage-row'); });
        return keys.reduce(function (p, key) {
            return p.then(function () {
                if (!force && reports[key]) { fill(key); return null; }
                return load(key);
            });
        }, Promise.resolve());
    }

    document.addEventListener('click', function (e) {
        var open = e.target.closest('.js-usage-open');
        if (open) {
            openReport(open.getAttribute('data-key'), open.getAttribute('data-status'));
            return;
        }
        var filter = e.target.closest('.js-usage-filter');
        if (filter) {
            filters[filter.getAttribute('data-key')] = filter.getAttribute('data-status');
            paint(filter.getAttribute('data-key'));
            return;
        }
        var calls = e.target.closest('.js-usage-calls, .js-usage-page');
        if (calls && !calls.disabled) {
            loadCalls(calls);
            return;
        }
        var btn = e.target.closest('.js-usage-toggle');
        if (!btn) return;
        var key = btn.getAttribute('data-key');
        if (openKeys[key]) setOpen(key, false);
        else openReport(key, filters[key] || 'all');
    });

    var refresh = document.getElementById('apiUsageRefresh');
    if (refresh) refresh.addEventListener('click', function () { loadVisible(true); });

    if (document.getElementById('usageTable')) {
        dt = AppDT.table('#usageTable', {
            url: baseUrl + '/data',
            order: [[0, 'asc']],
            emptyText: 'No active clients',
            createdRow: function (tr, r) { tr.setAttribute('data-usage-row', r.key); },
            columns: [
                { data: null, render: function (r) {
                    return '<div class="platform-client-name">' + esc(r.name) + '</div><div class="platform-client-meta">' + esc(r.key) + '</div>';
                } },
                { data: null, orderable: false, className: 'js-usage-keys', render: function () { return '\u2014'; } },
                { data: null, orderable: false, className: 'js-usage-cell-calls', render: function () { return '\u2014'; } },
                { data: null, orderable: false, className: 'js-usage-last', render: function () { return '\u2014'; } },
                { data: null, orderable: false, className: 'is-actions', render: function (r) {
                    return '<button type="button" class="btn-pf js-usage-toggle" data-key="' + esc(r.key) + '"><i class="fas fa-list"></i> <span>Report</span></button>';
                } }
            ],
            drawCallback: function () {
                $('#usageTable tbody tr[data-usage-row]').each(function () {
                    var key = this.getAttribute('data-usage-row');
                    $(this).find('.js-usage-keys').css({ fontSize: '0.82rem' });
                    $(this).find('.js-usage-last').css({ fontSize: '0.8rem', color: 'var(--pf-muted)', whiteSpace: 'nowrap' });
                    if (openKeys[key]) {
                        setOpen(key, true);
                        if (reports[key]) paint(key);
                    }
                });
                loadVisible(false);
            }
        });
    }
})();
</script>
<?= $this->endSection() ?>
