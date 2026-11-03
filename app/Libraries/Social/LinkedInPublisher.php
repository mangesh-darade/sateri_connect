<?php

declare(strict_types=1);

namespace App\Libraries\Social;

/**
 * LinkedIn member profile posts (text, image, video) via the versioned Posts / Images / Videos APIs.
 * external_id is the member id (OpenID `sub`); the author URN is urn:li:person:{id}.
 * Company pages need the Community Management API approval and are not covered here.
 */
class LinkedInPublisher implements SocialPublisherInterface
{
    use RawHttp;

    /** Bump when LinkedIn retires this monthly API version (YYYYMM). */
    private const VERSION = '202506';
    private const API     = 'https://api.linkedin.com/rest';

    public function platform(): string
    {
        return 'linkedin';
    }

    public function publish(array $account, array $post, string $caption, ?string $mediaUrl): array
    {
        $token = (string) ($account['access_token'] ?? '');
        $owner = 'urn:li:person:' . (string) ($account['external_id'] ?? '');
        if ($token === '' || $owner === 'urn:li:person:') {
            return ['ok' => false, 'status' => 401, 'message' => 'LinkedIn account is not connected.'];
        }

        $content = null;
        $path    = (string) ($post['media_path'] ?? '');
        if ($path !== '' && is_file($path)) {
            $isVideo = str_starts_with((string) ($post['media_mime'] ?? ''), 'video/');
            $media   = $isVideo ? $this->uploadVideo($token, $owner, $path) : $this->uploadImage($token, $owner, $path);
            if (! $media['ok']) {
                return $media;
            }
            $content = ['media' => ['id' => $media['data']['urn']]];
        }

        $payload = [
            'author'                    => $owner,
            'commentary'                => $this->escape(mb_substr($caption, 0, 3000)),
            'visibility'                => 'PUBLIC',
            'distribution'              => ['feedDistribution' => 'MAIN_FEED', 'targetEntities' => [], 'thirdPartyDistributionChannels' => []],
            'lifecycleState'            => 'PUBLISHED',
            'isReshareDisabledByAuthor' => false,
        ];
        if ($content !== null) {
            $payload['content'] = $content;
        }

        $r = $this->raw('POST', self::API . '/posts', $this->headers($token), (string) json_encode($payload), null, 60);
        if ($r['status'] !== 201) {
            return $this->failure($r, 'post');
        }
        $urn = (string) ($r['headers']['x-restli-id'] ?? '');

        return ['ok' => true, 'status' => 200, 'message' => 'Published', 'data' => [
            'external_id'  => $urn,
            'external_url' => $urn !== '' ? 'https://www.linkedin.com/feed/update/' . $urn : '',
        ]];
    }

    /**
     * @return array<string, mixed>
     */
    private function uploadImage(string $token, string $owner, string $path): array
    {
        $init = $this->raw('POST', self::API . '/images?action=initializeUpload', $this->headers($token), (string) json_encode(['initializeUploadRequest' => ['owner' => $owner]]), null, 60);
        $v    = json_decode($init['body'], true)['value'] ?? null;
        if ($init['status'] !== 200 || empty($v['uploadUrl']) || empty($v['image'])) {
            return $this->failure($init, 'image-init');
        }
        $size = (int) filesize($path);
        $up   = $this->raw('PUT', (string) $v['uploadUrl'], ['Authorization: Bearer ' . $token, 'Content-Type: application/octet-stream'], null, ['path' => $path, 'offset' => 0, 'length' => $size]);
        if ($up['status'] < 200 || $up['status'] >= 300) {
            return $this->failure($up, 'image-upload');
        }

        return ['ok' => true, 'status' => 200, 'message' => 'OK', 'data' => ['urn' => (string) $v['image']]];
    }

    /**
     * @return array<string, mixed>
     */
    private function uploadVideo(string $token, string $owner, string $path): array
    {
        $size = (int) filesize($path);
        $init = $this->raw('POST', self::API . '/videos?action=initializeUpload', $this->headers($token), (string) json_encode([
            'initializeUploadRequest' => ['owner' => $owner, 'fileSizeBytes' => $size, 'uploadCaptions' => false, 'uploadThumbnail' => false],
        ]), null, 60);
        $v = json_decode($init['body'], true)['value'] ?? null;
        if ($init['status'] !== 200 || empty($v['video']) || empty($v['uploadInstructions'])) {
            return $this->failure($init, 'video-init');
        }

        $etags = [];
        foreach ($v['uploadInstructions'] as $part) {
            $first = (int) $part['firstByte'];
            $len   = (int) $part['lastByte'] - $first + 1;
            $up    = $this->raw('PUT', (string) $part['uploadUrl'], ['Authorization: Bearer ' . $token, 'Content-Type: application/octet-stream'], null, ['path' => $path, 'offset' => $first, 'length' => $len]);
            if ($up['status'] < 200 || $up['status'] >= 300 || empty($up['headers']['etag'])) {
                return $this->failure($up, 'video-upload');
            }
            $etags[] = trim($up['headers']['etag'], '"');
        }

        $fin = $this->raw('POST', self::API . '/videos?action=finalizeUpload', $this->headers($token), (string) json_encode([
            'finalizeUploadRequest' => ['video' => $v['video'], 'uploadToken' => (string) ($v['uploadToken'] ?? ''), 'uploadedPartIds' => $etags],
        ]), null, 120);
        if ($fin['status'] < 200 || $fin['status'] >= 300) {
            return $this->failure($fin, 'video-finalize');
        }

        return ['ok' => true, 'status' => 200, 'message' => 'OK', 'data' => ['urn' => (string) $v['video']]];
    }

    /**
     * @return list<string>
     */
    private function headers(string $token): array
    {
        return [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
            'X-Restli-Protocol-Version: 2.0.0',
            'LinkedIn-Version: ' . self::VERSION,
        ];
    }

    /** LinkedIn "little text" treats these characters as markup; escape them so the caption shows literally. # is kept so hashtags link. */
    private function escape(string $text): string
    {
        return (string) preg_replace_callback('/[\\\\|{}@\[\]()<>*_~]/', static fn (array $m): string => '\\' . $m[0], $text);
    }

    /**
     * @param array{status: int, headers: array<string, string>, body: string, error: string} $r
     * @return array<string, mixed>
     */
    private function failure(array $r, string $step): array
    {
        log_message('error', 'LinkedInPublisher ' . $step . ' http=' . $r['status'] . ' curl=' . $r['error']);

        return match (true) {
            $r['status'] === 401 => ['ok' => false, 'status' => 401, 'message' => 'LinkedIn login expired. Reconnect the account.'],
            $r['status'] === 403 => ['ok' => false, 'status' => 403, 'message' => 'LinkedIn did not allow this post. Check the app has Share on LinkedIn enabled.'],
            $r['status'] === 429 => ['ok' => false, 'status' => 429, 'message' => 'LinkedIn rate limit reached. Will retry.', 'retry' => true],
            default              => ['ok' => false, 'status' => 502, 'message' => 'LinkedIn post failed. Please try again.', 'retry' => $r['status'] === 0 || $r['status'] >= 500],
        };
    }
}
