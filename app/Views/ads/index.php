<?= $this->extend('layouts/main') ?>

<?php
$canCreate   = function_exists('can') && can('ads.create');
$canActivate = function_exists('can') && can('ads.activate');
$canSettings = function_exists('can') && can('ads.accounts');
$badges      = ['draft' => 'secondary', 'paused' => 'warning', 'active' => 'success', 'ended' => 'dark', 'rejected' => 'danger', 'error' => 'danger'];
?>

<?= $this->section('header_actions') ?>
<?php if ($canSettings): ?>
    <a href="<?= site_url('ads/settings') ?>" class="btn btn-outline-secondary btn-sm me-1"><i class="fas fa-cog me-1"></i> Ad settings</a>
<?php endif; ?>
<?php if ($canCreate): ?>
    <a href="<?= site_url('ads/new') ?>" class="btn btn-wa btn-sm"><i class="fas fa-wand-magic-sparkles me-1"></i> New campaign</a>
<?php endif; ?>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<div class="page-list" id="adsListPage">

<?php if ($mode === 'mock'): ?>
    <div class="alert alert-info small">Test mode is on: campaigns are not sent to Meta and no money is spent. A person with ad settings access can switch to live mode in <strong>Ad settings</strong>.</div>
<?php endif; ?>

<div class="card">
    <div class="card-header"><h2 class="card-title mb-0">Campaigns</h2></div>
    <div class="card-body py-3">
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead><tr><th>Name</th><th>Status</th><th class="text-end">Daily budget</th><th class="text-end">Spent</th><th class="text-end">Chats</th><th class="text-end">Cost per chat</th><?php if ($canActivate): ?><th class="text-end">Actions</th><?php endif; ?></tr></thead>
                <tbody>
                <?php if (empty($campaigns)): ?>
                    <tr><td colspan="7" class="text-center text-muted py-4">No campaigns yet. Click <strong>New campaign</strong> and the AI will build one from your website.</td></tr>
                <?php endif; ?>
                <?php foreach ($campaigns as $c): ?>
                    <?php
                    $chats = (int) $c['conversations'];
                    $spend = (int) $c['spend_minor'];
                    ?>
                    <tr>
                        <td class="fw-medium">
                            <?= esc($c['name']) ?>
                            <?php if ((int) $c['is_mock'] === 1): ?><span class="badge bg-light text-dark border ms-1">Test</span><?php endif; ?>
                            <?php if (! empty($c['last_error'])): ?><div class="small text-danger"><?= esc($c['last_error']) ?></div><?php endif; ?>
                        </td>
                        <td><span class="badge bg-<?= $badges[$c['status']] ?? 'secondary' ?>"><?= esc(ucfirst($c['status'])) ?></span></td>
                        <td class="text-end"><?= esc($c['currency']) ?> <?= number_format((int) $c['daily_budget_minor'] / 100, 2) ?></td>
                        <td class="text-end"><?= $spend > 0 ? number_format($spend / 100, 2) : '-' ?></td>
                        <td class="text-end"><?= $chats > 0 ? $chats : '-' ?></td>
                        <td class="text-end"><?= $chats > 0 && $spend > 0 ? number_format($spend / 100 / $chats, 2) : '-' ?></td>
                        <?php if ($canActivate): ?>
                        <td class="text-end text-nowrap">
                            <?php if (in_array($c['status'], ['draft', 'paused', 'error'], true)): ?>
                                <button type="button" class="btn btn-sm btn-wa js-ad-launch" data-id="<?= (int) $c['id'] ?>" data-name="<?= esc($c['name'], 'attr') ?>">Review &amp; launch</button>
                            <?php endif; ?>
                            <?php if ($c['status'] === 'active'): ?>
                                <button type="button" class="btn btn-sm btn-outline-secondary js-ad-pause" data-id="<?= (int) $c['id'] ?>" data-name="<?= esc($c['name'], 'attr') ?>">Pause</button>
                            <?php endif; ?>
                            <?php if (in_array($c['status'], ['active', 'paused'], true)): ?>
                                <button type="button" class="btn btn-sm btn-outline-danger js-ad-end" data-id="<?= (int) $c['id'] ?>" data-name="<?= esc($c['name'], 'attr') ?>">End</button>
                            <?php endif; ?>
                        </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="small text-muted mt-3 mb-0">Spend and chats come from Meta and refresh every 30 minutes once live ads are running.</p>
    </div>
</div>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= asset_url('assets/js/ads.js') ?>"></script>
<?= $this->endSection() ?>
