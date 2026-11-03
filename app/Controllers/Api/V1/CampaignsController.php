<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Models\CampaignModel;
use CodeIgniter\HTTP\ResponseInterface;
use Throwable;

/**
 * REST API v1 WhatsApp campaigns: create a draft, preview the audience, then start (needs confirm), schedule, pause or cancel.
 * Thin wrapper over CampaignService, so consent, wallet and template rules are the same as on the Campaigns screen.
 */
class CampaignsController extends BaseV1Controller
{
    /** GET /api/v1/campaigns */
    public function index(): ResponseInterface
    {
        $rows = model(CampaignModel::class)->orderBy('id', 'DESC')->findAll(30);

        return $this->respondSuccess(['campaigns' => array_map([$this, 'summary'], $rows), 'total' => count($rows)], 'Campaigns loaded.');
    }

    /** GET /api/v1/campaigns/{id} */
    public function show(string $id): ResponseInterface
    {
        $row = model(CampaignModel::class)->find((int) $id);

        return $row === null ? $this->respondError('Campaign not found.', 404) : $this->respondSuccess($this->summary($row), 'Campaign loaded.');
    }

    /**
     * POST /api/v1/campaigns  {name, template_id, variables, tag_ids|contact_ids|all}
     * Creates a draft only. Nothing is sent.
     */
    public function create(): ResponseInterface
    {
        $in       = $this->getJsonPayload();
        $audience = $this->audience($in);
        if ($audience['all'] === false && $audience['contact_ids'] === [] && $audience['tag_ids'] === []) {
            return $this->respondError('Choose an audience: tag_ids, contact_ids or all=true.', 422);
        }
        if ((int) ($in['template_id'] ?? 0) < 1 || trim((string) ($in['name'] ?? '')) === '') {
            return $this->respondError('name and template_id are required (see GET /templates).', 422);
        }

        try {
            $svc = service('campaignService');
            $id  = $svc->create([
                'name'         => trim((string) $in['name']),
                'template_id'  => (int) $in['template_id'],
                'message_type' => 'template',
                'payload'      => ['_audience' => $audience],
                'variables'    => is_array($in['variables'] ?? null) ? $in['variables'] : null,
                'created_by'   => $this->currentUserId(),
            ]);
            $preview = $svc->previewAudience($audience['tag_ids'], $audience['contact_ids'], [], $audience['all']);

            log_activity('create', 'campaigns', 'Campaign drafted via API: ' . $in['name'], ['campaign_id' => $id]);

            return $this->respondSuccess(['id' => $id, 'status' => 'draft', 'audience' => $preview], 'Draft created. Start it with POST /campaigns/' . $id . '/start {"confirm": true}.', 201);
        } catch (Throwable $e) {
            log_message('error', 'API campaign create failed: ' . $e->getMessage());

            return $this->respondError('The campaign could not be created.', 422);
        }
    }

    /** POST /api/v1/campaigns/{id}/{start|schedule|pause|resume|cancel} */
    public function act(string $id, string $action): ResponseInterface
    {
        $id  = (int) $id;
        $svc = service('campaignService');
        $in  = $this->getJsonPayload();
        $row = model(CampaignModel::class)->find($id);
        if ($row === null) {
            return $this->respondError('Campaign not found.', 404);
        }

        try {
            if ($action === 'start' || $action === 'schedule') {
                if (empty($in['confirm'])) {
                    $payload = is_array($row['payload']) ? $row['payload'] : (json_decode((string) $row['payload'], true) ?: []);
                    $a       = (array) ($payload['_audience'] ?? []);
                    $p = $svc->previewAudience((array) ($a['tag_ids'] ?? []), (array) ($a['contact_ids'] ?? []), [], ! empty($a['all']));

                    return $this->respondError('This sends real WhatsApp messages. Repeat with {"confirm": true} to go ahead.', 409, ['audience' => $p]);
                }
            }

            switch ($action) {
                case 'start':
                    $r = $svc->start($id);
                    log_activity('run', 'campaigns', 'Campaign started via API: ' . $row['name'], ['campaign_id' => $id]);

                    return $this->respondSuccess($r, 'Campaign started.');
                case 'schedule':
                    $when = (string) ($in['scheduled_at'] ?? '');
                    if (app_local_to_storage($when) === null) {
                        return $this->respondError('scheduled_at ("YYYY-MM-DD HH:MM", app time zone) is required.', 422);
                    }
                    $svc->schedule($id, $when);

                    return $this->respondSuccess(['id' => $id, 'scheduled_at' => $when], 'Campaign scheduled.');
                case 'pause':
                    return $svc->pause($id) ? $this->respondSuccess(['id' => $id], 'Campaign paused.') : $this->respondError('It cannot be paused now.', 409);
                case 'resume':
                    return $svc->resume($id) ? $this->respondSuccess(['id' => $id], 'Campaign resumed.') : $this->respondError('It cannot be resumed now.', 409);
                default:
                    return $svc->cancel($id) ? $this->respondSuccess(['id' => $id], 'Campaign cancelled.') : $this->respondError('It cannot be cancelled now.', 409);
            }
        } catch (Throwable $e) {
            log_message('error', 'API campaign ' . $action . ' failed: ' . $e->getMessage());

            return $this->respondError($e->getMessage() !== '' ? $e->getMessage() : 'The action failed.', 422);
        }
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function summary(array $row): array
    {
        return array_intersect_key($row, array_flip(['id', 'name', 'status', 'template_id', 'scheduled_at', 'total_contacts', 'sent_count', 'delivered_count', 'read_count', 'failed_count', 'reply_count', 'created_at']));
    }

    /**
     * @param array<string, mixed> $in
     *
     * @return array{all: bool, contact_ids: list<int>, tag_ids: list<int>}
     */
    private function audience(array $in): array
    {
        $ids = static fn (mixed $v): array => array_values(array_filter(array_map('intval', (array) $v), static fn (int $n): bool => $n > 0));

        return ['all' => ! empty($in['all']), 'contact_ids' => $ids($in['contact_ids'] ?? []), 'tag_ids' => $ids($in['tag_ids'] ?? [])];
    }
}
