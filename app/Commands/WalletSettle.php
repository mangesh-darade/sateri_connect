<?php

declare(strict_types=1);

namespace App\Commands;

use App\Libraries\MasterTenantRepository;
use App\Libraries\TenantConnection;
use App\Libraries\TenantContext;
use App\Libraries\WalletService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Throwable;

/**
 * Daily: give back wallet debits for WhatsApp messages Meta never delivered (so never billed).
 *
 *   php spark wallet:settle              every active client
 *   php spark wallet:settle -tenant acme one client
 */
class WalletSettle extends BaseCommand
{
    protected $group       = 'WhatsApp';
    protected $name        = 'wallet:settle';
    protected $description = 'Refund wallet charges for messages not delivered within 30 days.';
    protected $usage       = 'wallet:settle [-tenant <key>]';
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

        $total = 0;
        foreach (array_filter($keys, static fn ($k): bool => $k !== '') ?: [''] as $key) {
            try {
                if ($key !== '') {
                    if (! (new TenantConnection())->apply($key, 'cli')) {
                        CLI::error('Could not connect to ' . $key);
                        continue;
                    }
                    TenantContext::set($key, 'cli');
                }
                $n = (new WalletService())->releaseStale();
                $total += $n;
                CLI::write(($key !== '' ? $key . ': ' : '') . $n . ' refunded', $n > 0 ? 'green' : 'white');
            } catch (Throwable $e) {
                CLI::error(($key !== '' ? $key . ': ' : '') . $e->getMessage());
            }
        }

        CLI::write('Done. ' . $total . ' undelivered message charge(s) refunded.', 'green');

        return EXIT_SUCCESS;
    }
}
