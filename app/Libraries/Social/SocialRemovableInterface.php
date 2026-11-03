<?php

declare(strict_types=1);

namespace App\Libraries\Social;

/**
 * Optional: a publisher that can also delete what it published. Instagram and LinkedIn are not part of it
 * (the Instagram API has no delete call), so those posts can only be removed from the calendar here.
 */
interface SocialRemovableInterface
{
    /**
     * @param array<string, mixed> $account social_accounts row with a decrypted access_token
     * @return array{ok: bool, status: int, message: string}
     */
    public function remove(array $account, string $externalId): array;
}
