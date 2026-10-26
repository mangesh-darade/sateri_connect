<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Platform margin: `wallets.markup_percent` (added on the Meta price) and, per message debit,
 * `wallet_transactions.base_amount` (Meta cost) and `tax_amount` (GST) so margin can be reported.
 */
class AddWalletMarginColumns extends Migration
{
    public function up(): void
    {
        if ($this->db->tableExists('wallets') && ! $this->db->fieldExists('markup_percent', 'wallets')) {
            $this->forge->addColumn('wallets', [
                'markup_percent' => ['type' => 'DECIMAL', 'constraint' => '6,2', 'default' => 0, 'after' => 'tax_percent'],
            ]);
        }

        if ($this->db->tableExists('wallet_transactions')) {
            $add = [];
            if (! $this->db->fieldExists('base_amount', 'wallet_transactions')) {
                $add['base_amount'] = ['type' => 'DECIMAL', 'constraint' => '14,4', 'null' => true, 'after' => 'description'];
            }
            if (! $this->db->fieldExists('tax_amount', 'wallet_transactions')) {
                $add['tax_amount'] = ['type' => 'DECIMAL', 'constraint' => '14,4', 'null' => true, 'after' => 'base_amount'];
            }
            if ($add !== []) {
                $this->forge->addColumn('wallet_transactions', $add);
            }
        }
    }

    public function down(): void
    {
        if ($this->db->fieldExists('markup_percent', 'wallets')) {
            $this->forge->dropColumn('wallets', 'markup_percent');
        }
        foreach (['base_amount', 'tax_amount'] as $col) {
            if ($this->db->fieldExists($col, 'wallet_transactions')) {
                $this->forge->dropColumn('wallet_transactions', $col);
            }
        }
    }
}
