<?php

declare(strict_types=1);

namespace App\Libraries;

/**
 * Request-scoped current tenant key (and how it was resolved).
 */
class TenantContext
{
    protected static ?string $key = null;

    protected static ?string $source = null;

    public static function set(string $key, string $source = 'manual'): void
    {
        $key = strtolower(trim($key));
        self::$key    = $key !== '' ? $key : null;
        self::$source = self::$key !== null ? $source : null;
    }

    public static function get(): ?string
    {
        return self::$key;
    }

    public static function source(): ?string
    {
        return self::$source;
    }

    public static function clear(): void
    {
        self::$key    = null;
        self::$source = null;
    }

    public static function has(): bool
    {
        return self::$key !== null && self::$key !== '';
    }
}
