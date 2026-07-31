<?php

namespace App\Libraries;

/**
 * Helpers for app-hosted /media/serve/… URLs.
 */
final class LocalMediaUrl
{
    /**
     * Extract the stored filename from a local media serve URL.
     */
    public static function filenameFromUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        // Use ~ delimiters so a literal # inside the character class is safe.
        if (preg_match('~/media/serve/([^/?#]+)~', $url, $m)) {
            return basename(rawurldecode($m[1]));
        }

        return '';
    }

    public static function isLocalHost(string $url): bool
    {
        $host = strtolower((string) (parse_url(trim($url), PHP_URL_HOST) ?: ''));

        return $host === ''
            || $host === 'localhost'
            || $host === '127.0.0.1'
            || str_ends_with($host, '.local');
    }
}
