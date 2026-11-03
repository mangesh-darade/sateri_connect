<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\EachTenant;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Every 30 minutes: pull each client's WhatsApp quality rating and messaging limit from Meta,
 * so drops are flagged (and campaigns paused on RED) even when no webhook arrives.
 *
 *   php spark whatsapp:quality-check              every active client
 *   php spark whatsapp:quality-check -tenant acme one client
 */
class WhatsAppQualityCheck extends BaseCommand
{
    use EachTenant;

    protected $group       = 'WhatsApp';
    protected $name        = 'whatsapp:quality-check';
    protected $description = 'Refresh the WhatsApp quality rating and messaging limit and raise alerts on changes.';
    protected $usage       = 'whatsapp:quality-check [-tenant <key>]';
    protected $options     = ['-tenant' => 'Only this client key (default: all active clients)'];

    public function run(array $params)
    {
        $this->eachTenant(static function (string $key): void {
            // Built after the tenant switch so it reads this client's settings.
            $rating = service('whatsAppConsent')->refreshQualityRating(true);
            CLI::write(($key !== '' ? $key . ': ' : '') . ($rating !== '' ? $rating : 'no rating'), $rating === 'RED' ? 'red' : 'white');
        });

        return EXIT_SUCCESS;
    }
}
