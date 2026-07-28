<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Functional smoke test for Emails single/bulk via active provider.
 */
class TestEmailSend extends BaseCommand
{
    protected $group       = 'App';
    protected $name        = 'app:test-email-send';
    protected $description = 'Send a single (and optional tiny bulk) test email via emailProvider.';
    protected $usage       = 'app:test-email-send [to] [--bulk]';
    protected $arguments   = [
        'to' => 'Recipient email (default: sateri.mangesh@gmail.com)',
    ];
    protected $options     = [
        '--bulk' => 'Also send a 1-recipient bulk campaign',
    ];

    public function run(array $params)
    {
        $to = trim((string) ($params[0] ?? 'sateri.mangesh@gmail.com'));
        if (! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            CLI::error('Invalid email: ' . $to);

            return;
        }

        $mailer   = service('emailProvider');
        $provider = $mailer->getProvider();
        CLI::write('Active email provider: ' . $provider, 'white');
        CLI::write('Recipient: ' . $to, 'white');
        CLI::newLine();

        $subject = 'Emails screen test — ' . date('Y-m-d H:i:s');
        $body    = "Hello,\n\nThis is a functional test from the new Emails (single send) screen.\n\nProvider: {$provider}\nTime: " . date('c');

        CLI::write('1) Single send…', 'yellow');
        $single = $mailer->send($to, $subject, $body, [
            'campaign_name' => 'Cheerio Test Campaign 1',
        ]);
        CLI::write(json_encode($single, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), $single['ok'] ? 'green' : 'red');

        if (CLI::getOption('bulk') !== null) {
            CLI::newLine();
            CLI::write('2) Bulk send (1 recipient)…', 'yellow');
            $bulk = $mailer->sendCampaign([
                'name'          => 'Emails bulk smoke',
                'subject'       => 'Emails bulk test — ' . date('Y-m-d H:i:s'),
                'plain_text'    => "Bulk smoke test via {$provider}.",
                'recipients'    => [$to],
                'campaign_name' => 'Cheerio Test Campaign 2',
            ]);
            CLI::write(json_encode($bulk, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), $bulk['ok'] ? 'green' : 'red');
        }

        CLI::newLine();
        if (! ($single['ok'] ?? false)) {
            CLI::error('Single send FAILED.');

            return EXIT_ERROR;
        }

        CLI::write('Single send OK.', 'green');

        return EXIT_SUCCESS;
    }
}
