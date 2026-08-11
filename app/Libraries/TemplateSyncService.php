<?php

declare(strict_types=1);

namespace App\Libraries;

use App\Models\TemplateModel;
use RuntimeException;
use Throwable;

/**
 * Sync WhatsApp message templates from the active provider into local `templates`.
 * Unique mapping: waba_id + template_id (meta_id). Never duplicates on re-sync.
 */
final class TemplateSyncService
{
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
     *     synced: int,
     *     inserted: int,
     *     updated: int,
     *     disabled: int,
     *     waba_id: string,
     *     provider: string,
     *     hello_world: array{exists: bool, template: ?array<string, mixed>},
     *     status_counts: array{APPROVED: int, PENDING: int, REJECTED: int, DISABLED: int, OTHER: int},
     *     last_synced_at: string
     * }
     */
    public function sync(?string $expectedWabaId = null): array
    {
        $meta   = $this->settings->getMetaConfig();
        $wabaId = trim((string) ($meta['waba_id'] ?? ''));
        $phone  = trim((string) ($meta['phone_number_id'] ?? ''));
        $provider = $this->settings->getWhatsAppProvider();

        if ($expectedWabaId !== null && $expectedWabaId !== '') {
            if ($wabaId === '' || ! hash_equals($wabaId, $expectedWabaId)) {
                MetaGraphLogger::log('templates.sync.denied', [
                    'waba_id'          => $wabaId,
                    'phone_number_id'  => $phone,
                    'expected_waba_id' => $expectedWabaId,
                    'meta_status'      => 403,
                ], 'warning');

                throw new RuntimeException('Access denied: WABA does not belong to this customer account.', 403);
            }
        }

        // Never trust a caller-supplied WABA for Meta fetch — always use tenant settings.
        $fetchWaba = $provider === SettingsService::PROVIDER_META ? $wabaId : null;
        if ($provider === SettingsService::PROVIDER_META && $fetchWaba === '') {
            throw new RuntimeException('Meta WABA ID is not configured for this customer. Complete WhatsApp onboarding first.');
        }

        MetaGraphLogger::log('templates.sync.start', [
            'waba_id'         => $wabaId,
            'phone_number_id' => $phone,
        ]);

        try {
            $response = $this->api->getTemplates($fetchWaba ?: null);
        } catch (Throwable $e) {
            MetaGraphLogger::log('templates.sync.error', [
                'waba_id'         => $wabaId,
                'phone_number_id' => $phone,
                'detail'          => $e->getMessage(),
                'meta_status'     => (string) $e->getCode(),
            ], 'error');

            throw new RuntimeException(
                MetaApiErrorMapper::humanize($e->getMessage(), (int) $e->getCode()),
                (int) $e->getCode(),
                $e
            );
        }

        $data = $response['data'] ?? [];
        if (! is_array($data)) {
            throw new RuntimeException('Unexpected response while listing WhatsApp templates.');
        }

        $now      = date('Y-m-d H:i:s');
        $synced   = 0;
        $inserted = 0;
        $updated  = 0;
        $seen     = [];
        $helloWorld = null;

        foreach ($data as $tpl) {
            if (! is_array($tpl) || empty($tpl['name'])) {
                continue;
            }

            $componentsList = is_array($tpl['components'] ?? null) ? $tpl['components'] : [];
            $parsed         = $this->parseComponents($componentsList);
            $metaId         = (string) ($tpl['id'] ?? '');
            $name           = (string) $tpl['name'];
            $language       = (string) ($tpl['language'] ?? 'en');
            $status         = strtoupper((string) ($tpl['status'] ?? ''));
            $templateType   = $this->detectTemplateType($tpl, $componentsList);
            $rejectedReason = $this->extractRejectedReason($tpl);

            $seen[] = strtolower(trim($name)) . '|' . strtolower(trim($language));

            $row = [
                'waba_id'         => $wabaId !== '' ? $wabaId : null,
                'meta_id'         => $metaId !== '' ? $metaId : null,
                'name'            => $name,
                'language'        => $language,
                'category'        => $tpl['category'] ?? null,
                'template_type'   => $templateType,
                'status'          => $status !== '' ? $status : null,
                'rejected_reason' => $rejectedReason,
                'header_type'     => $parsed['header_type'],
                'header_content'  => $parsed['header_content'],
                'body'            => $parsed['body'],
                'footer'          => $parsed['footer'],
                'buttons'         => $parsed['buttons'],
                'variables'       => $parsed['variables'],
                'raw_payload'     => $tpl,
                'synced_at'       => $now,
            ];

            // Drop columns that are not yet migrated on older DBs.
            $row = $this->filterWritableFields($row);

            $existing = $this->findExisting($wabaId, $metaId, $name, $language);
            if ($existing !== null) {
                $this->model->update((int) $existing['id'], $row);
                $updated++;
                $saved = array_merge($existing, $row);
            } else {
                $this->model->insert($row);
                $inserted++;
                $saved = $row;
            }
            $synced++;

            if (strtolower($name) === 'hello_world' && $helloWorld === null) {
                $helloWorld = [
                    'template_id' => $metaId,
                    'name'        => $name,
                    'language'    => $language,
                    'category'    => $tpl['category'] ?? null,
                    'status'      => $status,
                    'components'  => $componentsList,
                ];
            }

            unset($saved);
        }

        $disabled = $this->model->disableMissingFromSync($seen, $wabaId !== '' ? $wabaId : null);

        $statusCounts = $this->model->countByStatus($wabaId !== '' ? $wabaId : null);

        MetaGraphLogger::log('templates.sync.done', [
            'waba_id'         => $wabaId,
            'phone_number_id' => $phone,
            'meta_status'     => 200,
            'detail'          => "synced={$synced};inserted={$inserted};updated={$updated};disabled={$disabled}",
        ]);

        return [
            'synced'         => $synced,
            'inserted'       => $inserted,
            'updated'        => $updated,
            'disabled'       => $disabled,
            'waba_id'        => $wabaId,
            'provider'       => $provider,
            'hello_world'    => [
                'exists'   => $helloWorld !== null,
                'template' => $helloWorld,
            ],
            'status_counts'  => $statusCounts,
            'last_synced_at' => $now,
        ];
    }

    /**
     * @return array{exists: bool, template: ?array<string, mixed>}
     */
    public function findHelloWorldFromMeta(): array
    {
        $meta   = $this->settings->getMetaConfig();
        $wabaId = trim((string) ($meta['waba_id'] ?? ''));
        if ($wabaId === '') {
            return ['exists' => false, 'template' => null];
        }

        $response = $this->api->getTemplates($wabaId);
        $data     = is_array($response['data'] ?? null) ? $response['data'] : [];

        foreach ($data as $tpl) {
            if (! is_array($tpl)) {
                continue;
            }
            if (strtolower((string) ($tpl['name'] ?? '')) !== 'hello_world') {
                continue;
            }

            return [
                'exists'   => true,
                'template' => [
                    'template_id' => (string) ($tpl['id'] ?? ''),
                    'name'        => (string) ($tpl['name'] ?? ''),
                    'language'    => (string) ($tpl['language'] ?? ''),
                    'category'    => $tpl['category'] ?? null,
                    'status'      => strtoupper((string) ($tpl['status'] ?? '')),
                    'components'  => is_array($tpl['components'] ?? null) ? $tpl['components'] : [],
                ],
            ];
        }

        return ['exists' => false, 'template' => null];
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function findExisting(string $wabaId, string $metaId, string $name, string $language): ?array
    {
        if ($metaId !== '') {
            $byMeta = $this->model->findByWabaAndMetaId($wabaId, $metaId);
            if ($byMeta !== null) {
                return $byMeta;
            }
            // Legacy rows without waba_id
            $legacy = $this->model->findByMetaId($metaId);
            if ($legacy !== null) {
                return $legacy;
            }
        }

        return $this->model->findByWabaNameLanguage($wabaId, $name, $language)
            ?? $this->model->where('name', $name)->where('language', $language)->first();
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    protected function filterWritableFields(array $row): array
    {
        $allowed = $this->model->allowedFields;
        $out     = [];
        foreach ($row as $key => $value) {
            if (in_array($key, $allowed, true)) {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $tpl
     */
    protected function extractRejectedReason(array $tpl): ?string
    {
        foreach (['rejected_reason', 'rejection_reason', 'reason'] as $key) {
            if (! empty($tpl[$key]) && is_scalar($tpl[$key])) {
                return (string) $tpl[$key];
            }
        }

        return null;
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
    public function parseComponents(array $components): array
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
            if (! is_array($component)) {
                continue;
            }
            $type = strtoupper((string) ($component['type'] ?? ''));

            switch ($type) {
                case 'HEADER':
                    $result['header_type']    = strtolower((string) ($component['format'] ?? 'text'));
                    $result['header_content'] = $component['text']
                        ?? ($component['example']['header_text'][0] ?? null)
                        ?? ($component['example']['header_url'] ?? null)
                        ?? ($component['example']['link'] ?? null)
                        ?? ($component['example']['header_handle'][0] ?? null);
                    break;
                case 'BODY':
                    $result['body'] = $component['text'] ?? null;
                    $result['variables'] = WhatsAppTemplateVariables::identitiesFromComponents([$component]);
                    break;
                case 'FOOTER':
                    $result['footer'] = $component['text'] ?? null;
                    break;
                case 'BUTTONS':
                    $result['buttons'] = $component['buttons'] ?? null;
                    break;
                case 'CAROUSEL':
                    $cards = is_array($component['cards'] ?? null) ? $component['cards'] : [];
                    $result['buttons'] = ['carousel_cards' => count($cards)];
                    if ($result['header_type'] === null && isset($cards[0]['components']) && is_array($cards[0]['components'])) {
                        foreach ($cards[0]['components'] as $cardComponent) {
                            if (! is_array($cardComponent)) {
                                continue;
                            }
                            if (strtoupper((string) ($cardComponent['type'] ?? '')) !== 'HEADER') {
                                continue;
                            }
                            $result['header_type'] = strtolower((string) ($cardComponent['format'] ?? 'image'));
                            $result['header_content'] = $cardComponent['example']['header_url']
                                ?? $cardComponent['example']['link']
                                ?? ($cardComponent['example']['header_handle'][0] ?? null);
                            break;
                        }
                    }
                    break;
            }
        }

        if ($result['variables'] === []) {
            $result['variables'] = null;
        }

        return $result;
    }

    /**
     * @param array<string, mixed>       $tpl
     * @param list<array<string, mixed>> $components
     */
    public function detectTemplateType(array $tpl, array $components): string
    {
        $explicit = strtolower(trim((string) ($tpl['template_type'] ?? '')));
        if (in_array($explicit, ['default', 'carousel'], true)) {
            return $explicit;
        }

        foreach ($components as $component) {
            if (! is_array($component)) {
                continue;
            }
            if (strtoupper((string) ($component['type'] ?? '')) === 'CAROUSEL') {
                return 'carousel';
            }
        }

        return 'default';
    }
}
