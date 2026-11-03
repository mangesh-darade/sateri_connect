<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Libraries\DataTablesQuery;
use App\Libraries\KnowledgeBase;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Manage the FAQ entries the AI bot answers from. Same permission as the AI copilot.
 */
class KnowledgeBaseEntries extends BaseController
{
    public function index(): string|ResponseInterface
    {
        if ($denied = $this->requirePermission('ai.use')) {
            return $denied;
        }

        return $this->render('knowledge_base/index', ['pageTitle' => 'Knowledge base']);
    }

    /** DataTables JSON for the entries list. */
    public function data(): ResponseInterface
    {
        if ($denied = $this->requirePermission('ai.use')) {
            return $denied;
        }

        // Touch the model so the table exists before the first query.
        model(\App\Models\KbEntryModel::class);

        return $this->response->setJSON(DataTablesQuery::respond($this->request, [
            'builder'  => static fn () => db_connect()->table('kb_entries k')->select('k.id, k.title, k.content, k.is_active, k.updated_at'),
            'columns'  => [0 => 'k.title', 1 => 'k.content', 2 => 'k.is_active', 3 => 'k.updated_at'],
            'search'   => ['k.title', 'k.content'],
            'order'    => [3, 'desc'],
            'tiebreak' => 'k.id',
            'map'      => static fn (array $r): array => [
                'id'         => (int) $r['id'],
                'title'      => (string) $r['title'],
                'content'    => (string) $r['content'],
                'is_active'  => (int) $r['is_active'],
                'updated_at' => (string) $r['updated_at'],
            ],
        ]));
    }

    public function store(): ResponseInterface
    {
        return $this->save(null);
    }

    public function update(int $id): ResponseInterface
    {
        return $this->save($id);
    }

    public function delete(int $id): ResponseInterface
    {
        if ($denied = $this->requirePermission('ai.use')) {
            return $denied;
        }
        if (! (new KnowledgeBase())->delete($id)) {
            return $this->jsonResponse(false, null, 'Entry not found.', [], 404);
        }
        $this->logActivity('delete', 'knowledge_base', 'Knowledge base entry deleted', ['entry_id' => $id]);

        return $this->jsonResponse(true, null, 'Entry deleted.');
    }

    private function save(?int $id): ResponseInterface
    {
        if ($denied = $this->requirePermission('ai.use')) {
            return $denied;
        }

        $result = (new KnowledgeBase())->save($this->requestInput(), $id, $this->userId());
        if (! $result['ok']) {
            return $this->jsonResponse(false, null, $result['message'], [], $result['status']);
        }

        $this->logActivity($id === null ? 'create' : 'update', 'knowledge_base', 'Knowledge base entry saved', ['entry_id' => (int) $result['data']['id']]);

        return $this->jsonResponse(true, $result['data'], $id === null ? 'Entry added.' : 'Entry updated.');
    }
}
