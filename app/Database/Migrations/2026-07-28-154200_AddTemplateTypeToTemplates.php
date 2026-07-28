<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddTemplateTypeToTemplates extends Migration
{
    public function up(): void
    {
        if (! $this->db->tableExists('templates') || $this->db->fieldExists('template_type', 'templates')) {
            return;
        }

        $this->forge->addColumn('templates', [
            'template_type' => [
                'type'       => 'VARCHAR',
                'constraint' => 30,
                'default'    => 'default',
                'after'      => 'category',
            ],
        ]);
    }

    public function down(): void
    {
        if ($this->db->tableExists('templates') && $this->db->fieldExists('template_type', 'templates')) {
            $this->forge->dropColumn('templates', 'template_type');
        }
    }
}
