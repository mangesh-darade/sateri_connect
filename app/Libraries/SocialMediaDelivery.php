<?php

declare(strict_types=1);

namespace App\Libraries;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Throwable;

/**
 * Stages a Drive file in writable/social_tmp and hands out a short-lived signed public URL so Meta can fetch it.
 * The URL carries an HS256 token (file name + expiry) signed with the app encryption key, so serving it needs no
 * login and no tenant lookup. Staged files are removed after publishing and by purgeOld().
 */
class SocialMediaDelivery
{
    private const TTL_SECONDS = 3600;

    public function __construct(private ?GoogleDriveService $drive = null)
    {
        $this->drive ??= new GoogleDriveService();
    }

    public static function dir(): string
    {
        return WRITEPATH . 'social_tmp' . DIRECTORY_SEPARATOR;
    }

    /**
     * @param array<string, mixed> $asset social_media_assets row
     * @return array{ok: bool, status: int, message: string, data?: array{url: string, file: string}}
     */
    public function stage(array $asset, ?callable $onProgress = null): array
    {
        $dir = self::dir();
        if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
            return ['ok' => false, 'status' => 500, 'message' => 'Media staging folder is not writable.'];
        }

        $ext  = strtolower(pathinfo((string) ($asset['filename'] ?? ''), PATHINFO_EXTENSION));
        $ext  = preg_match('/^[a-z0-9]{2,5}$/', $ext) === 1 ? $ext : 'bin';
        $file = bin2hex(random_bytes(16)) . '.' . $ext;

        $driveId = (string) $asset['drive_file_id'];
        if (str_starts_with($driveId, 'local:')) {
            // An AI-made picture (SocialImageGenerator): copy it, so the original survives the post being removed.
            $src = self::localPath($driveId);
            $r   = $src !== null && @copy($src, $dir . $file) ? ['ok' => true] : ['ok' => false, 'status' => 404, 'message' => 'The AI picture file is missing.'];
        } else {
            $r = $this->drive->download($driveId, $dir . $file, $onProgress);
        }
        if (! $r['ok']) {
            return ['ok' => false, 'status' => (int) $r['status'], 'message' => (string) $r['message']];
        }

        if (! is_file($dir . $file) || (int) @filesize($dir . $file) === 0) {
            @unlink($dir . $file);

            return ['ok' => false, 'status' => 502, 'message' => 'The media file could not be saved from Google Drive.'];
        }

        $token = JWT::encode(['f' => $file, 'exp' => time() + self::TTL_SECONDS], $this->secret(), 'HS256');

        return ['ok' => true, 'status' => 200, 'message' => 'OK', 'data' => [
            'url'  => site_url('social/media/' . $token . '.' . $ext),
            'file' => $file,
        ]];
    }

    /** Path of an AI-made picture ("local:<name>"), or null for anything else or a missing file. */
    public static function localPath(string $driveFileId): ?string
    {
        $name = substr($driveFileId, 6);
        if (! str_starts_with($driveFileId, 'local:') || preg_match('/^[a-f0-9]{32}\.(jpg|png)$/', $name) !== 1) {
            return null;
        }
        $path = SocialImageGenerator::dir() . $name;

        return is_file($path) ? $path : null;
    }

    /**
     * Resolves a token from a media URL to a staged file path, or null if invalid/expired.
     */
    public function resolve(string $tokenWithExt): ?string
    {
        $token = preg_replace('/\.[A-Za-z0-9]{2,5}$/', '', $tokenWithExt) ?? '';
        try {
            $claims = (array) JWT::decode($token, new Key($this->secret(), 'HS256'));
        } catch (Throwable) {
            return null;
        }
        $file = (string) ($claims['f'] ?? '');
        if (preg_match('/^[a-f0-9]{32}\.[a-z0-9]{2,5}$/', $file) !== 1) {
            return null;
        }
        $path = self::dir() . $file;

        return is_file($path) ? $path : null;
    }

    public function release(string $file): void
    {
        if (preg_match('/^[a-f0-9]{32}\.[a-z0-9]{2,5}$/', $file) === 1) {
            @unlink(self::dir() . $file);
        }
    }

    /** Deletes staged files older than two hours (crashed runs). */
    public function purgeOld(): int
    {
        $n = 0;
        foreach (glob(self::dir() . '*') ?: [] as $path) {
            if (is_file($path) && filemtime($path) < time() - 7200 && @unlink($path)) {
                $n++;
            }
        }

        return $n;
    }

    private function secret(): string
    {
        return hash('sha256', 'social-media|' . (string) config('Encryption')->key, true);
    }
}
