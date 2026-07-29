<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateAutomationDelayedJobsAndSequences extends Migration
{
    public function up(): void
    {
        if (! $this->db->tableExists('automation_delayed_jobs')) {
            $this->forge->addField([
                'id' => [
                    'type'           => 'INT',
                    'constraint'     => 11,
                    'unsigned'       => true,
                    'auto_increment' => true,
                ],
                'automation_id' => [
                    'type'       => 'INT',
                    'constraint' => 11,
                    'unsigned'   => true,
                ],
                'contact_id' => [
                    'type'       => 'INT',
                    'constraint' => 11,
                    'unsigned'   => true,
                    'null'       => true,
                ],
                'resume_rule_id' => [
                    'type'       => 'INT',
                    'constraint' => 11,
                    'unsigned'   => true,
                    'null'       => true,
                ],
                'context_json' => [
                    'type' => 'LONGTEXT',
                    'null' => true,
                ],
                'run_at' => [
                    'type' => 'DATETIME',
                    'null' => false,
                ],
                'status' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 20,
                    'default'    => 'pending',
                ],
                'created_at' => [
                    'type' => 'DATETIME',
                    'null' => true,
                ],
                'updated_at' => [
                    'type' => 'DATETIME',
                    'null' => true,
                ],
            ]);
            $this->forge->addKey('id', true);
            $this->forge->addKey(['status', 'run_at']);
            $this->forge->addKey('automation_id');
            $this->forge->createTable('automation_delayed_jobs', true);
        }

        if (! $this->db->tableExists('message_sequences')) {
            $this->forge->addField([
                'id' => [
                    'type'           => 'INT',
                    'constraint'     => 11,
                    'unsigned'       => true,
                    'auto_increment' => true,
                ],
                'name' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 191,
                ],
                'channel' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 20,
                    'default'    => 'whatsapp',
                ],
                'is_active' => [
                    'type'       => 'TINYINT',
                    'constraint' => 1,
                    'default'    => 1,
                ],
                'exit_on_reply' => [
                    'type'       => 'TINYINT',
                    'constraint' => 1,
                    'default'    => 1,
                ],
                'created_by' => [
                    'type'       => 'INT',
                    'constraint' => 11,
                    'unsigned'   => true,
                    'null'       => true,
                ],
                'created_at' => [
                    'type' => 'DATETIME',
                    'null' => true,
                ],
                'updated_at' => [
                    'type' => 'DATETIME',
                    'null' => true,
                ],
            ]);
            $this->forge->addKey('id', true);
            $this->forge->createTable('message_sequences', true);
        }

        if (! $this->db->tableExists('sequence_steps')) {
            $this->forge->addField([
                'id' => [
                    'type'           => 'INT',
                    'constraint'     => 11,
                    'unsigned'       => true,
                    'auto_increment' => true,
                ],
                'sequence_id' => [
                    'type'       => 'INT',
                    'constraint' => 11,
                    'unsigned'   => true,
                ],
                'step_order' => [
                    'type'       => 'INT',
                    'constraint' => 11,
                    'unsigned'   => true,
                    'default'    => 1,
                ],
                'delay_minutes' => [
                    'type'       => 'INT',
                    'constraint' => 11,
                    'unsigned'   => true,
                    'default'    => 0,
                ],
                'message_type' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 30,
                    'default'    => 'text',
                ],
                'template_name' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 191,
                    'null'       => true,
                ],
                'language' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 20,
                    'default'    => 'en',
                ],
                'body_text' => [
                    'type' => 'TEXT',
                    'null' => true,
                ],
                'created_at' => [
                    'type' => 'DATETIME',
                    'null' => true,
                ],
                'updated_at' => [
                    'type' => 'DATETIME',
                    'null' => true,
                ],
            ]);
            $this->forge->addKey('id', true);
            $this->forge->addKey(['sequence_id', 'step_order']);
            $this->forge->createTable('sequence_steps', true);
        }

        if (! $this->db->tableExists('sequence_enrollments')) {
            $this->forge->addField([
                'id' => [
                    'type'           => 'INT',
                    'constraint'     => 11,
                    'unsigned'       => true,
                    'auto_increment' => true,
                ],
                'sequence_id' => [
                    'type'       => 'INT',
                    'constraint' => 11,
                    'unsigned'   => true,
                ],
                'contact_id' => [
                    'type'       => 'INT',
                    'constraint' => 11,
                    'unsigned'   => true,
                ],
                'current_step' => [
                    'type'       => 'INT',
                    'constraint' => 11,
                    'unsigned'   => true,
                    'default'    => 0,
                ],
                'status' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 20,
                    'default'    => 'active',
                ],
                'next_run_at' => [
                    'type' => 'DATETIME',
                    'null' => true,
                ],
                'last_sent_at' => [
                    'type' => 'DATETIME',
                    'null' => true,
                ],
                'created_at' => [
                    'type' => 'DATETIME',
                    'null' => true,
                ],
                'updated_at' => [
                    'type' => 'DATETIME',
                    'null' => true,
                ],
            ]);
            $this->forge->addKey('id', true);
            $this->forge->addKey(['status', 'next_run_at']);
            $this->forge->addUniqueKey(['sequence_id', 'contact_id']);
            $this->forge->createTable('sequence_enrollments', true);
        }
    }

    public function down(): void
    {
        foreach (['sequence_enrollments', 'sequence_steps', 'message_sequences', 'automation_delayed_jobs'] as $table) {
            if ($this->db->tableExists($table)) {
                $this->forge->dropTable($table, true);
            }
        }
    }
}
