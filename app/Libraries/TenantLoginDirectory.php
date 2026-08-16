<?php

declare(strict_types=1);

namespace App\Libraries;

/**
 * Keep master tenant_login_index in sync with tenant users.email.
 */
class TenantLoginDirectory
{
    public function __construct(protected ?MasterTenantRepository $master = null)
    {
        $this->master ??= new MasterTenantRepository();
    }

    public function syncEmail(?string $email, ?string $previousEmail = null): void
    {
        $tenantKey = TenantContext::get();
        if ($tenantKey === null || $tenantKey === '') {
            return;
        }

        if (! MasterTenantRepository::masterConfigured()) {
            return;
        }

        $email = $email !== null ? strtolower(trim($email)) : '';
        $previousEmail = $previousEmail !== null ? strtolower(trim($previousEmail)) : '';

        if ($previousEmail !== '' && $previousEmail !== $email) {
            $this->master->removeLoginIndexForTenantEmail($tenantKey, $previousEmail);
        }

        if ($email !== '') {
            $this->master->upsertLoginIndex($email, $tenantKey);
        }
    }

    public function removeEmail(string $email): void
    {
        $tenantKey = TenantContext::get();
        if ($tenantKey === null || $tenantKey === '') {
            return;
        }

        if (! MasterTenantRepository::masterConfigured()) {
            return;
        }

        $this->master->removeLoginIndexForTenantEmail($tenantKey, $email);
    }
}
