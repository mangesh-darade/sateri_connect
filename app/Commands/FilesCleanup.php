<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\EachTenant;
use App\Libraries\PublicFileService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Daily or weekly: delete API-uploaded files (catalogue PDFs, pictures) that are older than N days and that no workflow,
 * campaign or waiting message uses any more. Files that are still used are never removed.
 *
 *   php spark files:cleanup                 every active client, files older than 90 days
 *   php spark files:cleanup -days 30        older than 30 days
 *   php spark files:cleanup -tenant acme    one client
 */
class FilesCleanup extends BaseCommand
{
    use EachTenant;

    protected $group       = 'WhatsApp';
    protected $name        = 'files:cleanup';
    protected $description = 'Delete old uploaded files that nothing uses.';
    protected $usage       = 'files:cleanup [-days <n>] [-tenant <key>]';
    protected $options     = [
        '-days'   => 'Only files older than this many days (default 90)',
        '-tenant' => 'Only this client key (default: all active clients)',
    ];

    public function run(array $params)
    {
        $days = max(1, (int) (CLI::getOption('days') ?? 90));

        $this->eachTenant(static function (string $key) use ($days): void {
            $n = (new PublicFileService())->cleanup($days);
            if ($n > 0) {
                CLI::write(($key !== '' ? $key . ': ' : '') . $n . ' old file(s) deleted');
            }
        });

        return EXIT_SUCCESS;
    }
}
