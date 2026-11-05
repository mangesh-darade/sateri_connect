<?php

declare(strict_types=1);

namespace App\Libraries;

use App\Models\MediaModel;
use Throwable;

/**
 * Files that need a public https link: a catalogue PDF for a workflow "Send media" node, a picture or an MP4.
 * The file is stored outside the web root with an unguessable name and served by PublicFiles (route /files/{name}).
 *
 * Services return ['ok', 'status', 'message', 'data'?].
 */
class PublicFileService
{
    public const MAX_BYTES = 16 * 1024 * 1024;

    /** Per client: the most files and bytes kept at once. Delete old files (or run files:cleanup) to free space. */
    public const MAX_FILES = 200;
    public const MAX_TOTAL = 300 * 1024 * 1024;

    /** @var array<string, string> mime => extension */
    public const TYPES = [
        'application/pdf' => 'pdf',
        'image/jpeg'      => 'jpg',
        'image/png'       => 'png',
        'video/mp4'       => 'mp4',
    ];

    public static function dir(): string
    {
        return WRITEPATH . 'uploads' . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR;
    }

    /**
     * @return array{ok: bool, status: int, message: string, data?: array<string, mixed>}
     */
    public function store(string $bytes, string $originalName = '', ?int $userId = null): array
    {
        $size = strlen($bytes);
        if ($size === 0) {
            return $this->err(422, 'The file is empty.');
        }
        if ($size > self::MAX_BYTES) {
            return $this->err(422, 'The file is too large. The limit is 16 MB.');
        }

        $mime = $this->detectMime($bytes);
        if ($mime === null) {
            return $this->err(422, 'Only PDF, JPEG, PNG and MP4 files are accepted.');
        }

        $use = $this->usage();
        if ($use['files'] >= self::MAX_FILES || $use['bytes'] + $size > self::MAX_TOTAL) {
            return $this->err(422, 'The storage limit is reached (' . self::MAX_FILES . ' files or ' . (int) (self::MAX_TOTAL / 1048576) . ' MB). Delete files you no longer use, then try again.');
        }
        $ext = self::TYPES[$mime];

        $dir = self::dir();
        if (! is_dir($dir) && ! @mkdir($dir, 0755, true) && ! is_dir($dir)) {
            return $this->err(500, 'The file could not be saved on the server.');
        }

        $name = bin2hex(random_bytes(20)) . '.' . $ext;
        if (@file_put_contents($dir . $name, $bytes) === false) {
            return $this->err(500, 'The file could not be saved on the server.');
        }

        $clean = trim((string) preg_replace('/[^\w.\- ]+/u', '', basename(str_replace('\\', '/', $originalName))));
        $clean = $clean !== '' ? mb_substr($clean, 0, 120) : ('file.' . $ext);
        if (strtolower((string) pathinfo($clean, PATHINFO_EXTENSION)) !== $ext) {
            $clean = pathinfo($clean, PATHINFO_FILENAME) . '.' . $ext;
        }
        $url = site_url('files/' . $name);

        try {
            model(MediaModel::class)->insert([
                'filename'      => $name,
                'original_name' => $clean,
                'mime_type'     => $mime,
                'size'          => $size,
                'path'          => 'uploads/public/' . $name,
                'url'           => $url,
                'uploaded_by'   => $userId,
            ]);
        } catch (Throwable $e) {
            log_message('warning', 'PublicFileService media row: ' . $e::class . ': ' . $e->getMessage());
        }

        return ['ok' => true, 'status' => 200, 'message' => 'File uploaded.', 'data' => ['url' => $url, 'filename' => $clean, 'mime_type' => $mime, 'size' => $size]];
    }

