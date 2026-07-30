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

if (! function_exists('contact_within_24h_window')) {
    /**
     * Resolve 24h window from contact.last_reply_at, with fallback to latest inbound message.
     * Also backfills last_reply_at when inbound exists but the column is empty/stale.
     *
     * @param array<string, mixed>|null $contact
     */
    function contact_within_24h_window(?array $contact, bool $repair = true): bool
    {
        if ($contact === null) {
            return false;
        }

        $contactId = (int) ($contact['id'] ?? 0);
        $lastReply = $contact['last_reply_at'] ?? null;

        if (is_within_24h_window($lastReply)) {
            return true;
        }

        if ($contactId <= 0) {
            return false;
        }

        try {
            $row = model(\App\Models\MessageModel::class)
                ->select('created_at')
                ->where('contact_id', $contactId)
                ->where('direction', 'inbound')
                ->orderBy('id', 'DESC')
                ->first();
        } catch (Throwable $e) {
            return false;
        }

        $inboundAt = is_array($row) ? ($row['created_at'] ?? null) : null;
        if (! is_within_24h_window($inboundAt)) {
            return false;
        }

        if ($repair) {
            try {
                model(\App\Models\ContactModel::class)->update($contactId, [
                    'last_reply_at'   => $inboundAt,
                    'last_message_at' => $inboundAt,
                ]);
            } catch (Throwable $e) {
                // Non-fatal — window is still open for this request.
            }
        }

        return true;
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
