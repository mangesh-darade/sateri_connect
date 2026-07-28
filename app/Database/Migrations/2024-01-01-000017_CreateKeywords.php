<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateKeywords extends Migration
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
            'keyword' => [
                'type'       => 'VARCHAR',
                'constraint' => 191,
            ],
            'match_type' => [
                'type'       => 'ENUM',
                'constraint' => ['exact', 'contains', 'starts_with'],
                'default'    => 'exact',
            ],
            'response_type' => [
                'type'       => 'VARCHAR',
                'constraint' => 50,
                'default'    => 'text',
            ],
            'response_content' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'response_payload' => [
                'type' => 'JSON',
                'null' => true,
            ],
            'parent_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
                'null'       => true,
            ],
            'menu_order' => [
                'type'       => 'INT',
                'constraint' => 11,
                'default'    => 0,
            ],
            'is_active' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 1,
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
        $this->forge->addKey('keyword');
        $this->forge->addKey('match_type');
        $this->forge->addKey('parent_id');
        $this->forge->addKey('is_active');
        $this->forge->addForeignKey('parent_id', 'keywords', 'id', 'SET NULL', 'CASCADE');
        $this->forge->createTable('keywords', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('keywords', true);
    }
}
