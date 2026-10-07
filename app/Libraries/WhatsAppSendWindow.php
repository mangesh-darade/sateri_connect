<?php

declare(strict_types=1);

namespace App\Libraries;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Quiet hours for marketing (campaign) WhatsApp sends, evaluated in the tenant's timezone.
 * Settings: `wa_quiet_hours_start` / `wa_quiet_hours_end` ("HH:MM"), falling back to Config\WhatsApp.
 */
final class WhatsAppSendWindow
{
    /** Marks queue rows deferred by quiet hours so campaign clean-up never fails them. */
    public const DEFER_PREFIX = '[Quiet hours]';

    public function __construct(private ?SettingsService $settings = null)
    {
    }

    public static function isValidTime(string $value): bool
    {
        return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value) === 1;
    }

    /**
     * @return array{enabled: bool, start: string, end: string, timezone: string}
     */
    public function config(): array
    {
        $cfg   = config('WhatsApp');
        $start = trim((string) ($this->setting('wa_quiet_hours_start') ?? ($cfg->quietHoursStart ?? '')));
        $end   = trim((string) ($this->setting('wa_quiet_hours_end') ?? ($cfg->quietHoursEnd ?? '')));
        $tz    = trim((string) ($this->setting('app_timezone') ?: $this->setting('timezone') ?: date_default_timezone_get()));

        $enabled = self::isValidTime($start) && self::isValidTime($end) && $start !== $end;

        return ['enabled' => $enabled, 'start' => $start, 'end' => $end, 'timezone' => $tz];
    }

    /**
     * When marketing sends are inside quiet hours, the server-local datetime they may resume at;
     * null when sending is allowed now.
     */
    public function deferUntil(?int $now = null): ?string
    {
        $cfg = $this->config();
        if (! $cfg['enabled']) {
            return null;
        }

        try {
            $tz = new DateTimeZone($cfg['timezone']);
        } catch (Throwable) {
            $tz = new DateTimeZone(date_default_timezone_get());
        }

        $local   = (new DateTimeImmutable('@' . ($now ?? time())))->setTimezone($tz);
        $minutes = (int) $local->format('G') * 60 + (int) $local->format('i');
        [$sh, $sm] = array_map('intval', explode(':', $cfg['start']));
        [$eh, $em] = array_map('intval', explode(':', $cfg['end']));
        $start = $sh * 60 + $sm;
        $end   = $eh * 60 + $em;

        $quiet = $start < $end
            ? ($minutes >= $start && $minutes < $end)
            : ($minutes >= $start || $minutes < $end);
        if (! $quiet) {
            return null;
        }

        $resume = $local->setTime($eh, $em);
        if ($resume <= $local) {
            $resume = $resume->modify('+1 day');
        }

        return $resume->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s');
    }

    private function setting(string $key): mixed
    {
        try {
            $this->settings ??= service('settingsService');

            $value = $this->settings->get($key);

            return $value === '' ? null : $value;
        } catch (Throwable) {
            return null;
        }
    }
}
