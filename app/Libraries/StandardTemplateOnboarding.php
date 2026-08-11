<?php

declare(strict_types=1);

namespace App\Libraries;

use App\Models\TemplateModel;
use Throwable;

/**
 * Ensure a standard utility template exists after WABA onboarding.
 * Does NOT assume Meta auto-approves — status may remain PENDING.
 */
final class StandardTemplateOnboarding
{
    public const STANDARD_NAME     = 'order_confirmation';
    public const STANDARD_LANGUAGE = 'en_US';
    public const STANDARD_CATEGORY = 'UTILITY';
    public const STANDARD_BODY     = 'Hello {{1}}, your order {{2}} has been confirmed.';

    public function __construct(
        private ?WhatsAppCloudAPI $api = null,
        private ?SettingsService $settings = null,
        private ?TemplateModel $model = null
    ) {
        $this->api      = $this->api ?? service('whatsApp');
        $this->settings = $this->settings ?? service('settingsService');
        $this->model    = $this->model ?? model(TemplateModel::class);
    }

    /**
     * @return array{
     *     action: string,
     *     name: string,
     *     language: string,
     *     status: ?string,
     *     template_id: ?string,
     *     message: string
     * }
     */
    public function ensureOrderConfirmation(): array
    {
        if ($this->settings->getWhatsAppProvider() !== SettingsService::PROVIDER_META) {
            return [
                'action'      => 'skipped',
                'name'        => self::STANDARD_NAME,
                'language'    => self::STANDARD_LANGUAGE,
                'status'      => null,
                'template_id' => null,
                'message'     => 'Standard template onboarding applies to Meta provider only.',
            ];
        }

        $meta   = $this->settings->getMetaConfig();
        $wabaId = trim((string) ($meta['waba_id'] ?? ''));
        if ($wabaId === '') {
            return [
                'action'      => 'skipped',
                'name'        => self::STANDARD_NAME,
                'language'    => self::STANDARD_LANGUAGE,
                'status'      => null,
                'template_id' => null,
                'message'     => 'WABA ID missing; cannot create standard template.',
            ];
        }

        $existing = $this->model->findByWabaNameLanguage($wabaId, self::STANDARD_NAME, self::STANDARD_LANGUAGE);
        if ($existing === null) {
            $existing = $this->model->where('name', self::STANDARD_NAME)
                ->where('language', self::STANDARD_LANGUAGE)
                ->first();
        }

        if ($existing !== null) {
            return [
                'action'      => 'exists',
                'name'        => self::STANDARD_NAME,
                'language'    => self::STANDARD_LANGUAGE,
                'status'      => strtoupper((string) ($existing['status'] ?? '')),
                'template_id' => ! empty($existing['meta_id']) ? (string) $existing['meta_id'] : null,
                'message'     => 'Standard template already present locally.',
            ];
        }

        // Check Meta before creating
        try {
            $remote = $this->api->getTemplates($wabaId);
            $rows   = is_array($remote['data'] ?? null) ? $remote['data'] : [];
            foreach ($rows as $row) {
                if (! is_array($row)) {
                    continue;
                }
                if (
                    strtolower((string) ($row['name'] ?? '')) === self::STANDARD_NAME
                    && strtolower((string) ($row['language'] ?? '')) === strtolower(self::STANDARD_LANGUAGE)
                ) {
                    return [
                        'action'      => 'exists_remote',
                        'name'        => self::STANDARD_NAME,
                        'language'    => self::STANDARD_LANGUAGE,
                        'status'      => strtoupper((string) ($row['status'] ?? '')),
                        'template_id' => (string) ($row['id'] ?? ''),
                        'message'     => 'Standard template already exists on Meta. Run Sync Templates.',
                    ];
                }
            }
        } catch (Throwable $e) {
            MetaGraphLogger::log('templates.standard.check_failed', [
                'waba_id'         => $wabaId,
                'phone_number_id' => (string) ($meta['phone_number_id'] ?? ''),
                'template_name'   => self::STANDARD_NAME,
                'detail'          => $e->getMessage(),
            ], 'warning');
        }

        $components = [[
            'type' => 'BODY',
            'text' => self::STANDARD_BODY,
            'example' => [
                'body_text' => [['Customer', 'ORD-12345']],
            ],
        ]];

        try {
            $response = $this->api->createTemplate([
                'name'                  => self::STANDARD_NAME,
                'language'              => self::STANDARD_LANGUAGE,
                'category'              => self::STANDARD_CATEGORY,
                'components'            => $components,
                'allow_category_change' => true,
            ]);

            $responseData = is_array($response['data'] ?? null) ? $response['data'] : $response;
            $metaId       = (string) ($responseData['id'] ?? '');
            $status       = strtoupper((string) ($responseData['status'] ?? 'PENDING'));

            $row = [
                'waba_id'        => $wabaId,
                'meta_id'        => $metaId !== '' ? $metaId : null,
                'name'           => self::STANDARD_NAME,
                'language'       => self::STANDARD_LANGUAGE,
                'category'       => self::STANDARD_CATEGORY,
                'template_type'  => 'default',
                'status'         => $status !== '' ? $status : 'PENDING',
                'body'           => self::STANDARD_BODY,
                'variables'      => ['1', '2'],
                'raw_payload'    => is_array($responseData) ? $responseData : ['components' => $components],
                'synced_at'      => date('Y-m-d H:i:s'),
            ];

            // Only write columns that exist / are allowed
            $allowed = $this->model->allowedFields;
            $insert  = [];
            foreach ($row as $k => $v) {
                if (in_array($k, $allowed, true)) {
                    $insert[$k] = $v;
                }
            }
            $this->model->insert($insert);

            MetaGraphLogger::log('templates.standard.created', [
                'waba_id'         => $wabaId,
                'phone_number_id' => (string) ($meta['phone_number_id'] ?? ''),
                'template_id'     => $metaId,
                'template_name'   => self::STANDARD_NAME,
                'meta_status'     => $status,
            ]);

            return [
                'action'      => 'created',
                'name'        => self::STANDARD_NAME,
                'language'    => self::STANDARD_LANGUAGE,
                'status'      => $status,
                'template_id' => $metaId !== '' ? $metaId : null,
                'message'     => $status === 'APPROVED'
                    ? 'Standard template created and approved.'
                    : 'Standard template submitted to Meta and is awaiting review (' . $status . ').',
            ];
        } catch (Throwable $e) {
            MetaGraphLogger::log('templates.standard.create_failed', [
                'waba_id'         => $wabaId,
                'phone_number_id' => (string) ($meta['phone_number_id'] ?? ''),
                'template_name'   => self::STANDARD_NAME,
                'detail'          => $e->getMessage(),
            ], 'error');

            return [
                'action'      => 'failed',
                'name'        => self::STANDARD_NAME,
                'language'    => self::STANDARD_LANGUAGE,
                'status'      => null,
                'template_id' => null,
                'message'     => MetaApiErrorMapper::humanize($e->getMessage(), (int) $e->getCode()),
            ];
        }
    }
}
