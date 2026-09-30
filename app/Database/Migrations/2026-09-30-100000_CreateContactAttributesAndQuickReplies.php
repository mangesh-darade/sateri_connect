<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Attribute definitions (type / dropdown options / default) for contacts.custom_fields,
 * and inbox quick replies (canned responses inserted with "/shortcut").
 */
class CreateContactAttributesAndQuickReplies extends Migration
{
    public function up(): void
    {
        if (! $this->db->tableExists('contact_attributes')) {
            $this->forge->addField([
                'id'            => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
                'attr_key'      => ['type' => 'VARCHAR', 'constraint' => 50],
                'label'         => ['type' => 'VARCHAR', 'constraint' => 100],
                'type'          => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'text'],
                'options'       => ['type' => 'TEXT', 'null' => true],
                'default_value' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
                'created_at'    => ['type' => 'DATETIME', 'null' => true],
                'updated_at'    => ['type' => 'DATETIME', 'null' => true],
            ]);
            $this->forge->addPrimaryKey('id');
            $this->forge->addUniqueKey('attr_key');
            $this->forge->createTable('contact_attributes', true);
        }

        if (! $this->db->tableExists('quick_replies')) {
            $this->forge->addField([
                'id'         => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
                'shortcut'   => ['type' => 'VARCHAR', 'constraint' => 50],
                'title'      => ['type' => 'VARCHAR', 'constraint' => 100],
                'message'    => ['type' => 'TEXT'],
                'created_by' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'created_at' => ['type' => 'DATETIME', 'null' => true],
                'updated_at' => ['type' => 'DATETIME', 'null' => true],
            ]);
            $this->forge->addPrimaryKey('id');
            $this->forge->addUniqueKey('shortcut');
            $this->forge->createTable('quick_replies', true);
        }
    }

    public function down(): void
    {
        $this->forge->dropTable('quick_replies', true);
        $this->forge->dropTable('contact_attributes', true);
    }
}
