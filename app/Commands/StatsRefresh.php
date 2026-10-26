<?php

declare(strict_types=1);

namespace App\Commands;

use App\Libraries\ClientStatsCache;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Throwable;

/**
 * Keep the All clients numbers fresh without slowing the page. Cron, every 5 minutes:
 *
 *   php spark stats:refresh          recompute stale clients
 *   php spark stats:refresh -all     recompute every client now
 */
class StatsRefresh extends BaseCommand
{
    protected $group       = 'Platform';
    protected $name        = 'stats:refresh';
    protected $description = 'Refresh the cached client statistics used by the All clients page.';
    protected $usage       = 'stats:refresh [-all]';
    protected $options     = ['-all' => 'Recompute every client, not only the stale ones'];

    public function run(array $params)
    {
        try {
            $res = (new ClientStatsCache())->refreshStale(null, CLI::getOption('all') !== null, 0);
            CLI::write('Refreshed ' . $res['refreshed'] . ' of ' . $res['total'] . ' clients.', 'green');
        } catch (Throwable $e) {
            CLI::error($e->getMessage());
        }
    }
}
