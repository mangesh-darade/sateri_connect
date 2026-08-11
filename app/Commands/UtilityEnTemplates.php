<?php

declare(strict_types=1);

namespace App\Commands;

use App\Libraries\ActivityLogger;
use App\Libraries\StandardTemplateOnboarding;
use App\Models\TemplateModel;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Submit simple Utility en_US templates for Meta review.
 *
 *   php spark templates:utility-en
 */
class UtilityEnTemplates extends BaseCommand
{
    protected $group       = 'Templates';
    protected $name        = 'templates:utility-en';
    protected $description = 'Submit simple Utility en_US templates for Meta review.';
    protected $usage       = 'templates:utility-en';

    public function run(array $params)
    {
        $settings = service('settingsService');
        $meta     = $settings->getMetaConfig();
        $wabaId   = trim((string) ($meta['waba_id'] ?? ''));
        CLI::write('WABA: ' . $wabaId);
        CLI::write('Phone: ' . (string) ($meta['phone_number_id'] ?? ''));

        $templates = [
            [
                'name'      => 'service_update_en',
                'language'  => 'en_US',
                'category'  => 'UTILITY',
                'body'      => 'Hello {{1}}, your service request {{2}} has been updated successfully. Thank you.',
                'examples'  => [['Customer', 'SR-1001']],
                'variables' => ['1', '2'],
            ],
            [
                'name'      => 'account_notice_en',
                'language'  => 'en_US',
                'category'  => 'UTILITY',
                'body'      => 'Dear {{1}}, your account update has been completed. Reference number is {{2}}. Please keep this message for your records.',
                'examples'  => [['Vipin', 'ACC-7788']],
                'variables' => ['1', '2'],
            ],
        ];

        $api   = service('whatsApp');
        $model = model(TemplateModel::class);

        foreach ($templates as $spec) {
            $existing = $model->where('name', $spec['name'])->where('language', $spec['language'])->first();
            if ($existing !== null && strtoupper((string) ($existing['status'] ?? '')) !== 'REJECTED') {
                CLI::write('SKIP ' . $spec['name'] . ' — already local status=' . ($existing['status'] ?? ''), 'yellow');
                continue;
            }

            CLI::write('Submitting ' . $spec['name'] . '...', 'yellow');
            try {
                $components = [[
                    'type'    => 'BODY',
                    'text'    => $spec['body'],
                    'example' => ['body_text' => $spec['examples']],
                ]];
                $response = $api->createTemplate([
                    'name'                  => $spec['name'],
                    'language'              => $spec['language'],
                    'category'              => $spec['category'],
                    'components'            => $components,
                    'allow_category_change' => true,
                ]);
                $data   = is_array($response['data'] ?? null) ? $response['data'] : $response;
                $metaId = (string) ($data['id'] ?? '');
                $status = strtoupper((string) ($data['status'] ?? 'PENDING')) ?: 'PENDING';

                $row = [
                    'waba_id'       => $wabaId !== '' ? $wabaId : null,
                    'meta_id'       => $metaId !== '' ? $metaId : null,
                    'name'          => $spec['name'],
                    'language'      => $spec['language'],
                    'category'      => $spec['category'],
                    'template_type' => 'default',
                    'status'        => $status,
                    'body'          => $spec['body'],
                    'variables'     => $spec['variables'],
                    'raw_payload'   => array_merge(is_array($data) ? $data : [], [
                        'name'       => $spec['name'],
                        'language'   => $spec['language'],
                        'category'   => $spec['category'],
                        'components' => $components,
                    ]),
                    'synced_at'     => date('Y-m-d H:i:s'),
                ];
                $allowed = $model->allowedFields;
                $insert  = [];
                foreach ($row as $k => $v) {
                    if (in_array($k, $allowed, true)) {
                        $insert[$k] = $v;
                    }
                }
                if ($existing !== null) {
                    $model->update((int) $existing['id'], $insert);
                } else {
                    $model->insert($insert);
                }

                (new ActivityLogger())->log('create', 'templates', 'Utility EN template ' . $spec['name'] . ' submitted', [
                    'meta_id' => $metaId,
                    'status'  => $status,
                    'waba_id' => $wabaId,
                ]);

                CLI::write('OK ' . $spec['name'] . ' → ' . $status . ' id=' . $metaId, 'green');
            } catch (\Throwable $e) {
                CLI::error('FAIL ' . $spec['name'] . ': ' . $e->getMessage());
            }
        }

        CLI::newLine();
        CLI::write('Also ensuring order_confirmation...', 'yellow');
        $std = (new StandardTemplateOnboarding())->ensureOrderConfirmation();
        CLI::write(($std['action'] ?? '') . ' — ' . ($std['message'] ?? '') . ' status=' . ($std['status'] ?? ''), 'green');

        CLI::newLine();
        CLI::write('Done. Wait for Meta APPROVED, then: php spark templates:sync');

        return EXIT_SUCCESS;
    }
}
