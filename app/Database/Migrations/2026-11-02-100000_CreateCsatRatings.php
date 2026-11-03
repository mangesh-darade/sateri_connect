<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Customer satisfaction (CSAT): one row per question sent when a chat is resolved. `rating` stays NULL
 * until the customer answers 1-5 (CsatRatingModel).
 */
class CreateCsatRatings extends Migration
{
    public function up(): void
    {
        if ($this->db->tableExists('csat_ratings')) {
            return;
        }

        $this->forge->addField([
            'id'              => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true, 'auto_increment' => true],
            'conversation_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'contact_id'      => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'agent_id'        => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'rating'          => ['type' => 'TINYINT', 'constraint' => 3, 'unsigned' => true, 'null' => true],
            'asked_at'        => ['type' => 'DATETIME', 'null' => true],
            'answered_at'     => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['conversation_id', 'asked_at'], false, false, 'idx_csat_conversation');
        $this->forge->addKey(['contact_id', 'asked_at'], false, false, 'idx_csat_contact');
        $this->forge->addKey(['agent_id', 'answered_at'], false, false, 'idx_csat_agent');
        $this->forge->createTable('csat_ratings', true, ['ENGINE' => 'InnoDB']);
    }

    public function down(): void
    {
        $this->forge->dropTable('csat_ratings', true);
    }
}
