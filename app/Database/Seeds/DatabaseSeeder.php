<?php

declare(strict_types=1);

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(RoleSeeder::class);
        $this->call(PermissionSeeder::class);
        $this->call(SettingSeeder::class);
        $this->call(KeywordSeeder::class);
        $this->call(ConversationSeeder::class);
    }
}
