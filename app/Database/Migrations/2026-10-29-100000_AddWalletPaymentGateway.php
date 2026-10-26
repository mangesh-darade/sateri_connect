<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Which payment gateway took an online recharge, and its own references (Mswipe: TransID and IPG id).
 */
class AddWalletPaymentGateway extends Migration
{
    public function up(): void
    {
        if (! $this->db->tableExists('wallet_payments')) {
            return;
        }
        $add = [];
        if (! $this->db->fieldExists('gateway', 'wallet_payments')) {
            $add['gateway'] = ['type' => 'VARCHAR', 'constraint' => 16, 'default' => 'razorpay', 'after' => 'order_id'];
        }
        if (! $this->db->fieldExists('gateway_ref', 'wallet_payments')) {
            $add['gateway_ref'] = ['type' => 'VARCHAR', 'constraint' => 191, 'null' => true, 'after' => 'gateway'];
        }
        if (! $this->db->fieldExists('gateway_txn', 'wallet_payments')) {
            $add['gateway_txn'] = ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true, 'after' => 'gateway_ref'];
        }
        if ($add !== []) {
            $this->forge->addColumn('wallet_payments', $add);
        }
    }

    public function down(): void
    {
        foreach (['gateway', 'gateway_ref', 'gateway_txn'] as $col) {
            if ($this->db->tableExists('wallet_payments') && $this->db->fieldExists($col, 'wallet_payments')) {
                $this->forge->dropColumn('wallet_payments', $col);
            }
        }
    }
}
