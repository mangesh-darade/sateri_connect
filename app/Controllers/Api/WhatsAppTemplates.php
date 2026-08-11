<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Libraries\MetaGraphLogger;
use App\Libraries\SubdomainDatabase;
use App\Libraries\TemplateSyncService;
use App\Libraries\WhatsAppTemplateSendGuard;
use App\Libraries\WhatsAppTemplateVariables;
use App\Models\TemplateModel;
use CodeIgniter\HTTP\ResponseInterface;
use Throwable;

/**
 * Customer-scoped WhatsApp template API.
 *
 * GET  /api/whatsapp/templates
 * GET  /api/whatsapp/templates/{customer_id}
 * POST /api/whatsapp/templates/sync
 * POST /api/whatsapp/templates/{id}/send-test
 *
 * Multi-tenant: customer_id must match the current subdomain tenant.
 * WABA / phone / token always come from tenant settings — never from the client.
 */
class WhatsAppTemplates extends BaseApiController
{
    public function index(?string $customerId = null): ResponseInterface
    {
        try {
            $this->assertCustomerAccess($customerId);

            $settings = service('settingsService');
            $meta     = $settings->getMetaConfig();
            $wabaId   = trim((string) ($meta['waba_id'] ?? ''));
            $phoneId  = trim((string) ($meta['phone_number_id'] ?? ''));

            $statusFilter = strtoupper(trim((string) ($this->request->getGet('status') ?? '')));
            $approvedOnly = (string) ($this->request->getGet('approved_only') ?? '') === '1'
                || strtolower((string) ($this->request->getGet('sendable') ?? '')) === '1';

            $model = model(TemplateModel::class);
            if ($wabaId !== '' && $model->db->fieldExists('waba_id', 'templates')) {
                $model->groupStart()
                    ->where('waba_id', $wabaId)
                    ->orWhere('waba_id', null)
                    ->orWhere('waba_id', '')
                    ->groupEnd();
            }
            if ($approvedOnly) {
                $model->whereIn('status', ['APPROVED', 'approved']);
            } elseif ($statusFilter !== '') {
                $model->where('status', $statusFilter);
            }

            $items = $model->orderBy('name', 'ASC')->findAll();
            $out   = [];
            foreach ($items as $item) {
                $out[] = $this->serializeTemplate($item);
            }

            $counts = model(TemplateModel::class)->countByStatus($wabaId !== '' ? $wabaId : null);

            return $this->respondSuccess([
                'customer_id'     => SubdomainDatabase::resolve(),
                'waba_id'         => $wabaId,
                'phone_number_id' => $phoneId,
                'status_counts'   => $counts,
                'items'           => $out,
            ]);
        } catch (Throwable $e) {
            return $this->respondFromException($e);
        }
    }

    public function sync(?string $customerId = null): ResponseInterface
    {
        try {
            $this->assertCustomerAccess($customerId);
            $result = (new TemplateSyncService())->sync();

            return $this->respondSuccess($result, 'Templates synced.');
        } catch (Throwable $e) {
            return $this->respondFromException($e);
        }
    }

    public function sendTest(int $id): ResponseInterface
    {
        try {
            $this->assertCustomerAccess(null);
            $input = $this->getJsonInput();
            $to    = normalize_phone((string) ($input['to'] ?? $input['mobile'] ?? ''));
            if ($to === '') {
                return $this->respondValidationError(['to' => 'Recipient phone number is required.']);
            }

            $guard = new WhatsAppTemplateSendGuard();
            $guard->assertPhoneNumberId(isset($input['phone_number_id']) ? (string) $input['phone_number_id'] : null);
            $guard->assertWabaId(isset($input['waba_id']) ? (string) $input['waba_id'] : null);

            $tpl = $guard->resolveApprovedTemplate($id, null, isset($input['language']) ? (string) $input['language'] : null);
            $variables = is_array($input['variables'] ?? null) ? $input['variables'] : [];
            $components = $guard->buildBodyComponents($tpl, $variables);

            $result = service('whatsApp')->sendTemplate(
                $to,
                (string) $tpl['name'],
                (string) ($tpl['language'] ?? 'en_US'),
                $components
            );

            return $this->respondSuccess([
                'wa_message_id' => $result['messages'][0]['id'] ?? null,
                'template'      => $this->serializeTemplate($tpl),
            ], 'Test template sent.', 201);
        } catch (Throwable $e) {
            return $this->respondFromException($e);
        }
    }

    /**
     * Reject cross-tenant access. customer_id must equal current subdomain (or be omitted).
     */
    protected function assertCustomerAccess(?string $customerId): void
    {
        $current = SubdomainDatabase::resolve();
        if ($customerId === null || trim($customerId) === '') {
            return;
        }

        $requested = strtolower(trim($customerId));
        if (! hash_equals(strtolower($current), $requested)) {
            MetaGraphLogger::log('templates.access.denied', [
                'customer_id' => $current,
                'detail'      => 'requested_customer=' . $requested,
                'meta_status' => 403,
            ], 'warning');

            throw new \RuntimeException('Access denied for this customer WhatsApp Business Account.', 403);
        }
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    protected function serializeTemplate(array $item): array
    {
        $definitions = WhatsAppTemplateVariables::definitionsForTemplate(
            $item['variables'] ?? null,
            (string) ($item['body'] ?? ''),
            $item['raw_payload'] ?? null
        );

        foreach ($definitions as &$definition) {
            $definition['label'] = WhatsAppTemplateVariables::labelFor($definition);
        }
        unset($definition);

        return [
            'id'              => (int) ($item['id'] ?? 0),
            'waba_id'         => $item['waba_id'] ?? null,
            'template_id'     => $item['meta_id'] ?? null,
            'template_name'   => $item['name'] ?? '',
            'language'        => $item['language'] ?? '',
            'category'        => $item['category'] ?? '',
            'status'          => strtoupper((string) ($item['status'] ?? '')),
            'rejected_reason' => $item['rejected_reason'] ?? null,
            'variables_count' => count($definitions),
            'variables'       => $definitions,
            'body'            => $item['body'] ?? '',
            'components_json' => $item['raw_payload']['components'] ?? ($item['raw_payload'] ?? null),
            'last_synced_at'  => $item['synced_at'] ?? null,
            'sendable'        => strtoupper((string) ($item['status'] ?? '')) === 'APPROVED',
        ];
    }

    protected function respondFromException(Throwable $e): ResponseInterface
    {
        $code = (int) $e->getCode();
        $status = $code >= 400 && $code < 600 ? $code : 500;

        return $this->respondError($e->getMessage(), [], $status);
    }
}
