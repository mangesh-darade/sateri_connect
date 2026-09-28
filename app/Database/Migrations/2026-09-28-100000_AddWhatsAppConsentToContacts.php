<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * WhatsApp Business Policy: per-contact opt-in proof, opt-out and delivery suppression.
 *
 * Existing contacts are NOT auto opted-in — Meta requires recorded consent before
 * business-initiated messages. Operators mark consent via form, bulk action or import.
 */
class AddWhatsAppConsentToContacts extends Migration
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public static function columns(): array
    {
        return [
            'wa_opt_in' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'null'       => false,
                'default'    => 0,
            ],
            'wa_opt_in_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'wa_opt_in_source' => [
                'type'       => 'VARCHAR',
                'constraint' => 50,
                'null'       => true,
            ],
            'wa_opted_out_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'wa_suppressed_until' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'wa_suppress_reason' => [
                'type'       => 'VARCHAR',
                'constraint' => 191,
                'null'       => true,
            ],
        ];
    }

    public function up(): void
    {
        if (! $this->db->tableExists('contacts')) {
            return;
        }

        $after = 'custom_fields';
        foreach (self::columns() as $name => $definition) {
            if ($this->db->fieldExists($name, 'contacts')) {
                $after = $name;
                continue;
            }
            if ($this->db->fieldExists($after, 'contacts')) {
                $definition['after'] = $after;
            }
            $this->forge->addColumn('contacts', [$name => $definition]);
            $after = $name;
        }

        foreach (['wa_opt_in', 'wa_opted_out_at', 'wa_suppressed_until'] as $column) {
            $this->ensureIndex('contacts_' . $column . '_idx', $column);
        }
    }

    public function down(): void
    {
        if (! $this->db->tableExists('contacts')) {
            return;
        }

        foreach (['wa_opt_in', 'wa_opted_out_at', 'wa_suppressed_until'] as $column) {
            try {
                $this->db->query('ALTER TABLE `contacts` DROP INDEX `contacts_' . $column . '_idx`');
            } catch (\Throwable) {
                // index may not exist
            }
        }

        foreach (array_keys(self::columns()) as $name) {
            if ($this->db->fieldExists($name, 'contacts')) {
                $this->forge->dropColumn('contacts', $name);
            }
        }
    }

    protected function ensureIndex(string $name, string $column): void
    {
        try {
            $exists = $this->db->query(
                'SELECT 1 FROM information_schema.statistics
                 WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?
                 LIMIT 1',
                ['contacts', $name]
            )->getRowArray();
            if ($exists !== null) {
                return;
            }
            $this->db->query("ALTER TABLE `contacts` ADD KEY `{$name}` (`{$column}`)");
        } catch (\Throwable $e) {
            log_message('warning', 'contacts index {name} skipped: {msg}', [
                'name' => $name,
                'msg'  => $e->getMessage(),
            ]);
        }
    }
}
