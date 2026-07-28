<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateMessages extends Migration
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
            'campaign_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
                'null'       => true,
            ],
            'direction' => [
                'type'       => 'ENUM',
                'constraint' => ['inbound', 'outbound'],
            ],
            'message_type' => [
                'type'       => 'VARCHAR',
                'constraint' => 50,
                'default'    => 'text',
            ],
            'wa_message_id' => [
                'type'       => 'VARCHAR',
                'constraint' => 191,
                'null'       => true,
            ],
            'wamid' => [
                'type'       => 'VARCHAR',
                'constraint' => 191,
                'null'       => true,
            ],
            'content' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'media_url' => [
                'type'       => 'VARCHAR',
                'constraint' => 500,
                'null'       => true,
            ],
            'media_id' => [
                'type'       => 'VARCHAR',
                'constraint' => 191,
                'null'       => true,
            ],
            'payload' => [
                'type' => 'JSON',
                'null' => true,
            ],
            'status' => [
                'type'       => 'ENUM',
                'constraint' => ['pending', 'sent', 'delivered', 'read', 'failed', 'received'],
                'default'    => 'pending',
            ],
            'error_code' => [
                'type'       => 'VARCHAR',
                'constraint' => 50,
                'null'       => true,
            ],
            'error_message' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'is_read' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 0,
            ],
            'conversation_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
                'null'       => true,
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
        $this->forge->addKey('contact_id');
        $this->forge->addKey('campaign_id');
        $this->forge->addKey('direction');
        $this->forge->addKey('wa_message_id');
        $this->forge->addKey('wamid');
        $this->forge->addKey('status');
        $this->forge->addKey('conversation_id');
        $this->forge->addKey('created_at');
        $this->forge->addForeignKey('contact_id', 'contacts', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('campaign_id', 'campaigns', 'id', 'SET NULL', 'CASCADE');
        $this->forge->createTable('messages', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('messages', true);
    }
}
