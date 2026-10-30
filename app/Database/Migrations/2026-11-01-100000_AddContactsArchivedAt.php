<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Archived contacts (leads) are hidden from the main Contacts list and are not synced to ElintOm (LMS).
 * NULL = active.
 */
class AddContactsArchivedAt extends Migration
{
    public function up(): void
    {
        if ($this->db->tableExists('contacts') && ! $this->db->fieldExists('archived_at', 'contacts')) {
            $this->forge->addColumn('contacts', [
                'archived_at' => ['type' => 'DATETIME', 'null' => true, 'after' => 'deleted_at'],
            ]);
            $this->db->query('ALTER TABLE `contacts` ADD KEY `archived_at` (`archived_at`)');
        }
    }

    public function down(): void
    {
        if ($this->db->tableExists('contacts') && $this->db->fieldExists('archived_at', 'contacts')) {
            $this->forge->dropColumn('contacts', 'archived_at');
        }
    }
}
