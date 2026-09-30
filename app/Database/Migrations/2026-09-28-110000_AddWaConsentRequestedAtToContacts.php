<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * When the WhatsApp consent request (Agree / Stop) was last sent, so unanswered
 * contacts are not asked again before the resend cooldown.
 */
class AddWaConsentRequestedAtToContacts extends Migration
{
    public function up(): void
    {
        if (! $this->db->tableExists('contacts') || $this->db->fieldExists('wa_consent_requested_at', 'contacts')) {
            return;
        }

        $definition = ['type' => 'DATETIME', 'null' => true];
        if ($this->db->fieldExists('wa_suppress_reason', 'contacts')) {
            $definition['after'] = 'wa_suppress_reason';
        }
        $this->forge->addColumn('contacts', ['wa_consent_requested_at' => $definition]);
    }

    public function down(): void
    {
        if ($this->db->tableExists('contacts') && $this->db->fieldExists('wa_consent_requested_at', 'contacts')) {
            $this->forge->dropColumn('contacts', 'wa_consent_requested_at');
        }
    }
}
