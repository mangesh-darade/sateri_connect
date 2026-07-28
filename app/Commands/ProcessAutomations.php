<?php

namespace App\Commands;

use App\Libraries\AutomationEngine;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Throwable;

class ProcessAutomations extends BaseCommand
{
    protected $group       = 'WhatsApp';
    protected $name        = 'automations:process';
    protected $description = 'Process time-based automation triggers (e.g. birthdays).';
    protected $usage       = 'automations:process';

    public function run(array $params)
    {
        CLI::write('Processing automations...', 'yellow');

        try {
            $count = (new AutomationEngine())->processPending();
            CLI::write("Processed {$count} time-based automation trigger(s).", 'green');
        } catch (Throwable $e) {
            CLI::error('Automation processing failed: ' . $e->getMessage());

            return EXIT_ERROR;
        }

        return EXIT_SUCCESS;
    }
}
