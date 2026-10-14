<?php

declare(strict_types=1);

namespace App\Libraries;

use DateTimeImmutable;
use DateTimeZone;

class TimeZoneOptions
{
    /**
     * Extra words so search can match a country when the zone id is a city.
     *
     * @var array<string, string>
     */
    private const SEARCH_KEYWORDS = [
        'Asia/Dubai'           => 'UAE United Arab Emirates Gulf',
        'Asia/Kolkata'         => 'India IST',
        'Asia/Calcutta'        => 'India IST',
        'Asia/Karachi'         => 'Pakistan',
        'Asia/Riyadh'          => 'Saudi Arabia KSA',
        'Asia/Qatar'           => 'Qatar',
        'Asia/Kuwait'          => 'Kuwait',
        'Asia/Bahrain'         => 'Bahrain',
        'Asia/Muscat'          => 'Oman',
        'Europe/London'        => 'UK United Kingdom Britain',
        'America/New_York'     => 'USA US United States Eastern',
        'America/Chicago'      => 'USA US United States Central',
        'America/Denver'       => 'USA US United States Mountain',
        'America/Los_Angeles'  => 'USA US United States Pacific',
        'Australia/Sydney'     => 'Australia',
        'Pacific/Auckland'     => 'New Zealand',
    ];

    /**
     * @var array<string, list<array{value: string, label: string, keywords: string}>>
     */
    protected static array $cache = [];

    /**
     * @return array<string, list<array{value: string, label: string, keywords: string}>>
     */
    public function grouped(): array
    {
        if (self::$cache !== []) {
            return self::$cache;
        }

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $groups = [];

        foreach (DateTimeZone::listIdentifiers() as $identifier) {
            $tz = new DateTimeZone($identifier);
            $parts = explode('/', $identifier, 2);
            $group = $parts[0] ?? 'Other';
            $offset = $tz->getOffset($now);
            $hours = intdiv(abs($offset), 3600);
            $minutes = intdiv(abs($offset) % 3600, 60);
            $sign = $offset >= 0 ? '+' : '-';
            $offsetLabel = sprintf('UTC%s%02d:%02d', $sign, $hours, $minutes);
            $prettyName = str_replace('_', ' ', $identifier);

            $groups[$group][] = [
                'value'    => $identifier,
                'label'    => '(' . $offsetLabel . ') ' . $prettyName,
                'keywords' => self::SEARCH_KEYWORDS[$identifier] ?? '',
            ];
        }

        ksort($groups);
        foreach ($groups as &$options) {
            usort($options, static fn (array $a, array $b): int => strcmp($a['label'], $b['label']));
        }
        unset($options);

        self::$cache = $groups;

        return self::$cache;
    }
}
