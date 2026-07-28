<?php

namespace App\Commands;

use App\Libraries\QueueService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Throwable;

class RetryFailed extends BaseCommand
{
    protected $group       = 'WhatsApp';
    protected $name        = 'queue:retry';
    protected $description = 'Re-queue failed WhatsApp message queue items for another attempt.';
    protected $usage       = 'queue:retry [limit]';
    protected $arguments   = [
        'limit' => 'Maximum number of failed items to retry (default 20).',
    ];

    public function run(array $params)
    {
        $limit = isset($params[0]) ? (int) $params[0] : 20;
        CLI::write('Retrying failed queue items (limit=' . $limit . ')...', 'yellow');

        try {
            $service = new QueueService();
            $reset   = $service->retryFailed($limit);
            CLI::write("Reset {$reset} item(s) to pending.", 'green');

            if ($reset > 0) {
                $stats = $service->processBatch($reset);
                CLI::write(sprintf(
                    'Re-processed: processed=%d sent=%d failed=%d',
                    $stats['processed'],
                    $stats['sent'],
                    $stats['failed']
                ), 'yellow');
            }
        } catch (Throwable $e) {
            CLI::error('Queue retry failed: ' . $e->getMessage());

            return EXIT_ERROR;
        }

        return EXIT_SUCCESS;
    }
}
