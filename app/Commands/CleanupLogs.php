<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Throwable;

class CleanupLogs extends BaseCommand
{
    protected $group       = 'WhatsApp';
    protected $name        = 'logs:cleanup';
    protected $description = 'Delete old activity logs, webhook logs, and rate-limit rows.';
    protected $usage       = 'logs:cleanup [days]';
    protected $arguments   = [
        'days' => 'Retention period in days (default 30).',
    ];

    public function run(array $params)
    {
        $days = isset($params[0]) ? max(1, (int) $params[0]) : 30;
        $cutoff = date('Y-m-d H:i:s', strtotime('-' . $days . ' days'));

        CLI::write("Cleaning logs older than {$days} day(s) (before {$cutoff})...", 'yellow');

        try {
            $db = db_connect();

            $db->table('activity_logs')->where('created_at <', $cutoff)->delete();
            CLI::write('Activity logs deleted: ' . $db->affectedRows(), 'green');

            $db->table('webhook_logs')->where('created_at <', $cutoff)->delete();
            CLI::write('Webhook logs deleted: ' . $db->affectedRows(), 'green');

            // Rate limits older than the window — remove stale keys
            $rateCutoff = time() - max(3600, $days * 86400);
            $db->table('rate_limits')->where('window_start <', $rateCutoff)->delete();
            CLI::write('Rate limit rows deleted: ' . $db->affectedRows(), 'green');

            // Optionally trim old file logs in writable/logs
            $logPath = WRITEPATH . 'logs';
            if (is_dir($logPath)) {
                $deletedFiles = 0;
                foreach (glob($logPath . '/log-*.log') ?: [] as $file) {
                    if (is_file($file) && filemtime($file) < strtotime('-' . $days . ' days')) {
                        if (@unlink($file)) {
                            $deletedFiles++;
                        }
                    }
                }
                CLI::write("Log files deleted: {$deletedFiles}", 'green');
            }
        } catch (Throwable $e) {
            CLI::error('Log cleanup failed: ' . $e->getMessage());

            return EXIT_ERROR;
        }

        return EXIT_SUCCESS;
    }
}
