<?php

declare(strict_types=1);

namespace App\Libraries;

use DateTimeImmutable;

/**
 * Weekly business hours and the away message sent to WhatsApp customers who
 * write outside them. Stored as three settings keys (group "business_hours");
 * times are interpreted in the workspace timezone.
 */
class BusinessHours
{
    public const DAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    /** The away message is sent at most once per conversation in this window. */
    private const AWAY_COOLDOWN_SECONDS = 43200;

    private SettingsService $settings;

    public function __construct(?SettingsService $settings = null)
    {
        $this->settings = $settings ?? service('settingsService');
    }

    /**
     * @return array{enabled:bool,away_message:string,days:array<string,array{open:bool,from:string,to:string}>}
     */
    public function config(): array
    {
        $days = [];
        $saved = json_decode((string) $this->settings->get('bh_schedule', ''), true);
        $saved = is_array($saved) ? $saved : [];

        foreach (self::DAYS as $d) {
            $weekday = ! in_array($d, ['sat', 'sun'], true);
            $row     = is_array($saved[$d] ?? null) ? $saved[$d] : [];
            $days[$d] = [
                'open' => (bool) ($row['open'] ?? $weekday),
                'from' => $this->validTime($row['from'] ?? null) ?? '09:00',
                'to'   => $this->validTime($row['to'] ?? null) ?? '18:00',
            ];
        }

        return [
            'enabled'      => in_array(strtolower(trim((string) $this->settings->get('bh_enabled', '0'))), ['1', 'true', 'yes', 'on'], true),
            'away_message' => (string) $this->settings->get('bh_away_message', "Thanks for your message. We're currently closed and will get back to you as soon as we open."),
            'days'         => $days,
        ];
    }

    /**
     * @param array{enabled?:mixed,away_message?:mixed,open?:array<string,mixed>,from?:array<string,mixed>,to?:array<string,mixed>} $in
     * @return array{ok:bool,status:int,message:string}
     */
    public function save(array $in): array
    {
        $days = [];
        foreach (self::DAYS as $d) {
            $open = ! empty($in['open'][$d]);
            $from = $this->validTime($in['from'][$d] ?? null);
            $to   = $this->validTime($in['to'][$d] ?? null);

            if ($open && ($from === null || $to === null || $from >= $to)) {
                return ['ok' => false, 'status' => 422, 'message' => 'Business hours: opening time must be before closing time for ' . strtoupper($d) . '.'];
            }

            $days[$d] = ['open' => $open, 'from' => $from ?? '09:00', 'to' => $to ?? '18:00'];
        }

        $message = trim(mb_substr((string) ($in['away_message'] ?? ''), 0, 1000));
        if (! empty($in['enabled']) && $message === '') {
            return ['ok' => false, 'status' => 422, 'message' => 'Away message cannot be empty while business hours are enabled.'];
        }

        $this->settings->set('bh_enabled', ! empty($in['enabled']) ? '1' : '0', 'business_hours');
        $this->settings->set('bh_away_message', $message, 'business_hours');
        $this->settings->set('bh_schedule', (string) json_encode($days), 'business_hours');

        return ['ok' => true, 'status' => 200, 'message' => 'Business hours saved.'];
    }

    public function isOpen(?DateTimeImmutable $at = null): bool
    {
        $cfg = $this->config();
        $at  = ($at ?? new DateTimeImmutable('now'))->setTimezone(AppDateTime::appZone());
        $day = $cfg['days'][strtolower($at->format('D'))];
        $now = $at->format('H:i');

        return $day['open'] && $now >= $day['from'] && $now < $day['to'];
    }

    /**
     * True when the away message should go out for this conversation now.
     */
    public function shouldSendAway(int $conversationId): bool
    {
        $cfg = $this->config();
        if (! $cfg['enabled'] || trim($cfg['away_message']) === '' || $this->isOpen()) {
            return false;
        }

        $since = date('Y-m-d H:i:s', time() - self::AWAY_COOLDOWN_SECONDS);

        return db_connect()->table('messages')
            ->where('conversation_id', $conversationId)
            ->where('direction', 'outbound')
            ->where('content', $cfg['away_message'])
            ->where('created_at >=', $since)
            ->countAllResults() === 0;
    }

    public function awayMessage(): string
    {
        return $this->config()['away_message'];
    }

    private function validTime(mixed $v): ?string
    {
        return is_string($v) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v) === 1 ? $v : null;
    }
}
