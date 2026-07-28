<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateMessageQueue extends Migration
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
            'campaign_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
                'null'       => true,
            ],
            'contact_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
            ],
            'message_type' => [
                'type'       => 'VARCHAR',
                'constraint' => 50,
            ],
            'payload' => [
                'type' => 'JSON',
                'null' => true,
            ],
            'priority' => [
                'type'       => 'INT',
                'constraint' => 11,
                'default'    => 5,
            ],
            'status' => [
                'type'       => 'ENUM',
                'constraint' => ['pending', 'processing', 'sent', 'failed', 'cancelled'],
                'default'    => 'pending',
            ],
            'attempts' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
                'default'    => 0,
            ],
            'max_attempts' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
                'default'    => 3,
            ],
            'scheduled_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'processed_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'wa_message_id' => [
                'type'       => 'VARCHAR',
                'constraint' => 191,
                'null'       => true,
            ],
            'error_message' => [
                'type' => 'TEXT',
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
        $this->forge->addKey('campaign_id');
        $this->forge->addKey('contact_id');
        $this->forge->addKey('status');
        $this->forge->addKey('priority');
        $this->forge->addKey('scheduled_at');
        $this->forge->addForeignKey('campaign_id', 'campaigns', 'id', 'SET NULL', 'CASCADE');
        $this->forge->addForeignKey('contact_id', 'contacts', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('message_queue', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('message_queue', true);
    }
}
