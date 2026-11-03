<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\EachTenant;
use App\Libraries\OutgoingWebhooks;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Every minute: send the queued outgoing webhook calls (with retries) and drop old log rows.
 *
 *   php spark webhooks:deliver              every active client
 *   php spark webhooks:deliver -tenant acme one client
 */
class WebhooksDeliver extends BaseCommand
{
    use EachTenant;

    protected $group       = 'WhatsApp';
    protected $name        = 'webhooks:deliver';
    protected $description = 'Deliver queued outgoing webhook calls.';
    protected $usage       = 'webhooks:deliver [-tenant <key>]';
    protected $options     = ['-tenant' => 'Only this client key (default: all active clients)'];

    public function run(array $params)
    {
        $this->eachTenant(static function (string $key): void {
            $hooks = new OutgoingWebhooks();
            $n     = $hooks->deliverDue(50);
            $hooks->cleanup();
            if ($n > 0) {
                CLI::write(($key !== '' ? $key . ': ' : '') . $n . ' webhook call(s) attempted');
            }
        });

        return EXIT_SUCCESS;
    }
}
