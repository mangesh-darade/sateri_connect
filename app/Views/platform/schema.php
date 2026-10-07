<?= $this->extend('layouts/platform') ?>

<?= $this->section('content') ?>
<?php
$clients = $clients ?? [];
?>

<section class="platform-card">
    <div class="platform-card-head">
        <div>
            <h2>Database Health</h2>
            <p>
                Checks every client database against the app schema (<?= (int) $tableCount ?> tables<?= $snapshotAt ? ', snapshot ' . esc($snapshotAt) : '' ?>).
                <strong>Repair</strong> only creates missing tables, columns and indexes — existing data is never changed or deleted.
            </p>
        </div>
        <div style="display:flex;gap:0.5rem;flex-wrap:wrap">
            <button type="button" class="btn-pf" id="schemaCheckAll"><i class="fas fa-stethoscope"></i> Check all</button>
            <button type="button" class="btn-pf btn-pf-primary" id="schemaRepairAll"><i class="fas fa-wrench"></i> Repair all</button>
        </div>
    </div>

    <div style="display:flex;gap:0.6rem;flex-wrap:wrap;padding:0 1.25rem 1rem" id="schemaSummary"></div>

    <?php if ($tableCount === 0): ?>
        <div class="platform-empty">
            <strong>Schema snapshot missing</strong>
            <p>Deploy <code>app/Database/Schema/tables.php</code> (generated with <code>php spark schema:snapshot</code>).</p>
        </div>
    <?php elseif ($clients === []): ?>
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
                    <th>Status</th>
                    <th>Details</th>
                    <th>Last checked</th>
                    <th class="is-actions">Actions</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($clients as $c): ?>
                    <?php $key = (string) ($c['key'] ?? ''); ?>
                    <tr data-schema-row="<?= esc($key, 'attr') ?>">
                        <td>
                            <div class="platform-client-name"><?= esc((string) ($c['name'] ?? $key)) ?></div>
                            <div class="platform-client-meta"><?= esc($key) ?> · <?= esc((string) ($c['db_database'] ?? '')) ?></div>
                        </td>
                        <td class="js-schema-status"><span class="platform-badge">Not checked</span></td>
                        <td class="js-schema-detail" style="max-width:460px;font-size:0.82rem;color:var(--pf-muted)">—</td>
                        <td class="js-schema-time" style="font-size:0.8rem;color:var(--pf-muted);white-space:nowrap">—</td>
                        <td class="is-actions" style="white-space:nowrap">
                            <button type="button" class="btn-pf js-schema-action" data-action="check" data-key="<?= esc($key, 'attr') ?>"><i class="fas fa-stethoscope"></i> <span>Check</span></button>
                            <button type="button" class="btn-pf btn-pf-primary js-schema-action" data-action="repair" data-key="<?= esc($key, 'attr') ?>"><i class="fas fa-wrench"></i> <span>Repair</span></button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<section class="platform-card" style="font-size:0.85rem;color:var(--pf-muted)">
    <div class="platform-card-head"><div><h2>How it works</h2></div></div>
    <ul style="margin:0 1.25rem 1.25rem;padding-left:1.1rem;line-height:1.7">
        <li>Client databases also repair themselves automatically the first time a user opens the app each day — this screen is for checking or fixing right away.</li>
        <li><span class="platform-badge platform-badge-ok">Up to date</span> nothing missing · <span class="platform-badge platform-badge-warn">Needs repair</span> missing tables / columns — click Repair · <span class="platform-badge platform-badge-down">Attention</span> needs a developer (e.g. duplicate rows block a unique index) or the DB cannot be reached.</li>
        <li>Foreign keys are not created here; they come from migrations.</li>
    </ul>
