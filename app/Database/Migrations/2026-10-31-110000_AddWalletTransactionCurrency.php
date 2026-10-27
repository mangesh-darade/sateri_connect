<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Each ledger row records the currency it was saved in. A wallet can move to another currency (its country's rate
 * row), and without this old amounts would silently change meaning. NULL = saved before this column existed; those
 * rows are stamped with the old currency the first time the wallet is converted.
 */
class AddWalletTransactionCurrency extends Migration
{
    public function up(): void
    {
        if ($this->db->tableExists('wallet_transactions') && ! $this->db->fieldExists('currency', 'wallet_transactions')) {
            $this->forge->addColumn('wallet_transactions', [
                'currency' => ['type' => 'VARCHAR', 'constraint' => 3, 'null' => true, 'after' => 'amount'],
            ]);
        }
    }

    public function down(): void
    {
        if ($this->db->tableExists('wallet_transactions') && $this->db->fieldExists('currency', 'wallet_transactions')) {
            $this->forge->dropColumn('wallet_transactions', 'currency');
        }
    }
}
