<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddEmailTrackingAndUnsubscribes extends Migration
{
    public function up(): void
    {
        // 1. Add tracking columns to email_logs
        $fields = [
            'opened_at' => [
                'type' => 'DATETIME',
                'null' => true,
                'after' => 'status',
            ],
            'open_count' => [
                'type' => 'INT',
                'constraint' => 10,
                'unsigned' => true,
                'default' => 0,
                'after' => 'opened_at',
            ],
            'clicked_at' => [
                'type' => 'DATETIME',
                'null' => true,
                'after' => 'open_count',
            ],
            'click_count' => [
                'type' => 'INT',
                'constraint' => 10,
                'unsigned' => true,
                'default' => 0,
                'after' => 'clicked_at',
            ],
        ];

        if ($this->db->tableExists('email_logs')) {
            $existing = $this->db->getFieldNames('email_logs');
            $toAdd = [];
            foreach ($fields as $name => $def) {
                if (! in_array($name, $existing, true)) {
                    $toAdd[$name] = $def;
                }
            }
            if ($toAdd !== []) {
                $this->forge->addColumn('email_logs', $toAdd);
            }
        }

        // 2. Create email_unsubscribes table
        if (! $this->db->tableExists('email_unsubscribes')) {
            $this->forge->addField([
                'id' => [
                    'type'           => 'INT',
                    'constraint'     => 11,
                    'unsigned'       => true,
                    'auto_increment' => true,
                ],
                'email' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 191,
                ],
                'campaign_id' => [
                    'type'       => 'INT',
                    'constraint' => 11,
                    'unsigned'   => true,
                    'null'       => true,
                ],
                'reason' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 255,
                    'null'       => true,
                ],
                'ip_address' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 45,
                    'null'       => true,
                ],
                'is_active' => [
                    'type'       => 'TINYINT',
                    'constraint' => 1,
                    'default'    => 1,
                ],
                'is_deleted' => [
                    'type'       => 'TINYINT',
                    'constraint' => 1,
                    'default'    => 0,
                ],
                'created_at' => ['type' => 'DATETIME', 'null' => true],
                'updated_at' => ['type' => 'DATETIME', 'null' => true],
            ]);

            $this->forge->addKey('id', true);
            $this->forge->addUniqueKey('email');
            $this->forge->addKey('campaign_id');
            $this->forge->addKey('is_deleted');
            $this->forge->createTable('email_unsubscribes', true);
        }
    }

    public function down(): void
    {
        if ($this->db->tableExists('email_logs')) {
            $columns = ['opened_at', 'open_count', 'clicked_at', 'click_count'];
            foreach ($columns as $col) {
                if ($this->db->fieldExists($col, 'email_logs')) {
                    $this->forge->dropColumn('email_logs', $col);
                }
            }
        }

        $this->forge->dropTable('email_unsubscribes', true);
    }
}
