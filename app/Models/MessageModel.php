<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

class MessageModel extends Model
{
    protected $table            = 'messages';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'contact_id',
        'campaign_id',
        'direction',
        'message_type',
        'wa_message_id',
        'wamid',
        'external_message_id',
        'content',
        'media_url',
        'media_id',
        'payload',
        'status',
        'error_code',
        'error_message',
        'is_read',
        'conversation_id',
        'channel',
    ];

    protected bool $allowEmptyInserts = false;
    protected bool $updateOnlyChanged = true;

    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    protected $validationRules = [
        'contact_id'   => 'required|is_natural_no_zero',
        'direction'    => 'required|in_list[inbound,outbound]',
        'message_type' => 'permit_empty|max_length[50]',
        'status'       => 'permit_empty|in_list[pending,sent,delivered,read,failed,received]',
    ];

    protected $validationMessages   = [];
    protected $skipValidation       = false;
    protected $cleanValidationRules = true;

    protected $beforeInsert = ['encodePayload'];
    protected $beforeUpdate = ['encodePayload'];
    protected $afterFind    = ['decodePayload'];

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function encodePayload(array $data): array
    {
        if (isset($data['data']['payload']) && is_array($data['data']['payload'])) {
            $data['data']['payload'] = json_encode($data['data']['payload']);
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function decodePayload(array $data): array
    {
        if (! isset($data['data'])) {
            return $data;
        }

        $decode = static function (array &$row): void {
            if (isset($row['payload']) && is_string($row['payload'])) {
                $decoded = json_decode($row['payload'], true);
                $row['payload'] = is_array($decoded) ? $decoded : null;
            }
        };

        if ($data['singleton'] ?? false) {
            $decode($data['data']);

            return $data;
        }

        foreach ($data['data'] as &$row) {
            $decode($row);
        }
        unset($row);

        return $data;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByWaMessageId(string $waMessageId): ?array
    {
        $row = $this->groupStart()
            ->where('wa_message_id', $waMessageId)
            ->orWhere('wamid', $waMessageId)
            ->orWhere('external_message_id', $waMessageId)
            ->groupEnd()
            ->first();

        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByExternalMessageId(string $externalMessageId): ?array
    {
        $externalMessageId = trim($externalMessageId);
        if ($externalMessageId === '') {
            return null;
        }

        $row = $this->groupStart()
            ->where('external_message_id', $externalMessageId)
            ->orWhere('wa_message_id', $externalMessageId)
            ->orWhere('wamid', $externalMessageId)
            ->groupEnd()
            ->first();

        return is_array($row) ? $row : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getConversationMessages(int $conversationId, int $limit = 50, int $offset = 0): array
    {
        return $this->where('conversation_id', $conversationId)
            ->orderBy('created_at', 'ASC')
            ->findAll($limit, $offset);
    }
}
