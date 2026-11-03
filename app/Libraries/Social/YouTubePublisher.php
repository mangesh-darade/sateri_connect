<?php

declare(strict_types=1);

namespace App\Libraries\Social;

/**
 * YouTube video / Shorts through the Data API v3 resumable upload.
 * Needs the staged local file ($post['media_path']); publishes immediately at the due time.
 * Quota is per Google Cloud project (an upload costs 1,600 of the default 10,000 daily units), shared by all clients.
 */
class YouTubePublisher implements SocialPublisherInterface, SocialRemovableInterface
{
    use RawHttp;

    public function platform(): string
    {
        return 'youtube';
    }

    public function publish(array $account, array $post, string $caption, ?string $mediaUrl): array
    {
        $token = (string) ($account['access_token'] ?? '');
        $path  = (string) ($post['media_path'] ?? '');
        if ($token === '') {
            return ['ok' => false, 'status' => 401, 'message' => 'YouTube channel is not connected.'];
        }
        if ($path === '' || ! is_file($path) || ! str_starts_with((string) ($post['media_mime'] ?? ''), 'video/')) {
            return ['ok' => false, 'status' => 422, 'message' => 'YouTube posts need a video file.'];
        }

        $isShort = in_array((string) ($post['type'] ?? ''), ['reel', 'short'], true);
        $title   = trim((string) ($post['title'] ?? '')) ?: 'New video';
        $desc    = $caption . ($isShort && ! str_contains(strtolower($caption), '#shorts') ? "\n\n#Shorts" : '');
        $size    = (int) filesize($path);

        $init = $this->raw('POST', 'https://www.googleapis.com/upload/youtube/v3/videos?uploadType=resumable&part=snippet,status', [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json; charset=UTF-8',
            'X-Upload-Content-Type: ' . $post['media_mime'],
            'X-Upload-Content-Length: ' . $size,
        ], (string) json_encode([
            'snippet' => ['title' => mb_substr($title, 0, 100), 'description' => mb_substr($desc, 0, 5000), 'categoryId' => '22'],
            'status'  => ['privacyStatus' => 'public', 'selfDeclaredMadeForKids' => false],
        ]), null, 60);

        if ($init['status'] !== 200 || empty($init['headers']['location'])) {
            return $this->failure($init, 'start');
        }

        $up = $this->raw('PUT', $init['headers']['location'], [
            'Authorization: Bearer ' . $token,
            'Content-Type: ' . $post['media_mime'],
            'Content-Length: ' . $size,
        ], null, ['path' => $path, 'offset' => 0, 'length' => $size], 600, is_callable($post['on_progress'] ?? null) ? $post['on_progress'] : null);

        $body = json_decode($up['body'], true);
        if (! in_array($up['status'], [200, 201], true) || ! is_array($body) || empty($body['id'])) {
            return $this->failure($up, 'upload');
        }

        return ['ok' => true, 'status' => 200, 'message' => 'Published', 'data' => [
            'external_id'  => (string) $body['id'],
            'external_url' => 'https://youtu.be/' . $body['id'],
        ]];
    }

    public function remove(array $account, string $externalId): array
    {
        $token = (string) ($account['access_token'] ?? '');
        if ($token === '' || preg_match('/^[A-Za-z0-9_-]{6,20}$/', $externalId) !== 1) {
            return ['ok' => false, 'status' => 400, 'message' => 'YouTube channel is not connected.'];
        }
        $r = $this->raw('DELETE', 'https://www.googleapis.com/youtube/v3/videos?id=' . rawurlencode($externalId), ['Authorization: Bearer ' . $token], null, null, 60);
        // 404 means it is already gone on YouTube, which is what the user wants.
        if (in_array($r['status'], [200, 204, 404], true)) {
            return ['ok' => true, 'status' => 200, 'message' => 'Deleted from YouTube.'];
        }
        log_message('error', 'YouTubePublisher remove http=' . $r['status']);

        // Deleting needs the "manage your YouTube account" permission; connections made with upload-only access get a 403.
        return ['ok' => false, 'status' => 502, 'message' => $r['status'] === 403
            ? 'YouTube did not allow deleting this video because this connection only has upload permission. Reconnect YouTube on Social > Accounts (it now asks for permission to manage videos), or delete the video in YouTube Studio.'
            : 'Could not delete the video from YouTube. Try again or delete it in YouTube Studio.'];
    }

    /**
     * @param array{status: int, headers: array<string, string>, body: string, error: string} $r
     * @return array<string, mixed>
     */
    private function failure(array $r, string $step): array
    {
        $json   = json_decode($r['body'], true);
        $reason = (string) ($json['error']['errors'][0]['reason'] ?? '');
        log_message('error', 'YouTubePublisher ' . $step . ' http=' . $r['status'] . ' reason=' . $reason . ' curl=' . $r['error']);

        if (in_array($reason, ['quotaExceeded', 'dailyLimitExceeded', 'uploadLimitExceeded'], true)) {
            return ['ok' => false, 'status' => 429, 'message' => 'YouTube daily upload limit reached. Use Retry tomorrow.'];
        }
        if ($r['status'] === 401) {
            return ['ok' => false, 'status' => 401, 'message' => 'YouTube login expired. Reconnect the channel.'];
        }
        if ($r['status'] === 403) {
            return ['ok' => false, 'status' => 403, 'message' => 'YouTube did not allow this upload. The channel or API project may need verification.'];
        }

        return ['ok' => false, 'status' => 502, 'message' => 'YouTube upload failed. Please try again.', 'retry' => $r['status'] === 0 || $r['status'] >= 500];
    }
}
