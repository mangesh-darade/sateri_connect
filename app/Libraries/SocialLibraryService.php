<?php

declare(strict_types=1);

namespace App\Libraries;

use App\Models\SocialDriveSourceModel;
use App\Models\SocialMediaAssetModel;

/**
 * Google Drive folders and the media found in them. A scan upserts social_media_assets and stores a
 * per-asset readiness check (advisory: it never blocks scheduling, it tells the user what to fix).
 */
class SocialLibraryService
{
    public const MAX_REEL_SECONDS = 90;
    private const MAX_IMAGE_BYTES = 10 * 1024 * 1024;

    private SocialDriveSourceModel $sources;
    private SocialMediaAssetModel $assets;
    private GoogleDriveService $drive;

    public function __construct(?GoogleDriveService $drive = null)
    {
        $this->sources = model(SocialDriveSourceModel::class);
        $this->assets  = model(SocialMediaAssetModel::class);
        $this->drive   = $drive ?? new GoogleDriveService();
    }

    public function drive(): GoogleDriveService
    {
        return $this->drive;
    }

    /**
     * Accepts a folder id or a drive.google.com folder link.
     *
     * @return array<string, mixed>
     */
    public function addFolder(string $input): array
    {
        if (! $this->drive->isConfigured()) {
            return $this->err(503, 'Google Drive is not set up on this platform yet. Ask the administrator.');
        }
        $folderId = self::parseFolderId($input);
        if ($folderId === '') {
            return $this->err(422, 'Paste a Google Drive folder link or folder id.');
        }
        if ($this->sources->where('folder_id', $folderId)->first() !== null) {
            return $this->err(409, 'This folder is already added.');
        }

        $list = $this->drive->listFolder($folderId);
        if (! $list['ok']) {
            return $list;
        }

        $id = (int) $this->sources->insert(['folder_id' => $folderId, 'folder_name' => 'Drive folder ' . substr($folderId, 0, 6), 'mode' => 'service_account', 'status' => 'active'], true);
        $r  = $this->importFiles($id, $list['data']['files']);
        log_activity('social_folder_added', 'social', 'Added Google Drive folder', ['source_id' => $id, 'files' => $r['data']['total']]);

        return ['ok' => true, 'status' => 200, 'message' => 'Folder added. ' . ($r['data']['total'] > 0 ? $r['data']['new'] . ' new file(s) found.' : $this->emptyHint()), 'data' => ['source_id' => $id] + $r['data']];
    }

    /**
     * @return array<string, mixed>
     */
    public function scan(int $sourceId): array
    {
        $src = $this->sources->find($sourceId);
        if (! is_array($src)) {
            return $this->err(404, 'Folder not found.');
        }
        $list = $this->drive->listFolder((string) $src['folder_id']);
        if (! $list['ok']) {
            return $list;
        }
        $r = $this->importFiles($sourceId, $list['data']['files']);

        return ['ok' => true, 'status' => 200, 'message' => $r['data']['total'] > 0 ? $r['data']['new'] . ' new file(s), ' . $r['data']['total'] . ' in folder.' : $this->emptyHint(), 'data' => $r['data']];
    }

    /**
     * Drive answers "empty" (not "denied") for a folder the service account cannot see, so an empty result usually means it was not shared.
     */
    private function emptyHint(): string
    {
        return 'No photos or videos found. Share the folder with ' . $this->drive->serviceAccountEmail() . ' (Viewer) and scan again.';
    }

