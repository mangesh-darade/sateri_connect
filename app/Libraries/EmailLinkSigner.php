<?php

declare(strict_types=1);

namespace App\Libraries;

/**
 * HMAC signatures for public email links (unsubscribe + click tracking).
 *
 * Keyed with the app encryption key, so links cannot be forged to unsubscribe
 * someone else or to turn the click redirector into an open redirect.
 */
class EmailLinkSigner
{
    protected const SIG_LENGTH = 32;

    public static function unsubscribeSignature(string $email, ?string $tenant = null): string
    {
        $email = strtolower(trim($email));
        if ($email === '') {
            return '';
        }

        return self::sign('unsub|' . self::tenant($tenant) . '|' . $email);
    }

    public static function verifyUnsubscribe(string $email, string $signature, ?string $tenant = null): bool
    {
        $expected = self::unsubscribeSignature($email, $tenant);

        return $expected !== '' && $signature !== '' && hash_equals($expected, strtolower(trim($signature)));
    }

    public static function clickSignature(int $logId, string $url, ?string $tenant = null): string
    {
        return self::sign('click|' . self::tenant($tenant) . '|' . $logId . '|' . $url);
    }

    public static function verifyClick(int $logId, string $url, string $signature, ?string $tenant = null): bool
    {
        $expected = self::clickSignature($logId, $url, $tenant);

        return $expected !== '' && $signature !== '' && hash_equals($expected, strtolower(trim($signature)));
    }

    /**
     * Only absolute http(s) URLs with a host may be used as redirect targets.
     */
    public static function isSafeRedirectUrl(string $url): bool
    {
        if ($url === '' || preg_match('/[\x00-\x1F\x7F\s]/', $url) === 1 || ! filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        $parts = parse_url($url);

        return is_array($parts)
            && in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            && trim((string) ($parts['host'] ?? '')) !== '';
    }

    protected static function sign(string $payload): string
    {
        $key = self::key();
        if ($key === '') {
            log_message('critical', 'EmailLinkSigner: encryption key is not configured; signed email links are disabled.');

            return '';
        }

        return substr(hash_hmac('sha256', $payload, $key), 0, self::SIG_LENGTH);
    }

    protected static function key(): string
    {
        return (string) (config('Encryption')->key ?? '');
    }

    protected static function tenant(?string $tenant): string
    {
        return strtolower(trim((string) ($tenant ?? TenantContext::get() ?? '')));
    }
}
