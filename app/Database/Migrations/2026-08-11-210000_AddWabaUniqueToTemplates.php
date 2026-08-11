<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Harden WhatsApp template sync uniqueness per WABA.
 * Reuses existing `templates` table (do not create whatsapp_message_templates).
 */
class AddWabaUniqueToTemplates extends Migration
{
    public function up(): void
    {
        if (! $this->db->tableExists('templates')) {
            return;
        }

        if (! $this->db->fieldExists('waba_id', 'templates')) {
            $this->forge->addColumn('templates', [
                'waba_id' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 64,
                    'null'       => true,
                    'after'      => 'id',
                ],
            ]);
        }

        if (! $this->db->fieldExists('rejected_reason', 'templates')) {
            $this->forge->addColumn('templates', [
                'rejected_reason' => [
                    'type' => 'TEXT',
                    'null' => true,
                    'after' => 'status',
                ],
            ]);
        }

        // Backfill waba_id from tenant Meta settings when available.
        try {
            $row = $this->db->table('settings')->where('key', 'meta_waba_id')->get()->getRowArray();
            $waba = is_array($row) ? trim((string) ($row['value'] ?? '')) : '';
            if ($waba !== '') {
                $this->db->table('templates')
                    ->where('waba_id IS NULL', null, false)
                    ->orWhere('waba_id', '')
                    ->set('waba_id', $waba)
                    ->update();
            }
        } catch (\Throwable) {
            // Settings table may be absent in some test DBs.
        }

        $this->ensureIndex('templates_waba_meta_unique', 'UNIQUE KEY `templates_waba_meta_unique` (`waba_id`, `meta_id`)');
        $this->ensureIndex('templates_waba_name_lang_unique', 'UNIQUE KEY `templates_waba_name_lang_unique` (`waba_id`, `name`, `language`)');
        $this->ensureIndex('templates_waba_id_idx', 'KEY `templates_waba_id_idx` (`waba_id`)');
    }

    public function down(): void
    {
        if (! $this->db->tableExists('templates')) {
            return;
        }

        foreach (['templates_waba_meta_unique', 'templates_waba_name_lang_unique', 'templates_waba_id_idx'] as $index) {
            try {
                $this->db->query("ALTER TABLE `templates` DROP INDEX `{$index}`");
            } catch (\Throwable) {
                // ignore
            }
        }

        if ($this->db->fieldExists('rejected_reason', 'templates')) {
            $this->forge->dropColumn('templates', 'rejected_reason');
        }
        if ($this->db->fieldExists('waba_id', 'templates')) {
            $this->forge->dropColumn('templates', 'waba_id');
        }
    }

    protected function ensureIndex(string $name, string $definitionSql): void
    {
        try {
            $exists = $this->db->query(
                'SELECT 1 FROM information_schema.statistics
                 WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?
                 LIMIT 1',
                ['templates', $name]
            )->getRowArray();
            if ($exists !== null) {
                return;
            }
            $this->db->query("ALTER TABLE `templates` ADD {$definitionSql}");
        } catch (\Throwable $e) {
            log_message('warning', 'templates index {name} skipped: {msg}', [
                'name' => $name,
                'msg'  => $e->getMessage(),
            ]);
        }
    }
}
