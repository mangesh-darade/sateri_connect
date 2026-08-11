<?php

declare(strict_types=1);

namespace App\Libraries;

use Throwable;

/**
 * Single source of truth for “is the app installed?” so filters, Home, and
 * Install wizard never disagree and cause install ↔ login redirect loops.
 *
 * Priority:
 * 1. writable/install.lock (shared filesystem signal — no DB)
 * 2. settings.app_installed = 1 (DB)
 *
 * Request-cached so one page load never hits the filesystem/DB repeatedly.
 */
class InstallStatus
{
    protected static ?bool $installed = null;

    public static function lockPath(): string
    {
        return WRITEPATH . 'install.lock';
    }

    public static function hasLockFile(): bool
    {
        return is_file(self::lockPath());
    }

    public static function isInstalled(): bool
    {
        if (self::$installed !== null) {
            return self::$installed;
        }

        if (self::hasLockFile()) {
            return self::$installed = true;
        }

        try {
            return self::$installed = service('settingsService')->isInstalled();
        } catch (Throwable) {
            return self::$installed = false;
        }
    }

    /**
     * Clear request cache after writing the lock / finish step.
     */
    public static function refresh(): void
    {
        self::$installed = null;
    }

    public static function markInstalledFromLock(): void
    {
        self::$installed = true;
    }
}
