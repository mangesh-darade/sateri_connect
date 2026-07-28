<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddFlowGraphToAutomations extends Migration
{
    public function up(): void
    {
        if ($this->db->fieldExists('flow_graph', 'automations')) {
            return;
        }

        $this->forge->addColumn('automations', [
            'flow_graph' => [
                'type' => 'LONGTEXT',
                'null' => true,
                'after' => 'trigger_config',
            ],
        ]);
    }

    public function down(): void
    {
        if ($this->db->fieldExists('flow_graph', 'automations')) {
            $this->forge->dropColumn('automations', 'flow_graph');
        }
    }
}
