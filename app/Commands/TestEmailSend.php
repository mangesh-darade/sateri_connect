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

        $subject = 'Emails personalization test — ' . date('Y-m-d H:i:s');
        $body    = "Hello {{name}},\n\nThis is a functional test from the new Emails screen verifying that {{name}} gets replaced with your actual contact name!\n\nProvider: {$provider}\nTime: " . date('c');

        $options = [
            'campaign_name' => 'Cheerio Test Campaign 1',
        ];

        if (CLI::getOption('attach') !== null) {
            $testPdfPath = WRITEPATH . 'test_attachment.pdf';
            if (! file_exists($testPdfPath)) {
                file_put_contents($testPdfPath, "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj 2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj 3 0 obj<</Type/Page/MediaBox[0 0 612 792]/Parent 2 0 R/Resources<<>>>>endobj\nxref\n0 4\n0000000000 65535 f \n0000000009 00000 n \n0000000052 00000 n \n0000000101 00000 n \ntrailer<</Size 4/Root 1 0 R>>\nstartxref\n178\n%%EOF");
            }
            $options['attachments'] = [
                [
                    'path' => $testPdfPath,
                    'name' => 'Sample_Policy_Document.pdf',
                    'mime' => 'application/pdf',
                ],
            ];
            CLI::write('Attaching: ' . $testPdfPath, 'cyan');
        }

        CLI::write('1) Single send…', 'yellow');
        $single = $mailer->send($to, $subject, $body, $options);
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
