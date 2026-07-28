<?php

declare(strict_types=1);

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Throwable;

class TestTemplateSend extends BaseCommand
{
    protected $group       = 'Cheerio';
    protected $name        = 'cheerio:test-template';
    protected $description = 'Test sending a WhatsApp template via Cheerio.';
    protected $usage       = 'cheerio:test-template [name] [language] [to]';

    public function run(array $params)
    {
        $name     = (string) ($params[0] ?? 'dassera');
        $language = (string) ($params[1] ?? 'en');
        $to       = (string) ($params[2] ?? '917744010738');

        try {
            $api = service('whatsApp');
            $components = $api->ensureTemplateComponents($name, $language, []);
            CLI::write('Components: ' . json_encode($components, JSON_UNESCAPED_SLASHES), 'yellow');
            $result = $api->sendTemplate($to, $name, $language, []);
            CLI::write('OK: ' . json_encode($result, JSON_UNESCAPED_SLASHES), 'green');
        } catch (Throwable $e) {
            CLI::error($e->getMessage());
        }
    }
}
