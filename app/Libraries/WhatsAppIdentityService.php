<?php

declare(strict_types=1);

namespace App\Libraries;

use RuntimeException;
use Throwable;

/**
 * Secure Meta WhatsApp business identity (verified name, display phone, profile pic).
 * Caches locally so chrome UI does not depend on expiring Facebook CDN URLs.
 */
class WhatsAppIdentityService
{
    public const CACHE_TTL_SECONDS = 43200; // 12 hours
    public const MIN_REFRESH_GAP   = 120;   // rate-limit forced client refreshes

    protected SettingsService $settings;

    public function __construct(?SettingsService $settings = null)
    {
        $this->settings = $settings ?? service('settingsService');
    }

    /**
     * @return array{
     *     provider: string,
     *     display_name: string,
     *     phone: string,
     *     profile_picture_url: string,
     *     connected: bool,
     *     fetched_at: int,
     *     needs_refresh: bool
     * }
     */
    public function getIdentity(): array
    {
        $base = $this->settings->getWhatsAppIdentity();
        $fetchedAt = (int) $this->settings->get('wa_identity_fetched_at', '0');
        $localUrl  = $this->localAvatarPublicUrl();

        if ($localUrl !== '') {
            $base['profile_picture_url'] = $localUrl;
        } else {
            $remote = trim((string) ($base['profile_picture_url'] ?? ''));
            $base['profile_picture_url'] = $this->isSafeRemoteImageUrl($remote) ? $remote : '';
        }

        $base['fetched_at']    = $fetchedAt;
        $base['needs_refresh'] = $this->needsRefresh($base);

        return $base;
    }

    /**
     * @param array<string, mixed> $identity
     */
    public function needsRefresh(array $identity = []): bool
    {
        if (! $this->settings->isMetaProvider()) {
            return false;
        }

        $pnid  = trim((string) $this->settings->get('meta_phone_number_id', ''));
        $token = trim((string) $this->settings->get('meta_access_token', ''));
        if ($pnid === '' || $token === '') {
            return false;
        }

        if ($identity === []) {
            $identity = $this->settings->getWhatsAppIdentity();
        }

        $name    = trim((string) ($identity['display_name'] ?? ''));
        $phone   = trim((string) ($identity['phone'] ?? ''));
        $picture = trim((string) ($identity['profile_picture_url'] ?? ''));
        $local   = $this->localAvatarPath();
        $hasPic  = ($picture !== '' && $this->isSafeRemoteImageUrl($picture)) || ($local !== '' && is_file($local));

        if ($name === '' || $phone === '' || ! $hasPic) {
            return true;
        }

        $fetchedAt = (int) $this->settings->get('wa_identity_fetched_at', '0');

        return $fetchedAt <= 0 || (time() - $fetchedAt) > self::CACHE_TTL_SECONDS;
    }

