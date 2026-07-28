<?php

declare(strict_types=1);

namespace App\Commands;

use App\Libraries\ActivityLogger;
use App\Models\TemplateModel;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Throwable;

/**
 * Submit a simple UTILITY lab test template via Cheerio.
 *
 * php spark templates:create-test
 */
class CreateTestTemplate extends BaseCommand
{
    protected $group       = 'Templates';
    protected $name        = 'templates:create-test';
    protected $description = 'Submit a UTILITY lab testing template via Cheerio and store locally.';
    protected $usage       = 'templates:create-test [name]';

    public function run(array $params)
    {
        $suffix = date('mdHi');
        $name   = strtolower((string) ($params[0] ?? ('lab_test_hello_' . $suffix)));
        $name   = preg_replace('/[^a-z0-9_]/', '_', $name) ?: ('lab_test_hello_' . $suffix);

        $language = 'en_US';
        $category = 'UTILITY';
        $header   = 'Update';
        $body     = 'Hello {{1}}, your request has been received. Reference ID: {{2}}. We will get back to you shortly.';
        $footer   = 'Lab test template';
        $examples = 'Ravi,LAB-' . $suffix;

        $components = [
            [
                'type'   => 'HEADER',
                'format' => 'TEXT',
                'text'   => $header,
            ],
            [
                'type' => 'BODY',
                'text' => $body,
                'example' => [
                    'body_text' => [['Ravi', 'LAB-' . $suffix]],
                ],
            ],
            [
                'type' => 'FOOTER',
                'text' => $footer,
            ],
        ];

        CLI::write("Submitting template: {$name} ({$language} / {$category})", 'yellow');

        try {
            $api      = service('whatsApp');
            $response = $api->createTemplate([
                'name'                  => $name,
                'language'              => $language,
                'category'              => $category,
                'components'            => $components,
                'allow_category_change' => true,
            ]);

            $metaId = (string) ($response['id'] ?? '');
            $status = strtoupper((string) ($response['status'] ?? 'PENDING'));

            model(TemplateModel::class)->insert([
                'meta_id'        => $metaId !== '' ? $metaId : null,
                'name'           => $name,
                'language'       => $language,
                'category'       => $category,
                'status'         => $status !== '' ? $status : 'PENDING',
                'header_type'    => 'TEXT',
                'header_content' => $header,
                'body'           => $body,
                'footer'         => $footer,
                'buttons'        => null,
                'variables'      => ['1', '2'],
                'raw_payload'    => $response,
                'synced_at'      => date('Y-m-d H:i:s'),
            ]);

            (new ActivityLogger())->log('create', 'templates', "Lab test template {$name} submitted", [
                'meta_id' => $metaId,
                'status'  => $status,
            ]);

            CLI::write("OK — submitted to Cheerio as {$status}", 'green');
            CLI::write("Name: {$name}");
            CLI::write("Provider ID: " . ($metaId !== '' ? $metaId : '(none yet)'));
            CLI::write('Open: http://localhost/whstapp/public/templates');
            CLI::write('After Cheerio APPROVED → Templates → Sync, then use in Campaigns/Chat.');
        } catch (Throwable $e) {
            CLI::error('Failed: ' . $e->getMessage());

            return EXIT_ERROR;
        }

        return EXIT_SUCCESS;
    }
}
