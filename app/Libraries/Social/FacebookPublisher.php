<?php

declare(strict_types=1);

namespace App\Libraries\Social;

use CURLFile;
use Config\Services;
use Throwable;

/**
 * Facebook Page: text post, photo, video and Reel through the Graph API.
 * The account's access_token is a Page access token and external_id is the Page id.
 */
class FacebookPublisher extends MetaGraphPublisher implements SocialRemovableInterface
{
    public function platform(): string
    {
        return 'facebook';
    }

    public function publish(array $account, array $post, string $caption, ?string $mediaUrl): array
    {
        $pageId = (string) ($account['external_id'] ?? '');
        $token  = (string) ($account['access_token'] ?? '');
        if ($pageId === '' || $token === '') {
            return ['ok' => false, 'status' => 400, 'message' => 'Facebook Page is not connected.'];
        }

        $type = (string) ($post['type'] ?? 'post');
        if ($mediaUrl === null || $mediaUrl === '') {
            $r = $this->graph('POST', $pageId . '/feed', $token, ['message' => $caption]);
        } elseif ($type === 'reel') {
            return $this->publishReel($pageId, $token, $caption, $mediaUrl);
        } elseif ($type === 'short' || $this->looksLikeVideo($mediaUrl)) {
            $r = $this->graph('POST', $pageId . '/videos', $token, $this->mediaParams($post, 'description', $caption, 'file_url', $mediaUrl));
        } else {
            $r = $this->graph('POST', $pageId . '/photos', $token, $this->mediaParams($post, 'caption', $caption, 'url', $mediaUrl));
        }

        return $this->result($r);
    }

    /**
     * Reels use a 3-step flow: start, upload by URL, finish with the caption.
     *
     * @return array<string, mixed>
     */
    private function publishReel(string $pageId, string $token, string $caption, string $mediaUrl): array
    {
        $start = $this->graph('POST', $pageId . '/video_reels', $token, ['upload_phase' => 'start']);
        if (! $start['ok']) {
            return $this->fail($start);
        }
        $videoId = (string) ($start['body']['video_id'] ?? '');
        $upload  = (string) ($start['body']['upload_url'] ?? '');
        if ($videoId === '' || $upload === '') {
            return ['ok' => false, 'status' => 502, 'message' => 'Facebook did not accept the Reel upload.'];
        }

        // The upload host fetches the video from a hosted URL given in the file_url header.
        try {
            $client = Services::curlrequest(['timeout' => 120, 'http_errors' => false], null, null, false);
            $res    = $client->post($upload, ['headers' => ['Authorization' => 'OAuth ' . $token, 'file_url' => $mediaUrl]]);
            if ($res->getStatusCode() >= 300) {
                return ['ok' => false, 'status' => 502, 'message' => 'Facebook could not fetch the Reel video.', 'retry' => $res->getStatusCode() >= 500];
            }
        } catch (Throwable $e) {
            log_message('error', self::class . '::publishReel upload exception ' . $e::class);

            return ['ok' => false, 'status' => 502, 'message' => 'Could not reach Facebook. Please try again.', 'retry' => true];
        }

        $finish = $this->graph('POST', $pageId . '/video_reels', $token, [
            'upload_phase' => 'finish',
            'video_id'     => $videoId,
            'video_state'  => 'PUBLISHED',
            'description'  => $caption,
        ]);
        if (! $finish['ok']) {
            return $this->fail($finish);
        }

        return ['ok' => true, 'status' => 200, 'message' => 'Published', 'data' => [
            'external_id'  => $videoId,
            'external_url' => 'https://www.facebook.com/reel/' . $videoId,
        ]];
    }

    /**
     * Sends the staged file itself when we have it (works without a public site address); otherwise Facebook fetches the URL.
     *
     * @param array<string, mixed> $post
     * @return array<string, mixed>
     */
    private function mediaParams(array $post, string $captionKey, string $caption, string $urlKey, string $mediaUrl): array
    {
        $path = (string) ($post['media_path'] ?? '');
        if ($path !== '' && is_file($path)) {
            return [$captionKey => $caption, 'source' => new CURLFile($path, (string) ($post['media_mime'] ?? ''), basename($path))];
        }

        return [$captionKey => $caption, $urlKey => $mediaUrl];
    }

    public function remove(array $account, string $externalId): array
    {
        $token = (string) ($account['access_token'] ?? '');
        if ($token === '' || $externalId === '') {
            return ['ok' => false, 'status' => 400, 'message' => 'Facebook Page is not connected.'];
        }
        $r = $this->graph('DELETE', $externalId, $token);

        return $r['ok']
            ? ['ok' => true, 'status' => 200, 'message' => 'Deleted from Facebook.']
            : ['ok' => false, 'status' => (int) $r['status'], 'message' => $r['message']];
    }

    private function looksLikeVideo(string $url): bool
    {
        return preg_match('/\.(mp4|mov|m4v|webm)(\?|$)/i', $url) === 1;
    }

    /**
     * @param array{ok: bool, status: int, body: array<string, mixed>, message: string, retry: bool} $r
     * @return array<string, mixed>
     */
    private function result(array $r): array
    {
        if (! $r['ok']) {
            return $this->fail($r);
        }
        $id = (string) ($r['body']['post_id'] ?? $r['body']['id'] ?? '');
        if ($id === '') {
            return ['ok' => false, 'status' => 502, 'message' => 'Facebook returned no post id.'];
        }

        return ['ok' => true, 'status' => 200, 'message' => 'Published', 'data' => [
            'external_id'  => $id,
            'external_url' => 'https://www.facebook.com/' . $id,
        ]];
    }
}
