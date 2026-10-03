<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

class AiCopilotLogModel extends Model
{
    protected $table            = 'ai_copilot_logs';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'user_id',
        'screen',
        'page_url',
        'prompt',
        'reply',
        'thinking',
        'action_type',
        'action_data',
        'created_at',
        'updated_at',
        'is_active',
        'is_deleted',
    ];

    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    /**
     * Get recent prompt history for a user.
     *
     * @return list<array<string, mixed>>
     */
    public function getRecentHistory(int $userId, int $limit = 20): array
    {
        return $this->select('id, user_id, screen, page_url, prompt, reply, thinking, action_type, action_data, created_at')
            ->where('user_id', $userId)
            ->where('is_deleted', 0)
            ->orderBy('id', 'DESC')
            ->limit($limit)
            ->findAll();
    }
}
