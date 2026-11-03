<?= $this->extend('layouts/main') ?>

<?= $this->section('header_actions') ?>
<div class="d-flex flex-wrap gap-2">
<?php foreach ([['facebook', 'fab fa-facebook', 'Facebook &amp; Instagram', $configured], ['youtube', 'fab fa-youtube', 'YouTube', $oauth['youtube']], ['linkedin', 'fab fa-linkedin', 'LinkedIn', $oauth['linkedin']]] as [$key, $icon, $label, $ready]): ?>
    <?php if ($ready): ?>
        <a href="<?= site_url('social/accounts/connect/' . $key) ?>" class="btn btn-wa btn-sm"><i class="<?= $icon ?> me-1"></i> Connect <?= $label ?></a>
    <?php else: ?>
        <a href="<?= site_url('social/setup') ?>" class="btn btn-outline-secondary btn-sm" title="Add the app credentials first"><i class="<?= $icon ?> me-1"></i> Set up <?= $label ?></a>
    <?php endif; ?>
<?php endforeach; ?>
</div>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<div class="page-list">

<?php if (session()->getFlashdata('success')): ?>
    <div class="alert alert-success py-2"><?= esc(session()->getFlashdata('success')) ?></div>
<?php endif; ?>
<?php if (session()->getFlashdata('error')): ?>
    <div class="alert alert-danger py-2"><?= esc(session()->getFlashdata('error')) ?></div>
<?php endif; ?>

<?php if (! $configured || ! $oauth['youtube'] || ! $oauth['linkedin']): ?>
    <div class="alert alert-warning">
        Some logins are not set up yet (<?= implode(', ', array_filter([! $configured ? 'Facebook &amp; Instagram' : '', ! $oauth['youtube'] ? 'YouTube' : '', ! $oauth['linkedin'] ? 'LinkedIn' : ''])) ?>).
        <a href="<?= site_url('social/setup') ?>" class="alert-link">Open Setup</a> to enter the app credentials and copy the redirect URLs.
    </div>
<?php endif; ?>

<div class="page-hint">
    <i class="fas fa-info-circle" aria-hidden="true"></i>
    <span>Connect a Facebook Page to publish to it. Instagram works when the Page is linked to an Instagram Business or Creator account.
    YouTube posts need a video. LinkedIn posts go to your personal profile. <a href="<?= site_url('guide/social') ?>">Read the Social Scheduler guide</a>.
    To connect with an access token or a YouTube refresh token, use <a href="<?= site_url('social/setup') ?>#soc-token">Social Setup</a>.</span>
</div>


<div class="card" data-social-accounts>
    <div class="card-header"><h2 class="card-title mb-0">Connected accounts</h2></div>
    <div class="card-body py-3">
        <?php if ($accounts === []): ?>
            <p class="text-muted mb-0">No accounts connected yet.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead><tr><th>Platform</th><th>Name</th><th>Status</th><th>Connected</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($accounts as $a):
                        // Where to see this account on its own platform (and where WhatsApp broadcasts are managed).
                        $handle = ltrim((string) $a['name'], '@');
                        $openUrl = match ((string) $a['platform']) {
                            'facebook'  => 'https://www.facebook.com/' . rawurlencode((string) $a['external_id']),
                            'instagram' => preg_match('/^[A-Za-z0-9._]{1,30}$/', $handle) === 1 && ! ctype_digit($handle) ? 'https://www.instagram.com/' . $handle . '/' : '',
                            'youtube'   => 'https://www.youtube.com/channel/' . rawurlencode((string) $a['external_id']),
                            'linkedin'  => 'https://www.linkedin.com/in/me/',
                            'whatsapp'  => site_url('campaigns'),
                            default     => '',
                        };
                        $external = (string) $a['platform'] !== 'whatsapp';
                        ?>
                        <tr>
                            <td><i class="fab fa-<?= esc($a['platform']) ?> me-1"></i><?= esc(ucfirst((string) $a['platform'])) ?></td>
                            <td>
                                <?php if ($openUrl !== ''): ?>
                                    <a href="<?= esc($openUrl, 'attr') ?>"<?= $external ? ' target="_blank" rel="noopener noreferrer"' : '' ?>><?= esc((string) $a['name']) ?></a>
                                <?php else: ?>
                                    <?= esc((string) $a['name']) ?>
                                <?php endif; ?>
                            </td>
                            <td><span class="badge <?= $a['status'] === 'active' ? 'bg-success' : 'bg-warning text-dark' ?>"><?= esc(ucfirst((string) $a['status'])) ?></span></td>
                            <td class="text-muted small"><?= esc((string) $a['created_at']) ?></td>
                            <td class="text-end text-nowrap">
                                <?php if ($openUrl !== ''): ?>
                                    <a class="btn btn-outline-secondary btn-sm me-1" href="<?= esc($openUrl, 'attr') ?>"<?= $external ? ' target="_blank" rel="noopener noreferrer"' : '' ?>>
                                        <i class="fas fa-<?= $external ? 'arrow-up-right-from-square' : 'bullhorn' ?> me-1"></i><span><?= $external ? 'Open on ' . esc(ucfirst((string) $a['platform'])) : 'Open Broadcasts' ?></span>
                                    </a>
                                <?php endif; ?>
                                <?php if (function_exists('can') && can('social.accounts')): ?>
                                    <button class="btn btn-outline-danger btn-sm" data-social-disconnect="<?= (int) $a['id'] ?>" data-name="<?= esc((string) $a['name'], 'attr') ?>">Disconnect</button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= base_url('assets/js/social.js') ?>"></script>
<?= $this->endSection() ?>
