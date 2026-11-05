<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Libraries\KnowledgeBase;
use App\Models\KbEntryModel;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * The AI knowledge base (FAQ the bot answers from): GET /api/v1/knowledge-base lists entries, POST adds one and
 * POST /{id} changes one. Entries are never deleted through the API.
 */
class KnowledgeController extends BaseV1Controller
{
    public function index(): ResponseInterface
    {
        $rows = model(KbEntryModel::class)->orderBy('id', 'DESC')->findAll(200);
        $list = array_map(static fn (array $r): array => [
            'id'         => (int) $r['id'],
            'title'      => (string) $r['title'],
            'content'    => (string) $r['content'],
            'is_active'  => (int) $r['is_active'],
            'updated_at' => (string) ($r['updated_at'] ?? ''),
        ], $rows);

        return $this->respondSuccess(['entries' => $list, 'total' => count($list)], 'Knowledge base loaded.');
    }

    public function create(): ResponseInterface
    {
        return $this->save(null);
    }

    public function update(string $id): ResponseInterface
    {
        return $this->save((int) $id);
    }

    private function save(?int $id): ResponseInterface
    {
        $in = $this->getJsonPayload();
        if ($id !== null) {
            // Only the fields sent are changed.
            $current = model(KbEntryModel::class)->find($id);
            if ($current === null) {
                return $this->respondError('Entry not found.', 404);
            }
            $in = $in + ['title' => $current['title'], 'content' => $current['content'], 'is_active' => (string) $current['is_active']];
        }

        $res = (new KnowledgeBase())->save($in, $id, $this->currentUserId() ?: null);
        if (! $res['ok']) {
            return $this->respondError($res['message'], $res['status']);
        }
        log_activity($id === null ? 'create' : 'update', 'knowledge_base', 'Knowledge base entry saved via API', ['entry_id' => (int) $res['data']['id']]);

        return $this->respondSuccess($res['data'], $id === null ? 'Entry added.' : 'Entry updated.');
    }
}
