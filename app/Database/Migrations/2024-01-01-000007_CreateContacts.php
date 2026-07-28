<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateContacts extends Migration
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
                'constraint' => 150,
                'null'       => true,
            ],
            'mobile' => [
                'type'       => 'VARCHAR',
                'constraint' => 30,
            ],
            'country' => [
                'type'       => 'VARCHAR',
                'constraint' => 100,
                'null'       => true,
            ],
            'email' => [
                'type'       => 'VARCHAR',
                'constraint' => 191,
                'null'       => true,
            ],
            'notes' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'status' => [
                'type'       => 'ENUM',
                'constraint' => ['active', 'inactive', 'blocked'],
                'default'    => 'active',
            ],
            'last_message_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'last_reply_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'assigned_to' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
                'null'       => true,
            ],
            'birthday' => [
                'type' => 'DATE',
                'null' => true,
            ],
            'custom_fields' => [
                'type' => 'JSON',
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
            'deleted_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('mobile');
        $this->forge->addKey('status');
        $this->forge->addKey('assigned_to');
        $this->forge->addKey('last_message_at');
        $this->forge->addKey('deleted_at');
        $this->forge->addForeignKey('assigned_to', 'users', 'id', 'SET NULL', 'CASCADE');
        $this->forge->createTable('contacts', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('contacts', true);
    }
}
