<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Libraries\TemplateSyncService;
use App\Libraries\WhatsAppTemplateVariables;
use App\Models\TemplateModel;
use CodeIgniter\HTTP\ResponseInterface;
use Throwable;

/**
 * API templates list and provider sync.
 */
class Templates extends BaseApiController
{
    public function index(): ResponseInterface
    {
        $status = (string) ($this->request->getGet('status') ?? '');
        $model  = model(TemplateModel::class);
        $wabaId = trim((string) (service('settingsService')->getMetaConfig()['waba_id'] ?? ''));

        if ($status !== '') {
            $model->where('status', $status);
        }
        if ($wabaId !== '' && $model->db->fieldExists('waba_id', 'templates')) {
            $model->groupStart()
                ->where('waba_id', $wabaId)
                ->orWhere('waba_id', null)
                ->orWhere('waba_id', '')
                ->groupEnd();
        }

        $items = $model->orderBy('name', 'ASC')->findAll();

        return $this->respondSuccess([
            'waba_id'       => $wabaId,
            'status_counts' => model(TemplateModel::class)->countByStatus($wabaId !== '' ? $wabaId : null),
            'items'         => $items,
        ]);
    }

    public function sync(): ResponseInterface
    {
        try {
            $result = (new TemplateSyncService())->sync();

            return $this->respondSuccess(
                $result,
                "Synced {$result['synced']} template(s)."
                . (($result['disabled'] ?? 0) > 0 ? " Disabled {$result['disabled']} not on provider." : '')
            );
        } catch (Throwable $e) {
            $code = (int) $e->getCode();
            $status = $code >= 400 && $code < 600 ? $code : 500;

            return $this->respondError($e->getMessage(), [], $status);
        }
    }
}
