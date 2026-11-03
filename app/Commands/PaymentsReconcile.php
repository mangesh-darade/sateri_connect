<?php

declare(strict_types=1);

namespace App\Commands;

use App\Libraries\CustomerPaymentLinks;
use App\Libraries\MasterTenantRepository;
use App\Libraries\MswipeService;
use App\Libraries\TenantConnection;
use App\Libraries\TenantContext;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Throwable;

/**
 * Cron, every 10 minutes: re-check open Mswipe payment links with Mswipe and credit the ones that were paid.
 * Needed because the customer may close the Mswipe page before returning, and Mswipe's back-post is unsigned
 * and best-effort.
 *
 *   php spark payments:reconcile              every active client
 *   php spark payments:reconcile -tenant acme one client
 */
class PaymentsReconcile extends BaseCommand
{
    protected $group       = 'Platform';
    protected $name        = 'payments:reconcile';
    protected $description = 'Credit wallets for Mswipe payment links that were paid.';
    protected $usage       = 'payments:reconcile [-tenant <key>]';
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
        }

        // Mswipe is platform-wide; customer payment links are per client and need no platform setup.
        $mswipe = (new MswipeService())->isConfigured();

        $total = 0;
        foreach (array_filter($keys) as $key) {
            try {
                if (! (new TenantConnection())->apply($key, 'cli')) {
                    CLI::error('Could not connect to ' . $key);
                    continue;
                }
                TenantContext::set($key, 'cli');
                if ($mswipe) {
                    $n = (new MswipeService())->reconcilePending();
                    $total += $n;
                    if ($n > 0) {
                        CLI::write($key . ': ' . $n . ' credited', 'green');
                    }
                }
                $links = (new CustomerPaymentLinks())->reconcilePending();
                if ($links > 0) {
                    CLI::write($key . ': ' . $links . ' customer payment link(s) paid', 'green');
                }
            } catch (Throwable $e) {
                CLI::error($key . ': ' . $e->getMessage());
            }
        }
        CLI::write('Done. ' . $total . ' payment' . ($total === 1 ? '' : 's') . ' credited.', 'green');
    }
}
