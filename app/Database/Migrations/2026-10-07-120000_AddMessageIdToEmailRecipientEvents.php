<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Provider message id (e.g. Amazon SES MessageId) per recipient, so bounce/complaint
 * notifications can be matched back to the exact send.
 */
class AddMessageIdToEmailRecipientEvents extends Migration
{
    public function up(): void
    {
        if (! $this->db->tableExists('email_recipient_events') || $this->db->fieldExists('message_id', 'email_recipient_events')) {
            return;
        }

        $this->forge->addColumn('email_recipient_events', [
            'message_id' => [
                'type'       => 'VARCHAR',
                'constraint' => 100,
                'null'       => true,
                'after'      => 'detail',
            ],
        ]);
        $this->db->query('ALTER TABLE `' . $this->db->prefixTable('email_recipient_events') . '` ADD KEY `message_id` (`message_id`)');
    }

    public function down(): void
    {
        if ($this->db->fieldExists('message_id', 'email_recipient_events')) {
            $this->forge->dropColumn('email_recipient_events', 'message_id');
        }
    }
}
