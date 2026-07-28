<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateWebhookLogs extends Migration
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
            'event_type' => [
                'type'       => 'VARCHAR',
                'constraint' => 100,
                'null'       => true,
            ],
            'payload' => [
                'type' => 'LONGTEXT',
                'null' => true,
            ],
            'headers' => [
                'type' => 'JSON',
                'null' => true,
            ],
            'signature_valid' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 0,
            ],
            'processed' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 0,
            ],
            'error_message' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addKey('event_type');
        $this->forge->addKey('processed');
        $this->forge->addKey('created_at');
        $this->forge->createTable('webhook_logs', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('webhook_logs', true);
    }
}
