<?php

declare(strict_types=1);

namespace App\Commands;

use App\Libraries\MetaGraphLogger;
use App\Libraries\TemplateSyncService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Throwable;

/**
 * Inspect Meta WABA templates and report whether hello_world exists.
 *
 *   php spark templates:inspect
 *   php spark templates:inspect --sync
 */
class InspectTemplates extends BaseCommand
{
    protected $group       = 'WhatsApp';
    protected $name        = 'templates:inspect';
    protected $description = 'Fetch WABA templates from Meta and report hello_world / approval counts.';
    protected $usage       = 'templates:inspect [--sync]';
    protected $options     = [
        '--sync' => 'Also persist sync into local templates table',
    ];

    public function run(array $params)
    {
        $settings = service('settingsService');
        $meta     = $settings->getMetaConfig();
        $wabaId   = trim((string) ($meta['waba_id'] ?? ''));
        $phoneId  = trim((string) ($meta['phone_number_id'] ?? ''));
        $appId    = trim((string) ($meta['app_id'] ?? ''));
        $tokenSet = trim((string) ($meta['access_token'] ?? '')) !== '';

        CLI::write('Customer/tenant: ' . MetaGraphLogger::currentCustomerId());
        CLI::write('WhatsApp provider: ' . $settings->getWhatsAppProvider());
        CLI::write('App ID: ' . ($appId !== '' ? $appId : '(missing)'));
        CLI::write('WABA ID: ' . ($wabaId !== '' ? $wabaId : '(missing)'));
        CLI::write('Phone Number ID: ' . ($phoneId !== '' ? $phoneId : '(missing)'));
        CLI::write('Access token: ' . ($tokenSet ? 'configured (not shown)' : 'MISSING'));

        if (! $tokenSet || $wabaId === '') {
            CLI::error('Cannot call Meta without access token + WABA ID.');

            return EXIT_ERROR;
        }

        $doSync = CLI::getOption('sync') !== null || in_array('--sync', $params, true);

        try {
            if ($doSync) {
                $result = (new TemplateSyncService())->sync($wabaId);
                CLI::write('Synced ' . $result['synced'] . ' template(s).', 'green');
                $hello = $result['hello_world'];
                $counts = $result['status_counts'];
            } else {
                $sync = new TemplateSyncService();
                $hello = $sync->findHelloWorldFromMeta();
                $api = service('whatsApp');
                $response = $api->getTemplates($wabaId);
                $rows = is_array($response['data'] ?? null) ? $response['data'] : [];
                $counts = ['APPROVED' => 0, 'PENDING' => 0, 'REJECTED' => 0, 'DISABLED' => 0, 'OTHER' => 0];
                CLI::newLine();
                CLI::write('Templates from GET /' . $wabaId . '/message_templates:', 'yellow');
                foreach ($rows as $row) {
                    if (! is_array($row)) {
                        continue;
                    }
                    $status = strtoupper((string) ($row['status'] ?? ''));
                    if ($status === 'APPROVED') {
                        $counts['APPROVED']++;
                    } elseif (in_array($status, ['PENDING', 'IN_REVIEW', 'IN_PROGRESS'], true)) {
                        $counts['PENDING']++;
                    } elseif ($status === 'REJECTED') {
                        $counts['REJECTED']++;
                    } elseif (in_array($status, ['DISABLED', 'DELETED', 'PAUSED'], true)) {
                        $counts['DISABLED']++;
                    } else {
                        $counts['OTHER']++;
                    }
                    CLI::write(sprintf(
                        ' - %s | %s | %s | %s | id=%s',
                        (string) ($row['name'] ?? ''),
                        (string) ($row['language'] ?? ''),
                        (string) ($row['category'] ?? ''),
                        $status,
                        (string) ($row['id'] ?? '')
                    ));
                }
                CLI::write('Total: ' . count($rows));
            }

            CLI::newLine();
            CLI::write(sprintf(
                'Templates: %d · Approved: %d · Pending: %d · Rejected: %d',
                array_sum($counts),
                (int) ($counts['APPROVED'] ?? 0),
                (int) ($counts['PENDING'] ?? 0),
                (int) ($counts['REJECTED'] ?? 0)
            ), 'green');

            if (! empty($hello['exists'])) {
                $hw = $hello['template'] ?? [];
                CLI::write('hello_world: EXISTS', 'green');
                CLI::write('  template_id: ' . ($hw['template_id'] ?? ''));
                CLI::write('  language: ' . ($hw['language'] ?? ''));
                CLI::write('  category: ' . ($hw['category'] ?? ''));
                CLI::write('  status: ' . ($hw['status'] ?? ''));
            } else {
                CLI::write('hello_world: NOT AVAILABLE for this WABA', 'yellow');
                CLI::write('Meta API Setup may still show hello_world as a sample — that does NOT mean it exists here.');
            }
        } catch (Throwable $e) {
            CLI::error($e->getMessage());

            return EXIT_ERROR;
        }

        return EXIT_SUCCESS;
    }
}
