<?php

/**
 * WhatsApp-related helper functions.
 */

if (! function_exists('normalize_phone')) {
    /**
     * Strip a phone number to digits only (E.164 without +).
     */
    function normalize_phone(?string $phone): string
    {
        if ($phone === null || $phone === '') {
            return '';
        }

        return preg_replace('/\D+/', '', $phone) ?? '';
    }
}

if (! function_exists('format_phone')) {
    /**
     * Format a phone number for display. Adds + prefix by default.
     */
    function format_phone(?string $phone, bool $withPlus = true): string
    {
        $digits = normalize_phone($phone);
        if ($digits === '') {
            return '';
        }

        return $withPlus ? '+' . $digits : $digits;
    }
}

if (! function_exists('is_within_24h_window')) {
    /**
     * Whether a contact is still inside the WhatsApp 24-hour customer care window.
     *
     * @param string|int|null $lastReplyAt Datetime string or unix timestamp of last inbound message
     */
    function is_within_24h_window(string|int|null $lastReplyAt): bool
    {
        if ($lastReplyAt === null || $lastReplyAt === '' || $lastReplyAt === 0) {
            return false;
        }

        $ts = is_numeric($lastReplyAt) ? (int) $lastReplyAt : strtotime((string) $lastReplyAt);
        if ($ts === false || $ts <= 0) {
            return false;
        }

        return (time() - $ts) <= 86400;
    }
}

if (! function_exists('wa_status_badge')) {
    /**
     * Return a Bootstrap badge HTML snippet for a WhatsApp / queue status.
     */
    function wa_status_badge(?string $status): string
    {
        $status = strtolower((string) $status);
        $map    = [
            'pending'     => 'secondary',
            'queued'      => 'secondary',
            'processing'  => 'info',
            'sent'        => 'primary',
            'delivered'   => 'success',
            'read'        => 'success',
            'received'    => 'info',
            'failed'      => 'danger',
            'cancelled'   => 'dark',
            'draft'       => 'secondary',
            'scheduled'   => 'warning',
            'running'     => 'primary',
            'paused'      => 'warning',
            'completed'   => 'success',
            'active'      => 'success',
            'inactive'    => 'secondary',
            'blocked'     => 'danger',
            'open'        => 'success',
            'closed'      => 'secondary',
        ];

        $class = $map[$status] ?? 'secondary';
        $label = $status !== '' ? esc(ucfirst($status)) : 'Unknown';

        return '<span class="badge bg-' . $class . '">' . $label . '</span>';
    }
}
