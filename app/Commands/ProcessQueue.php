<?php

namespace App\Commands;

use App\Libraries\QueueService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Throwable;

class ProcessQueue extends BaseCommand
{
    protected $group       = 'WhatsApp';
    protected $name        = 'queue:process';
    protected $description = 'Process the next batch of pending WhatsApp message queue items.';
    protected $usage       = 'queue:process [limit]';
    protected $arguments   = [
        'limit' => 'Maximum number of items to process (default 50).',
    ];

    public function run(array $params)
    {
        $limit = isset($params[0]) ? (int) $params[0] : 50;
        CLI::write('Processing message queue (limit=' . $limit . ')...', 'yellow');

        try {
            $stats = (new QueueService())->processBatch($limit);
            CLI::write(sprintf(
                'Done. processed=%d sent=%d failed=%d',
                $stats['processed'],
                $stats['sent'],
                $stats['failed']
            ), 'green');
        } catch (Throwable $e) {
            CLI::error('Queue processing failed: ' . $e->getMessage());

            return EXIT_ERROR;
        }

        return EXIT_SUCCESS;
    }
}
