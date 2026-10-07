<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateCountriesTable extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => [
                'type'           => 'INT',
                'constraint'     => 11,
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'name' => [
                'type'       => 'VARCHAR',
                'constraint' => 100,
            ],
            'iso2' => [
                'type'       => 'VARCHAR',
                'constraint' => 2,
            ],
            'dial_code' => [
                'type'       => 'VARCHAR',
                'constraint' => 10,
            ],
            'min_digits' => [
                'type'       => 'TINYINT',
                'constraint' => 3,
                'unsigned'   => true,
                'default'    => 10,
            ],
            'max_digits' => [
                'type'       => 'TINYINT',
                'constraint' => 3,
                'unsigned'   => true,
                'default'    => 10,
            ],
            'phone_digits' => [
                'type'       => 'TINYINT',
                'constraint' => 3,
                'unsigned'   => true,
                'default'    => 10,
            ],
            'sort_order' => [
                'type'       => 'INT',
                'constraint' => 11,
                'default'    => 0,
            ],
            'is_active' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 1,
            ],
            'is_deleted' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 0,
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'updated_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addKey('iso2');
        $this->forge->addKey('dial_code');
        $this->forge->addKey(['is_active', 'is_deleted']);
        $this->forge->createTable('countries', true);

        // Seed initial countries with dial code and standard subscriber length
        $countries = [
            ['name' => 'India', 'iso2' => 'IN', 'dial_code' => '91', 'min_digits' => 10, 'max_digits' => 10, 'phone_digits' => 10, 'sort_order' => 1],
            ['name' => 'United States', 'iso2' => 'US', 'dial_code' => '1', 'min_digits' => 10, 'max_digits' => 10, 'phone_digits' => 10, 'sort_order' => 2],
            ['name' => 'United Kingdom', 'iso2' => 'GB', 'dial_code' => '44', 'min_digits' => 10, 'max_digits' => 11, 'phone_digits' => 10, 'sort_order' => 3],
            ['name' => 'United Arab Emirates', 'iso2' => 'AE', 'dial_code' => '971', 'min_digits' => 9, 'max_digits' => 9, 'phone_digits' => 9, 'sort_order' => 4],
            ['name' => 'Saudi Arabia', 'iso2' => 'SA', 'dial_code' => '966', 'min_digits' => 9, 'max_digits' => 9, 'phone_digits' => 9, 'sort_order' => 5],
            ['name' => 'Canada', 'iso2' => 'CA', 'dial_code' => '1', 'min_digits' => 10, 'max_digits' => 10, 'phone_digits' => 10, 'sort_order' => 6],
            ['name' => 'Australia', 'iso2' => 'AU', 'dial_code' => '61', 'min_digits' => 9, 'max_digits' => 9, 'phone_digits' => 9, 'sort_order' => 7],
            ['name' => 'Singapore', 'iso2' => 'SG', 'dial_code' => '65', 'min_digits' => 8, 'max_digits' => 8, 'phone_digits' => 8, 'sort_order' => 8],
            ['name' => 'Qatar', 'iso2' => 'QA', 'dial_code' => '974', 'min_digits' => 8, 'max_digits' => 8, 'phone_digits' => 8, 'sort_order' => 9],
            ['name' => 'Kuwait', 'iso2' => 'KW', 'dial_code' => '965', 'min_digits' => 8, 'max_digits' => 8, 'phone_digits' => 8, 'sort_order' => 10],
            ['name' => 'Oman', 'iso2' => 'OM', 'dial_code' => '968', 'min_digits' => 8, 'max_digits' => 8, 'phone_digits' => 8, 'sort_order' => 11],
            ['name' => 'Bahrain', 'iso2' => 'BH', 'dial_code' => '973', 'min_digits' => 8, 'max_digits' => 8, 'phone_digits' => 8, 'sort_order' => 12],
            ['name' => 'Malaysia', 'iso2' => 'MY', 'dial_code' => '60', 'min_digits' => 9, 'max_digits' => 10, 'phone_digits' => 10, 'sort_order' => 13],
            ['name' => 'Germany', 'iso2' => 'DE', 'dial_code' => '49', 'min_digits' => 10, 'max_digits' => 11, 'phone_digits' => 10, 'sort_order' => 14],
            ['name' => 'France', 'iso2' => 'FR', 'dial_code' => '33', 'min_digits' => 9, 'max_digits' => 9, 'phone_digits' => 9, 'sort_order' => 15],
            ['name' => 'Italy', 'iso2' => 'IT', 'dial_code' => '39', 'min_digits' => 10, 'max_digits' => 10, 'phone_digits' => 10, 'sort_order' => 16],
            ['name' => 'Bangladesh', 'iso2' => 'BD', 'dial_code' => '880', 'min_digits' => 10, 'max_digits' => 10, 'phone_digits' => 10, 'sort_order' => 17],
            ['name' => 'Pakistan', 'iso2' => 'PK', 'dial_code' => '92', 'min_digits' => 10, 'max_digits' => 10, 'phone_digits' => 10, 'sort_order' => 18],
            ['name' => 'Sri Lanka', 'iso2' => 'LK', 'dial_code' => '94', 'min_digits' => 9, 'max_digits' => 9, 'phone_digits' => 9, 'sort_order' => 19],
            ['name' => 'Nepal', 'iso2' => 'NP', 'dial_code' => '977', 'min_digits' => 10, 'max_digits' => 10, 'phone_digits' => 10, 'sort_order' => 20],
            ['name' => 'South Africa', 'iso2' => 'ZA', 'dial_code' => '27', 'min_digits' => 9, 'max_digits' => 9, 'phone_digits' => 9, 'sort_order' => 21],
            ['name' => 'Philippines', 'iso2' => 'PH', 'dial_code' => '63', 'min_digits' => 10, 'max_digits' => 10, 'phone_digits' => 10, 'sort_order' => 22],
            ['name' => 'Indonesia', 'iso2' => 'ID', 'dial_code' => '62', 'min_digits' => 9, 'max_digits' => 12, 'phone_digits' => 10, 'sort_order' => 23],
            ['name' => 'New Zealand', 'iso2' => 'NZ', 'dial_code' => '64', 'min_digits' => 8, 'max_digits' => 10, 'phone_digits' => 9, 'sort_order' => 24],
            ['name' => 'China', 'iso2' => 'CN', 'dial_code' => '86', 'min_digits' => 11, 'max_digits' => 11, 'phone_digits' => 11, 'sort_order' => 25],
            ['name' => 'Japan', 'iso2' => 'JP', 'dial_code' => '81', 'min_digits' => 10, 'max_digits' => 10, 'phone_digits' => 10, 'sort_order' => 26],
            ['name' => 'Brazil', 'iso2' => 'BR', 'dial_code' => '55', 'min_digits' => 10, 'max_digits' => 11, 'phone_digits' => 11, 'sort_order' => 27],
            ['name' => 'Spain', 'iso2' => 'ES', 'dial_code' => '34', 'min_digits' => 9, 'max_digits' => 9, 'phone_digits' => 9, 'sort_order' => 28],
            ['name' => 'Netherlands', 'iso2' => 'NL', 'dial_code' => '31', 'min_digits' => 9, 'max_digits' => 9, 'phone_digits' => 9, 'sort_order' => 29],
            ['name' => 'Switzerland', 'iso2' => 'CH', 'dial_code' => '41', 'min_digits' => 9, 'max_digits' => 9, 'phone_digits' => 9, 'sort_order' => 30],
            ['name' => 'Nigeria', 'iso2' => 'NG', 'dial_code' => '234', 'min_digits' => 10, 'max_digits' => 10, 'phone_digits' => 10, 'sort_order' => 31],
            ['name' => 'Kenya', 'iso2' => 'KE', 'dial_code' => '254', 'min_digits' => 9, 'max_digits' => 9, 'phone_digits' => 9, 'sort_order' => 32],
            ['name' => 'Egypt', 'iso2' => 'EG', 'dial_code' => '20', 'min_digits' => 10, 'max_digits' => 10, 'phone_digits' => 10, 'sort_order' => 33],
            ['name' => 'Turkey', 'iso2' => 'TR', 'dial_code' => '90', 'min_digits' => 10, 'max_digits' => 10, 'phone_digits' => 10, 'sort_order' => 34],
            ['name' => 'Thailand', 'iso2' => 'TH', 'dial_code' => '66', 'min_digits' => 9, 'max_digits' => 9, 'phone_digits' => 9, 'sort_order' => 35],
            ['name' => 'Vietnam', 'iso2' => 'VN', 'dial_code' => '84', 'min_digits' => 9, 'max_digits' => 9, 'phone_digits' => 9, 'sort_order' => 36],
            ['name' => 'Russia', 'iso2' => 'RU', 'dial_code' => '7', 'min_digits' => 10, 'max_digits' => 10, 'phone_digits' => 10, 'sort_order' => 37],
            ['name' => 'Ireland', 'iso2' => 'IE', 'dial_code' => '353', 'min_digits' => 9, 'max_digits' => 9, 'phone_digits' => 9, 'sort_order' => 38],
            ['name' => 'Hong Kong', 'iso2' => 'HK', 'dial_code' => '852', 'min_digits' => 8, 'max_digits' => 8, 'phone_digits' => 8, 'sort_order' => 39],
            ['name' => 'Sweden', 'iso2' => 'SE', 'dial_code' => '46', 'min_digits' => 9, 'max_digits' => 9, 'phone_digits' => 9, 'sort_order' => 40],
        ];

        $now = date('Y-m-d H:i:s');
        foreach ($countries as &$c) {
            $c['is_active']  = 1;
            $c['is_deleted'] = 0;
            $c['created_at'] = $now;
            $c['updated_at'] = $now;
        }
        unset($c);

        $db = \Config\Database::connect();
        if ($db->table('countries')->countAllResults() > 0) {
            return;
        }
        $db->table('countries')->insertBatch($countries);
    }

    public function down(): void
    {
        $this->forge->dropTable('countries', true);
    }
}
