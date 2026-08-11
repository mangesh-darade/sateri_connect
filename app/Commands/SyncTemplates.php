<?php

namespace App\Commands;

use App\Libraries\TemplateSyncService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Throwable;

class SyncTemplates extends BaseCommand
{
    protected $group       = 'WhatsApp';
    protected $name        = 'templates:sync';
    protected $description = 'Sync message templates from the active WhatsApp provider (Cheerio or Meta) into the local database.';
    protected $usage       = 'templates:sync';

    public function run(array $params)
    {
        $provider = service('settingsService')->getWhatsAppProvider();
        CLI::write('Syncing templates from ' . $provider . '...', 'yellow');

        try {
            $result = (new TemplateSyncService())->sync();
            CLI::write("Synced {$result['synced']} template(s) (inserted {$result['inserted']}, updated {$result['updated']}).", 'green');
            if (($result['disabled'] ?? 0) > 0) {
                CLI::write("Disabled {$result['disabled']} local template(s) not returned by {$provider}.", 'yellow');
            }
            CLI::write('WABA: ' . ($result['waba_id'] ?: '(none)'));
            $counts = $result['status_counts'] ?? [];
            CLI::write(sprintf(
                'Status — Approved: %d · Pending: %d · Rejected: %d · Disabled: %d',
                (int) ($counts['APPROVED'] ?? 0),
                (int) ($counts['PENDING'] ?? 0),
                (int) ($counts['REJECTED'] ?? 0),
                (int) ($counts['DISABLED'] ?? 0)
            ));
            if (! empty($result['hello_world']['exists'])) {
                $hw = $result['hello_world']['template'] ?? [];
                CLI::write('hello_world EXISTS on this WABA: id=' . ($hw['template_id'] ?? '')
                    . ' status=' . ($hw['status'] ?? '') . ' lang=' . ($hw['language'] ?? ''), 'green');
            } else {
                CLI::write('hello_world NOT AVAILABLE on this WABA (do not treat Meta API Setup sample as production).', 'yellow');
            }
        } catch (Throwable $e) {
            CLI::error('Template sync failed: ' . $e->getMessage());

            return EXIT_ERROR;
        }

        return EXIT_SUCCESS;
    }
}
