<?= $this->extend('layouts/main') ?>

<?= $this->section('header_actions') ?>
<?php if (function_exists('can') && can('keywords.create')): ?>
    <a href="<?= site_url('keywords/create') ?>" class="btn btn-wa btn-sm"><i class="fas fa-plus me-1"></i> Add keyword</a>
<?php endif; ?>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php
$provider = function_exists('whatsapp_provider') ? whatsapp_provider() : 'cheerio';
$providerShort = function_exists('whatsapp_provider_short') ? whatsapp_provider_short() : ($provider === 'meta' ? 'Meta' : 'Cheerio');
$cheerioPhone = '';
try {
    $cfg = (new \App\Libraries\SettingsService())->getCheerioConfig();
    $raw = preg_replace('/\D+/', '', (string) ($cfg['display_phone'] ?? '')) ?? '';
    if (strlen($raw) === 12 && str_starts_with($raw, '91')) {
        $cheerioPhone = '+91 ' . substr($raw, 2, 5) . ' ' . substr($raw, 7);
    } elseif ($raw !== '') {
        $cheerioPhone = '+' . $raw;
    }
} catch (Throwable $e) {
    $cheerioPhone = '';
}
?>
<div class="page-list">
<div class="page-hint">
    <i class="fas fa-info-circle" aria-hidden="true"></i>
    <span>
        <span class="badge badge-soft me-1">Active: <?= esc($providerShort) ?></span>
        <?php if ($provider === 'cheerio'): ?>
            Cheerio active — customer must message
            <?php if ($cheerioPhone !== ''): ?>
                <strong><?= esc($cheerioPhone) ?></strong>.
            <?php else: ?>
                your Cheerio number (<a href="<?= site_url('settings') ?>">set display phone in Settings</a>).
            <?php endif; ?>
            Meta test number will not auto-reply.
        <?php else: ?>
            Meta active — message your Meta WhatsApp number only.
        <?php endif; ?>
        <a href="<?= site_url('settings') ?>">Change provider</a>
    </span>
</div>

<div class="card">
    <div class="card-header">
        <h2 class="card-title mb-0">Keywords</h2>
    </div>
    <div class="card-body py-3">
        <?php if (! empty($keywords)): ?>
        <table class="table table-sm table-hover align-middle w-100" id="keywordsTable">
            <thead>
                <tr>
                    <th>Order</th>
                    <th>Keyword</th>
                    <th>Match</th>
                    <th>Response Type</th>
                    <th>Active</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($keywords as $kw): ?>
                    <tr data-id="<?= (int) $kw['id'] ?>">
                        <td><?= esc((string) ($kw['menu_order'] ?? 0)) ?></td>
                        <td><code><?= esc($kw['keyword']) ?></code></td>
                        <td><?= esc($kw['match_type'] ?? '') ?></td>
                        <td><?= esc($kw['response_type'] ?? 'text') ?></td>
                        <td><?= ! empty($kw['is_active']) ? '<span class="badge rounded-pill" style="background:var(--wa-mist);color:var(--wa-teal)">On</span>' : '<span class="badge rounded-pill bg-secondary">Off</span>' ?></td>
                        <td class="text-end">
                            <div class="table-actions justify-content-end">
                            <?php if (function_exists('can') && can('keywords.edit')): ?>
                                <a href="<?= site_url('keywords/' . (int) $kw['id'] . '/edit') ?>" class="btn btn-sm btn-outline-secondary" title="Edit"><i class="fas fa-edit"></i></a>
                            <?php endif; ?>
                            <?php if (function_exists('can') && can('keywords.delete')): ?>
                                <button type="button" class="btn btn-sm btn-outline-danger" data-confirm-delete data-url="<?= site_url('keywords/' . (int) $kw['id'] . '/delete') ?>" title="Delete"><i class="fas fa-trash"></i></button>
                            <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
            <div class="activity-empty py-5">
                <i class="fas fa-key"></i>
                No keywords yet — add one to auto-reply.
                <?php if (function_exists('can') && can('keywords.create')): ?>
                    <div class="mt-3"><a href="<?= site_url('keywords/create') ?>" class="btn btn-wa btn-sm">Add keyword</a></div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</div>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>$(function(){ if($.fn.DataTable && $('#keywordsTable').length){ $('#keywordsTable').DataTable({order:[[0,'asc']]}); } });</script>
<?= $this->endSection() ?>
