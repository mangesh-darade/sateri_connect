<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

class NotificationModel extends Model
{
    protected $table            = 'notifications';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'user_id',
        'title',
        'message',
        'type',
        'is_read',
        'link',
    ];

    protected bool $allowEmptyInserts = false;
    protected bool $updateOnlyChanged = true;

    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    protected $validationRules = [
        'user_id' => 'required|is_natural_no_zero',
        'title'   => 'required|max_length[255]',
        'type'    => 'permit_empty|max_length[50]',
    ];

    protected $validationMessages   = [];
    protected $skipValidation       = false;
    protected $cleanValidationRules = true;

    /**
     * @return list<array<string, mixed>>
     */
    public function getUnreadForUser(int $userId, int $limit = 20): array
    {
        return $this->where('user_id', $userId)
            ->where('is_read', 0)
            ->orderBy('created_at', 'DESC')
            ->findAll($limit);
    }

    public function markAsRead(int $id, int $userId): bool
    {
        return $this->where('id', $id)
            ->where('user_id', $userId)
            ->set(['is_read' => 1])
            ->update();
    }

    public function markAllAsRead(int $userId): bool
    {
        return $this->where('user_id', $userId)
            ->where('is_read', 0)
            ->set(['is_read' => 1])
            ->update();
    }

    /**
     * Create an unread notification for a user.
     *
     * @return int|false Insert id
     */
    public function push(int $userId, string $title, string $message = '', string $type = 'info', string $link = ''): int|false
    {
        if ($userId <= 0 || $title === '') {
            return false;
        }

        $id = $this->insert([
            'user_id' => $userId,
            'title'   => mb_substr($title, 0, 255),
            'message' => $message,
            'type'    => mb_substr($type !== '' ? $type : 'info', 0, 50),
            'is_read' => 0,
            'link'    => $link !== '' ? $link : null,
        ]);

        return $id ? (int) $id : false;
    }

    /**
     * Notify assigned agent, or every user that can view chat.
     *
     * @param list<int>|null $userIds
     */
    public function notifyChatUsers(string $title, string $message, string $link = '', ?int $preferUserId = null): int
    {
        $ids = [];
        if ($preferUserId !== null && $preferUserId > 0) {
            $ids[] = $preferUserId;
        }

        if ($ids === []) {
            $db = db_connect();
            $builder = $db->table('users u')
                ->select('u.id')
                ->join('roles r', 'r.id = u.role_id', 'left')
                ->limit(50);

            // Prefer active users when column exists
            try {
                $cols = $db->getFieldNames('users');
                if (in_array('status', $cols, true)) {
                    $builder->where('u.status', 'active');
                } elseif (in_array('is_active', $cols, true)) {
                    $builder->where('u.is_active', 1);
                }
            } catch (\Throwable $e) {
                // ignore
            }

            $rows = $builder->get()->getResultArray();
            foreach ($rows as $row) {
                $ids[] = (int) $row['id'];
            }
        }

        $ids = array_values(array_unique(array_filter($ids)));
        $n   = 0;
        foreach ($ids as $uid) {
            if ($this->push($uid, $title, $message, 'chat', $link)) {
                $n++;
            }
        }

        return $n;
    }
}
