<?php

declare(strict_types=1);

namespace App\Libraries;

use DateTimeImmutable;
use DateTimeZone;

class TimeZoneOptions
{
    /**
     * @var array<string, list<array{value: string, label: string}>>
     */
    protected static array $cache = [];

    /**
     * @return array<string, list<array{value: string, label: string}>>
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
                'value' => $identifier,
                'label' => '(' . $offsetLabel . ') ' . $prettyName,
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