    /**
     * @return array<string, mixed>
     */
    public function removeFolder(int $sourceId): array
    {
        if ($this->sources->find($sourceId) === null) {
            return $this->err(404, 'Folder not found.');
        }
        // Keep assets that already have posts; drop the unused ones.
        $used = model(\App\Models\SocialPostModel::class)->select('asset_id')->where('asset_id IS NOT NULL')->findColumn('asset_id') ?? [];
        $q    = $this->assets->where('source_id', $sourceId);
        if ($used !== []) {
            $q->whereNotIn('id', $used);
        }
        $q->delete();
        $this->sources->delete($sourceId);
        log_activity('social_folder_removed', 'social', 'Removed Google Drive folder', ['source_id' => $sourceId]);

        return ['ok' => true, 'status' => 200, 'message' => 'Folder removed.'];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function folders(): array
    {
        $rows = $this->sources->orderBy('created_at', 'DESC')->findAll();

        return is_array($rows) ? $rows : [];
    }

    /**
     * Adds a picture made outside the app (an AI chat, a design tool) to the library, ready to schedule.
     *
     * @return array{ok: bool, status: int, message: string, data?: array{asset_id: int}}
     */
    public function addImage(string $bytes, string $name): array
    {
        $info = $bytes !== '' && strlen($bytes) <= self::MAX_IMAGE_BYTES ? @getimagesizefromstring($bytes) : false;
        if (is_array($info) && in_array($info['mime'] ?? '', ['image/webp', 'image/gif'], true) && function_exists('imagecreatefromstring') && ($img = @imagecreatefromstring($bytes)) !== false) {
            ob_start();
            imagejpeg($img, null, 92);
            $bytes = (string) ob_get_clean();
            $info  = @getimagesizefromstring($bytes);
        }
        $ext  = ['image/jpeg' => 'jpg', 'image/png' => 'png'][$info['mime'] ?? ''] ?? null;
        if ($ext === null) {
            return $this->err(422, 'Send a JPEG, PNG or WebP picture up to 10 MB.');
        }

        $dir  = SocialImageGenerator::dir();
        $file = bin2hex(random_bytes(16)) . '.' . $ext;
        if ((! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) || @file_put_contents($dir . $file, $bytes) === false) {
            return $this->err(500, 'The picture could not be saved.');
        }

        $base = preg_replace('/[^A-Za-z0-9._-]+/', '-', pathinfo($name, PATHINFO_FILENAME)) ?: 'picture';
        $id   = (int) $this->assets->insert([
            'drive_file_id' => 'local:' . $file,
            'filename'      => mb_substr($base, 0, 100) . '.' . $ext,
            'mime'          => $info['mime'],
            'size_bytes'    => strlen($bytes),
            'width'         => (int) $info[0],
            'height'        => (int) $info[1],
            'validation'    => json_encode(['reel_ready' => false, 'issues' => []]),
            'status'        => 'ready',
        ], true);

        return ['ok' => true, 'status' => 200, 'message' => 'Picture added to the library.', 'data' => ['asset_id' => $id]];
    }

    /**
     * Makes a picture with the app's image AI (Social Setup / Settings > AI key) and adds it to the library.
     *
     * @return array{ok: bool, status: int, message: string, data?: array{asset_id: int}}
     */
    public function generateImage(string $prompt): array
    {
        $r = (new SocialImageGenerator())->generate($prompt);
        if (! $r['ok']) {
            return $r;
        }
        $path  = SocialImageGenerator::dir() . $r['data']['file'];
        $bytes = (string) @file_get_contents($path);
        @unlink($path);

        return $this->addImage($bytes, 'ai-' . date('Ymd-His'));
    }

    /**
     * Fetches a picture from a public https link (an AI chat's generated image, a website) and adds it to the library.
     * The host must resolve to a public address and is pinned for the request, so it cannot reach internal services.
     *
     * @return array{ok: bool, status: int, message: string, data?: array{asset_id: int}}
     */
    public function addImageFromUrl(string $url, string $name = ''): array
    {
        $p    = parse_url($url);
        $host = (string) ($p['host'] ?? '');
        $ip   = ($p['scheme'] ?? '') === 'https' && ! isset($p['user']) && (int) ($p['port'] ?? 443) === 443 ? OutgoingWebhooks::publicIp($host) : null;
        if ($ip === null) {
            return $this->err(422, 'Use a public https image link.');
        }

        $bytes = '';
        $ch    = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_RESOLVE        => [$host . ':443:' . $ip],
            CURLOPT_WRITEFUNCTION  => static function ($c, string $chunk) use (&$bytes): int {
                $bytes .= $chunk;

                return strlen($bytes) > self::MAX_IMAGE_BYTES ? 0 : strlen($chunk);
            },
        ]);
        curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($http !== 200 || $bytes === '') {
            return $this->err(422, 'The picture could not be downloaded (HTTP ' . $http . ').');
        }

