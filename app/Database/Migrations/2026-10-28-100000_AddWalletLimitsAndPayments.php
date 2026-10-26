<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Spending limits and the low-balance top-up amount on `wallets`, and `wallet_payments` (online recharges).
 */
class AddWalletLimitsAndPayments extends Migration
{
    public function up(): void
    {
        if ($this->db->tableExists('wallets')) {
            $add = [];
            foreach (['daily_cap', 'monthly_cap', 'auto_topup_amount'] as $col) {
                if (! $this->db->fieldExists($col, 'wallets')) {
                    $add[$col] = ['type' => 'DECIMAL', 'constraint' => '14,2', 'default' => 0];
                }
            }
            if ($add !== []) {
                $this->forge->addColumn('wallets', $add);
            }
        }

        if (! $this->db->tableExists('wallet_payments')) {
            $this->forge->addField([
                'id'            => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true, 'auto_increment' => true],
                'order_id'      => ['type' => 'VARCHAR', 'constraint' => 64],
                'payment_id'    => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
                'source'        => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'checkout'], // checkout | link
                'status'        => ['type' => 'VARCHAR', 'constraint' => 12, 'default' => 'created'],  // created | paid | failed
                'currency'      => ['type' => 'VARCHAR', 'constraint' => 3, 'default' => 'INR'],
                'credit_amount' => ['type' => 'DECIMAL', 'constraint' => '14,2', 'default' => 0],
                'tax_percent'   => ['type' => 'DECIMAL', 'constraint' => '5,2', 'default' => 0],
                'tax_amount'    => ['type' => 'DECIMAL', 'constraint' => '14,2', 'default' => 0],
                'total_amount'  => ['type' => 'DECIMAL', 'constraint' => '14,2', 'default' => 0],
                'invoice_no'    => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true],
                'created_by'    => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
                'paid_at'       => ['type' => 'DATETIME', 'null' => true],
                'created_at'    => ['type' => 'DATETIME', 'null' => true],
                'updated_at'    => ['type' => 'DATETIME', 'null' => true],
            ]);
            $this->forge->addKey('id', true);
            $this->forge->addUniqueKey('order_id', 'uniq_wallet_payments_order');
            $this->forge->addKey('payment_id');
            $this->forge->addKey(['status', 'created_at']);
            $this->forge->createTable('wallet_payments', true, ['ENGINE' => 'InnoDB']);
        }
    }

    public function down(): void
    {
        $this->forge->dropTable('wallet_payments', true);
        foreach (['daily_cap', 'monthly_cap', 'auto_topup_amount'] as $col) {
            if ($this->db->fieldExists($col, 'wallets')) {
                $this->forge->dropColumn('wallets', $col);
            }
        }
    }
}
