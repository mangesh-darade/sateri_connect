<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Sender purpose: transactional (primary) vs marketing (promotional) From addresses.
 */
class AddPurposeToEmailSenders extends Migration
{
    public function up(): void
    {
        if (! $this->db->tableExists('email_senders') || $this->db->fieldExists('purpose', 'email_senders')) {
            return;
        }

        $this->forge->addColumn('email_senders', [
            'purpose' => [
                'type'       => 'ENUM',
                'constraint' => ['transactional', 'marketing'],
                'default'    => 'transactional',
                'null'       => false,
                'after'      => 'type',
            ],
        ]);
    }

    public function down(): void
    {
        if ($this->db->fieldExists('purpose', 'email_senders')) {
            $this->forge->dropColumn('email_senders', 'purpose');
        }
    }
}
