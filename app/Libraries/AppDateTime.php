<?php

declare(strict_types=1);

namespace App\Libraries;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Throwable;

/**
 * App timezone conversion for storage (UTC) ↔ display/input (Settings app_timezone).
 *
 * DB timestamps are written with PHP/CI default timezone (UTC). UI and schedules
 * must use the configured IANA zone from settings.
 */
class AppDateTime
{
    public const STORAGE_TZ = 'UTC';

    public const FORMAT_DISPLAY = 'd-M-Y, g:i A';

    public const FORMAT_DISPLAY_SHORT = 'd-M-Y, g:i A';

    public const FORMAT_DATETIME_LOCAL = 'Y-m-d\TH:i';

    public const FORMAT_STORAGE = 'Y-m-d H:i:s';

    private static ?string $timezoneOverride = null;

    /**
     * Test / request-scoped override (null clears).
     */
    public static function setTimezoneOverride(?string $timezone): void
    {
        self::$timezoneOverride = $timezone;
    }

    /**
     * Resolve configured IANA timezone (falls back to UTC).
     */
    public static function timezone(): string
    {
        if (self::$timezoneOverride !== null && self::$timezoneOverride !== '') {
            try {
                new DateTimeZone(self::$timezoneOverride);

                return self::$timezoneOverride;
            } catch (Throwable $e) {
                return self::STORAGE_TZ;
            }
        }

        $tz = 'UTC';

        try {
            if (function_exists('setting')) {
                $tz = (string) (setting('app_timezone') ?: setting('timezone', 'UTC'));
            }
        } catch (Throwable $e) {
            $tz = 'UTC';
        }

        $tz = trim($tz);
        if ($tz === '') {
            return self::STORAGE_TZ;
        }

        try {
            new DateTimeZone($tz);
        } catch (Throwable $e) {
            return self::STORAGE_TZ;
        }

        return $tz;
    }

    public static function appZone(): DateTimeZone
    {
        return new DateTimeZone(self::timezone());
    }

    public static function storageZone(): DateTimeZone
    {
        return new DateTimeZone(self::STORAGE_TZ);
    }

    /**
     * Parse a stored UTC (or naive) datetime into DateTimeImmutable in storage TZ.
     */
    public static function parseStorage(null|string|int|DateTimeInterface $value): ?DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value)->setTimezone(self::storageZone());
        }

        if (is_int($value) || (is_string($value) && ctype_digit($value))) {
            $ts = (int) $value;
            if ($ts > 20000000000) {
                $ts = (int) floor($ts / 1000);
            }

            return (new DateTimeImmutable('@' . $ts))->setTimezone(self::storageZone());
        }

        $raw = trim((string) $value);
        if ($raw === '' || $raw === '0000-00-00 00:00:00' || $raw === '0000-00-00') {
            return null;
        }

        // ISO with offset / Z — honor embedded zone, then normalize to storage.
        if (preg_match('/[Tt].*(Z|[+-]\d{2}:?\d{2})$/', $raw) === 1) {
            try {
                return (new DateTimeImmutable($raw))->setTimezone(self::storageZone());
            } catch (Throwable $e) {
                return null;
            }
        }

        $normalized = str_replace('T', ' ', $raw);
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $normalized) === 1) {
            $normalized .= ':00';
        }

        try {
            return new DateTimeImmutable($normalized, self::storageZone());
        } catch (Throwable $e) {
            $ts = strtotime($raw);

            return $ts === false ? null : (new DateTimeImmutable('@' . $ts))->setTimezone(self::storageZone());
        }
    }

    /**
     * Interpret user / datetime-local input as wall time in app timezone → UTC storage string.
     */
    public static function localToStorage(?string $local): ?string
    {
        if ($local === null) {
            return null;
        }

        $raw = trim($local);
        if ($raw === '') {
            return null;
        }

        $raw = str_replace('T', ' ', $raw);
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $raw) === 1) {
            $raw .= ':00';
        }

        try {
            $dt = new DateTimeImmutable($raw, self::appZone());
        } catch (Throwable $e) {
            return null;
        }

        return $dt->setTimezone(self::storageZone())->format(self::FORMAT_STORAGE);
    }

    /**
     * Format a stored UTC datetime for display in app timezone.
     */
    public static function format(
        null|string|int|DateTimeInterface $value,
        string $format = self::FORMAT_DISPLAY,
        string $empty = ''
    ): string {
        $dt = self::parseStorage($value);
        if ($dt === null) {
            return $empty;
        }

        return $dt->setTimezone(self::appZone())->format($format);
    }

    /**
     * Value for &lt;input type="datetime-local"&gt; in app timezone.
     */
    public static function toDatetimeLocal(null|string|int|DateTimeInterface $value): string
    {
        return self::format($value, self::FORMAT_DATETIME_LOCAL, '');
    }

    /**
     * Current instant as UTC storage string.
     */
    public static function nowStorage(): string
    {
        return (new DateTimeImmutable('now', self::storageZone()))->format(self::FORMAT_STORAGE);
    }

    /**
     * Today's Y-m-d in app timezone.
     */
    public static function todayYmd(): string
    {
        return (new DateTimeImmutable('now', self::appZone()))->format('Y-m-d');
    }

    /**
     * Convert an app-local calendar day to UTC [start, end] inclusive bounds.
     *
     * @return array{0: string, 1: string}
     */
    public static function dayBoundsUtc(?string $ymd = null): array
    {
        $ymd = $ymd !== null && $ymd !== '' ? $ymd : self::todayYmd();
        $start = new DateTimeImmutable($ymd . ' 00:00:00', self::appZone());
        $end   = new DateTimeImmutable($ymd . ' 23:59:59', self::appZone());

        return [
            $start->setTimezone(self::storageZone())->format(self::FORMAT_STORAGE),
            $end->setTimezone(self::storageZone())->format(self::FORMAT_STORAGE),
        ];
    }

    /**
     * Convert an app-local date range to UTC [start, end] inclusive bounds.
     *
     * @return array{0: string, 1: string}
     */
    public static function rangeBoundsUtc(string $fromYmd, string $toYmd): array
    {
        $start = new DateTimeImmutable($fromYmd . ' 00:00:00', self::appZone());
        $end   = new DateTimeImmutable($toYmd . ' 23:59:59', self::appZone());

        return [
            $start->setTimezone(self::storageZone())->format(self::FORMAT_STORAGE),
            $end->setTimezone(self::storageZone())->format(self::FORMAT_STORAGE),
        ];
    }

    /**
     * First day of current month (app TZ) → UTC start, and now end-of-today UTC.
     *
     * @return array{0: string, 1: string}
     */
    public static function monthToDateBoundsUtc(): array
    {
        $now   = new DateTimeImmutable('now', self::appZone());
        $start = $now->modify('first day of this month')->setTime(0, 0, 0);
        $end   = $now->setTime(23, 59, 59);

        return [
            $start->setTimezone(self::storageZone())->format(self::FORMAT_STORAGE),
            $end->setTimezone(self::storageZone())->format(self::FORMAT_STORAGE),
        ];
    }

    /**
     * List of recent app-local Y-m-d dates (oldest first), length = $days.
     *
     * @return list<string>
     */
    public static function recentDaysYmd(int $days): array
    {
        $days = max(1, $days);
        $out  = [];
        $cursor = new DateTimeImmutable('now', self::appZone());

        for ($i = $days - 1; $i >= 0; $i--) {
            $out[] = $cursor->modify("-{$i} days")->format('Y-m-d');
        }

        return $out;
    }
}
