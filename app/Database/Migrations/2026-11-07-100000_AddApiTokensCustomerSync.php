<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Per API key switch: contacts added or updated through a key with customer_sync = 1 are pushed to ElintOm (LMS).
 * 0 = off (default).
 */
class AddApiTokensCustomerSync extends Migration
{
    public function up(): void
    {
        if ($this->db->tableExists('api_tokens') && ! $this->db->fieldExists('customer_sync', 'api_tokens')) {
            $this->forge->addColumn('api_tokens', [
                'customer_sync' => ['type' => 'TINYINT', 'constraint' => 1, 'unsigned' => true, 'null' => false, 'default' => 0, 'after' => 'abilities'],
            ]);
        }
    }

    public function down(): void
    {
        if ($this->db->tableExists('api_tokens') && $this->db->fieldExists('customer_sync', 'api_tokens')) {
            $this->forge->dropColumn('api_tokens', 'customer_sync');
        }
    }
}
