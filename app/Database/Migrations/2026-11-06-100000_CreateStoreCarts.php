<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Abandoned cart recovery: one row per WooCommerce unpaid order or Shopify checkout that arrived through
 * the store webhook (StoreCartModel). `status` goes open -> reminded -> recovered, or skipped.
 */
class CreateStoreCarts extends Migration
{
    public function up(): void
    {
        if ($this->db->tableExists('store_carts')) {
            return;
        }

        $this->forge->addField([
            'id'               => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true, 'auto_increment' => true],
            'platform'         => ['type' => 'VARCHAR', 'constraint' => 20],
            'external_id'      => ['type' => 'VARCHAR', 'constraint' => 80],
            'phone'            => ['type' => 'VARCHAR', 'constraint' => 32, 'default' => ''],
            'customer_name'    => ['type' => 'VARCHAR', 'constraint' => 120, 'default' => ''],
            'items_text'       => ['type' => 'VARCHAR', 'constraint' => 500, 'default' => ''],
            'total'            => ['type' => 'VARCHAR', 'constraint' => 40, 'default' => ''],
            'amount'           => ['type' => 'DECIMAL', 'constraint' => '12,2', 'default' => '0.00'],
            'checkout_url'     => ['type' => 'VARCHAR', 'constraint' => 1000, 'default' => ''],
            'status'           => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'open'],
            'reminders_sent'   => ['type' => 'TINYINT', 'constraint' => 3, 'unsigned' => true, 'default' => 0],
            'queue_id'         => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'last_error'       => ['type' => 'VARCHAR', 'constraint' => 255, 'default' => ''],
            'last_activity_at' => ['type' => 'DATETIME', 'null' => true],
            'last_reminded_at' => ['type' => 'DATETIME', 'null' => true],
            'recovered_at'     => ['type' => 'DATETIME', 'null' => true],
            'created_at'       => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['platform', 'external_id'], 'uq_store_cart_ext');
        $this->forge->addKey(['status', 'last_activity_at'], false, false, 'idx_store_cart_due');
        $this->forge->addKey(['phone', 'status'], false, false, 'idx_store_cart_phone');
        $this->forge->createTable('store_carts', true, ['ENGINE' => 'InnoDB']);
    }

    public function down(): void
    {
        $this->forge->dropTable('store_carts', true);
    }
}