    /**
     * Pull verified name / display phone / profile picture from Meta and cache.
     *
     * @return array<string, mixed>
     */
    public function refreshFromMeta(bool $force = false): array
    {
        if (! $this->settings->isMetaProvider()) {
            throw new RuntimeException('Active provider is not Meta Cloud API.');
        }

        $pnid  = trim((string) $this->settings->get('meta_phone_number_id', ''));
        $token = trim((string) $this->settings->get('meta_access_token', ''));
        if ($pnid === '' || $token === '') {
            throw new RuntimeException('Meta Phone Number ID and access token are required.');
        }

        $last = (int) $this->settings->get('wa_identity_fetched_at', '0');
        if (! $force && $last > 0 && (time() - $last) < self::MIN_REFRESH_GAP && ! $this->needsRefresh()) {
            return $this->getIdentity();
        }

        $api      = new MetaCloudAPI($this->settings);
        $info     = $api->getPhoneNumberInfo();
        $picture  = '';
        $warnings = [];

        try {
            $profile = $api->getBusinessProfile();
            $picture = trim((string) ($profile['profile_picture_url'] ?? ''));
        } catch (Throwable $e) {
            $warnings[] = 'Business profile: ' . $e->getMessage();
            log_message('notice', 'Meta business profile fetch failed: {msg}', ['msg' => $e->getMessage()]);
        }

        $localAvatarUrl = '';
        if ($picture !== '') {
            try {
                $localAvatarUrl = $this->mirrorRemoteAvatar($picture);
            } catch (Throwable $e) {
                $warnings[] = 'Avatar mirror: ' . $e->getMessage();
                log_message('notice', 'Meta avatar mirror failed: {msg}', ['msg' => $e->getMessage()]);
                // Keep validated remote URL as temporary fallback.
                if (! $this->isSafeRemoteImageUrl($picture)) {
                    $picture = '';
                }
            }
        }

        $this->settings->cacheWhatsAppIdentity([
            'display_name'        => (string) ($info['verified_name'] ?? ''),
            'phone'               => (string) ($info['display_phone'] ?? ''),
            'profile_picture_url' => $localAvatarUrl !== '' ? $localAvatarUrl : $picture,
        ]);
        $this->settings->set('wa_identity_fetched_at', (string) time(), 'whatsapp', false);
        if ($picture !== '') {
            $this->settings->set('wa_profile_picture_remote', $picture, 'whatsapp', false);
        }

        $identity = $this->getIdentity();
        $identity['warnings'] = $warnings;
        $identity['refreshed'] = true;

        return $identity;
    }

