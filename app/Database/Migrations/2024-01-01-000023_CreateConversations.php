<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateConversations extends Migration
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
            'contact_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
            ],
            'last_message_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
                'null'       => true,
            ],
            'unread_count' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
                'default'    => 0,
            ],
            'assigned_to' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
                'null'       => true,
            ],
            'status' => [
                'type'       => 'ENUM',
                'constraint' => ['open', 'closed'],
                'default'    => 'open',
            ],
            'last_message_at' => [
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
        $this->forge->addUniqueKey('contact_id');
        $this->forge->addKey('last_message_id');
        $this->forge->addKey('assigned_to');
        $this->forge->addKey('status');
        $this->forge->addKey('last_message_at');
        $this->forge->addForeignKey('contact_id', 'contacts', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('last_message_id', 'messages', 'id', 'SET NULL', 'CASCADE');
        $this->forge->addForeignKey('assigned_to', 'users', 'id', 'SET NULL', 'CASCADE');
        $this->forge->createTable('conversations', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('conversations', true);
    }
}