        return $this->addImage($bytes, $name !== '' ? $name : basename((string) ($p['path'] ?? 'picture')));
    }

    /**
     * Assets with how many posts already use them.
     *
     * @return list<array<string, mixed>>
     */
    public function assets(): array
    {
        $rows = $this->assets
            ->select('social_media_assets.*, (SELECT COUNT(*) FROM social_posts p WHERE p.asset_id = social_media_assets.id AND p.status <> \'failed\') AS used_count', false)
            ->orderBy('social_media_assets.created_at', 'DESC')
            ->orderBy('social_media_assets.id', 'DESC')
            ->findAll(500);

        foreach ($rows as &$r) {
            $r['validation'] = json_decode((string) ($r['validation'] ?? ''), true) ?: ['issues' => []];
        }
        unset($r);

        return $rows;
    }

    /**
     * @param list<array<string, mixed>> $files
     * @return array{data: array{new: int, total: int}}
     */
    private function importFiles(int $sourceId, array $files): array
    {
        $new = 0;
        foreach ($files as $f) {
            $row = [
                'source_id'     => $sourceId,
                'drive_file_id' => $f['id'],
                'filename'      => mb_substr((string) $f['name'], 0, 255),
                'mime'          => $f['mime'],
                'size_bytes'    => $f['size'],
                'duration_sec'  => $f['duration_sec'],
                'width'         => $f['width'],
                'height'        => $f['height'],
                'checksum'      => $f['md5'] !== '' ? $f['md5'] : null,
                'validation'    => json_encode($this->check($f)),
            ];
            $existing = $this->assets->where('drive_file_id', $f['id'])->first();
            if (is_array($existing)) {
                $this->assets->update((int) $existing['id'], $row);
            } else {
                $this->assets->insert($row + ['status' => 'ready']);
                $new++;
            }
        }
        $this->sources->update($sourceId, ['last_scanned_at' => date('Y-m-d H:i:s')]);

        return ['data' => ['new' => $new, 'total' => count($files)]];
    }

    /**
     * Advisory readiness for Reels/Shorts and posts.
     *
     * @param array<string, mixed> $f
     * @return array{reel_ready: bool, issues: list<string>}
     */
    private function check(array $f): array
    {
        $issues  = [];
        $isVideo = str_starts_with((string) $f['mime'], 'video/');
        $w       = (int) ($f['width'] ?? 0);
        $h       = (int) ($f['height'] ?? 0);

        if ($isVideo) {
            if ($w > 0 && $h > 0 && ($h / $w) < 1.5) {
                $issues[] = 'Not vertical 9:16, so it may be cropped as a Reel.';
            }
            $d = (float) ($f['duration_sec'] ?? 0);
            if ($d > self::MAX_REEL_SECONDS) {
                $issues[] = 'Longer than ' . self::MAX_REEL_SECONDS . ' seconds, which is too long for a Reel.';
            }
            if ($d > 0 && $d < 3) {
                $issues[] = 'Shorter than 3 seconds.';
            }
            if (! in_array($f['mime'], ['video/mp4', 'video/quicktime'], true)) {
                $issues[] = 'Use MP4 or MOV for best results.';
            }
        }
        if ((int) $f['size'] > 1024 * 1024 * 1024) {
            $issues[] = 'File is larger than 1 GB.';
        }

        return ['reel_ready' => $isVideo && $issues === [], 'issues' => $issues];
    }

    /** Folder id from a Drive folder link, an ?id= link or a bare id; empty when it is none of those. */
    public static function parseFolderId(string $input): string
    {
        $input = trim($input);
        if (preg_match('#/folders/([A-Za-z0-9_-]{10,128})#', $input, $m) === 1) {
            return $m[1];
        }
        if (preg_match('#[?&]id=([A-Za-z0-9_-]{10,128})#', $input, $m) === 1) {
            return $m[1];
        }

        return preg_match('/^[A-Za-z0-9_-]{10,128}$/', $input) === 1 ? $input : '';
    }

    /**
     * @return array<string, mixed>
     */
    private function err(int $status, string $message): array
    {
        return ['ok' => false, 'status' => $status, 'message' => $message];
    }
}
