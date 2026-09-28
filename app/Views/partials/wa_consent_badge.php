<?php
/**
 * WhatsApp consent badge for a contact row.
 *
 * @var array<string, mixed> $contact
 */
$contact = $contact ?? [];
$consent = service('whatsAppConsent');

if (! empty($contact['wa_opted_out_at'])) {
    $cls   = 'danger';
    $label = 'Opted out';
    $title = 'Customer opted out on ' . format_app_datetime($contact['wa_opted_out_at']);
} elseif ($consent->isSuppressed($contact)) {
    $cls   = 'warning';
    $label = 'Paused';
    $title = 'Business-initiated sends paused until ' . format_app_datetime($contact['wa_suppressed_until'])
        . (! empty($contact['wa_suppress_reason']) ? ' — ' . $contact['wa_suppress_reason'] : '');
} elseif ((int) ($contact['wa_opt_in'] ?? 0) === 1) {
    $cls   = 'success';
    $label = 'Opted in';
    $title = 'Opt-in via ' . (\App\Libraries\WhatsAppConsentService::OPT_IN_SOURCES[$contact['wa_opt_in_source'] ?? ''] ?? ($contact['wa_opt_in_source'] ?? 'unknown'))
        . (! empty($contact['wa_opt_in_at']) ? ' on ' . format_app_datetime($contact['wa_opt_in_at']) : '');
} else {
    $cls   = 'secondary';
    $label = 'No opt-in';
    $title = 'No WhatsApp consent recorded — campaigns will skip this contact';
}
?>
<span class="badge bg-<?= $cls ?>" title="<?= esc($title) ?>"><i class="fab fa-whatsapp me-1"></i><?= esc($label) ?></span>
