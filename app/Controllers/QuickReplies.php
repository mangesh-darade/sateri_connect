<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Libraries\ActivityLogger;
use CodeIgniter\HTTP\ResponseInterface;
use InvalidArgumentException;

/**
 * Inbox quick replies (canned responses) — agents type "/shortcut" in the chat composer.
 */
class QuickReplies extends BaseController
{
    public function index(): string|ResponseInterface
    {
        if ($denied = $this->requirePermission('chat.view')) {
            return $denied;
        }
        $service = service('quickReplies');
        $rows    = $service->all();

        if ($this->request->isAJAX() || $this->request->getGet('json') === '1') {
            return $this->jsonResponse(true, ['quick_replies' => $rows]);
        }

        return $this->render('quick_replies/index', [
            'pageTitle'    => 'Quick replies',
            'quickReplies' => $rows,
            'tableReady'   => $service->tableReady(),
            'attributeDefs' => service('contactAttributes')->definitions(),
        ]);
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
        if ($denied = $this->requirePermission('chat.send')) {
            return $denied;
        }
        if (! service('quickReplies')->delete($id)) {
            return $this->jsonResponse(false, null, 'Quick reply not found.', [], 404);
        }
        (new ActivityLogger())->log('delete', 'quick_replies', 'Quick reply deleted', ['quick_reply_id' => $id]);

        return $this->jsonResponse(true, null, 'Quick reply deleted.');
    }

    protected function save(?int $id): ResponseInterface
    {
        if ($denied = $this->requirePermission('chat.send')) {
            return $denied;
        }
        try {
            $row = service('quickReplies')->save($this->requestInput(), $id, (int) ($this->currentUser['id'] ?? 0));
        } catch (InvalidArgumentException $e) {
            return $this->jsonResponse(false, null, $e->getMessage(), [], 422);
        }
        (new ActivityLogger())->log($id === null ? 'create' : 'update', 'quick_replies', 'Quick reply /' . $row['shortcut'] . ' saved', ['quick_reply_id' => (int) $row['id']]);

        return $this->jsonResponse(true, ['quick_reply' => $row], $id === null ? 'Quick reply added.' : 'Quick reply updated.');
    }
}
