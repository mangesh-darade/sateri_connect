<?php
/**
 * Status badge partial.
 * Expected: $status (string), optional $map (array), optional $label
 */
$status = strtolower(trim((string) ($status ?? 'unknown')));
$status = str_replace([' ', '-'], '_', $status);
$status = preg_replace('/_+/', '_', $status) ?: 'unknown';
$map = $map ?? [
    'active'      => 'success',
    'inactive'    => 'secondary',
    'blocked'     => 'danger',
    'draft'       => 'secondary',
    'scheduled'   => 'info',
    'running'     => 'primary',
    'paused'      => 'warning',
    'completed'   => 'success',
    'cancelled'   => 'dark',
    'canceled'    => 'dark',
    'pending'     => 'warning',
    'processing'  => 'info',
    'inprogress'  => 'info',
    'in_progress' => 'info',
    'sent'        => 'primary',
    'delivered'   => 'success',
    'read'        => 'success',
    'failed'      => 'danger',
    'approved'    => 'success',
    'rejected'    => 'danger',
    'pending_review' => 'warning',
    'open'        => 'success',
    'closed'      => 'secondary',
    'queued'      => 'info',
];
$color = $map[$status] ?? 'secondary';
$text  = $label ?? ucfirst(str_replace('_', ' ', $status));
?>
<span class="badge bg-<?= esc($color, 'attr') ?>"><?= esc($text) ?></span>
