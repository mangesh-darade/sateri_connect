<?php
/**
 * Shared empty state for list/detail screens.
 *
 * @var string      $title
 * @var string|null $text
 * @var string|null $icon   Font Awesome class without "fas " prefix (e.g. "inbox")
 * @var string|null $actionUrl
 * @var string|null $actionLabel
 * @var string|null $actionClass
 */
$title       = (string) ($title ?? 'Nothing here yet');
$text        = $text ?? null;
$icon        = (string) ($icon ?? 'inbox');
$actionUrl   = $actionUrl ?? null;
$actionLabel = $actionLabel ?? null;
$actionClass = (string) ($actionClass ?? 'btn btn-wa btn-sm');
?>
<div class="page-empty" role="status">
    <div class="page-empty__icon" aria-hidden="true"><i class="fas fa-<?= esc($icon) ?>"></i></div>
    <h3 class="page-empty__title"><?= esc($title) ?></h3>
    <?php if ($text !== null && $text !== ''): ?>
        <p class="page-empty__text"><?= esc($text) ?></p>
    <?php endif; ?>
    <?php if ($actionUrl && $actionLabel): ?>
        <div class="page-empty__actions">
            <a href="<?= esc($actionUrl) ?>" class="<?= esc($actionClass) ?>"><?= esc($actionLabel) ?></a>
        </div>
    <?php endif; ?>
</div>
