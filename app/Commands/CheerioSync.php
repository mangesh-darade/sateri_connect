<?php

declare(strict_types=1);

namespace App\Commands;

use App\Libraries\CheerioSyncService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Throwable;

/**
 * Sync contacts and workflows from Cheerio Direct APIs.
 *
 * Usage: php spark cheerio:sync
 *        php spark cheerio:sync contacts
 *        php spark cheerio:sync workflows
 */
class CheerioSync extends BaseCommand
{
    protected $group       = 'Cheerio';
    protected $name        = 'cheerio:sync';
    protected $description = 'Sync contacts and/or workflows from Cheerio Direct API.';
    protected $usage       = 'cheerio:sync [contacts|workflows|all]';

    public function run(array $params)
    {
        $target = strtolower((string) ($params[0] ?? 'all'));

        if (! in_array($target, ['all', 'contacts', 'workflows'], true)) {
            CLI::error('Use: cheerio:sync [contacts|workflows|all]');

            return;
        }

        $service = new CheerioSyncService();

        if ($target === 'all' || $target === 'contacts') {
            CLI::write('Syncing Cheerio contacts…', 'yellow');
            try {
                $stats = $service->syncContacts();
                CLI::write(sprintf(
                    'Contacts: %d created, %d updated, %d skipped (total remote %d)',
                    $stats['created'],
                    $stats['updated'],
                    $stats['skipped'],
                    $stats['total']
                ), 'green');
            } catch (Throwable $e) {
                CLI::error('Contacts sync failed: ' . $e->getMessage());
            }
        }

        if ($target === 'all' || $target === 'workflows') {
            CLI::write('Syncing Cheerio workflows…', 'yellow');
            try {
                $stats = $service->syncWorkflows();
                CLI::write(sprintf(
                    'Workflows: %d created, %d updated, %d skipped (total remote %d)',
                    $stats['created'],
                    $stats['updated'],
                    $stats['skipped'],
                    $stats['total']
                ), 'green');
            } catch (Throwable $e) {
                CLI::error('Workflows sync failed: ' . $e->getMessage());
            }
        }
    }
}
