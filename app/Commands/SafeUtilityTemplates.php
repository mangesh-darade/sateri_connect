<?php

declare(strict_types=1);

namespace App\Commands;

use App\Libraries\ActivityLogger;
use App\Models\TemplateModel;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Submit Meta-safe Utility en_US templates (highest approval chance content).
 *
 *   php spark templates:safe-utility
 */
class SafeUtilityTemplates extends BaseCommand
{
    protected $group       = 'Templates';
    protected $name        = 'templates:safe-utility';
    protected $description = 'Submit Meta-safe simple Utility en_US templates.';
    protected $usage       = 'templates:safe-utility';

    public function run(array $params)
    {
        $wabaId = trim((string) (service('settingsService')->getMetaConfig()['waba_id'] ?? ''));
        $api    = service('whatsApp');
        $model  = model(TemplateModel::class);

        $templates = [
            [
                'name' => 'appt_reminder_en',
                'body' => 'Hello {{1}}, this is a reminder that your service appointment is scheduled on {{2}} at {{3}}. Please be available.',
                'examples' => [['Rahul', '12 Aug 2026', '11:00 AM']],
                'variables' => ['1', '2', '3'],
            ],
            [
                'name' => 'request_closed_en',
                'body' => 'Hello {{1}}, your maintenance request {{2}} has been completed. Thank you for contacting us.',
                'examples' => [['Rahul', 'REQ-2045']],
                'variables' => ['1', '2'],
            ],
            [
                'name' => 'visit_confirm_en',
                'body' => 'Hello {{1}}, your technician visit is confirmed for {{2}}. Our team will arrive between {{3}}.',
                'examples' => [['Rahul', '13 Aug 2026', '10 AM to 12 PM']],
                'variables' => ['1', '2', '3'],
            ],
        ];

        foreach ($templates as $spec) {
            $existing = $model->where('name', $spec['name'])->where('language', 'en_US')->first();
            if ($existing !== null) {
                $st = strtoupper((string) ($existing['status'] ?? ''));
                if (in_array($st, ['PENDING', 'APPROVED', 'IN_REVIEW'], true)) {
                    CLI::write('SKIP ' . $spec['name'] . ' status=' . $st, 'yellow');
                    continue;
                }
            }

            CLI::write('Submitting ' . $spec['name'] . '...', 'yellow');
            try {
                $components = [[
                    'type' => 'BODY',
                    'text' => $spec['body'],
                    'example' => ['body_text' => $spec['examples']],
                ]];
                $response = $api->createTemplate([
                    'name' => $spec['name'],
                    'language' => 'en_US',
                    'category' => 'UTILITY',
                    'components' => $components,
                    'allow_category_change' => true,
                ]);
                $data = is_array($response['data'] ?? null) ? $response['data'] : $response;
                $metaId = (string) ($data['id'] ?? '');
                $status = strtoupper((string) ($data['status'] ?? 'PENDING')) ?: 'PENDING';

                $row = [
                    'waba_id' => $wabaId !== '' ? $wabaId : null,
                    'meta_id' => $metaId !== '' ? $metaId : null,
                    'name' => $spec['name'],
                    'language' => 'en_US',
                    'category' => 'UTILITY',
                    'template_type' => 'default',
                    'status' => $status,
                    'body' => $spec['body'],
                    'variables' => $spec['variables'],
                    'raw_payload' => array_merge(is_array($data) ? $data : [], [
                        'name' => $spec['name'],
                        'language' => 'en_US',
                        'category' => 'UTILITY',
                        'components' => $components,
                    ]),
                    'synced_at' => date('Y-m-d H:i:s'),
                ];
                $insert = [];
                foreach ($row as $k => $v) {
                    if (in_array($k, $model->allowedFields, true)) {
                        $insert[$k] = $v;
                    }
                }
                if ($existing !== null) {
                    $model->update((int) $existing['id'], $insert);
                } else {
                    $model->insert($insert);
                }

                (new ActivityLogger())->log('create', 'templates', 'Safe utility ' . $spec['name'], [
                    'meta_id' => $metaId,
                    'status' => $status,
                ]);
                CLI::write('OK ' . $spec['name'] . ' → ' . $status . ' id=' . $metaId, $status === 'REJECTED' ? 'red' : 'green');
            } catch (\Throwable $e) {
                CLI::error('FAIL ' . $spec['name'] . ': ' . $e->getMessage());
            }
        }

        return EXIT_SUCCESS;
    }
}
