<?php

declare(strict_types=1);

namespace App\Libraries\Social;

/**
 * One adapter per platform. Adapters never throw: they return
 * ['ok' => bool, 'status' => int, 'message' => string, 'data'? => ['external_id' => string, 'external_url'? => string]].
 * A 'retry' => true flag in the result means the failure is temporary (rate limit, media still processing).
 */
interface SocialPublisherInterface
{
    public function platform(): string;

    /**
     * @param array<string, mixed> $account   social_accounts row with a decrypted access_token
     * @param array<string, mixed> $post      social_posts row
     * @param string               $caption   final caption (override + hashtags already applied)
     * @param string|null          $mediaUrl  public URL the platform can fetch, or null for a text-only post
     * @return array<string, mixed>
     */
    public function publish(array $account, array $post, string $caption, ?string $mediaUrl): array;
}
