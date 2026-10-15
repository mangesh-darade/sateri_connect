<?= $this->extend('layouts/platform') ?>

<?= $this->section('content') ?>
<?php
$clients = $clients ?? [];
$fmt = static function ($n): string {
    return number_format((int) $n);
};
?>

<section class="platform-card">
    <div class="platform-card-head">
        <div>
            <h2>All clients</h2>
            <p><?= $fmt(count($clients)) ?> workspace<?= count($clients) === 1 ? '' : 's' ?> registered</p>
        </div>
        <a class="btn-pf btn-pf-primary" href="<?= site_url('platform/clients/create') ?>">Create client</a>
    </div>

    <?php if ($clients === []): ?>
        <div class="platform-empty">
            <strong>No clients yet</strong>
            <p>Create the first workspace to start tracking performance.</p>
            <a class="btn-pf btn-pf-accent" href="<?= site_url('platform/clients/create') ?>">Create client</a>
        </div>
    <?php else: ?>
        <div class="platform-table-wrap">
            <table class="platform-table platform-table-cards">
                <thead>
                <tr>
                    <th>Client</th>
                    <th>Health</th>
                    <th class="col-lg">Users</th>
                    <th>Contacts</th>
                    <th>WA sent</th>
                    <th class="col-md">WA failed</th>
                    <th class="col-lg">Chats</th>
                    <th>Emails</th>
                    <th>Channels</th>
                    <th class="is-actions">Actions</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($clients as $c): ?>
                    <?php
                    $key = (string) ($c['key'] ?? '');
                    $health = (string) ($c['health'] ?? 'down');
                    $badgeClass = $health === 'ok' ? 'platform-badge-ok' : ($health === 'warn' ? 'platform-badge-warn' : 'platform-badge-down');
                    ?>
                    <tr>
                        <td class="td-full">
                            <div class="platform-client-name"><?= esc((string) ($c['name'] ?? $key)) ?></div>
                            <div class="platform-client-meta"><?= esc($key) ?> · <?= esc((string) ($c['db_database'] ?? '')) ?></div>
                        </td>
                        <td data-label="Health">
                            <span class="platform-badge <?= $badgeClass ?>">
                                <?= esc((string) ($c['health_label'] ?? $health)) ?>
                            </span>
                            <?php if (! empty($c['error'])): ?>
                                <div class="platform-client-meta platform-client-error" title="<?= esc((string) $c['error']) ?>"><?= esc((string) $c['error']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="col-lg" data-label="Users"><span class="platform-stat-chip"><?= $fmt($c['users'] ?? 0) ?></span></td>
                        <td data-label="Contacts"><span class="platform-stat-chip"><?= $fmt($c['contacts'] ?? 0) ?></span></td>
                        <td data-label="WA sent"><span class="platform-stat-chip"><?= $fmt($c['sent'] ?? 0) ?></span></td>
                        <td class="col-md" data-label="WA failed"><span class="platform-stat-chip"><?= $fmt($c['failed'] ?? 0) ?></span></td>
                        <td class="col-lg" data-label="Chats"><span class="platform-stat-chip"><?= $fmt($c['open_chats'] ?? 0) ?></span></td>
                        <td data-label="Emails"><span class="platform-stat-chip"><?= $fmt($c['email_sent'] ?? 0) ?></span></td>
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
                                <a class="btn-pf btn-pf-icon" href="<?= site_url('platform/clients/' . rawurlencode($key)) ?>" title="Deep view" aria-label="Deep view">
                                    <i class="fas fa-chart-pie" aria-hidden="true"></i>
                                </a>
                                <a class="btn-pf btn-pf-icon" href="<?= site_url('platform/clients/' . rawurlencode($key) . '/settings') ?>" title="Settings" aria-label="Settings">
                                    <i class="fas fa-gear" aria-hidden="true"></i>
                                </a>
                                <form action="<?= site_url('platform/clients/' . rawurlencode($key) . '/enter') ?>" method="post">
                                    <?= csrf_field() ?>
                                    <button type="submit" class="btn-pf btn-pf-icon btn-pf-primary" title="Open workspace" aria-label="Open workspace">
                                        <i class="fas fa-arrow-right-to-bracket" aria-hidden="true"></i>
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
<?= $this->endSection() ?>
