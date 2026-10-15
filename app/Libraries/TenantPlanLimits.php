<?php

declare(strict_types=1);

namespace App\Libraries;

use App\Models\RoleModel;
use App\Models\UserModel;
use Throwable;

/**
 * Per-client user and role caps set on the platform tenant row.
 * Null means no cap. Counts include every current user and role.
 */
class TenantPlanLimits
{
    public function ensureColumns(): void
    {
        try {
            $db = MasterTenantRepository::masterConnection();
            if (! $db->tableExists('tenants')) {
                return;
            }
            if (! $db->fieldExists('max_users', 'tenants')) {
                $db->query('ALTER TABLE `tenants` ADD `max_users` INT UNSIGNED NULL DEFAULT NULL');
            }
            if (! $db->fieldExists('max_roles', 'tenants')) {
                $db->query('ALTER TABLE `tenants` ADD `max_roles` INT UNSIGNED NULL DEFAULT NULL');
            }
        } catch (Throwable $e) {
            log_message('error', 'Tenant plan limit columns failed: {msg}', ['msg' => $e->getMessage()]);
        }
    }

    /**
     * @return array{max_users: ?int, max_roles: ?int}
     */
    public function limitsFor(string $key): array
    {
        $this->ensureColumns();
        $key = strtolower(trim($key));
        $empty = ['max_users' => null, 'max_roles' => null];
        if ($key === '') {
            return $empty;
        }

        try {
            $row = MasterTenantRepository::masterConnection()
                ->table('tenants')
                ->select('max_users, max_roles')
                ->where('key', $key)
                ->get()
                ->getRowArray();
        } catch (Throwable $e) {
            log_message('error', 'Tenant plan limits read failed: {msg}', ['msg' => $e->getMessage()]);

            return $empty;
        }

        if (! is_array($row)) {
            return $empty;
        }

        return [
            'max_users' => $this->nullableInt($row['max_users'] ?? null),
            'max_roles' => $this->nullableInt($row['max_roles'] ?? null),
        ];
    }

    public function save(string $key, ?int $maxUsers, ?int $maxRoles): bool
    {
        $this->ensureColumns();
        $key = strtolower(trim($key));
        if ($key === '') {
            return false;
        }

        try {
            return MasterTenantRepository::masterConnection()
                ->table('tenants')
                ->where('key', $key)
                ->update([
                    'max_users'  => $maxUsers,
                    'max_roles'  => $maxRoles,
                    'updated_at' => date('Y-m-d H:i:s'),
                ]) !== false;
        } catch (Throwable $e) {
            log_message('error', 'Tenant plan limits save failed: {msg}', ['msg' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * @return array{
     *     users: int,
     *     roles: int,
     *     max_users: ?int,
     *     max_roles: ?int,
     *     can_add_user: bool,
     *     can_add_role: bool
     * }
     */
    public function snapshot(?string $key = null): array
    {
        $key ??= (string) (TenantContext::get() ?: session('tenant_key') ?: '');
        $limits = $this->limitsFor($key);
        $users  = $this->userCount();
        $roles  = $this->roleCount();

        return [
            'users'        => $users,
            'roles'        => $roles,
            'max_users'    => $limits['max_users'],
            'max_roles'    => $limits['max_roles'],
            'can_add_user' => $limits['max_users'] === null || $users < $limits['max_users'],
            'can_add_role' => $limits['max_roles'] === null || $roles < $limits['max_roles'],
        ];
    }

    public function userCreateBlock(): ?string
    {
        $seat = $this->snapshot();
        if ($seat['can_add_user']) {
            return null;
        }

        return 'User limit reached (' . (int) $seat['users'] . ' of ' . (int) $seat['max_users'] . '). Ask the platform admin to raise this client’s user limit.';
    }

    public function roleCreateBlock(): ?string
    {
        $seat = $this->snapshot();
        if ($seat['can_add_role']) {
            return null;
        }

        return 'Role limit reached (' . (int) $seat['roles'] . ' of ' . (int) $seat['max_roles'] . '). Ask the platform admin to raise this client’s role limit.';
    }

    public function userCount(): int
    {
        try {
            return model(UserModel::class)->countAllResults();
        } catch (Throwable) {
            return 0;
        }
    }

    public function roleCount(): int
    {
        try {
            return model(RoleModel::class)->countAllResults();
        } catch (Throwable) {
            return 0;
        }
    }

    protected function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $n = (int) $value;

        return $n > 0 ? $n : null;
    }
}
