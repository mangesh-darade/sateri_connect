<?php

declare(strict_types=1);

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

class KeywordSeeder extends Seeder
{
    public function run(): void
    {
        $now = date('Y-m-d H:i:s');

        $welcomePayload = json_encode([
            'type' => 'interactive',
            'interactive' => [
                'type' => 'list',
                'body' => [
                    'text' => "Welcome! Please choose an option:\n1. Products\n2. Support",
                ],
                'action' => [
                    'button' => 'Menu',
                    'sections' => [
                        [
                            'title' => 'Main Menu',
                            'rows'  => [
                                ['id' => '1', 'title' => 'Products', 'description' => 'Browse our products'],
                                ['id' => '2', 'title' => 'Support', 'description' => 'Get help from our team'],
                            ],
                        ],
                    ],
                ],
            ],
        ], JSON_UNESCAPED_UNICODE);

        if ($this->db->table('keywords')->countAllResults() > 0) {
            return;
        }

        $this->db->table('keywords')->insert([
            'keyword'          => 'Hi',
            'match_type'       => 'exact',
            'response_type'    => 'interactive',
            'response_content' => "Welcome! Please choose an option:\n1. Products\n2. Support",
            'response_payload' => $welcomePayload,
            'parent_id'        => null,
            'menu_order'       => 0,
            'is_active'        => 1,
            'created_at'       => $now,
            'updated_at'       => $now,
        ]);

        $welcomeId = (int) $this->db->insertID();

        $this->db->table('keywords')->insertBatch([
            [
                'keyword'          => '1',
                'match_type'       => 'exact',
                'response_type'    => 'text',
                'response_content' => 'Here are our products. Reply with the product name for more details, or type Hi to return to the main menu.',
                'response_payload' => null,
                'parent_id'        => $welcomeId,
                'menu_order'       => 1,
                'is_active'        => 1,
                'created_at'       => $now,
                'updated_at'       => $now,
            ],
            [
                'keyword'          => '2',
                'match_type'       => 'exact',
                'response_type'    => 'text',
                'response_content' => 'You have reached support. Please describe your issue and an agent will assist you shortly. Type Hi to return to the main menu.',
                'response_payload' => null,
                'parent_id'        => $welcomeId,
                'menu_order'       => 2,
                'is_active'        => 1,
                'created_at'       => $now,
                'updated_at'       => $now,
            ],
        ]);
    }
}
