<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Razorpay payment links an agent sent to a customer from the inbox (PaymentLinkModel).
 * These use the client's own Razorpay account, not the platform wallet account.
 */
class CreatePaymentLinks extends Migration
{
    public function up(): void
    {
        if ($this->db->tableExists('payment_links')) {
            return;
        }

        $this->forge->addField([
            'id'          => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true, 'auto_increment' => true],
            'contact_id'  => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'link_id'     => ['type' => 'VARCHAR', 'constraint' => 64],
            'short_url'   => ['type' => 'VARCHAR', 'constraint' => 255],
            'amount'      => ['type' => 'DECIMAL', 'constraint' => '14,2', 'default' => '0.00'],
            'currency'    => ['type' => 'VARCHAR', 'constraint' => 3, 'default' => 'INR'],
            'description' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'status'      => ['type' => 'VARCHAR', 'constraint' => 12, 'default' => 'created'],
            'payment_id'  => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            'created_by'  => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'paid_at'     => ['type' => 'DATETIME', 'null' => true],
            'created_at'  => ['type' => 'DATETIME', 'null' => true],
            'updated_at'  => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('link_id', 'uniq_payment_links_link');
        $this->forge->addKey(['contact_id', 'created_at'], false, false, 'idx_payment_links_contact');
        $this->forge->addKey(['status', 'created_at'], false, false, 'idx_payment_links_status');
        $this->forge->createTable('payment_links', true, ['ENGINE' => 'InnoDB']);
    }

    public function down(): void
    {
        $this->forge->dropTable('payment_links', true);
    }
}
