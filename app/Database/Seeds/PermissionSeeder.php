<?php

declare(strict_types=1);

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        $now = date('Y-m-d H:i:s');

        $permissions = [
            // Dashboard
            ['name' => 'View Dashboard', 'slug' => 'dashboard.view', 'module' => 'dashboard', 'description' => 'Access the main dashboard'],
            // Contacts
            ['name' => 'View Contacts', 'slug' => 'contacts.view', 'module' => 'contacts', 'description' => 'View contact list and details'],
            ['name' => 'Create Contacts', 'slug' => 'contacts.create', 'module' => 'contacts', 'description' => 'Create new contacts'],
            ['name' => 'Edit Contacts', 'slug' => 'contacts.edit', 'module' => 'contacts', 'description' => 'Edit existing contacts'],
            ['name' => 'Delete Contacts', 'slug' => 'contacts.delete', 'module' => 'contacts', 'description' => 'Delete contacts'],
            ['name' => 'Import Contacts', 'slug' => 'contacts.import', 'module' => 'contacts', 'description' => 'Import contacts from CSV/Excel'],
            ['name' => 'Export Contacts', 'slug' => 'contacts.export', 'module' => 'contacts', 'description' => 'Export contacts'],
            // Campaigns
            ['name' => 'View Campaigns', 'slug' => 'campaigns.view', 'module' => 'campaigns', 'description' => 'View campaigns'],
            ['name' => 'Create Campaigns', 'slug' => 'campaigns.create', 'module' => 'campaigns', 'description' => 'Create new campaigns'],
            ['name' => 'Edit Campaigns', 'slug' => 'campaigns.edit', 'module' => 'campaigns', 'description' => 'Edit campaigns'],
            ['name' => 'Delete Campaigns', 'slug' => 'campaigns.delete', 'module' => 'campaigns', 'description' => 'Delete campaigns'],
            ['name' => 'Start Campaigns', 'slug' => 'campaigns.start', 'module' => 'campaigns', 'description' => 'Start, pause, or cancel campaigns'],
            // Emails
            ['name' => 'View Emails', 'slug' => 'emails.view', 'module' => 'emails', 'description' => 'Access Email Manager (builder, drips, verifier, campaigns, senders)'],
            ['name' => 'Send Emails', 'slug' => 'emails.send', 'module' => 'emails', 'description' => 'Manage and send email builder/drips/HTML campaigns'],
            // Templates
            ['name' => 'View Templates', 'slug' => 'templates.view', 'module' => 'templates', 'description' => 'View message templates'],
            ['name' => 'Create Templates', 'slug' => 'templates.create', 'module' => 'templates', 'description' => 'Create templates'],
            ['name' => 'Edit Templates', 'slug' => 'templates.edit', 'module' => 'templates', 'description' => 'Edit templates'],
            ['name' => 'Delete Templates', 'slug' => 'templates.delete', 'module' => 'templates', 'description' => 'Delete templates'],
            ['name' => 'Sync Templates', 'slug' => 'templates.sync', 'module' => 'templates', 'description' => 'Sync templates from Cheerio'],
            // Chat
            ['name' => 'View Chat', 'slug' => 'chat.view', 'module' => 'chat', 'description' => 'Access inbox and conversations'],
            ['name' => 'Send Messages', 'slug' => 'chat.send', 'module' => 'chat', 'description' => 'Send chat messages'],
            ['name' => 'Assign Conversations', 'slug' => 'chat.assign', 'module' => 'chat', 'description' => 'Assign conversations to agents'],
            ['name' => 'Close Conversations', 'slug' => 'chat.close', 'module' => 'chat', 'description' => 'Close conversations'],
            // Automations
            ['name' => 'View Automations', 'slug' => 'automations.view', 'module' => 'automations', 'description' => 'View automations'],
            ['name' => 'Create Automations', 'slug' => 'automations.create', 'module' => 'automations', 'description' => 'Create automations'],
            ['name' => 'Edit Automations', 'slug' => 'automations.edit', 'module' => 'automations', 'description' => 'Edit automations'],
            ['name' => 'Delete Automations', 'slug' => 'automations.delete', 'module' => 'automations', 'description' => 'Delete automations'],
            // Keywords
            ['name' => 'View Keywords', 'slug' => 'keywords.view', 'module' => 'keywords', 'description' => 'View keyword replies'],
            ['name' => 'Create Keywords', 'slug' => 'keywords.create', 'module' => 'keywords', 'description' => 'Create keyword replies'],
            ['name' => 'Edit Keywords', 'slug' => 'keywords.edit', 'module' => 'keywords', 'description' => 'Edit keyword replies'],
            ['name' => 'Delete Keywords', 'slug' => 'keywords.delete', 'module' => 'keywords', 'description' => 'Delete keyword replies'],
            // Reports
            ['name' => 'View Reports', 'slug' => 'reports.view', 'module' => 'reports', 'description' => 'View reports and analytics'],
            ['name' => 'Export Reports', 'slug' => 'reports.export', 'module' => 'reports', 'description' => 'Export reports'],
            // Settings
            ['name' => 'View Settings', 'slug' => 'settings.view', 'module' => 'settings', 'description' => 'View application settings'],
            ['name' => 'Edit Settings', 'slug' => 'settings.edit', 'module' => 'settings', 'description' => 'Update application settings'],
            // Users
            ['name' => 'View Users', 'slug' => 'users.view', 'module' => 'users', 'description' => 'View users'],
            ['name' => 'Create Users', 'slug' => 'users.create', 'module' => 'users', 'description' => 'Create users'],
            ['name' => 'Edit Users', 'slug' => 'users.edit', 'module' => 'users', 'description' => 'Edit users'],
            ['name' => 'Delete Users', 'slug' => 'users.delete', 'module' => 'users', 'description' => 'Delete users'],
            // Roles
            ['name' => 'View Roles', 'slug' => 'roles.view', 'module' => 'roles', 'description' => 'View roles and permissions'],
            ['name' => 'Create Roles', 'slug' => 'roles.create', 'module' => 'roles', 'description' => 'Create roles'],
            ['name' => 'Edit Roles', 'slug' => 'roles.edit', 'module' => 'roles', 'description' => 'Edit roles and assign permissions'],
            ['name' => 'Delete Roles', 'slug' => 'roles.delete', 'module' => 'roles', 'description' => 'Delete roles'],
            // Queue
            ['name' => 'View Queue', 'slug' => 'queue.view', 'module' => 'queue', 'description' => 'View message queue'],
            ['name' => 'Manage Queue', 'slug' => 'queue.manage', 'module' => 'queue', 'description' => 'Retry, cancel, or clear queue items'],
        ];

        $rows = [];
        foreach ($permissions as $permission) {
            $exists = $this->db->table('permissions')->where('slug', $permission['slug'])->countAllResults();
            if ($exists > 0) {
                continue;
            }
            $rows[] = array_merge($permission, [
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        if ($rows !== []) {
            $this->db->table('permissions')->insertBatch($rows);
        }

        $allPermissions = $this->db->table('permissions')->get()->getResultArray();
        $permissionIds  = array_column($allPermissions, 'id', 'slug');

        $roles = $this->db->table('roles')->get()->getResultArray();
        $roleIds = array_column($roles, 'id', 'slug');

        $managerSlugs = [
            'dashboard.view',
            'contacts.view', 'contacts.create', 'contacts.edit', 'contacts.delete', 'contacts.import', 'contacts.export',
            'campaigns.view', 'campaigns.create', 'campaigns.edit', 'campaigns.delete', 'campaigns.start',
            'emails.view', 'emails.send',
            'templates.view', 'templates.create', 'templates.edit', 'templates.delete', 'templates.sync',
            'chat.view', 'chat.send', 'chat.assign', 'chat.close',
            'automations.view', 'automations.create', 'automations.edit', 'automations.delete',
            'keywords.view', 'keywords.create', 'keywords.edit', 'keywords.delete',
            'reports.view', 'reports.export',
            'settings.view',
            'queue.view', 'queue.manage',
        ];

        $agentSlugs = [
            'dashboard.view',
            'contacts.view',
            'campaigns.view',
            'emails.view',
            'chat.view', 'chat.send', 'chat.close',
            'templates.view',
            'keywords.view',
            'reports.view',
        ];

        $assignments = [];

        foreach (['super-admin', 'admin'] as $roleSlug) {
            if (! isset($roleIds[$roleSlug])) {
                continue;
            }

            foreach ($permissionIds as $permissionId) {
                $assignments[] = [
                    'role_id'       => $roleIds[$roleSlug],
                    'permission_id' => $permissionId,
                ];
            }
        }

        if (isset($roleIds['manager'])) {
            foreach ($managerSlugs as $slug) {
                if (isset($permissionIds[$slug])) {
                    $assignments[] = [
                        'role_id'       => $roleIds['manager'],
                        'permission_id' => $permissionIds[$slug],
                    ];
                }
            }
        }

        if (isset($roleIds['agent'])) {
            foreach ($agentSlugs as $slug) {
                if (isset($permissionIds[$slug])) {
                    $assignments[] = [
                        'role_id'       => $roleIds['agent'],
                        'permission_id' => $permissionIds[$slug],
                    ];
                }
            }
        }

        if ($assignments !== []) {
            // Re-sync safely so installer re-runs don't duplicate pivot rows.
            $this->db->table('role_permissions')->truncate();
            $this->db->table('role_permissions')->insertBatch($assignments);
        }
    }
}
