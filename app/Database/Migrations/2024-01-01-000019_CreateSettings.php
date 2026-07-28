<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateSettings extends Migration
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
            'key' => [
                'type'       => 'VARCHAR',
                'constraint' => 191,
            ],
            'value' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'group' => [
                'type'       => 'VARCHAR',
                'constraint' => 100,
                'default'    => 'general',
            ],
            'is_encrypted' => [
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
        $this->forge->addUniqueKey('key');
        $this->forge->addKey('group');
        $this->forge->createTable('settings', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('settings', true);
    }
}
