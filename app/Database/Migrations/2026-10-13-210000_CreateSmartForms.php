<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Shareable lead forms. A submit creates or updates a contact and can start
 * a "New form response" workflow.
 */
class CreateSmartForms extends Migration
{
    public function up(): void
    {
        if (! $this->db->tableExists('smart_forms')) {
            $this->forge->addField([
                'id'              => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
                'name'            => ['type' => 'VARCHAR', 'constraint' => 120],
                'slug'            => ['type' => 'VARCHAR', 'constraint' => 80],
                'status'          => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'active'],
                'success_message' => ['type' => 'VARCHAR', 'constraint' => 255, 'default' => 'Thank you. We have received your details.'],
                'fields'          => ['type' => 'TEXT', 'null' => true],
                'created_by'      => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'created_at'      => ['type' => 'DATETIME', 'null' => true],
                'updated_at'      => ['type' => 'DATETIME', 'null' => true],
            ]);
            $this->forge->addPrimaryKey('id');
            $this->forge->addUniqueKey('slug');
            $this->forge->createTable('smart_forms', true);
        }

        if (! $this->db->tableExists('smart_form_responses')) {
            $this->forge->addField([
                'id'         => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
                'form_id'    => ['type' => 'INT', 'unsigned' => true],
                'contact_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'payload'    => ['type' => 'TEXT', 'null' => true],
                'created_at' => ['type' => 'DATETIME', 'null' => true],
            ]);
            $this->forge->addPrimaryKey('id');
            $this->forge->addKey('form_id');
            $this->forge->addKey('contact_id');
            $this->forge->createTable('smart_form_responses', true);
        }
    }

    public function down(): void
    {
        $this->forge->dropTable('smart_form_responses', true);
        $this->forge->dropTable('smart_forms', true);
    }
}
