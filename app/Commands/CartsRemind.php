<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\EachTenant;
use App\Libraries\CartRecoveryService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Every minute: queue the WhatsApp abandoned cart reminders that are due.
 *
 *   php spark carts:remind              every active client
 *   php spark carts:remind -tenant acme one client
 */
class CartsRemind extends BaseCommand
{
    use EachTenant;

    protected $group       = 'WhatsApp';
    protected $name        = 'carts:remind';
    protected $description = 'Queue due abandoned cart reminders (WooCommerce, Shopify).';
    protected $usage       = 'carts:remind [-tenant <key>]';
    protected $options     = ['-tenant' => 'Only this client key (default: all active clients)'];

    public function run(array $params)
    {
        $this->eachTenant(static function (string $key): void {
            $n = (new CartRecoveryService())->remindDue(50);
            if ($n > 0) {
                CLI::write(($key !== '' ? $key . ': ' : '') . $n . ' cart reminder(s) queued');
            }
        });

        return EXIT_SUCCESS;
    }
}
