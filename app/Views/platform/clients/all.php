<?= $this->extend('layouts/platform') ?>

<?= $this->section('content') ?>
<?php
$clients = $clients ?? [];
$fmt = static fn ($n): string => number_format((int) $n);

// Search, filter, sort and paging run on the server (see PlatformClients::all); the URL carries the state.
$counts = $counts ?? ['all' => 0];
$f      = $f ?? [];
$filterDefs = [
    'health'   => ['Health',   ['ok' => 'Healthy', 'warn' => 'Needs setup', 'down' => 'Offline']],
    'wallet'   => ['Wallet',   ['on' => 'Wallet on', 'off' => 'Wallet off', 'funded' => 'Funded', 'low' => 'Low balance', 'empty' => 'Empty']],
    'wa'       => ['WhatsApp', ['ready' => 'Connected', 'setup' => 'Not connected']],
    'email'    => ['Email',    ['ready' => 'Ready', 'setup' => 'Not ready']],
    'activity' => ['Activity', ['sending' => 'Sending messages', 'idle' => 'No messages sent', 'failures' => 'Has failed messages', 'chats' => 'Has open chats']],
];
$state  = ['q' => $q ?? '', 'country' => $country ?? '', 'sort' => ($sort ?? 'name') === 'name' ? '' : $sort,
           'dir' => ($dir ?? 'asc') === 'asc' ? '' : $dir, 'per' => ($per ?? 10) === 10 ? '' : (string) $per];
foreach ($filterDefs as $fk => $_) {
    $state[$fk] = (string) ($f[$fk] ?? '');
}
$activeFilters = array_filter(array_intersect_key($state, $filterDefs + ['country' => 1]), static fn (string $v): bool => $v !== '');
$u = static fn (array $over): string => site_url('platform/clients/all') . (($qs = http_build_query(array_filter(array_merge($state, $over), static fn ($v): bool => $v !== '' && $v !== null))) !== '' ? '?' . $qs : '');
$th = static function (string $col, string $label, string $cls = '') use ($sort, $dir, $u): string {
    $on   = $sort === $col;
    $next = $on && $dir === 'asc' ? 'desc' : 'asc';
    $icon = $on ? ($dir === 'asc' ? 'fa-arrow-up' : 'fa-arrow-down') : 'fa-sort';

    return '<a class="pfw-sort" href="' . esc($u(['sort' => $col === 'name' ? '' : $col, 'dir' => $next === 'asc' ? '' : 'desc', 'page' => '']), 'attr') . '">' . esc($label) . ' <i class="fas ' . $icon . '" aria-hidden="true"></i></a>';
};
$from   = $total > 0 ? ($page - 1) * $per + 1 : 0;
$to     = min($total, $page * $per);
$window = array_values(array_unique(array_filter([1, $page - 2, $page - 1, $page, $page + 1, $page + 2, $pages], static fn (int $n): bool => $n >= 1 && $n <= $pages)));
sort($window);

$symbolOf = static fn (string $cur): string => strtoupper($cur) === 'INR' ? '₹' : strtoupper($cur) . ' ';
$initials = static function (string $name): string {
    $parts = preg_split('/[\s_\-]+/', trim($name)) ?: [];
    $out   = '';
    foreach (array_slice(array_filter($parts), 0, 2) as $p) {
        $out .= mb_strtoupper(mb_substr($p, 0, 1));
    }

    return $out !== '' ? $out : '?';
};
$hue = static fn (string $key): int => hexdec(substr(md5($key), 0, 4)) % 360;
?>

