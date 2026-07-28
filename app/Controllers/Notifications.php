<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\NotificationModel;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Live notification poll + mark-read for header bell / browser alerts.
 */
class Notifications extends BaseController
{
    public function poll(): ResponseInterface
    {
        $uid = $this->userId();
        if ($uid === null || $uid <= 0) {
            return $this->jsonResponse(false, null, 'Unauthorized.', [], 401);
        }

        $sinceId = max(0, (int) ($this->request->getGet('since_id') ?? 0));
        $limit   = max(1, min(30, (int) ($this->request->getGet('limit') ?? 12)));

        $model  = model(NotificationModel::class);
        $unread = $model->getUnreadForUser($uid, $limit);
        $count  = (int) model(NotificationModel::class)
            ->where('user_id', $uid)
            ->where('is_read', 0)
            ->countAllResults();

        $fresh = [];
        if ($sinceId > 0) {
            $fresh = model(NotificationModel::class)
                ->where('user_id', $uid)
                ->where('id >', $sinceId)
                ->orderBy('id', 'ASC')
                ->findAll(20);
        }

        $maxId = 0;
        foreach ($unread as $row) {
            $maxId = max($maxId, (int) ($row['id'] ?? 0));
        }
        foreach ($fresh as $row) {
            $maxId = max($maxId, (int) ($row['id'] ?? 0));
        }

        return $this->jsonResponse(true, [
            'unread_count' => $count,
            'items'        => $unread,
            'fresh'        => $fresh,
            'max_id'       => $maxId,
            'server_time'  => date('Y-m-d H:i:s'),
        ]);
    }

    public function markRead(int $id): ResponseInterface
    {
        $uid = $this->userId();
        if ($uid === null || $uid <= 0) {
            return $this->jsonResponse(false, null, 'Unauthorized.', [], 401);
        }

        $ok = model(NotificationModel::class)->markAsRead($id, $uid);

        return $this->jsonResponse($ok, null, $ok ? 'Marked read.' : 'Not found.');
    }

    public function markAllRead(): ResponseInterface
    {
        $uid = $this->userId();
        if ($uid === null || $uid <= 0) {
            return $this->jsonResponse(false, null, 'Unauthorized.', [], 401);
        }

        model(NotificationModel::class)->markAllAsRead($uid);

        return $this->jsonResponse(true, null, 'All marked read.');
    }
}
