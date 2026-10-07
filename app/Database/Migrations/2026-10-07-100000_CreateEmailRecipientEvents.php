<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Per-recipient email engagement / deliverability events
 * (open, click, delivery, bounce, complaint).
 */
class CreateEmailRecipientEvents extends Migration
{
    public function up(): void
    {
        if ($this->db->tableExists('email_recipient_events')) {
            return;
        }

        $this->forge->addField([
            'id' => [
                'type'           => 'INT',
                'constraint'     => 11,
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'log_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
                'default'    => 0,
            ],
            'campaign_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
                'null'       => true,
            ],
            'email' => [
                'type'       => 'VARCHAR',
                'constraint' => 191,
            ],
            'event_type' => [
                'type'       => 'VARCHAR',
                'constraint' => 20,
            ],
            'detail' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
            ],
            'event_count' => [
                'type'       => 'INT',
                'constraint' => 10,
                'unsigned'   => true,
                'default'    => 1,
            ],
            'first_at'   => ['type' => 'DATETIME', 'null' => true],
            'last_at'    => ['type' => 'DATETIME', 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['log_id', 'email', 'event_type'], 'uniq_log_email_event');
        $this->forge->addKey('campaign_id');
        $this->forge->addKey(['email', 'event_type']);
        $this->forge->createTable('email_recipient_events', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('email_recipient_events', true);
    }
}
