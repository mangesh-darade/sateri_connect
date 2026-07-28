<?php

namespace App\Commands;

use App\Libraries\WhatsAppCloudAPI;
use App\Models\TemplateModel;
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
            $api      = new WhatsAppCloudAPI();
            $response = $api->getTemplates();
            $data     = $response['data'] ?? [];

            if (! is_array($data) || $data === []) {
                CLI::write('No templates returned from ' . $provider . '.', 'yellow');

                return EXIT_SUCCESS;
            }

            $model = model(TemplateModel::class);
            $synced = 0;
            $now    = date('Y-m-d H:i:s');

            foreach ($data as $tpl) {
                if (! is_array($tpl) || empty($tpl['name'])) {
                    continue;
                }

                $metaId   = (string) ($tpl['id'] ?? '');
                $name     = (string) $tpl['name'];
                $language = (string) ($tpl['language'] ?? 'en');
                $components = $tpl['components'] ?? [];

                $parsed = $this->parseComponents(is_array($components) ? $components : []);

                $row = [
                    'meta_id'        => $metaId,
                    'name'           => $name,
                    'language'       => $language,
                    'category'       => $tpl['category'] ?? null,
                    'status'         => $tpl['status'] ?? null,
                    'header_type'    => $parsed['header_type'],
                    'header_content' => $parsed['header_content'],
                    'body'           => $parsed['body'],
                    'footer'         => $parsed['footer'],
                    'buttons'        => $parsed['buttons'] !== null ? json_encode($parsed['buttons']) : null,
                    'variables'      => $parsed['variables'] !== null ? json_encode($parsed['variables']) : null,
                    'raw_payload'    => json_encode($tpl),
                    'synced_at'      => $now,
                ];

                $existing = null;
                if ($metaId !== '') {
                    $existing = $model->where('meta_id', $metaId)->first();
                }
                if ($existing === null) {
                    $existing = $model->where('name', $name)->where('language', $language)->first();
                }

                if ($existing !== null) {
                    $model->update((int) $existing['id'], $row);
                } else {
                    $model->insert($row);
                }
                $synced++;
            }

            CLI::write("Synced {$synced} template(s).", 'green');
        } catch (Throwable $e) {
            CLI::error('Template sync failed: ' . $e->getMessage());

            return EXIT_ERROR;
        }

        return EXIT_SUCCESS;
    }

    /**
     * @param list<array<string, mixed>> $components
     *
     * @return array{
     *     header_type: ?string,
     *     header_content: ?string,
     *     body: ?string,
     *     footer: ?string,
     *     buttons: ?array,
     *     variables: ?array
     * }
     */
    protected function parseComponents(array $components): array
    {
        $result = [
            'header_type'    => null,
            'header_content' => null,
            'body'           => null,
            'footer'         => null,
            'buttons'        => null,
            'variables'      => [],
        ];

        foreach ($components as $component) {
            $type = strtoupper((string) ($component['type'] ?? ''));

            switch ($type) {
                case 'HEADER':
                    $result['header_type']    = strtolower((string) ($component['format'] ?? 'text'));
                    $result['header_content'] = $component['text'] ?? ($component['example']['header_text'][0] ?? null);
                    break;

                case 'BODY':
                    $result['body'] = $component['text'] ?? null;
                    if (! empty($component['example']['body_text'][0]) && is_array($component['example']['body_text'][0])) {
                        $result['variables'] = $component['example']['body_text'][0];
                    }
                    break;

                case 'FOOTER':
                    $result['footer'] = $component['text'] ?? null;
                    break;

                case 'BUTTONS':
                    $result['buttons'] = $component['buttons'] ?? null;
                    break;
            }
        }

        if ($result['variables'] === []) {
            $result['variables'] = null;
        }

        return $result;
    }
}
