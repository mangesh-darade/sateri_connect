<?php
/**
 * Shared active-email-provider banner for Emails screens.
 *
 * @var string $provider
 * @var string $providerLabel
 * @var string $providerDetail
 * @var string $defaultTo
 * @var string|null $mode  null|single|bulk
 */
$provider = $provider ?? 'smtp';
$providerLabel = $providerLabel ?? 'SMTP';
$providerDetail = $providerDetail ?? '';
$defaultTo = $defaultTo ?? '';
$mode = $mode ?? null;

$toneClass = match ($provider) {
    'cheerio'  => 'email-provider-banner--cheerio',
    'sendgrid' => 'email-provider-banner--sendgrid',
    default    => 'email-provider-banner--smtp',
};

$icon = match ($provider) {
    'cheerio'  => 'fa-bolt',
    'sendgrid' => 'fa-paper-plane',
    default    => 'fa-server',
};

$singleHow = match ($provider) {
    'cheerio'  => 'One address via Cheerio email API',
    'sendgrid' => 'One address via SendGrid',
    default    => 'One address via SMTP',
};

$bulkHow = match ($provider) {
    'cheerio'  => 'Many addresses, or one Cheerio contact label',
    'sendgrid' => 'Many addresses via SendGrid',
    default    => 'Many addresses via SMTP',
};
?>
<div class="email-provider-banner <?= esc($toneClass) ?> mb-3" role="status">
    <div class="email-provider-banner__main">
        <div class="email-provider-banner__icon"><i class="fas <?= esc($icon) ?>"></i></div>
        <div class="email-provider-banner__copy">
            <div class="email-provider-banner__kicker">Active email provider</div>
            <div class="email-provider-banner__title"><?= esc($providerLabel) ?></div>
            <?php if ($providerDetail !== ''): ?>
                <div class="email-provider-banner__detail text-muted"><?= esc($providerDetail) ?></div>
            <?php endif; ?>
        </div>
        <a href="<?= site_url('settings') ?>#tabEmail" class="btn btn-sm btn-outline-secondary">Change in Settings</a>
    </div>

    <div class="email-provider-banner__grid">
        <div class="email-provider-banner__chip <?= $mode === 'single' ? 'is-current' : '' ?>">
            <span class="email-provider-banner__chip-label"><i class="fas fa-paper-plane me-1"></i> Single</span>
            <strong>Runs via <?= esc($providerLabel) ?></strong>
            <span class="small text-muted"><?= esc($singleHow) ?></span>
        </div>
        <div class="email-provider-banner__chip <?= $mode === 'bulk' ? 'is-current' : '' ?>">
            <span class="email-provider-banner__chip-label"><i class="fas fa-mail-bulk me-1"></i> Bulk</span>
            <strong>Runs via <?= esc($providerLabel) ?></strong>
            <span class="small text-muted"><?= esc($bulkHow) ?></span>
        </div>
    </div>

    <?php if ($defaultTo !== ''): ?>
        <div class="email-provider-banner__foot small text-muted">
            From / default address: <strong><?= esc($defaultTo) ?></strong>
        </div>
    <?php endif; ?>
</div>
