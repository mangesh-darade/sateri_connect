<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateTemplates extends Migration
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
            'meta_id' => [
                'type'       => 'VARCHAR',
                'constraint' => 100,
                'null'       => true,
            ],
            'name' => [
                'type'       => 'VARCHAR',
                'constraint' => 191,
            ],
            'language' => [
                'type'       => 'VARCHAR',
                'constraint' => 20,
                'default'    => 'en',
            ],
            'category' => [
                'type'       => 'VARCHAR',
                'constraint' => 50,
                'null'       => true,
            ],
            'status' => [
                'type'       => 'VARCHAR',
                'constraint' => 50,
                'default'    => 'PENDING',
            ],
            'header_type' => [
                'type'       => 'VARCHAR',
                'constraint' => 30,
                'null'       => true,
            ],
            'header_content' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'body' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'footer' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'buttons' => [
                'type' => 'JSON',
                'null' => true,
            ],
            'variables' => [
                'type' => 'JSON',
                'null' => true,
            ],
            'raw_payload' => [
                'type' => 'JSON',
                'null' => true,
            ],
            'synced_at' => [
                'type' => 'DATETIME',
                'null' => true,
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
        $this->forge->addKey('meta_id');
        $this->forge->addKey('name');
        $this->forge->addKey('status');
        $this->forge->createTable('templates', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('templates', true);
    }
}
