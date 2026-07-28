<?php

declare(strict_types=1);

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        $now = date('Y-m-d H:i:s');

        $roles = [
            [
                'name'        => 'Super Admin',
                'slug'        => 'super-admin',
                'description' => 'Full system access with unrestricted privileges.',
                'created_at'  => $now,
                'updated_at'  => $now,
            ],
            [
                'name'        => 'Admin',
                'slug'        => 'admin',
                'description' => 'Administrative access to manage platform operations.',
                'created_at'  => $now,
                'updated_at'  => $now,
            ],
            [
                'name'        => 'Manager',
                'slug'        => 'manager',
                'description' => 'Manages campaigns, contacts, chat, and reports.',
                'created_at'  => $now,
                'updated_at'  => $now,
            ],
            [
                'name'        => 'Agent',
                'slug'        => 'agent',
                'description' => 'Handles chat conversations and views contacts/campaigns.',
                'created_at'  => $now,
                'updated_at'  => $now,
            ],
        ];

        foreach ($roles as $role) {
            $exists = $this->db->table('roles')->where('slug', $role['slug'])->countAllResults();
            if ($exists === 0) {
                $this->db->table('roles')->insert($role);
            }
        }
    }
}
