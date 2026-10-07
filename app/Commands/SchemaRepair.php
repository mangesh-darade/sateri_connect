<?php

declare(strict_types=1);

namespace App\Commands;

use App\Libraries\MasterTenantRepository;
use App\Libraries\SchemaRepairService;
use App\Libraries\TenantConnection;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Create missing tables / columns / indexes from the schema snapshot right now (no cache).
 *
 *   php spark schema:repair -tenant swasthe_testing
 *   php spark schema:repair -all
 */
class SchemaRepair extends BaseCommand
{
    protected $group       = 'Database';
    protected $name        = 'schema:repair';
    protected $description = 'Auto-create missing tables / columns / indexes for one or all tenants.';
    protected $usage       = 'schema:repair [-tenant <key> | -all]';
    protected $options     = [
        '-tenant' => 'Tenant key',
        '-all'    => 'Every active tenant in the master DB',
    ];

    public function run(array $params)
    {
        $keys = CLI::getOption('all') !== null
            ? array_map(static fn ($t) => (string) $t['key'], (new MasterTenantRepository())->listActiveTenants())
            : array_filter([(string) (CLI::getOption('tenant') ?? '')]);

        if ($keys === []) {
            CLI::error('Give -tenant <key> or -all (the master DB is never repaired).');

            return EXIT_ERROR;
        }

        foreach ($keys as $key) {
            if (! (new TenantConnection())->apply($key, 'cli')) {
                CLI::error("[{$key}] could not connect — skipped");

                continue;
            }

            $db     = db_connect();
            $label  = $key . ' / ' . $db->getDatabase();
            $report = (new SchemaRepairService())->repairDatabase($db, [], true);

            if (isset($report['_error'])) {
                CLI::error("[{$label}] " . implode('; ', $report['_error']));

                continue;
            }
            if ($report === []) {
                CLI::write("[{$label}] schema OK — nothing to change", 'green');

                continue;
            }
            CLI::write("[{$label}] repaired:", 'yellow');
            foreach ($report as $table => $changes) {
                CLI::write('  ' . $table . ': ' . implode(', ', $changes));
            }
        }

        CLI::write('Errors (if any) are in writable/logs.');
    }
}
