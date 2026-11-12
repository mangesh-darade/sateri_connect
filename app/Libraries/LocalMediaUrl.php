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

    /** Types a signed sample link may serve (the template header upload types). */
    private const SAMPLE_TYPES = [
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp',
        'gif' => 'image/gif', 'pdf' => 'application/pdf', 'mp4' => 'video/mp4', '3gp' => 'video/3gpp',
    ];

    private const SAMPLE_TTL_SECONDS = 86400;

    /**
     * /media/serve/… needs a login, so the WhatsApp provider cannot fetch it as a template sample.
     * Turns such a link on this app's own public host into a short-lived signed link that needs no login.
     * Any other URL (external, localhost, media id) is returned unchanged.
     */
    public static function signedSampleUrl(string $url): string
    {
        $url  = trim($url);
        $file = self::filenameFromUrl($url);
        if ($file === '' || self::isLocalHost($url) || self::sampleMime($file) === null) {
            return $url;
        }

        $appHost = strtolower((string) (parse_url(site_url(), PHP_URL_HOST) ?: ''));
        $urlHost = strtolower((string) (parse_url($url, PHP_URL_HOST) ?: ''));
        if ($appHost === '' || $appHost !== $urlHost) {
            return $url;
        }

        $token = \Firebase\JWT\JWT::encode(['f' => $file, 'exp' => time() + self::SAMPLE_TTL_SECONDS], self::sampleSecret(), 'HS256');

        return site_url('media/sample/' . $token . '.' . strtolower(pathinfo($file, PATHINFO_EXTENSION)));
    }

    /**
     * Resolves a signed sample token to the stored filename, or null when invalid or expired.
     */
    public static function filenameFromSampleToken(string $tokenWithExt): ?string
    {
        $token = preg_replace('/\.[A-Za-z0-9]{2,5}$/', '', $tokenWithExt) ?? '';
        try {
            $claims = (array) \Firebase\JWT\JWT::decode($token, new \Firebase\JWT\Key(self::sampleSecret(), 'HS256'));
        } catch (\Throwable) {
            return null;
        }

        $file = (string) ($claims['f'] ?? '');

        return self::sampleMime($file) !== null ? $file : null;
    }

    /** Content type for a sample file name, or null when the name or type is not allowed. */
    public static function sampleMime(string $file): ?string
    {
        if (preg_match('/^[A-Za-z0-9_-]+(\.[A-Za-z0-9_-]+)*\.([A-Za-z0-9]{2,5})$/', $file, $m) !== 1) {
            return null;
        }

        return self::SAMPLE_TYPES[strtolower($m[2])] ?? null;
    }

    private static function sampleSecret(): string
    {
        return hash('sha256', 'template-sample|' . (string) config('Encryption')->key, true);
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
