<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateAutomationRules extends Migration
{
    public function up(): void
    {
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
            'step_order' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
                'default'    => 1,
            ],
            'rule_type' => [
                'type'       => 'ENUM',
                'constraint' => ['condition', 'action'],
                'default'    => 'action',
            ],
            'action_type' => [
                'type'       => 'VARCHAR',
                'constraint' => 100,
                'null'       => true,
            ],
            'config' => [
                'type' => 'JSON',
                'null' => true,
            ],
            'next_on_true' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
                'null'       => true,
            ],
            'next_on_false' => [
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
        $this->forge->addKey('automation_id');
        $this->forge->addKey('step_order');
        $this->forge->addKey('rule_type');
        $this->forge->addForeignKey('automation_id', 'automations', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('automation_rules', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('automation_rules', true);
    }
}
