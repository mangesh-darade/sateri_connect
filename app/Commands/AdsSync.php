<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\EachTenant;
use App\Libraries\AdsSyncService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Every 30 minutes: pull the status and the daily numbers of live ad campaigns from Meta, and alert on
 * rejected ads and spend near the client's daily limit. Test-mode campaigns are skipped.
 *
 *   php spark ads:sync              every active client
 *   php spark ads:sync -tenant acme one client
 */
class AdsSync extends BaseCommand
{
    use EachTenant;

    protected $group       = 'Ads';
    protected $name        = 'ads:sync';
    protected $description = 'Sync live ad campaign status and daily numbers from Meta.';
    protected $usage       = 'ads:sync [-tenant <key>]';
    protected $options     = ['-tenant' => 'Only this client key (default: all active clients)'];

    public function run(array $params)
    {
        $this->eachTenant(static function (string $key): void {
            // Built after the tenant switch so it reads this client's settings.
            $r = (new AdsSyncService())->run();
            if ($r['skipped'] === '') {
                CLI::write(($key !== '' ? $key . ': ' : '') . $r['campaigns'] . ' campaign(s), ' . $r['metrics'] . ' day row(s), ' . $r['alerts'] . ' alert(s)');
            }
        });

        return EXIT_SUCCESS;
    }
}