    public function localAvatarPath(): string
    {
        $stored = trim((string) $this->settings->get('wa_profile_picture_path', ''));
        if ($stored === '') {
            return '';
        }
        $stored = str_replace(['../', '..\\'], '', $stored);
        $full   = WRITEPATH . ltrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $stored), DIRECTORY_SEPARATOR);
        $root   = realpath(WRITEPATH . 'uploads' . DIRECTORY_SEPARATOR . 'wa-identity');
        $real   = realpath($full);
        if ($root === false || $real === false || ! str_starts_with($real, $root)) {
            return '';
        }

        return is_file($real) ? $real : '';
    }

    public function localAvatarPublicUrl(): string
    {
        if ($this->localAvatarPath() === '') {
            return '';
        }
        $v = (int) $this->settings->get('wa_identity_fetched_at', '0');

        return site_url('wa-identity/avatar') . '?v=' . max(1, $v);
    }

    public function localAvatarMime(): string
    {
        $mime = trim((string) $this->settings->get('wa_profile_picture_mime', 'image/jpeg'));
        $allowed = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

        return in_array($mime, $allowed, true) ? $mime : 'image/jpeg';
    }

    /**
     * Download Meta CDN avatar to local writable storage (SSRF-hardened).
     */
    public function mirrorRemoteAvatar(string $url): string
    {
        $url = trim($url);
        $this->assertSafeRemoteImageUrl($url);

        $dir = WRITEPATH . 'uploads' . DIRECTORY_SEPARATOR . 'wa-identity' . DIRECTORY_SEPARATOR;
        if (! is_dir($dir) && ! mkdir($dir, 0755, true) && ! is_dir($dir)) {
            throw new RuntimeException('Unable to create avatar cache directory.');
        }

        $bin = $this->downloadBinary($url, 2_000_000);
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime  = (string) $finfo->buffer($bin);
        $map   = [
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/webp' => 'webp',
            'image/gif'  => 'gif',
        ];
        if (! isset($map[$mime])) {
            throw new RuntimeException('Remote avatar is not a supported image type.');
        }

        // Replace previous files in directory.
        foreach (glob($dir . 'avatar.*') ?: [] as $old) {
            @unlink($old);
        }

        $filename = 'avatar.' . $map[$mime];
        $full     = $dir . $filename;
        if (file_put_contents($full, $bin) === false) {
            throw new RuntimeException('Failed to store avatar file.');
        }

        $relative = 'uploads/wa-identity/' . $filename;
        $this->settings->set('wa_profile_picture_path', $relative, 'whatsapp', false);
        $this->settings->set('wa_profile_picture_mime', $mime, 'whatsapp', false);

        return site_url('wa-identity/avatar') . '?v=' . time();
    }

    public function isSafeRemoteImageUrl(string $url): bool
    {
        try {
            $this->assertSafeRemoteImageUrl($url);

            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * @throws RuntimeException
     */
    protected function assertSafeRemoteImageUrl(string $url): void
    {
        if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new RuntimeException('Invalid avatar URL.');
        }
        $parts = parse_url($url);
        if (! is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https') {
            throw new RuntimeException('Avatar URL must be HTTPS.');
        }
        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host === '' || ! $this->isAllowedCdnHost($host)) {
            throw new RuntimeException('Avatar host is not an allowed Meta CDN.');
        }
        // Block SSRF to private/reserved networks after DNS resolve.
        $ips = @gethostbynamel($host) ?: [];
        if ($ips === []) {
            // IPv6-only hosts: allow host allowlist match without IP check.
            return;
        }
        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                throw new RuntimeException('Avatar host resolved to a private network address.');
            }
        }
    }

    protected function isAllowedCdnHost(string $host): bool
    {
        $allowedExact = [
            'lookaside.fbsbx.com',
            'scontent.xx.fbcdn.net',
        ];
        if (in_array($host, $allowedExact, true)) {
            return true;
        }

        $suffixes = [
            '.fbcdn.net',
            '.facebook.com',
            '.fbsbx.com',
            '.whatsapp.net',
            '.cdninstagram.com',
        ];
        foreach ($suffixes as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return true;
            }
        }

        return false;
    }

    protected function downloadBinary(string $url, int $maxBytes): string
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Unable to init avatar download.');
        }

        $verify = $this->resolveCaBundle();
        $opts   = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_USERAGENT      => 'SateriConnect-WaIdentity/1.0',
            CURLOPT_HTTPHEADER     => ['Accept: image/*,*/*;q=0.8'],
        ];
        if (is_string($verify) && $verify !== '' && is_file($verify)) {
            $opts[CURLOPT_SSL_VERIFYPEER] = true;
            $opts[CURLOPT_SSL_VERIFYHOST] = 2;
            $opts[CURLOPT_CAINFO]         = $verify;
        } elseif (ENVIRONMENT === 'production') {
            $opts[CURLOPT_SSL_VERIFYPEER] = true;
            $opts[CURLOPT_SSL_VERIFYHOST] = 2;
        } else {
            // Local WAMP often lacks CA bundle.
            $opts[CURLOPT_SSL_VERIFYPEER] = false;
            $opts[CURLOPT_SSL_VERIFYHOST] = 0;
        }

        curl_setopt_array($ch, $opts);
        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $size   = (int) curl_getinfo($ch, CURLINFO_SIZE_DOWNLOAD);
        curl_close($ch);

        if ($errno !== 0 || ! is_string($body) || $body === '') {
            throw new RuntimeException('Avatar download failed: ' . ($error !== '' ? $error : 'empty body'));
        }
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException('Avatar download HTTP ' . $status);
        }
        if (strlen($body) > $maxBytes || $size > $maxBytes) {
            throw new RuntimeException('Avatar exceeds size limit.');
        }

        return $body;
    }

    protected function resolveCaBundle(): ?string
    {
        foreach ([
            ini_get('curl.cainfo') ?: null,
            ini_get('openssl.cafile') ?: null,
            'C:\\wamp64\\bin\\php\\php8.2.13\\extras\\ssl\\cacert.pem',
            'C:\\wamp64\\bin\\php\\php8.3.14\\extras\\ssl\\cacert.pem',
        ] as $candidate) {
            if (is_string($candidate) && $candidate !== '' && is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
