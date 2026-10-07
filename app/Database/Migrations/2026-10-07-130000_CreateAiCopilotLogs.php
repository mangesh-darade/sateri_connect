<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * AI Copilot prompt / reply history (AiCopilotLogModel). The model existed without a table.
 */
class CreateAiCopilotLogs extends Migration
{
    public function up(): void
    {
        if ($this->db->tableExists('ai_copilot_logs')) {
            return;
        }

        $this->forge->addField([
            'id'          => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'user_id'     => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'screen'      => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'page_url'    => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'prompt'      => ['type' => 'TEXT', 'null' => true],
            'reply'       => ['type' => 'MEDIUMTEXT', 'null' => true],
            'thinking'    => ['type' => 'TEXT', 'null' => true],
            'action_type' => ['type' => 'VARCHAR', 'constraint' => 50, 'null' => true],
            'action_data' => ['type' => 'TEXT', 'null' => true],
            'is_active'   => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
            'is_deleted'  => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'created_at'  => ['type' => 'DATETIME', 'null' => true],
            'updated_at'  => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['user_id', 'is_deleted']);
        $this->forge->createTable('ai_copilot_logs', true, ['ENGINE' => 'InnoDB']);
    }

    public function down(): void
    {
        $this->forge->dropTable('ai_copilot_logs', true);
    }
}
