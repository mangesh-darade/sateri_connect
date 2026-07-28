<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Models\CampaignModel;
use CodeIgniter\HTTP\ResponseInterface;
use Throwable;

/**
 * REST campaign list/create/show and pause/resume.
 */
class Campaigns extends BaseApiController
{
    public function index(): ResponseInterface
    {
        $status = (string) ($this->request->getGet('status') ?? '');
        $model  = model(CampaignModel::class);

        if ($status !== '') {
            $model->where('status', $status);
        }

        $items = $model->orderBy('id', 'DESC')->findAll(100);

        return $this->respondSuccess(['items' => $items]);
    }

    public function create(): ResponseInterface
    {
        $input = $this->getJsonInput();

        if (empty($input['name'])) {
            return $this->respondValidationError(['name' => 'Name is required.']);
        }

        try {
            $id = service('campaignService')->create([
                'name'         => $input['name'],
                'template_id'  => $input['template_id'] ?? null,
                'message_type' => $input['message_type'] ?? 'template',
                'payload'      => $input['payload'] ?? null,
                'variables'    => $input['variables'] ?? null,
                'created_by'   => $this->apiUserId(),
            ]);

            $campaign = model(CampaignModel::class)->find($id);

            return $this->respondSuccess($campaign, 'Campaign created.', 201);
        } catch (Throwable $e) {
            return $this->respondError($e->getMessage(), [], 400);
        }
    }

    public function show(int $id): ResponseInterface
    {
        $campaign = model(CampaignModel::class)->find($id);
        if ($campaign === null) {
            return $this->respondError('Campaign not found.', [], 404);
        }

        model(CampaignModel::class)->updateStats($id);
        $campaign = model(CampaignModel::class)->find($id);

        return $this->respondSuccess($campaign);
    }

    public function pause(int $id): ResponseInterface
    {
        try {
            service('campaignService')->pause($id);

            return $this->respondSuccess(model(CampaignModel::class)->find($id), 'Campaign paused.');
        } catch (Throwable $e) {
            return $this->respondError($e->getMessage(), [], 400);
        }
    }

    public function resume(int $id): ResponseInterface
    {
        try {
            service('campaignService')->resume($id);

            return $this->respondSuccess(model(CampaignModel::class)->find($id), 'Campaign resumed.');
        } catch (Throwable $e) {
            return $this->respondError($e->getMessage(), [], 400);
        }
    }
}
