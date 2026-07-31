<?php
/**
 * Renders a WhatsApp template header sample for server-side previews.
 *
 * Expects: $headerType (string), $headerContent (string)
 */
$headerType    = strtolower(trim((string) ($headerType ?? '')));
$headerContent = trim((string) ($headerContent ?? ''));

if ($headerContent === '') {
    return;
}

$isUrl = (bool) preg_match('#^https?://#i', $headerContent);

if ($headerType === 'text' || ($headerType === '' && ! $isUrl)) {
    echo '<div class="fw-semibold">' . esc($headerContent) . '</div>';

    return;
}

if ($headerType === '' && $isUrl) {
    if (preg_match('#\.(jpe?g|png|webp|gif)(\?|$)#i', $headerContent)) {
        $headerType = 'image';
    } elseif (preg_match('#\.(mp4|3gp|mov)(\?|$)#i', $headerContent)) {
        $headerType = 'video';
    } else {
        $headerType = 'document';
    }
}

$label = ucfirst($headerType !== '' ? $headerType : 'media') . ' header';

if ($headerType === 'image' && $isUrl) {
    echo '<img src="' . esc($headerContent) . '" alt="' . esc($label)
        . '" class="img-fluid rounded mb-2" style="max-height:220px">';

    return;
}

if ($headerType === 'video' && $isUrl) {
    echo '<video src="' . esc($headerContent) . '" controls preload="metadata"'
        . ' class="rounded mb-2" style="max-width:100%;max-height:220px"></video>';

    return;
}

if ($isUrl) {
    echo '<a href="' . esc($headerContent) . '" target="_blank" rel="noopener"'
        . ' class="d-inline-flex align-items-center gap-2 border rounded px-2 py-1 mb-2 text-decoration-none">'
        . '<i class="fas fa-file-lines"></i><span>' . esc($label) . '</span></a>';

    return;
}

// Media ID / upload handle — not browsable in the browser.
echo '<div class="small text-muted mb-2">' . esc($label)
    . ' sample is stored, but no preview URL is available.</div>';
