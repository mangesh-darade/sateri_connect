<?php
$guideType = (string) ($guideType ?? 'local');
$guideBase = (string) ($guideBase ?? 'guide/');
$guideNav = $guideNav ?? [];
?>
<nav class="guide-tabs" aria-label="Guides">
    <?php foreach ($guideNav as $nav): ?>
        <?php $navType = (string) ($nav['type'] ?? ''); ?>
        <a href="<?= site_url($guideBase . $navType) ?>" class="guide-tab<?= $guideType === $navType ? ' is-active' : '' ?>">
            <?= esc($nav['label'] ?? $navType) ?>
        </a>
    <?php endforeach; ?>
</nav>
<style>
.guide-tabs {
    display: flex;
    flex-wrap: wrap;
    gap: .35rem;
    margin-bottom: 1rem;
}
.guide-tab {
    display: inline-flex;
    align-items: center;
    padding: .4rem .85rem;
    border-radius: 999px;
    border: 1px solid var(--border, rgba(21, 32, 41, .12));
    background: var(--surface, #fff);
    color: var(--text-muted, #5c6b66);
    font-size: .82rem;
    font-weight: 600;
    text-decoration: none;
}
.guide-tab:hover { color: var(--wa-ink, #152029); }
.guide-tab.is-active {
    background: var(--wa-green, #25d366);
    border-color: var(--wa-green, #25d366);
    color: #fff;
}
</style>
