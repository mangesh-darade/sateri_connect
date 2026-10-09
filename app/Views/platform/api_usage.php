<?= $this->extend('layouts/platform') ?>

<?= $this->section('content') ?>
<?php
$clients = $clients ?? [];
?>

<section class="platform-card">
    <div class="platform-card-head">
        <div>
            <h2>API Usage</h2>
            <p>Which external systems call each client's API with an API key — calls in the last 30 days, failures and the latest requests.</p>
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
            <table class="platform-table">
                <thead>
                <tr>
                    <th>Client</th>
                    <th>API keys</th>
                    <th>Calls (30 days)</th>
                    <th>Last call</th>
                    <th class="is-actions">Details</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($clients as $c): ?>
                    <?php $key = (string) ($c['key'] ?? ''); ?>
                    <tr data-usage-row="<?= esc($key, 'attr') ?>">
                        <td>
                            <div class="platform-client-name"><?= esc((string) ($c['name'] ?? $key)) ?></div>
                            <div class="platform-client-meta"><?= esc($key) ?></div>
                        </td>
                        <td class="js-usage-keys" style="font-size:0.82rem">—</td>
                        <td class="js-usage-calls">—</td>
                        <td class="js-usage-last" style="font-size:0.8rem;color:var(--pf-muted);white-space:nowrap">—</td>
                        <td class="is-actions">
                            <button type="button" class="btn-pf js-usage-toggle" data-key="<?= esc($key, 'attr') ?>" disabled><i class="fas fa-list"></i> <span>Recent calls</span></button>
                        </td>
                    </tr>
                    <tr data-usage-detail="<?= esc($key, 'attr') ?>" hidden>
                        <td colspan="5" class="js-usage-detail" style="background:var(--pf-soft, #f8fafc)"></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
(function () {
    'use strict';

    var baseUrl = <?= json_encode(rtrim(site_url('platform/api-usage'), '/')) ?>;

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

    function recentHtml(recent) {
        if (!recent.length) return '<div style="padding:0.5rem;color:var(--pf-muted)">No external API calls recorded yet.</div>';
        var rows = recent.map(function (r) {
            var bad = r.status_code >= 400;
            return '<tr>' +
                '<td style="white-space:nowrap">' + esc(r.created_at) + '</td>' +
                '<td><strong>' + esc(r.key) + '</strong></td>' +
                '<td><code>' + esc(r.method) + ' ' + esc(r.endpoint) + '</code></td>' +
                '<td><span class="platform-badge ' + (bad ? 'platform-badge-down' : 'platform-badge-ok') + '">' + r.status_code + '</span></td>' +
                '<td>' + esc(r.ip_address) + '<div style="font-size:0.75rem;color:var(--pf-muted);max-width:240px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="' + esc(r.user_agent) + '">' + esc(r.user_agent) + '</div></td>' +
                '<td style="text-align:right">' + r.duration_ms + ' ms</td>' +
                '</tr>';
        }).join('');
        return '<table class="platform-table" style="font-size:0.8rem"><thead><tr><th>Time</th><th>API key</th><th>Request</th><th>Status</th><th>IP / Client</th><th style="text-align:right">Time taken</th></tr></thead><tbody>' + rows + '</tbody></table>';
    }

    function load(key) {
        var row = sel('data-usage-row', key);
        if (!row) return Promise.resolve();
        row.querySelector('.js-usage-calls').innerHTML = '<i class="fas fa-spinner fa-spin"></i>';

        return fetch(baseUrl + '/' + encodeURIComponent(key), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (res) {
                return res.json().catch(function () { return { success: false, message: 'Unexpected response (HTTP ' + res.status + ').' }; });
            })
            .then(function (json) {
                if (!json.success) {
                    row.querySelector('.js-usage-calls').innerHTML = '<span class="platform-badge platform-badge-down">' + esc(json.message || 'Error') + '</span>';
                    return;
                }
                var d = json.data;
                row.querySelector('.js-usage-keys').innerHTML = keysHtml(d.keys);
                row.querySelector('.js-usage-calls').innerHTML = '<strong>' + d.calls + '</strong>' +
                    (d.failed ? ' <span class="platform-badge platform-badge-down">' + d.failed + ' failed</span>' : '');
                row.querySelector('.js-usage-last').textContent = d.recent.length ? d.recent[0].created_at : 'Never';
                sel('data-usage-detail', key).querySelector('.js-usage-detail').innerHTML = recentHtml(d.recent);
                row.querySelector('.js-usage-toggle').disabled = false;
            })
            .catch(function () {
                row.querySelector('.js-usage-calls').innerHTML = '<span class="platform-badge platform-badge-down">Network error</span>';
            });
    }

    function loadAll() {
        var keys = Array.prototype.map.call(document.querySelectorAll('[data-usage-row]'), function (r) { return r.getAttribute('data-usage-row'); });
        return keys.reduce(function (p, key) { return p.then(function () { return load(key); }); }, Promise.resolve());
    }

    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.js-usage-toggle');
        if (!btn) return;
        var detail = sel('data-usage-detail', btn.getAttribute('data-key'));
        detail.hidden = !detail.hidden;
        btn.querySelector('span').textContent = detail.hidden ? 'Recent calls' : 'Hide';
    });

    var refresh = document.getElementById('apiUsageRefresh');
    if (refresh) refresh.addEventListener('click', loadAll);

    if (document.querySelector('[data-usage-row]')) loadAll();
})();
</script>
<?= $this->endSection() ?>
