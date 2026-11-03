<?php

declare(strict_types=1);

namespace App\Libraries;

use Config\Services;
use Firebase\JWT\JWT;
use Throwable;

/**
 * Read-only Google Drive access through ONE platform service account (docs/SOCIAL_SCHEDULER.md section 3).
 * A client shares a folder with the service account's email; no per-client Google login is needed.
 *
 * Setup: create a service account, enable the Drive API, and point `.env` at its JSON key file:
 *   google.serviceAccountFile = 'C:/secure/sateri-drive.json'
 * Every method returns ['ok','status','message','data'?] and never throws.
 */
class GoogleDriveService
{
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const API       = 'https://www.googleapis.com/drive/v3';
    private const SCOPE     = 'https://www.googleapis.com/auth/drive.readonly';

    private ?string $token       = null;
    private int $tokenExpiresAt  = 0;

    public function isConfigured(): bool
    {
        return $this->credentials() !== null;
    }

    /** True when Google accepts the service account key (used by the Setup screen). */
    public function canAuthenticate(): bool
    {
        return $this->accessToken() !== null;
    }

    /** Email clients must share their folder with. */
    public function serviceAccountEmail(): string
    {
        return (string) ($this->credentials()['client_email'] ?? '');
    }

    /**
     * Video and image files directly inside a folder (not recursive).
     *
     * @return array<string, mixed>
     */
    public function listFolder(string $folderId): array
    {
        if (preg_match('/^[A-Za-z0-9_-]{10,128}$/', $folderId) !== 1) {
            return $this->err(422, 'That Google Drive folder id is not valid.');
        }

        $files     = [];
        $pageToken = '';
        do {
            $r = $this->api('GET', '/files', [
                'q'         => "'" . $folderId . "' in parents and trashed = false and (mimeType contains 'video/' or mimeType contains 'image/')",
                'fields'    => 'nextPageToken,files(id,name,mimeType,size,md5Checksum,videoMediaMetadata(width,height,durationMillis),imageMediaMetadata(width,height))',
                'pageSize'  => 100,
                'pageToken' => $pageToken,
            ]);
            if (! $r['ok']) {
                return $r['status'] === 404
                    ? $this->err(404, 'Folder not found. Share it with ' . $this->serviceAccountEmail() . ' first.')
                    : $r;
            }
            foreach ((array) ($r['data']['files'] ?? []) as $f) {
                $meta = $f['videoMediaMetadata'] ?? $f['imageMediaMetadata'] ?? [];
                $files[] = [
                    'id'           => (string) $f['id'],
                    'name'         => (string) ($f['name'] ?? ''),
                    'mime'         => (string) ($f['mimeType'] ?? ''),
                    'size'         => (int) ($f['size'] ?? 0),
                    'md5'          => (string) ($f['md5Checksum'] ?? ''),
                    'width'        => isset($meta['width']) ? (int) $meta['width'] : null,
                    'height'       => isset($meta['height']) ? (int) $meta['height'] : null,
                    'duration_sec' => isset($meta['durationMillis']) ? round(((int) $meta['durationMillis']) / 1000, 2) : null,
                ];
            }
            $pageToken = (string) ($r['data']['nextPageToken'] ?? '');
        } while ($pageToken !== '');

        return ['ok' => true, 'status' => 200, 'message' => 'OK', 'data' => ['files' => $files]];
    }

    /**
     * Streams one file to disk (no full-file memory load).
     *
     * @return array<string, mixed>
     */
    public function download(string $fileId, string $destPath, ?callable $onProgress = null): array
    {
        if (preg_match('/^[A-Za-z0-9_-]{10,128}$/', $fileId) !== 1) {
            return $this->err(422, 'That Google Drive file id is not valid.');
        }
        $token = $this->accessToken();
        if ($token === null) {
            return $this->err(503, 'Google Drive is not configured.');
        }

        try {
            // CodeIgniter's CURLRequest has no "sink" option, so stream straight to disk with cURL.
            $fh = fopen($destPath, 'wb');
            if ($fh === false) {
                return $this->err(500, 'Could not save the file from Google Drive.');
            }
            $ch = curl_init(self::API . '/files/' . $fileId . '?alt=media');
            curl_setopt_array($ch, [
                CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token],
                CURLOPT_FILE           => $fh,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_CONNECTTIMEOUT => 30,
                CURLOPT_TIMEOUT        => 600,
                CURLOPT_FAILONERROR    => false,
                CURLOPT_NOPROGRESS     => $onProgress === null,
                CURLOPT_PROGRESSFUNCTION => static function ($c, $dlTotal, $dlNow) use ($onProgress): int {
                    if ($onProgress !== null && $dlTotal > 0) {
                        $onProgress((int) floor($dlNow * 100 / $dlTotal));
                    }

                    return 0;
                },
            ]);
            $ok   = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);
            fclose($fh);

