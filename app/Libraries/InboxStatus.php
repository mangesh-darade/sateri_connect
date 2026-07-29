<?php

declare(strict_types=1);

namespace App\Libraries;

/**
 * Cheerio-style Team Inbox status helpers.
 */
class InboxStatus
{
    public const OPEN        = 'open';
    public const PENDING     = 'pending';
    public const RESOLVED    = 'resolved';
    public const CHATBOT     = 'chatbot';
    public const INTERVENED  = 'intervened';
    /** @deprecated Use RESOLVED; kept for API/UI aliases */
    public const CLOSED      = 'closed';

    /** Stored workflow statuses (not window/assignee filters). */
    public const WORKFLOW_STATUSES = [
        self::OPEN,
        self::PENDING,
        self::RESOLVED,
        self::CHATBOT,
        self::INTERVENED,
        self::CLOSED,
    ];

    /** Quick filters that are not a single DB status column. */
    public const COMPOSITE_FILTERS = [
        'all',
        'active',
        'expired',
        'unassigned',
        'frt_exceeded',
        'ctwa',
        'unread',
        'assigned',
    ];

    public static function normalize(string $status): string
    {
        $status = strtolower(trim($status));
        if ($status === self::CLOSED) {
            return self::RESOLVED;
        }

        return $status;
    }

    public static function isWritable(string $status): bool
    {
        $status = self::normalize($status);

        return in_array($status, [
            self::OPEN,
            self::PENDING,
            self::RESOLVED,
            self::CHATBOT,
            self::INTERVENED,
        ], true);
    }

    /**
     * Labels for UI chips / badges.
     *
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            self::OPEN       => 'Open',
            self::PENDING    => 'Pending',
            self::RESOLVED   => 'Resolved',
            self::CHATBOT    => 'Chatbot',
            self::INTERVENED => 'Intervened',
            self::CLOSED     => 'Resolved',
            'active'         => 'Active',
            'expired'        => 'Expired',
            'unassigned'     => 'Unassigned',
            'frt_exceeded'   => 'FRT Exceeded',
            'ctwa'           => 'CTWA',
            'all'            => 'All',
        ];
    }

    public static function label(string $status): string
    {
        $labels = self::labels();
        $key    = strtolower(trim($status));

        return $labels[$key] ?? ucfirst($key);
    }

    /**
     * Map UI/API status filter to SQL constraints.
     *
     * @param object $builder Query builder with conversations aliased as cv
     */
    public static function applyStatusFilter(object $builder, string $status): void
    {
        $status = strtolower(trim($status));
        if ($status === '' || $status === 'all') {
            return;
        }

        if ($status === 'closed' || $status === self::RESOLVED) {
            $builder->whereIn('cv.status', [self::RESOLVED, self::CLOSED]);

            return;
        }

        if (in_array($status, [self::OPEN, self::PENDING, self::CHATBOT, self::INTERVENED], true)) {
            $builder->where('cv.status', $status);
        }
    }

    /**
     * Composite inbox filters (window / assignee / FRT / CTWA).
     *
     * @param object $builder Query builder with conversations aliased as cv and contacts as c
     */
    public static function applyCompositeFilter(object $builder, string $filter, bool $hasFrt = true, bool $hasCtwa = true): void
    {
        $filter = strtolower(trim($filter));
        if ($filter === '' || $filter === 'all') {
            return;
        }

        $now = date('Y-m-d H:i:s');
        $windowStart = date('Y-m-d H:i:s', time() - 86400);

        if ($filter === 'active') {
            $builder->groupStart()
                ->where('c.last_reply_at >=', $windowStart)
                ->orWhere('cv.last_message_at >=', $windowStart)
                ->groupEnd();

            return;
        }

        if ($filter === 'expired') {
            $builder->groupStart()
                ->groupStart()
                    ->where('c.last_reply_at <', $windowStart)
                    ->orWhere('c.last_reply_at', null)
                ->groupEnd()
                ->groupStart()
                    ->where('cv.last_message_at <', $windowStart)
                    ->orWhere('cv.last_message_at', null)
                ->groupEnd()
                ->groupEnd();

            return;
        }

        if ($filter === 'unassigned') {
            $builder->where('cv.assigned_to', null);

            return;
        }

        if ($filter === 'assigned') {
            $builder->where('cv.assigned_to IS NOT NULL');

            return;
        }

        if ($filter === 'frt_exceeded' && $hasFrt) {
            $builder->where('cv.frt_due_at <', $now)
                ->whereIn('cv.status', [self::OPEN, self::PENDING]);

            return;
        }

        if ($filter === 'ctwa' && $hasCtwa) {
            $builder->where('cv.ctwa_referral IS NOT NULL')
                ->where('cv.ctwa_referral !=', '');
        }
    }

    /**
     * Default FRT due timestamp (5 minutes from now).
     */
    public static function defaultFrtDueAt(?int $minutes = null): string
    {
        $minutes = $minutes ?? 5;

        return date('Y-m-d H:i:s', time() + max(1, $minutes) * 60);
    }
}