</section>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
(function () {
    'use strict';

    var csrfName = <?= json_encode(csrf_token()) ?>;
    var csrfHash = <?= json_encode(csrf_hash()) ?>;
    var baseUrl  = <?= json_encode(rtrim(site_url('platform/schema'), '/')) ?>;
    var results  = {};

    var STATES = {
        ok:           ['Up to date', 'platform-badge-ok'],
        needs_repair: ['Needs repair', 'platform-badge-warn'],
        attention:    ['Attention', 'platform-badge-down'],
        error:        ['Connect error', 'platform-badge-down'],
        working:      ['Working…', '']
    };

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function row(key) {
        return document.querySelector('[data-schema-row="' + (window.CSS && CSS.escape ? CSS.escape(key) : key) + '"]');
    }

    function badge(state) {
        var s = STATES[state] || STATES.error;
        return '<span class="platform-badge ' + s[1] + '">' + (state === 'working' ? '<i class="fas fa-spinner fa-spin"></i> ' : '') + s[0] + '</span>';
    }

    function detailHtml(d, message) {
        var html = '<div style="color:var(--pf-ink)">' + esc(message || d.message || '') + '</div>';
        var lists = [['Repaired now', d.changes || {}], ['Still missing / needs fix', d.issues || {}]];
        lists.forEach(function (pair) {
            var tables = Object.keys(pair[1]);
            if (!tables.length) return;
            html += '<details style="margin-top:0.35rem"><summary style="cursor:pointer">' + pair[0] + ' (' + tables.length + ' table' + (tables.length === 1 ? '' : 's') + ')</summary><ul style="margin:0.3rem 0 0;padding-left:1rem">';
            tables.forEach(function (t) {
                html += '<li><code>' + esc(t) + '</code>: ' + esc(pair[1][t].join(', ')) + '</li>';
            });
            html += '</ul></details>';
        });
        return html;
    }

    function render(key, state, d, message) {
        var r = row(key);
        if (!r) return;
        r.querySelector('.js-schema-status').innerHTML = badge(state);
        if (d) {
            r.querySelector('.js-schema-detail').innerHTML = detailHtml(d, message);
            r.querySelector('.js-schema-time').textContent = d.checked_at || '';
        } else if (message) {
            r.querySelector('.js-schema-detail').innerHTML = '<span style="color:var(--pf-down)">' + esc(message) + '</span>';
        }
        r.querySelectorAll('.js-schema-action').forEach(function (b) { b.disabled = state === 'working'; });
    }

    function summary() {
        var counts = {};
        Object.keys(results).forEach(function (k) { counts[results[k]] = (counts[results[k]] || 0) + 1; });
        document.getElementById('schemaSummary').innerHTML = Object.keys(counts).map(function (s) {
            return badge(s).replace('</span>', ' · ' + counts[s] + '</span>');
        }).join('');
    }

    function run(key, action) {
        render(key, 'working');
        var body = new FormData();
        body.append(csrfName, csrfHash);

        return fetch(baseUrl + '/' + encodeURIComponent(key) + '/' + action, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: body
        }).then(function (res) {
            return res.json().catch(function () { return { success: false, message: 'Unexpected response (HTTP ' + res.status + ').' }; });
        }).then(function (json) {
            var d = json.data || null;
            var state = d ? d.state : 'error';
            results[key] = state;
            render(key, state, d, json.message);
        }).catch(function () {
            results[key] = 'error';
            render(key, 'error', null, 'Network error — please retry.');
        }).then(summary);
    }

    function runAll(action) {
        var keys = Array.prototype.map.call(document.querySelectorAll('[data-schema-row]'), function (r) { return r.getAttribute('data-schema-row'); });
        return keys.reduce(function (p, key) { return p.then(function () { return run(key, action); }); }, Promise.resolve());
    }

    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.js-schema-action');
        if (btn) {
            var action = btn.getAttribute('data-action');
            if (action === 'repair' && !window.confirm('Create missing tables / columns for this client now? Existing data is not changed.')) return;
            run(btn.getAttribute('data-key'), action);
        }
    });

    var checkAll = document.getElementById('schemaCheckAll');
    var repairAll = document.getElementById('schemaRepairAll');
    if (checkAll) checkAll.addEventListener('click', function () { runAll('check'); });
    if (repairAll) repairAll.addEventListener('click', function () {
        if (window.confirm('Repair every client database now? Only missing tables / columns / indexes are created.')) runAll('repair');
    });

    // Fresh status on open (read-only check).
    if (document.querySelector('[data-schema-row]')) runAll('check');
})();
</script>
<?= $this->endSection() ?>
