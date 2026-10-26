<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Per-client WhatsApp wallet: one `wallets` row (balance + on/off switch) and an append-only
 * `wallet_transactions` ledger. Rates live in the master DB (wa_rates), not here.
 */
class CreateWallet extends Migration
{
    public function up(): void
    {
        if (! $this->db->tableExists('wallets')) {
            $this->forge->addField([
                'id'                => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
                'enabled'           => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
                'balance'           => ['type' => 'DECIMAL', 'constraint' => '14,4', 'default' => 0],
                'currency'          => ['type' => 'VARCHAR', 'constraint' => 3, 'default' => 'INR'],
                'low_balance_alert' => ['type' => 'DECIMAL', 'constraint' => '14,2', 'default' => 0],
                'tax_percent'       => ['type' => 'DECIMAL', 'constraint' => '5,2', 'default' => 0],
                'last_low_alert_at' => ['type' => 'DATETIME', 'null' => true],
                'updated_at'        => ['type' => 'DATETIME', 'null' => true],
            ]);
            $this->forge->addKey('id', true);
            $this->forge->createTable('wallets', true, ['ENGINE' => 'InnoDB']);
        }

        if (! $this->db->tableExists('wallet_transactions')) {
            $this->forge->addField([
                'id'            => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true, 'auto_increment' => true],
                'type'          => ['type' => 'VARCHAR', 'constraint' => 10], // credit | debit | refund
                'amount'        => ['type' => 'DECIMAL', 'constraint' => '14,4', 'default' => 0],
                'balance_after' => ['type' => 'DECIMAL', 'constraint' => '14,4', 'default' => 0],
                'category'      => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true],
                'country_code'  => ['type' => 'VARCHAR', 'constraint' => 8, 'null' => true],
                'reference'     => ['type' => 'VARCHAR', 'constraint' => 191, 'null' => true],
                'description'   => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
                'created_by'    => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
                'created_at'    => ['type' => 'DATETIME', 'null' => true],
            ]);
            $this->forge->addKey('id', true);
            // One refund per message, and a message is never debited twice.
            $this->forge->addUniqueKey(['type', 'reference'], 'uniq_wallet_tx_type_reference');
            $this->forge->addKey(['category', 'created_at']);
            $this->forge->addKey('created_at');
            $this->forge->createTable('wallet_transactions', true, ['ENGINE' => 'InnoDB']);
        }
    }

    public function down(): void
    {
        $this->forge->dropTable('wallet_transactions', true);
        $this->forge->dropTable('wallets', true);
    }
}
