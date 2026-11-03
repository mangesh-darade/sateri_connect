<?php

declare(strict_types=1);

namespace App\Libraries\Social;

/**
 * Instagram Business/Creator: image, Reel and Story via the Content Publishing API.
 * external_id is the Instagram user id. Instagram has no scheduling API, so the cron calls this at the due time.
 */
class InstagramPublisher extends MetaGraphPublisher
{
    private const POLL_TRIES   = 6;
    private const POLL_SECONDS = 5;

    public function platform(): string
    {
        return 'instagram';
    }

    public function publish(array $account, array $post, string $caption, ?string $mediaUrl): array
    {
        $igId  = (string) ($account['external_id'] ?? '');
        $token = (string) ($account['access_token'] ?? '');
        if ($igId === '' || $token === '') {
            return ['ok' => false, 'status' => 400, 'message' => 'Instagram account is not connected.'];
        }
        if ($mediaUrl === null || $mediaUrl === '') {
            return ['ok' => false, 'status' => 422, 'message' => 'Instagram posts need an image or video.'];
        }

        $type    = (string) ($post['type'] ?? 'post');
        $isVideo = in_array($type, ['reel', 'short'], true)
            || preg_match('/\.(mp4|mov|m4v)(\?|$)/i', $mediaUrl) === 1;

        $params = ['caption' => $caption];
        if ($type === 'story') {
            unset($params['caption']);
            $params['media_type'] = 'STORIES';
            $params[$isVideo ? 'video_url' : 'image_url'] = $mediaUrl;
        } elseif ($isVideo) {
            $params['media_type'] = 'REELS';
            $params['video_url']  = $mediaUrl;
        } else {
            $params['image_url'] = $mediaUrl;
        }

        $container = $this->graph('POST', $igId . '/media', $token, $params);
        if (! $container['ok']) {
            return $this->fail($container);
        }
        $containerId = (string) ($container['body']['id'] ?? '');
        if ($containerId === '') {
            return ['ok' => false, 'status' => 502, 'message' => 'Instagram did not accept the media.'];
        }

        for ($i = 0; $i < self::POLL_TRIES; $i++) {
            $s = $this->graph('GET', $containerId, $token, ['fields' => 'status_code']);
            if (! $s['ok']) {
                return $this->fail($s);
            }
            $code = (string) ($s['body']['status_code'] ?? '');
            if ($code === 'FINISHED') {
                break;
            }
            if ($code === 'ERROR' || $code === 'EXPIRED') {
                return ['ok' => false, 'status' => 422, 'message' => 'Instagram could not process this media. Check format and length.'];
            }
            if ($i === self::POLL_TRIES - 1) {
                return ['ok' => false, 'status' => 202, 'message' => 'Instagram is still processing the media.', 'retry' => true];
            }
            sleep(self::POLL_SECONDS);
        }

        $pub = $this->graph('POST', $igId . '/media_publish', $token, ['creation_id' => $containerId]);
        if (! $pub['ok']) {
            return $this->fail($pub);
        }
        $mediaId = (string) ($pub['body']['id'] ?? '');
        $link    = $this->graph('GET', $mediaId, $token, ['fields' => 'permalink']);

        return ['ok' => true, 'status' => 200, 'message' => 'Published', 'data' => [
            'external_id'  => $mediaId,
            'external_url' => $link['ok'] ? (string) ($link['body']['permalink'] ?? '') : '',
        ]];
    }
}
