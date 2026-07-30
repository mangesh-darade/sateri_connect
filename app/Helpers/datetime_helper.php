<?php

declare(strict_types=1);

use App\Libraries\AppDateTime;

/**
 * Datetime helpers — Settings timezone (display / schedule) ↔ UTC storage.
 */

if (! function_exists('settings_timezone')) {
    function settings_timezone(): string
    {
        return AppDateTime::timezone();
    }
}

if (! function_exists('format_app_datetime')) {
    /**
     * Format a stored UTC datetime for display in the app timezone.
     */
    function format_app_datetime(mixed $value, string $format = AppDateTime::FORMAT_DISPLAY, string $empty = '—'): string
    {
        return AppDateTime::format($value, $format, $empty);
    }
}

if (! function_exists('app_datetime_local')) {
    /**
     * Value for &lt;input type="datetime-local"&gt; (app timezone wall clock).
     */
    function app_datetime_local(mixed $value): string
    {
        return AppDateTime::toDatetimeLocal($value);
    }
}

if (! function_exists('app_local_to_storage')) {
    /**
     * Interpret schedule / form datetime as app timezone → UTC storage string.
     */
    function app_local_to_storage(?string $local): ?string
    {
        return AppDateTime::localToStorage($local);
    }
}

if (! function_exists('app_now_storage')) {
    function app_now_storage(): string
    {
        return AppDateTime::nowStorage();
    }
}

if (! function_exists('app_today_ymd')) {
    function app_today_ymd(): string
    {
        return AppDateTime::todayYmd();
    }
}

if (! function_exists('app_day_bounds_utc')) {
    /**
     * @return array{0: string, 1: string}
     */
    function app_day_bounds_utc(?string $ymd = null): array
    {
        return AppDateTime::dayBoundsUtc($ymd);
    }
}

if (! function_exists('app_range_bounds_utc')) {
    /**
     * @return array{0: string, 1: string}
     */
    function app_range_bounds_utc(string $fromYmd, string $toYmd): array
    {
        return AppDateTime::rangeBoundsUtc($fromYmd, $toYmd);
    }
}
