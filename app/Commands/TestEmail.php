<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class TestEmail extends BaseCommand
{
    protected $group       = 'App';
    protected $name        = 'app:test-email';
    protected $description = 'Send a test email to verify the active email provider.';
    protected $usage       = 'app:test-email <to>';
    protected $arguments   = ['to' => 'Recipient email address'];

    public function run(array $params)
    {
        $to = $params[0] ?? '';
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            CLI::error('Usage: php spark app:test-email user@example.com');
            return;
        }

        $mailer   = service('emailProvider');
        $provider = $mailer->getProvider();
        CLI::write("Active email provider: {$provider}", 'yellow');

        $result = $mailer->testConnection($to);
        CLI::write(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        if ($result['ok'] ?? false) {
            CLI::write('SUCCESS', 'green');
        } else {
            CLI::error('FAILED: ' . ($result['message'] ?? 'Unknown error'));
        }
    }
}
