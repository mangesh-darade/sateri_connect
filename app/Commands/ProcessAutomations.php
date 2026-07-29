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
    protected $description = 'Process delayed automation resumes, birthdays, and due sequence steps.';
    protected $usage       = 'automations:process';

    public function run(array $params)
    {
        CLI::write('Processing automations...', 'yellow');

        try {
            $count = (new AutomationEngine())->processPending();
            CLI::write("Processed {$count} delayed/birthday automation job(s).", 'green');

            $seqCount = (new \App\Libraries\SequenceService())->processDue(100);
            CLI::write("Processed {$seqCount} sequence step(s).", 'green');
        } catch (Throwable $e) {
            CLI::error('Automation processing failed: ' . $e->getMessage());

            return EXIT_ERROR;
        }

        return EXIT_SUCCESS;
    }
}
