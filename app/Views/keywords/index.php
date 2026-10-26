<?= $this->extend('layouts/main') ?>

<?= $this->section('header_actions') ?>
<?php if (function_exists('can') && can('keywords.create')): ?>
    <a href="<?= site_url('keywords/create') ?>" class="btn btn-wa btn-sm"><i class="fas fa-plus me-1"></i> Add keyword</a>
<?php endif; ?>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php
$provider = function_exists('whatsapp_provider') ? whatsapp_provider() : 'cheerio';
$providerShort = function_exists('whatsapp_provider_short') ? whatsapp_provider_short() : ($provider === 'meta' ? 'Meta' : 'External');
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
            External active — customer must message
            <?php if ($cheerioPhone !== ''): ?>
                <strong><?= esc($cheerioPhone) ?></strong>.
            <?php else: ?>
                your External number (<a href="<?= site_url('settings') ?>">set display phone in Settings</a>).
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
        <?php if (! empty($hasKeywords)): ?>
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
            <tbody></tbody>
        </table>
        <?php else: ?>
            <?= view('partials/empty_state', [
                'icon'        => 'key',
                'title'       => 'No keywords yet',
                'text'        => 'Add a keyword to auto-reply on inbound WhatsApp messages.',
                'actionUrl'   => (function_exists('can') && can('keywords.create')) ? site_url('keywords/create') : null,
                'actionLabel' => (function_exists('can') && can('keywords.create')) ? 'Add keyword' : null,
            ]) ?>
        <?php endif; ?>
    </div>
</div>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
$(function () {
    var esc = AppDT.esc;
    AppDT.table('#keywordsTable', {
        url: <?= json_encode(site_url('keywords/data')) ?>,
        order: [[0, 'asc']],
        emptyText: 'No keywords found',
        columns: [
            {data: 'order'},
            {data: null, render: function (r, t) { return t === 'display' ? '<code>' + esc(r.keyword) + '</code>' : r.keyword; }},
            {data: 'match', render: function (v, t) { return t === 'display' ? esc(v) : v; }},
            {data: 'type', render: function (v, t) { return t === 'display' ? esc(v) : v; }},
            {data: null, render: function (r, t) {
                if (t !== 'display') return r.active ? 1 : 0;
                return r.active ? '<span class="badge rounded-pill" style="background:var(--wa-mist);color:var(--wa-teal)">On</span>' : '<span class="badge rounded-pill bg-secondary">Off</span>';
            }},
            {data: null, orderable: false, className: 'text-end', render: function (r) {
                var h = '<div class="table-actions justify-content-end">';
                if (r.edit_url) h += '<a href="' + esc(r.edit_url) + '" class="btn btn-sm btn-outline-secondary" title="Edit"><i class="fas fa-edit"></i></a>';
                if (r.delete_url) h += '<button type="button" class="btn btn-sm btn-outline-danger" data-confirm-delete data-url="' + esc(r.delete_url) + '" title="Delete"><i class="fas fa-trash"></i></button>';
                return h + '</div>';
            }}
        ]
    });
});
</script>
<?= $this->endSection() ?>
