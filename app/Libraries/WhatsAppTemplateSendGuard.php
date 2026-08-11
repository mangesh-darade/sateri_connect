<?php

declare(strict_types=1);

namespace App\Libraries;

use App\Models\TemplateModel;
use RuntimeException;

/**
 * Multi-tenant template send validation.
 * Credentials / WABA / phone IDs always come from tenant settings — never from the client.
 */
final class WhatsAppTemplateSendGuard
{
    public const SENDABLE_STATUSES = ['APPROVED'];

    public function __construct(
        private ?SettingsService $settings = null,
        private ?TemplateModel $model = null
    ) {
        $this->settings = $this->settings ?? service('settingsService');
        $this->model    = $this->model ?? model(TemplateModel::class);
    }

    /**
     * Resolve a local template that belongs to this tenant's WABA and is sendable.
     *
     * @return array<string, mixed>
     */
    public function resolveApprovedTemplate(?int $templateId, ?string $templateName, ?string $language): array
    {
        $meta   = $this->settings->getMetaConfig();
        $wabaId = trim((string) ($meta['waba_id'] ?? ''));

        $tpl = null;
        if ($templateId !== null && $templateId > 0) {
            $tpl = $this->model->find($templateId);
            if ($tpl === null) {
                throw new RuntimeException('Template not found.', 404);
            }
        } elseif ($templateName !== null && trim($templateName) !== '') {
            $builder = $this->model->where('name', trim($templateName));
            if ($language !== null && trim($language) !== '') {
                $builder->where('language', trim($language));
            }
            if ($wabaId !== '' && $this->model->db->fieldExists('waba_id', 'templates')) {
                $builder->groupStart()
                    ->where('waba_id', $wabaId)
                    ->orWhere('waba_id', null)
                    ->orWhere('waba_id', '')
                    ->groupEnd();
            }
            $tpl = $builder->orderBy('synced_at', 'DESC')->first();
            if ($tpl === null) {
                throw new RuntimeException(
                    'Selected WhatsApp template was not found on this WhatsApp Business Account. Sync templates and try again.',
                    404
                );
            }
        } else {
            throw new RuntimeException('Template selection is required.', 422);
        }

        $this->assertBelongsToTenantWaba($tpl, $wabaId);
        $this->assertApproved($tpl);

        if ($language !== null && trim($language) !== '') {
            $tplLang = strtolower(trim((string) ($tpl['language'] ?? '')));
            $reqLang = strtolower(trim($language));
            if ($tplLang !== '' && $reqLang !== '' && $tplLang !== $reqLang) {
                throw new RuntimeException(
                    'Template language does not match. Expected "' . ($tpl['language'] ?? '') . '", got "' . $language . '".',
                    422
                );
            }
        }

        return $tpl;
    }

    /**
     * @param array<string, mixed> $template
     * @param array<string, mixed> $variables Map of placeholder key => value
     * @return list<array<string, mixed>> Meta components payload
     */
    public function buildBodyComponents(array $template, array $variables): array
    {
        $definitions = WhatsAppTemplateVariables::definitionsForTemplate(
            $template['variables'] ?? null,
            (string) ($template['body'] ?? ''),
            $template['raw_payload'] ?? null
        );

        $missing = [];
        $params  = [];

        foreach ($definitions as $definition) {
            $key = (string) ($definition['key'] ?? '');
            if ($key === '') {
                continue;
            }
            $value = '';
            if (array_key_exists($key, $variables) && is_scalar($variables[$key])) {
                $value = trim((string) $variables[$key]);
            } elseif (ctype_digit($key) && array_key_exists((int) $key - 1, $variables) && is_scalar($variables[(int) $key - 1])) {
                // Allow 0-based list submission
                $value = trim((string) $variables[(int) $key - 1]);
            }

            if ($value === '') {
                $label = WhatsAppTemplateVariables::labelFor($definition);
                $missing[] = $label !== '' ? $label : ('{{' . $key . '}}');
                continue;
            }

            $params[] = [
                'type' => 'text',
                'text' => $value,
            ];
        }

        if ($missing !== []) {
            throw new RuntimeException(
                'Missing required template variables: ' . implode(', ', $missing) . '.',
                422
            );
        }

        if ($params === []) {
            return [];
        }

        return [[
            'type'       => 'body',
            'parameters' => $params,
        ]];
    }

    /**
     * Validate optional client-supplied phone number ID against tenant settings.
     */
    public function assertPhoneNumberId(?string $clientPhoneNumberId): string
    {
        $meta  = $this->settings->getMetaConfig();
        $phone = trim((string) ($meta['phone_number_id'] ?? ''));

        if ($phone === '') {
            throw new RuntimeException('Phone Number ID is not configured for this customer.', 422);
        }

        if ($clientPhoneNumberId !== null && trim($clientPhoneNumberId) !== '') {
            if (! hash_equals($phone, trim($clientPhoneNumberId))) {
                MetaGraphLogger::log('templates.send.phone_mismatch', [
                    'waba_id'         => (string) ($meta['waba_id'] ?? ''),
                    'phone_number_id' => $phone,
                    'detail'          => 'client_phone_number_id rejected',
                    'meta_status'     => 403,
                ], 'warning');

                throw new RuntimeException(
                    'Incorrect Phone Number ID for this WhatsApp Business Account.',
                    403
                );
            }
        }

        return $phone;
    }

    /**
     * Validate optional client-supplied WABA against tenant settings.
     */
    public function assertWabaId(?string $clientWabaId): string
    {
        $meta   = $this->settings->getMetaConfig();
        $wabaId = trim((string) ($meta['waba_id'] ?? ''));

        if ($wabaId === '') {
            throw new RuntimeException('WABA ID is not configured for this customer.', 422);
        }

        if ($clientWabaId !== null && trim($clientWabaId) !== '') {
            if (! hash_equals($wabaId, trim($clientWabaId))) {
                MetaGraphLogger::log('templates.access.denied', [
                    'waba_id'     => $wabaId,
                    'detail'      => 'client_waba_id rejected',
                    'meta_status' => 403,
                ], 'warning');

                throw new RuntimeException('Access denied for this WhatsApp Business Account.', 403);
            }
        }

        return $wabaId;
    }

    /**
     * @param array<string, mixed> $tpl
     */
    public function assertApproved(array $tpl): void
    {
        $status = strtoupper(trim((string) ($tpl['status'] ?? '')));
        if ($status === '') {
            return;
        }

        if (! in_array($status, self::SENDABLE_STATUSES, true)) {
            $message = match ($status) {
                'PENDING'  => 'Selected WhatsApp template is still pending Meta review and cannot be sent yet.',
                'REJECTED' => 'Selected WhatsApp template was rejected by Meta and cannot be sent.',
                'DISABLED', 'DELETED' => 'Selected WhatsApp template is disabled and cannot be sent.',
                default    => 'Selected WhatsApp template is not approved for this WhatsApp Business Account.',
            };

            throw new RuntimeException($message, 422);
        }
    }

    /**
     * @param array<string, mixed> $tpl
     */
    protected function assertBelongsToTenantWaba(array $tpl, string $wabaId): void
    {
        $rowWaba = trim((string) ($tpl['waba_id'] ?? ''));
        if ($wabaId === '' || $rowWaba === '') {
            return;
        }

        if (! hash_equals($wabaId, $rowWaba)) {
            throw new RuntimeException('Access denied: template does not belong to this WhatsApp Business Account.', 403);
        }
    }
}
