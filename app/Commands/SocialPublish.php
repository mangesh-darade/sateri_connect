<?php

declare(strict_types=1);

namespace App\Commands;

use App\Libraries\MasterTenantRepository;
use App\Libraries\SocialMediaDelivery;
use App\Libraries\SocialPublishService;
use App\Libraries\TenantConnection;
use App\Libraries\TenantContext;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Throwable;

/**
 * Every minute: publish social posts whose scheduled time has arrived.
 *
 *   php spark social:publish              every active client
 *   php spark social:publish -tenant acme one client
 */
class SocialPublish extends BaseCommand
{
    protected $group       = 'Social';
    protected $name        = 'social:publish';
    protected $description = 'Publish due social posts (Facebook, Instagram) for every client.';
    protected $usage       = 'social:publish [-tenant <key>]';
    protected $options     = ['-tenant' => 'Only this client key (default: all active clients)'];

    public function run(array $params)
    {
        $only = strtolower(trim((string) (CLI::getOption('tenant') ?? '')));
        $keys = [];

        if ($only !== '') {
            $keys[] = $only;
        } elseif (MasterTenantRepository::masterConfigured()) {
            foreach ((new MasterTenantRepository())->listActiveTenants(null) as $t) {
                $keys[] = strtolower(trim((string) ($t['key'] ?? '')));
            }
        } else {
            // Single-database install: use the connection as configured.
            $keys[] = '';
        }

        (new SocialMediaDelivery())->purgeOld();

        $total = ['published' => 0, 'failed' => 0, 'retried' => 0];
        foreach (array_filter($keys, static fn ($k): bool => $k !== '') ?: [''] as $key) {
            try {
                if ($key !== '') {
                    if (! (new TenantConnection())->apply($key, 'cli')) {
                        CLI::error('Could not connect to ' . $key);
                        continue;
                    }
                    TenantContext::set($key, 'cli');
                }
                // Built after the tenant switch so models and services use this client's database.
                $r = (new SocialPublishService())->publishDue();
                (new \App\Libraries\SettingsService())->set('social_last_publish_at', date('Y-m-d H:i:s'), 'social', false);
                foreach ($r['data'] as $k => $n) {
                    $total[$k] += $n;
                }
                if (! $r['ok']) {
                    CLI::error(($key !== '' ? $key . ': ' : '') . $r['message']);
                }
            } catch (Throwable $e) {
                CLI::error(($key !== '' ? $key . ': ' : '') . $e->getMessage());
            }
        }

        CLI::write(sprintf('Done. %d published, %d retried, %d failed.', $total['published'], $total['retried'], $total['failed']), 'green');

        return EXIT_SUCCESS;
    }
}