            if ($ok === false || $code !== 200) {
                @unlink($destPath);
                log_message('error', 'GoogleDriveService::download http=' . $code);

                return $this->err($code === 404 ? 404 : 502, 'Could not download the file from Google Drive.');
            }
        } catch (Throwable $e) {
            @unlink($destPath);
            log_message('error', 'GoogleDriveService::download ' . $e::class);

            return $this->err(502, 'Could not download the file from Google Drive.');
        }

        return ['ok' => true, 'status' => 200, 'message' => 'OK', 'data' => ['path' => $destPath, 'bytes' => (int) @filesize($destPath)]];
    }

    /**
     * Sends a Drive file straight to the browser (headers, then body), honouring a Range request so a video can
     * be played and skipped through without downloading all of it first.
     * Returns false (before anything was sent) when Drive refused or the file cannot be read.
     */
    public function stream(string $fileId, string $range, string $mime): bool
    {
        if (preg_match('/^[A-Za-z0-9_-]{10,128}$/', $fileId) !== 1 || ($token = $this->accessToken()) === null) {
            return false;
        }
        $headers = ['Authorization: Bearer ' . $token];
        if (preg_match('/^bytes=\d*-\d*$/', $range) === 1) {
            $headers[] = 'Range: ' . $range;
        }

        $status  = 0;
        $meta    = [];
        $started = false;
        $ch      = curl_init(self::API . '/files/' . $fileId . '?alt=media');
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_TIMEOUT        => 0,
            CURLOPT_HEADERFUNCTION => static function ($c, string $line) use (&$status, &$meta): int {
                if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m) === 1) {
                    $status = (int) $m[1];
                    $meta   = [];
                } elseif (str_contains($line, ':')) {
                    [$k, $v] = explode(':', $line, 2);
                    $meta[strtolower(trim($k))] = trim($v);
                }

                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION  => static function ($c, string $data) use (&$status, &$meta, &$started, $mime): int {
                if (! $started) {
                    if ($status !== 200 && $status !== 206) {
                        return 0; // Drive refused: abort before anything is sent
                    }
                    while (ob_get_level() > 0) {
                        ob_end_clean();
                    }
                    http_response_code($status);
                    header('Content-Type: ' . ($mime !== '' ? $mime : ($meta['content-type'] ?? 'application/octet-stream')));
                    header('Accept-Ranges: bytes');
                    header('X-Content-Type-Options: nosniff');
                    header('Cache-Control: private, no-store');
                    foreach (['content-length', 'content-range'] as $h) {
                        if (isset($meta[$h])) {
                            header(ucwords($h, '-') . ': ' . $meta[$h]);
                        }
                    }
                    $started = true;
                }
                echo $data;
                flush();

                return strlen($data);
            },
        ]);
        curl_exec($ch);
        curl_close($ch);

        return $started;
    }

    /**
     * A small preview picture of a Drive file (image or video frame) as made by Google.
     *
     * @return array{mime: string, body: string}|null
     */
    public function thumbnail(string $fileId, int $size = 480): ?array
    {
        $meta = $this->api('GET', '/files/' . $fileId, ['fields' => 'thumbnailLink']);
        $link = $meta['ok'] ? (string) ($meta['data']['thumbnailLink'] ?? '') : '';
        $token = $this->accessToken();
        if ($link === '' || $token === null || ! str_starts_with($link, 'https://')) {
            return null;
        }
        $link = (string) preg_replace('/=s\d+$/', '=s' . max(64, min(1024, $size)), $link);

        try {
            $client = Services::curlrequest(['timeout' => 20, 'http_errors' => false], null, null, false);
            $res    = $client->get($link, ['headers' => ['Authorization' => 'Bearer ' . $token]]);
            $type   = strtolower(trim(explode(';', $res->getHeaderLine('Content-Type'))[0]));
            if ($res->getStatusCode() !== 200 || ! str_starts_with($type, 'image/')) {
                return null;
            }

            return ['mime' => $type, 'body' => (string) $res->getBody()];
        } catch (Throwable $e) {
            log_message('error', 'GoogleDriveService::thumbnail ' . $e::class);

            return null;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function credentials(): ?array
    {
        return (new SocialCredentials())->driveServiceAccount();
    }

    private function accessToken(): ?string
    {
        if ($this->token !== null && time() < $this->tokenExpiresAt - 60) {
            return $this->token;
        }
        $cred = $this->credentials();
        if ($cred === null) {
            return null;
        }

        try {
            $now = time();
            $jwt = JWT::encode([
                'iss'   => $cred['client_email'],
                'scope' => self::SCOPE,
                'aud'   => self::TOKEN_URL,
                'iat'   => $now,
                'exp'   => $now + 3600,
            ], (string) $cred['private_key'], 'RS256');

            $client = Services::curlrequest(['timeout' => 30, 'http_errors' => false], null, null, false);
            $res    = $client->post(self::TOKEN_URL, ['form_params' => [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'  => $jwt,
            ]]);
            $body = json_decode((string) $res->getBody(), true);
            if ($res->getStatusCode() !== 200 || empty($body['access_token'])) {
                log_message('error', 'GoogleDriveService::accessToken http=' . $res->getStatusCode());

                return null;
            }
            $this->token          = (string) $body['access_token'];
            $this->tokenExpiresAt = $now + (int) ($body['expires_in'] ?? 3600);

            return $this->token;
        } catch (Throwable $e) {
            log_message('error', 'GoogleDriveService::accessToken ' . $e::class);

            return null;
        }
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    private function api(string $method, string $path, array $query): array
    {
        $token = $this->accessToken();
        if ($token === null) {
            return $this->err(503, 'Google Drive is not configured.');
        }

        try {
            $client = Services::curlrequest(['timeout' => 30, 'http_errors' => false], null, null, false);
            $res    = $client->request($method, self::API . $path, [
                'headers' => ['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json'],
                'query'   => array_filter($query, static fn ($v): bool => $v !== ''),
            ]);
            $body = json_decode((string) $res->getBody(), true);
            $code = $res->getStatusCode();
            if ($code >= 200 && $code < 300 && is_array($body)) {
                return ['ok' => true, 'status' => $code, 'message' => 'OK', 'data' => $body];
            }
            log_message('error', 'GoogleDriveService::api ' . $path . ' http=' . $code);

            return $this->err($code === 404 ? 404 : 502, 'Google Drive request failed.');
        } catch (Throwable $e) {
            log_message('error', 'GoogleDriveService::api ' . $e::class);

            return $this->err(502, 'Could not reach Google Drive.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function err(int $status, string $message): array
    {
        return ['ok' => false, 'status' => $status, 'message' => $message];
    }
}