<section class="platform-card pf-clients">
    <div class="platform-card-head">
        <div>
            <h2>Workspaces</h2>
            <p>Monitor every client, top up wallets and open any workspace.</p>
        </div>
        <div class="platform-actions">
            <?php
            $ago = ($refreshedAt ?? '') !== '' ? max(0, time() - (int) strtotime((string) $refreshedAt)) : null;
            $agoText = $ago === null ? '' : ($ago < 60 ? 'just now' : ($ago < 3600 ? floor($ago / 60) . ' min ago' : floor($ago / 3600) . ' h ago'));
            ?>
            <form method="post" action="<?= site_url('platform/clients/refresh-stats') ?>" class="pfc-refresh">
                <?= csrf_field() ?>
                <?php if ($agoText !== ''): ?><span class="pfc-updated" title="Figures are cached for a few minutes">Updated <?= esc($agoText) ?></span><?php endif; ?>
                <button class="btn-pf" type="submit" title="Recompute every client now"><i class="fas fa-rotate" aria-hidden="true"></i> Refresh</button>
            </form>
            <a class="btn-pf" href="<?= site_url('platform/wallet-report') ?>"><i class="fas fa-wallet" aria-hidden="true"></i> Wallet report</a>
            <a class="btn-pf btn-pf-primary" href="<?= site_url('platform/clients/create') ?>"><i class="fas fa-plus" aria-hidden="true"></i> Create client</a>
        </div>
    </div>

    <?php if ($counts['all'] === 0): ?>
        <div class="platform-empty">
            <strong>No clients yet</strong>
            <p>Create the first workspace to start tracking performance.</p>
            <a class="btn-pf btn-pf-accent" href="<?= site_url('platform/clients/create') ?>">Create client</a>
        </div>
    <?php else: ?>

        <div class="pfc-kpis">
            <div class="pfc-kpi"><span>Clients</span><strong><?= $fmt($counts['all']) ?></strong></div>
            <div class="pfc-kpi"><span>Healthy</span><strong class="is-ok"><?= $fmt($counts['ok']) ?></strong></div>
            <div class="pfc-kpi"><span>Needs setup</span><strong class="<?= $counts['warn'] > 0 ? 'is-warn' : '' ?>"><?= $fmt($counts['warn']) ?></strong></div>
            <div class="pfc-kpi"><span>Wallet on</span><strong><?= $fmt($counts['walleton']) ?><small>/ <?= $fmt($counts['all']) ?></small></strong></div>
            <div class="pfc-kpi">
                <span>Wallet balance (all)</span>
                <strong><?= isset($walletTotal) && $walletTotal !== null ? '₹' . number_format((float) $walletTotal, 2) : '—' ?></strong>
                <?php if (! empty($walletTotalNote)): ?><em><?= esc((string) $walletTotalNote) ?></em><?php endif; ?>
            </div>
        </div>

        <div class="pfc-panel">
        <form class="pfc-toolbar" method="get" action="<?= site_url('platform/clients/all') ?>" role="search">
            <?php foreach (['sort' => $state['sort'], 'dir' => $state['dir'], 'per' => $state['per']] as $hk => $hv): ?>
                <?php if ($hv !== ''): ?><input type="hidden" name="<?= $hk ?>" value="<?= esc($hv, 'attr') ?>"><?php endif; ?>
            <?php endforeach; ?>
            <div class="pfc-search">
                <i class="fas fa-magnifying-glass" aria-hidden="true"></i>
                <input type="search" name="q" value="<?= esc($state['q'], 'attr') ?>" placeholder="Search client, key, database or country…" aria-label="Search clients" autocomplete="off">
            </div>
            <button type="submit" class="btn-pf btn-pf-primary pfc-go"><i class="fas fa-magnifying-glass" aria-hidden="true"></i> Search</button>

            <div class="pfc-filters">
                <?php foreach ($filterDefs as $fk => [$flabel, $fopts]): ?>
                    <label class="pfc-filter<?= $state[$fk] !== '' ? ' is-on' : '' ?>">
                        <span><?= esc($flabel) ?></span>
                        <select name="<?= $fk ?>" onchange="this.form.submit()">
                            <option value="">All</option>
                            <?php foreach ($fopts as $ov => $ol): ?>
                                <option value="<?= $ov ?>" <?= $state[$fk] === $ov ? 'selected' : '' ?>><?= esc($ol) ?> (<?= (int) ($counts[$fk][$ov] ?? 0) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                <?php endforeach; ?>
                <label class="pfc-filter<?= $state['country'] !== '' ? ' is-on' : '' ?>">
                    <span>Country</span>
                    <select name="country" onchange="this.form.submit()">
                        <option value="">All</option>
                        <option value="none" <?= $state['country'] === 'none' ? 'selected' : '' ?>>Country not set</option>
                        <?php foreach (($countries ?? []) as $cc => $cn): ?><option value="<?= esc((string) $cc, 'attr') ?>" <?= $state['country'] === (string) $cc ? 'selected' : '' ?>><?= esc($cn) ?></option><?php endforeach; ?>
                    </select>
                </label>
            </div>
        </form>

        <?php if ($activeFilters !== [] || $state['q'] !== ''): ?>
            <div class="pfc-tags" aria-label="Active filters">
                <span class="pfc-tags-label">Filtered by</span>
                <?php if ($state['q'] !== ''): ?>
                    <a class="pfc-tag" href="<?= esc($u(['q' => '', 'page' => '']), 'attr') ?>" title="Remove">Search: <?= esc($state['q']) ?> <i class="fas fa-xmark" aria-hidden="true"></i></a>
                <?php endif; ?>
                <?php foreach ($activeFilters as $fk => $fv): ?>
                    <?php $tagLabel = $fk === 'country' ? ($fv === 'none' ? 'Country not set' : ($countries[$fv] ?? '+' . $fv)) : ($filterDefs[$fk][1][$fv] ?? $fv); ?>
                    <a class="pfc-tag" href="<?= esc($u([$fk => '', 'page' => '']), 'attr') ?>" title="Remove"><?= $fk === 'country' ? 'Country: ' : esc($filterDefs[$fk][0]) . ': ' ?><?= esc($tagLabel) ?> <i class="fas fa-xmark" aria-hidden="true"></i></a>
                <?php endforeach; ?>
                <a class="pfc-tags-clear" href="<?= site_url('platform/clients/all') ?>">Clear all</a>
            </div>
        <?php endif; ?>
        </div>

        <div class="pfc-strip">
            <strong>Clients</strong>
            <span><?= $total === 0 ? 'No matches' : 'Showing ' . $fmt($from) . '–' . $fmt($to) . ' of ' . $fmt($total) ?></span>
        </div>
        <div class="platform-table-wrap pfc-tablewrap">
            <table class="platform-table platform-table-cards pfc-table" id="pfcTable">
                <thead>
                <tr>
                    <th><?= $th('name', 'Client') ?></th>
                    <th><?= $th('health', 'Health') ?></th>
                    <th class="num col-lg"><?= $th('users', 'Users') ?></th>
                    <th class="num"><?= $th('contacts', 'Contacts') ?></th>
                    <th class="num"><?= $th('sent', 'WA sent') ?></th>
                    <th class="num col-md"><?= $th('failed', 'WA failed') ?></th>
                    <th class="num col-lg"><?= $th('open_chats', 'Chats') ?></th>
                    <th class="num"><?= $th('email_sent', 'Emails') ?></th>
                    <th class="num"><?= $th('wallet', 'Wallet') ?></th>
                    <th>Channels</th>
                    <th class="is-actions">Actions</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($clients as $c): ?>
                    <?php
                    $key        = (string) ($c['key'] ?? '');
                    $name       = (string) ($c['name'] ?? $key);
                    $health     = (string) ($c['health'] ?? 'down');
                    $healthKey  = in_array($health, ['ok', 'warn'], true) ? $health : 'down';
                    $badgeClass = $health === 'ok' ? 'platform-badge-ok' : ($health === 'warn' ? 'platform-badge-warn' : 'platform-badge-down');
                    $cc         = strtolower(trim((string) ($c['country_code'] ?? '')));
                    $country    = $cc !== '' ? \App\Libraries\WhatsAppCountries::nameOf($cc) : '';
                    $cur        = (string) ($c['wallet_currency'] ?? 'INR');
                    $balance    = (float) ($c['wallet_balance'] ?? 0);
                    $walletOn   = ! empty($c['wallet_enabled']);
                    $failed     = (int) ($c['failed'] ?? 0);
                    $clientUrl  = 'platform/clients/' . rawurlencode($key);
                    ?>
                    <tr data-health="<?= $healthKey ?>" data-wallet="<?= $walletOn ? 'on' : 'off' ?>" data-search="<?= esc(strtolower($name . ' ' . $key . ' ' . $country . ' ' . ($c['db_database'] ?? '')), 'attr') ?>">
                        <td class="td-full">
                            <div class="pfc-client">
                                <span class="pfc-avatar" style="--h:<?= $hue($key) ?>" aria-hidden="true"><?= esc($initials($name)) ?></span>
                                <div class="pfc-client-text">
                                    <a class="platform-client-name pfc-name" href="<?= site_url($clientUrl) ?>"><?= esc($name) ?></a>
                                    <div class="platform-client-meta"><?= esc($key) ?> · <?= esc((string) ($c['db_database'] ?? '')) ?></div>
                                    <?php if ($country !== ''): ?>
                                        <span class="pfc-pill" title="Wallet currency <?= esc($cur, 'attr') ?>"><i class="fas fa-earth-asia" aria-hidden="true"></i> <?= esc($country) ?> · <?= esc($cur) ?></span>
                                    <?php else: ?>
                                        <a class="pfc-pill is-warn" href="<?= site_url($clientUrl) ?>" title="Choose the country on the client page"><i class="fas fa-triangle-exclamation" aria-hidden="true"></i> Country not set</a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </td>
                        <td data-label="Health">
                            <span class="platform-badge <?= $badgeClass ?>"><?= esc(['Needs channel setup' => 'Needs setup'][(string) ($c['health_label'] ?? '')] ?? (string) ($c['health_label'] ?? $health)) ?></span>
                            <?php if (! empty($c['error'])): ?>
                                <div class="platform-client-meta platform-client-error" title="<?= esc((string) $c['error']) ?>"><?= esc((string) $c['error']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="num col-lg" data-label="Users"><span class="pfc-n<?= (int) ($c['users'] ?? 0) === 0 ? ' is-zero' : '' ?>"><?= $fmt($c['users'] ?? 0) ?></span></td>
                        <td class="num" data-label="Contacts"><span class="pfc-n<?= (int) ($c['contacts'] ?? 0) === 0 ? ' is-zero' : '' ?>"><?= $fmt($c['contacts'] ?? 0) ?></span></td>
                        <td class="num" data-label="WA sent"><span class="pfc-n<?= (int) ($c['sent'] ?? 0) === 0 ? ' is-zero' : '' ?>"><?= $fmt($c['sent'] ?? 0) ?></span></td>
                        <td class="num col-md" data-label="WA failed"><span class="pfc-n<?= $failed === 0 ? ' is-zero' : ' is-bad' ?>"><?= $fmt($failed) ?></span></td>
                        <td class="num col-lg" data-label="Chats"><span class="pfc-n<?= (int) ($c['open_chats'] ?? 0) === 0 ? ' is-zero' : '' ?>"><?= $fmt($c['open_chats'] ?? 0) ?></span></td>
                        <td class="num" data-label="Emails"><span class="pfc-n<?= (int) ($c['email_sent'] ?? 0) === 0 ? ' is-zero' : '' ?>"><?= $fmt($c['email_sent'] ?? 0) ?></span></td>
                        <td class="num" data-label="Wallet">
                            <a class="pfc-wallet" href="<?= site_url($clientUrl . '/wallet') ?>" title="Open wallet">
                                <span class="pfc-wallet-amt<?= in_array((string) ($c['wallet_state'] ?? ''), ['low', 'empty'], true) ? ' is-bad' : '' ?>" title="<?= esc(['empty' => 'Wallet is empty', 'low' => 'Balance is below the low-balance alert'][(string) ($c['wallet_state'] ?? '')] ?? '', 'attr') ?>"><?= esc($symbolOf($cur)) ?><?= number_format($balance, 2) ?></span>
                                <span class="pfc-wallet-state <?= $walletOn ? 'is-on' : '' ?>"><i aria-hidden="true"></i><?= $walletOn ? 'On' : 'Off' ?></span>
                            </a>
                        </td>
                        <td data-label="Channels">
                            <div class="platform-channel-badges">
                                <span class="platform-badge <?= ! empty($c['meta_ready']) ? 'platform-badge-ok' : 'platform-badge-warn' ?>" title="WhatsApp">
                                    WA <?= ! empty($c['meta_ready']) ? 'ready' : 'setup' ?>
                                </span>
                                <span class="platform-badge <?= ! empty($c['email_ready']) ? 'platform-badge-ok' : 'platform-badge-warn' ?>" title="Email · <?= esc((string) ($c['email_provider_label'] ?? '—')) ?>">
                                    Email <?= ! empty($c['email_ready']) ? 'ready' : 'setup' ?>
                                </span>
                            </div>
                        </td>
                        <td class="is-actions td-full">
                            <div class="platform-actions platform-icon-actions">
                                <button type="button" class="btn-pf btn-pf-icon js-add-money" title="Add money to wallet" aria-label="Add money to <?= esc($name, 'attr') ?>"
                                        data-url="<?= site_url($clientUrl . '/wallet') ?>" data-name="<?= esc($name, 'attr') ?>"
                                        data-balance="<?= esc($symbolOf($cur) . number_format($balance, 2), 'attr') ?>" data-cur="<?= esc($cur, 'attr') ?>">
                                    <i class="fas fa-plus" aria-hidden="true"></i>
                                </button>
                                <a class="btn-pf btn-pf-icon" href="<?= site_url($clientUrl) ?>" title="Deep view" aria-label="Deep view of <?= esc($name, 'attr') ?>">
                                    <i class="fas fa-chart-pie" aria-hidden="true"></i>
                                </a>
                                <a class="btn-pf btn-pf-icon" href="<?= site_url($clientUrl . '/settings') ?>" title="Settings" aria-label="Settings of <?= esc($name, 'attr') ?>">
                                    <i class="fas fa-gear" aria-hidden="true"></i>
                                </a>
                                <form action="<?= site_url($clientUrl . '/enter') ?>" method="post">
                                    <?= csrf_field() ?>
                                    <button type="submit" class="btn-pf btn-pf-icon btn-pf-primary" title="Open workspace" aria-label="Open workspace of <?= esc($name, 'attr') ?>">
                                        <i class="fas fa-arrow-right-to-bracket" aria-hidden="true"></i>
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php if ($clients === []): ?>
                <div class="platform-empty" style="margin:1rem">
                    <strong>No clients match</strong>
                    <p>Try a different search or <a href="<?= site_url('platform/clients/all') ?>">clear all filters</a>.</p>
                </div>
            <?php endif; ?>
        </div>

        <div class="pfc-foot">
            <form method="get" action="<?= site_url('platform/clients/all') ?>" class="pfw-per">
                <?php foreach (array_diff_key($state, ['per' => 1]) as $hk => $hv): ?>
                    <?php if ($hv !== ''): ?><input type="hidden" name="<?= $hk ?>" value="<?= esc($hv, 'attr') ?>"><?php endif; ?>
                <?php endforeach; ?>
                <label for="pfcPer">Rows</label>
                <select class="platform-input" id="pfcPer" name="per" onchange="this.form.submit()">
                    <?php foreach ([10, 25, 50, 100] as $n): ?><option value="<?= $n ?>" <?= $per === $n ? 'selected' : '' ?>><?= $n ?></option><?php endforeach; ?>
                </select>
            </form>
            <?php if ($pages > 1): ?>
                <nav class="pfw-pager" aria-label="Client pages">
                    <a class="pfw-pg<?= $page <= 1 ? ' is-off' : '' ?>" href="<?= esc($u(['page' => $page > 2 ? (string) ($page - 1) : '']), 'attr') ?>" aria-label="Previous page"><i class="fas fa-chevron-left" aria-hidden="true"></i></a>
                    <?php $prev = 0; foreach ($window as $n): ?>
                        <?php if ($prev && $n - $prev > 1): ?><span class="pfw-gap">…</span><?php endif; ?>
                        <a class="pfw-pg<?= $n === $page ? ' is-on' : '' ?>" href="<?= esc($u(['page' => $n > 1 ? (string) $n : '']), 'attr') ?>"<?= $n === $page ? ' aria-current="page"' : '' ?>><?= $n ?></a>
                        <?php $prev = $n; ?>
                    <?php endforeach; ?>
                    <a class="pfw-pg<?= $page >= $pages ? ' is-off' : '' ?>" href="<?= esc($u(['page' => (string) min($pages, $page + 1)]), 'attr') ?>" aria-label="Next page"><i class="fas fa-chevron-right" aria-hidden="true"></i></a>
                </nav>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</section>

<dialog class="pfc-dialog" id="pfcMoney" aria-labelledby="pfcMoneyTitle">
    <form method="post" id="pfcMoneyForm" action="">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="credit">
        <input type="hidden" name="back" value="list">
        <div class="pfc-dialog-head">
            <div>
                <h3 id="pfcMoneyTitle">Add money</h3>
                <p><span id="pfcMoneyName"></span> · balance <strong id="pfcMoneyBalance"></strong></p>
            </div>
            <button type="button" class="pfc-dialog-x" id="pfcMoneyClose" aria-label="Close">✕</button>
        </div>
        <label class="platform-label" for="pfcMoneyAmount">Amount (<span id="pfcMoneyCur"></span>)</label>
        <input class="platform-input" id="pfcMoneyAmount" type="number" name="amount" step="0.01" min="0.01" required inputmode="decimal" placeholder="0.00">
        <div class="pfc-quick" aria-label="Quick amounts">
            <button type="button" data-amt="500">500</button>
            <button type="button" data-amt="1000">1,000</button>
            <button type="button" data-amt="5000">5,000</button>
            <button type="button" data-amt="10000">10,000</button>
        </div>
        <label class="platform-label" for="pfcMoneyNote" style="margin-top:.75rem">Note <span class="pfc-opt">(payment reference, optional)</span></label>
        <input class="platform-input" id="pfcMoneyNote" type="text" name="note" maxlength="255" placeholder="e.g. UPI ref 4821…">
        <div class="pfc-dialog-foot">
            <button type="button" class="btn-pf" id="pfcMoneyCancel">Cancel</button>
            <button type="submit" class="btn-pf btn-pf-primary"><i class="fas fa-plus" aria-hidden="true"></i> Add to wallet</button>
        </div>
    </form>
</dialog>

<script>
(function () {
    // Add money dialog
    var dlg = document.getElementById('pfcMoney');
    var form = document.getElementById('pfcMoneyForm');
    var amount = document.getElementById('pfcMoneyAmount');
    document.querySelectorAll('.js-add-money').forEach(function (btn) {
        btn.addEventListener('click', function () {
            form.setAttribute('action', btn.getAttribute('data-url'));
            document.getElementById('pfcMoneyName').textContent = btn.getAttribute('data-name');
            document.getElementById('pfcMoneyBalance').textContent = btn.getAttribute('data-balance');
            document.getElementById('pfcMoneyCur').textContent = btn.getAttribute('data-cur');
            form.reset();
            if (typeof dlg.showModal === 'function') dlg.showModal(); else dlg.setAttribute('open', '');
            amount.focus();
        });
    });
    function close() { if (typeof dlg.close === 'function') dlg.close(); else dlg.removeAttribute('open'); }
    document.getElementById('pfcMoneyClose').addEventListener('click', close);
    document.getElementById('pfcMoneyCancel').addEventListener('click', close);
    dlg.addEventListener('click', function (e) { if (e.target === dlg) close(); });
    document.querySelectorAll('.pfc-quick button').forEach(function (b) {
        b.addEventListener('click', function () { amount.value = b.getAttribute('data-amt'); amount.focus(); });
    });
    form.addEventListener('submit', function () {
        var btn = form.querySelector('button[type="submit"]');
        if (btn) { btn.disabled = true; btn.textContent = 'Adding…'; }
    });

})();
</script>
<?= $this->endSection() ?>
