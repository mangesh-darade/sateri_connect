<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * One row per external API call made with an API key (ApiRequestLogModel), shown in Settings > API.
 */
class CreateApiRequestLogs extends Migration
{
    public function up(): void
    {
        if ($this->db->tableExists('api_request_logs')) {
            return;
        }

        $this->forge->addField([
            'id'          => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true, 'auto_increment' => true],
            'token_id'    => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'token_name'  => ['type' => 'VARCHAR', 'constraint' => 150, 'null' => true],
            'user_id'     => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'method'      => ['type' => 'VARCHAR', 'constraint' => 10],
            'endpoint'    => ['type' => 'VARCHAR', 'constraint' => 255],
            'status_code' => ['type' => 'SMALLINT', 'constraint' => 5, 'unsigned' => true, 'default' => 0],
            'ip_address'  => ['type' => 'VARCHAR', 'constraint' => 45, 'null' => true],
            'user_agent'  => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'duration_ms' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'default' => 0],
            'created_at'  => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['token_id', 'created_at']);
        $this->forge->addKey('created_at');
        $this->forge->createTable('api_request_logs', true, ['ENGINE' => 'InnoDB']);
    }

    public function down(): void
    {
        $this->forge->dropTable('api_request_logs', true);
    }
}
