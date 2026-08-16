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
     * Mark unread chat notifications for a contact as read (link contains contact_id=N).
     *
     * @return int Number of rows marked read
     */
    public function markChatNotificationsReadForContact(int $userId, int $contactId): int
    {
        if ($userId <= 0 || $contactId <= 0) {
            return 0;
        }

        $rows = $this->where('user_id', $userId)
            ->where('is_read', 0)
            ->groupStart()
                ->where('type', 'chat')
                ->orLike('link', 'chat?', 'after')
                ->orLike('link', '/chat?', 'both')
            ->groupEnd()
            ->findAll(200);

        $ids = [];
        $needle = 'contact_id=' . $contactId;
        foreach ($rows as $row) {
            $link = (string) ($row['link'] ?? '');
            if ($link === '' || ! str_contains($link, $needle)) {
                continue;
            }
            // Avoid contact_id=12 matching contact_id=123
            if (preg_match('/(?:[?&])contact_id=' . preg_quote((string) $contactId, '/') . '(?:&|$)/', $link) !== 1) {
                continue;
            }
            $ids[] = (int) ($row['id'] ?? 0);
        }

        $ids = array_values(array_filter($ids));
        if ($ids === []) {
            return 0;
        }

        $this->whereIn('id', $ids)->set(['is_read' => 1])->update();

        return count($ids);
    }

    public function countUnreadForUser(int $userId): int
    {
        if ($userId <= 0) {
            return 0;
        }

        return (int) $this->where('user_id', $userId)
            ->where('is_read', 0)
            ->countAllResults();
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

    /**
     * Attach contact display fields for mobile-style notification UI.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    public function enrichForUi(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $contactIds = [];
        foreach ($rows as $row) {
            $cid = $this->contactIdFromLink((string) ($row['link'] ?? ''));
            if ($cid > 0) {
                $contactIds[$cid] = $cid;
            }
        }

        $contacts = [];
        if ($contactIds !== []) {
            try {
                $found = model(ContactModel::class)
                    ->select('id, name, mobile, external_id, channel')
                    ->whereIn('id', array_values($contactIds))
                    ->findAll();
                foreach ($found as $c) {
                    $contacts[(int) ($c['id'] ?? 0)] = $c;
                }
            } catch (\Throwable $e) {
                $contacts = [];
            }
        }

        $out = [];
        foreach ($rows as $row) {
            $cid   = $this->contactIdFromLink((string) ($row['link'] ?? ''));
            $c     = $cid > 0 ? ($contacts[$cid] ?? null) : null;
            $name  = is_array($c) ? trim((string) ($c['name'] ?? '')) : '';
            $phone = is_array($c) ? trim((string) (($c['mobile'] ?? '') !== '' ? $c['mobile'] : ($c['external_id'] ?? ''))) : '';

            if ($name === '') {
                $title = (string) ($row['title'] ?? '');
                if (preg_match('/(?:message from|from)\s+(.+)$/i', $title, $m) === 1) {
                    $name = trim($m[1]);
                } elseif ($title !== '' && ! str_starts_with(strtolower($title), 'new ')) {
                    $name = $title;
                }
            }

            $label = $name !== '' ? $name : ($phone !== '' ? $phone : (string) ($row['title'] ?? 'Notification'));
            $row['contact_id']       = $cid > 0 ? $cid : null;
            $row['contact_name']     = $name;
            $row['contact_phone']    = $phone;
            $row['display_title']    = $label;
            $row['display_subtitle'] = $phone !== '' && $phone !== $label ? $phone : '';
            $row['display_body']     = trim((string) ($row['message'] ?? ''));
            $row['avatar_initials']  = $this->avatarInitials($label);
            $row['avatar_color']     = $this->avatarColor($label . '|' . $phone);
            $out[] = $row;
        }

        return $out;
    }

    protected function contactIdFromLink(string $link): int
    {
        if ($link === '' || preg_match('/(?:[?&])contact_id=(\d+)/', $link, $m) !== 1) {
            return 0;
        }

        return (int) $m[1];
    }

    protected function avatarInitials(string $label): string
    {
        $label = trim($label);
        if ($label === '') {
            return 'N';
        }
        if (preg_match('/^\+?\d+$/', $label) === 1) {
            return mb_strtoupper(mb_substr($label, -2));
        }
        $parts = preg_split('/\s+/', $label) ?: [];
        $a = mb_strtoupper(mb_substr((string) ($parts[0] ?? 'N'), 0, 1));
        $b = count($parts) > 1 ? mb_strtoupper(mb_substr((string) $parts[1], 0, 1)) : '';

        return $a . $b;
    }

    protected function avatarColor(string $seed): string
    {
        $palette = ['#7c3aed', '#2563eb', '#059669', '#d97706', '#db2777', '#0891b2', '#4f46e5', '#0f766e'];
        $hash    = abs(crc32($seed));

        return $palette[$hash % count($palette)];
    }
}
