<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Track which provider owns a sender/domain identity (e.g. Amazon SES) and its custom MAIL FROM domain.
 */
class AddSesIdentityFieldsToEmailSenders extends Migration
{
    public function up(): void
    {
        if (! $this->db->tableExists('email_senders')) {
            return;
        }

        $fields = [
            'provider' => [
                'type'       => 'VARCHAR',
                'constraint' => 20,
                'null'       => true,
                'after'      => 'type',
            ],
            'mail_from_domain' => [
                'type'       => 'VARCHAR',
                'constraint' => 191,
                'null'       => true,
                'after'      => 'domain',
            ],
            'last_checked_at' => [
                'type' => 'DATETIME',
                'null' => true,
                'after' => 'is_default',
            ],
        ];

        $existing = $this->db->getFieldNames('email_senders');
        $toAdd    = array_diff_key($fields, array_flip($existing));
        if ($toAdd !== []) {
            $this->forge->addColumn('email_senders', $toAdd);
        }
    }

    public function down(): void
    {
        foreach (['provider', 'mail_from_domain', 'last_checked_at'] as $col) {
            if ($this->db->fieldExists($col, 'email_senders')) {
                $this->forge->dropColumn('email_senders', $col);
            }
        }
    }
}
