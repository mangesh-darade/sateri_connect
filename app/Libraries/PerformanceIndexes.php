<?php

declare(strict_types=1);

namespace App\Libraries;

use CodeIgniter\Database\BaseConnection;
use Throwable;

/**
 * Composite indexes behind the dashboard / platform aggregates. Screens call ensure() so a tenant
 * that has not run the migration yet gets the missing indexes created on first open (one cheap
 * information_schema lookup per call; ALTERs run only for what is actually missing).
 */
class PerformanceIndexes
{
    /** Above this many rows (estimate) a web request will not build the index itself. */
    public const MAX_AUTO_ROWS = 2000000;

    /** @var array<string, array{0:string,1:string}> table => [index name, columns] */
    public const INDEXES = [
        'messages'               => ['idx_messages_created_dir_status', '`created_at`, `direction`, `status`'],
        'email_logs'             => ['idx_email_logs_created_status', '`created_at`, `status`'],
        'notifications'          => ['idx_notifications_user_read_created', '`user_id`, `is_read`, `created_at`'],
        'email_recipient_events' => ['idx_ere_type_first_at', '`event_type`, `first_at`'],
    ];

    /**
     * Create any missing index on the given connection. Never throws.
     *
     * @return list<string> names of indexes created
     */
    public static function ensure(BaseConnection $db): array
    {
        $created = [];

        // Checked once per database per hour: avoids an information_schema lookup on every page view and
        // stops a failing ALTER (missing privilege, lock timeout) from being retried on every request.
        $memo = null;
        try {
            if (PHP_SAPI === 'cli') {
                throw new \RuntimeException('cli: always check');
            }
            $memo = 'perfidx_' . md5((string) $db->getDatabase());
            if (cache($memo) !== null) {
                return $created;
            }
            cache()->save($memo, 1, 3600);
        } catch (Throwable) {
            // Cache unavailable: fall through and check directly.
        }

        try {
            $names = array_column(self::INDEXES, 0);
            $found = $db->query(
                'SELECT TABLE_NAME, INDEX_NAME FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE() AND INDEX_NAME IN (' . implode(',', array_map([$db, 'escape'], $names)) . ')
                 GROUP BY TABLE_NAME, INDEX_NAME'
            )->getResultArray();
            $have = [];
            foreach ($found as $row) {
                $have[strtolower((string) $row['TABLE_NAME']) . '|' . $row['INDEX_NAME']] = true;
            }

            foreach (self::INDEXES as $table => [$name, $cols]) {
                $real = $db->prefixTable($table);
                if (isset($have[strtolower($real) . '|' . $name]) || ! $db->tableExists($table)) {
                    continue;
                }
                // A big table is indexed by the migration in a maintenance window, never from a page view.
                $rows = $db->query(
                    'SELECT TABLE_ROWS AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ' . $db->escape($real)
                )->getRowArray();
                if ((int) ($rows['n'] ?? 0) > self::MAX_AUTO_ROWS && PHP_SAPI !== 'cli') {
                    continue;
                }
                $db->query("ALTER TABLE `{$real}` ADD KEY `{$name}` ({$cols})");
                $created[] = $name;
            }
        } catch (Throwable $e) {
            log_message('error', 'PerformanceIndexes::ensure failed: {msg}', ['msg' => $e->getMessage()]);
        }

        return $created;
    }

    public static function drop(BaseConnection $db): void
    {
        foreach (self::INDEXES as $table => [$name]) {
            if (! $db->tableExists($table)) {
                continue;
            }
            try {
                $db->query('ALTER TABLE `' . $db->prefixTable($table) . "` DROP INDEX `{$name}`");
            } catch (Throwable) {
                // Index already absent.
            }
        }
    }
}
