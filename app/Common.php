<?php

/**
 * The goal of this file is to allow developers a location
 * where they can overwrite core procedural functions and
 * replace them with their own. This file is loaded during
 * the bootstrap process and is called during the framework's
 * execution.
 *
 * This can be looked at as a `master helper` file that is
 * loaded early on, and may also contain additional functions
 * that you'd like to use throughout your entire application
 */

if (! function_exists('log_activity')) {
    /**
     * Log an application activity event globally.
     *
     * @param array<string, mixed> $metadata
     */
    function log_activity(string $action, string $module, string $description, array $metadata = [], ?int $userId = null): bool
    {
        return (new \App\Libraries\ActivityLogger())->log($action, $module, $description, $metadata, $userId);
    }
}
