<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Outgoing webhooks: the client's endpoints (WebhookEndpointModel) and the queue / log of calls
 * made to them (WebhookDeliveryModel).
 */
class CreateOutgoingWebhooks extends Migration
{
    public function up(): void
    {
        if (! $this->db->tableExists('webhook_endpoints')) {
            $this->forge->addField([
                'id'         => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
                'name'       => ['type' => 'VARCHAR', 'constraint' => 100],
                'url'        => ['type' => 'VARCHAR', 'constraint' => 500],
                'secret'     => ['type' => 'TEXT'],
                'events'     => ['type' => 'VARCHAR', 'constraint' => 255],
                'is_active'  => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
                'created_by' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
                'created_at' => ['type' => 'DATETIME', 'null' => true],
                'updated_at' => ['type' => 'DATETIME', 'null' => true],
            ]);
            $this->forge->addKey('id', true);
            $this->forge->createTable('webhook_endpoints', true, ['ENGINE' => 'InnoDB']);
        }

        if (! $this->db->tableExists('webhook_deliveries')) {
            $this->forge->addField([
                'id'               => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true, 'auto_increment' => true],
                'endpoint_id'      => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
                'event'            => ['type' => 'VARCHAR', 'constraint' => 50],
                'payload'          => ['type' => 'MEDIUMTEXT'],
                'status'           => ['type' => 'VARCHAR', 'constraint' => 10, 'default' => 'pending'],
                'attempts'         => ['type' => 'TINYINT', 'constraint' => 3, 'unsigned' => true, 'default' => 0],
                'next_attempt_at'  => ['type' => 'DATETIME', 'null' => true],
                'last_http_status' => ['type' => 'SMALLINT', 'constraint' => 5, 'unsigned' => true, 'null' => true],
                'last_error'       => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
                'created_at'       => ['type' => 'DATETIME', 'null' => true],
                'sent_at'          => ['type' => 'DATETIME', 'null' => true],
            ]);
            $this->forge->addKey('id', true);
            $this->forge->addKey(['status', 'next_attempt_at'], false, false, 'idx_webhook_deliveries_due');
            $this->forge->addKey(['endpoint_id', 'id'], false, false, 'idx_webhook_deliveries_endpoint');
            $this->forge->addKey('created_at', false, false, 'idx_webhook_deliveries_created');
            $this->forge->createTable('webhook_deliveries', true, ['ENGINE' => 'InnoDB']);
        }
    }

    public function down(): void
    {
        $this->forge->dropTable('webhook_deliveries', true);
        $this->forge->dropTable('webhook_endpoints', true);
    }
}
