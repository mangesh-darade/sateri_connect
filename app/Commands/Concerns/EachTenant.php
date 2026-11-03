<?php

declare(strict_types=1);

namespace App\Commands\Concerns;

use App\Libraries\MasterTenantRepository;
use App\Libraries\TenantConnection;
use App\Libraries\TenantContext;
use CodeIgniter\CLI\CLI;
use Throwable;

/**
 * For cron commands that run once per client: `-tenant <key>` for one client, otherwise every active client
 * (or the single configured database when there is no master DB). The callback runs after the tenant switch,
 * so build services and models inside it.
 */
trait EachTenant
{
    /**
     * @param callable(string): void $fn Receives the tenant key ('' for a single-database install).
     */
    protected function eachTenant(callable $fn): void
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
            $keys[] = '';
        }

        foreach (array_filter($keys, static fn ($k): bool => $k !== '') ?: [''] as $key) {
            try {
                if ($key !== '') {
                    if (! (new TenantConnection())->apply($key, 'cli')) {
                        CLI::error($key . ': could not connect');
                        continue;
                    }
                    TenantContext::set($key, 'cli');
                }
                $fn($key);
            } catch (Throwable $e) {
                CLI::error(($key !== '' ? $key . ': ' : '') . $e->getMessage());
            }
        }
    }
}