    /**
     * Downloads a public https file (no redirects, address pinned, size capped) and stores it.
     *
     * @return array{ok: bool, status: int, message: string, data?: array<string, mixed>}
     */
    public function storeFromUrl(string $url, string $name = '', ?int $userId = null): array
    {
        $p    = parse_url($url);
        $host = (string) ($p['host'] ?? '');
        $ip   = ($p['scheme'] ?? '') === 'https' && ! isset($p['user']) && (int) ($p['port'] ?? 443) === 443 ? OutgoingWebhooks::publicIp($host) : null;
        if ($ip === null) {
            return $this->err(422, 'Use a public https link.');
        }

        $bytes = '';
        $ch    = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_RESOLVE        => [$host . ':443:' . $ip],
            CURLOPT_WRITEFUNCTION  => static function ($c, string $chunk) use (&$bytes): int {
                $bytes .= $chunk;

                return strlen($bytes) > self::MAX_BYTES + 1 ? 0 : strlen($chunk);
            },
        ]);
        curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($http !== 200 || $bytes === '') {
            return $this->err(422, 'The file could not be downloaded (HTTP ' . $http . ').');
        }

        return $this->store($bytes, $name !== '' ? $name : basename((string) ($p['path'] ?? 'file')), $userId);
    }

    /**
     * @return array{files: int, bytes: int}
     */
    public function usage(): array
    {
        try {
            $row = db_connect()->table('media')->select('COUNT(*) AS n, COALESCE(SUM(size), 0) AS b', false)->like('path', 'uploads/public/', 'after')->get()->getRowArray() ?: [];

            return ['files' => (int) ($row['n'] ?? 0), 'bytes' => (int) ($row['b'] ?? 0)];
        } catch (Throwable) {
            return ['files' => 0, 'bytes' => 0];
        }
    }

    /**
     * Uploaded files, newest first, with whether a workflow, campaign or waiting message still uses each.
     *
     * @return list<array<string, mixed>>
     */
    public function list(int $limit = 100): array
    {
        $rows = model(MediaModel::class)->like('path', 'uploads/public/', 'after')->orderBy('id', 'DESC')->findAll(max(1, min(500, $limit)));

        return array_map(fn (array $r): array => [
            'name'       => (string) $r['filename'],
            'filename'   => (string) $r['original_name'],
            'mime_type'  => (string) $r['mime_type'],
            'size'       => (int) $r['size'],
            'url'        => (string) $r['url'],
            'in_use'     => $this->isReferenced((string) $r['filename']),
            'created_at' => (string) ($r['created_at'] ?? ''),
        ], $rows);
    }

    /** True when a workflow, a campaign or a message still waiting to be sent points at this file. */
    public function isReferenced(string $name): bool
    {
        $db = db_connect();
        foreach ([['automations', 'flow_graph'], ['automation_rules', 'config'], ['campaigns', 'payload']] as [$table, $col]) {
            try {
                if ($db->tableExists($table) && $db->table($table)->like($col, $name)->countAllResults() > 0) {
                    return true;
                }
            } catch (Throwable) {
                // A table that is not there cannot reference the file.
            }
        }
        try {
            if ($db->tableExists('message_queue') && $db->table('message_queue')->whereIn('status', ['pending', 'processing'])->like('payload', $name)->countAllResults() > 0) {
                return true;
            }
        } catch (Throwable) {
            // see above
        }

        return false;
    }

    /**
     * Removes one uploaded file. Refuses while something still uses it, unless $force.
     *
     * @return array{ok: bool, status: int, message: string}
     */
    public function delete(string $name, bool $force = false): array
    {
        if (preg_match('/^[a-f0-9]{40}\.(pdf|jpg|png|mp4)$/', $name) !== 1) {
            return $this->err(404, 'File not found.');
        }
        $model = model(MediaModel::class);
        $row   = $model->where('filename', $name)->like('path', 'uploads/public/', 'after')->first();
        if ($row === null) {
            return $this->err(404, 'File not found.');
        }
        if (! $force && $this->isReferenced($name)) {
            return $this->err(409, 'A workflow, campaign or waiting message still uses this file. Remove it there first (or send force = true).');
        }
        @unlink(self::dir() . $name);
        $model->delete((int) $row['id'], true);

        return ['ok' => true, 'status' => 200, 'message' => 'File deleted.'];
    }

    /**
     * Deletes files older than $days that nothing uses. Returns how many were removed.
     */
    public function cleanup(int $days = 90): int
    {
        $cut  = date('Y-m-d H:i:s', time() - max(1, $days) * 86400);
        $rows = model(MediaModel::class)->like('path', 'uploads/public/', 'after')->where('created_at <', $cut)->findAll(500);
        $n    = 0;
        foreach ($rows as $r) {
            if ($this->delete((string) $r['filename'])['ok']) {
                $n++;
            }
        }

        return $n;
    }

    /** The file type from its first bytes (works without the fileinfo extension), never from the name the sender gave. */
    private function detectMime(string $bytes): ?string
    {
        if (str_starts_with($bytes, '%PDF-')) {
            return 'application/pdf';
        }
        if (str_starts_with($bytes, "\xFF\xD8\xFF")) {
            return 'image/jpeg';
        }
        if (str_starts_with($bytes, "\x89PNG\r\n\x1A\n")) {
            return 'image/png';
        }
        // MP4: a size, then the box type 'ftyp' at byte 4
        if (strlen($bytes) > 12 && substr($bytes, 4, 4) === 'ftyp') {
            return 'video/mp4';
        }

        return null;
    }

    /**
     * @return array{ok: bool, status: int, message: string}
     */
    private function err(int $status, string $message): array
    {
        return ['ok' => false, 'status' => $status, 'message' => $message];
    }
}
