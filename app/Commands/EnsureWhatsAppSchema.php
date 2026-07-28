<?php

declare(strict_types=1);

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Throwable;

/**
 * Idempotent: add missing omnichannel/live-chat and template columns.
 * Safe to run on production when migrate history is behind.
 *
 *   php spark whatsapp:ensure-schema
 */
class EnsureWhatsAppSchema extends BaseCommand
{
    protected $group       = 'WhatsApp';
    protected $name        = 'whatsapp:ensure-schema';
    protected $description = 'Add missing channel/external_id and template_type columns (safe to re-run).';
    protected $usage       = 'whatsapp:ensure-schema';

    public function run(array $params)
    {
        $db = db_connect();
        $added = 0;

        $columns = [
            'contacts' => [
                'channel' => "VARCHAR(20) NOT NULL DEFAULT 'whatsapp'",
                'external_id' => 'VARCHAR(191) NULL DEFAULT NULL',
            ],
            'conversations' => [
                'channel' => "VARCHAR(20) NOT NULL DEFAULT 'whatsapp'",
                'page_id' => 'VARCHAR(64) NULL DEFAULT NULL',
            ],
            'messages' => [
                'channel' => "VARCHAR(20) NOT NULL DEFAULT 'whatsapp'",
                'external_message_id' => 'VARCHAR(191) NULL DEFAULT NULL',
            ],
            'templates' => [
                'template_type' => "VARCHAR(30) NOT NULL DEFAULT 'default'",
            ],
        ];

        $after = [
            'contacts.channel'              => 'id',
            'contacts.external_id'          => 'channel',
            'conversations.channel'         => 'contact_id',
            'conversations.page_id'         => 'channel',
            'messages.channel'              => 'conversation_id',
            'messages.external_message_id'  => 'wamid',
            'templates.template_type'       => 'category',
        ];

        foreach ($columns as $table => $defs) {
            if (! $db->tableExists($table)) {
                CLI::error("Table missing: {$table}");
                continue;
            }

            foreach ($defs as $column => $definition) {
                if ($db->fieldExists($column, $table)) {
                    CLI::write("OK  {$table}.{$column} (exists)", 'green');
                    continue;
                }

                $key = $table . '.' . $column;
                $afterCol = $after[$key] ?? null;
                $sql = "ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}";
                if ($afterCol !== null && $db->fieldExists($afterCol, $table)) {
                    $sql .= " AFTER `{$afterCol}`";
                }

                try {
                    $db->query($sql);
                    CLI::write("ADD {$table}.{$column}", 'yellow');
                    $added++;
                } catch (Throwable $e) {
                    CLI::error("FAIL {$table}.{$column}: " . $e->getMessage());
                }
            }
        }

        // Backfill defaults
        try {
            $db->query("UPDATE `contacts` SET `channel` = 'whatsapp' WHERE `channel` IS NULL OR `channel` = ''");
            $db->query(
                "UPDATE `contacts` SET `external_id` = `mobile`
                 WHERE (`external_id` IS NULL OR `external_id` = '')
                   AND `mobile` IS NOT NULL AND `mobile` != ''"
            );
            $db->query("UPDATE `conversations` SET `channel` = 'whatsapp' WHERE `channel` IS NULL OR `channel` = ''");
            $db->query("UPDATE `messages` SET `channel` = 'whatsapp' WHERE `channel` IS NULL OR `channel` = ''");
            if ($db->fieldExists('template_type', 'templates')) {
                $db->query("UPDATE `templates` SET `template_type` = 'default' WHERE `template_type` IS NULL OR `template_type` = ''");
            }
            if ($db->fieldExists('external_message_id', 'messages')) {
                $db->query(
                    "UPDATE `messages`
                     SET `external_message_id` = COALESCE(NULLIF(`wamid`, ''), NULLIF(`wa_message_id`, ''))
                     WHERE (`external_message_id` IS NULL OR `external_message_id` = '')"
                );
            }
            CLI::write('Backfill defaults done', 'green');
        } catch (Throwable $e) {
            CLI::write('Backfill warning: ' . $e->getMessage(), 'yellow');
        }

        CLI::newLine();
        CLI::write($added > 0
            ? "Done. Added {$added} column(s). Refresh Live Chat."
            : 'Done. Schema already up to date.', 'green');

        return EXIT_SUCCESS;
    }
}
