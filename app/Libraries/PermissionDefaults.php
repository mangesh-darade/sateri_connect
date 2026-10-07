<?php

declare(strict_types=1);

namespace App\Libraries;

use Throwable;

/**
 * Permissions added after a tenant was seeded. On first sight in a tenant DB the row is created
 * and granted to the listed system roles; later revocations by an admin are left untouched.
 */
final class PermissionDefaults
{
    /** @var array<string, array{name: string, module: string, description: string, roles: list<string>}> */
    public const ADDED = [
        'emails.delete' => [
            'name'        => 'Delete Emails',
            'module'      => 'emails',
            'description' => 'Delete email templates, drips, campaigns and senders',
            'roles'       => ['admin', 'manager'],
        ],
        'ai.use' => [
            'name'        => 'Use AI Copilot',
            'module'      => 'ai',
            'description' => 'Use the AI Copilot assistant',
            'roles'       => ['admin', 'manager'],
        ],
    ];

    public static function ensure(): void
    {
        try {
            $db       = db_connect();
            $existing = array_column(
                $db->table('permissions')->select('slug')->whereIn('slug', array_keys(self::ADDED))->get()->getResultArray(),
                'slug'
            );
            $missing = array_diff(array_keys(self::ADDED), $existing);
            if ($missing === []) {
                return;
            }

            $roleIds = array_column($db->table('roles')->select('id, slug')->get()->getResultArray(), 'id', 'slug');
            $now     = date('Y-m-d H:i:s');

            foreach ($missing as $slug) {
                $def = self::ADDED[$slug];
                $db->table('permissions')->insert([
                    'name'        => $def['name'],
                    'slug'        => $slug,
                    'module'      => $def['module'],
                    'description' => $def['description'],
                    'created_at'  => $now,
                    'updated_at'  => $now,
                ]);
                $permissionId = (int) $db->insertID();

                foreach ($def['roles'] as $roleSlug) {
                    if (isset($roleIds[$roleSlug])) {
                        $db->table('role_permissions')->insert([
                            'role_id'       => (int) $roleIds[$roleSlug],
                            'permission_id' => $permissionId,
                        ]);
                    }
                }
            }
        } catch (Throwable $e) {
            log_message('error', 'PermissionDefaults::ensure: {msg}', ['msg' => $e->getMessage()]);
        }
    }
}
