<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

class ConversationModel extends Model
{
    protected $table            = 'conversations';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'contact_id',
        'channel',
        'page_id',
        'last_message_id',
        'unread_count',
        'assigned_to',
        'status',
        'last_message_at',
    ];

    protected bool $allowEmptyInserts = false;
    protected bool $updateOnlyChanged = true;

    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    protected $validationRules = [
        'contact_id' => 'required|is_natural_no_zero',
        'status'     => 'permit_empty|in_list[open,closed]',
    ];

    protected $validationMessages   = [];
    protected $skipValidation       = false;
    protected $cleanValidationRules = true;

    /**
     * @return array<string, mixed>|null
     */
    public function findByContact(int $contactId, string $channel = 'whatsapp'): ?array
    {
        $channel = strtolower(trim($channel)) ?: 'whatsapp';
        $row     = $this->where('contact_id', $contactId)
            ->where('channel', $channel)
            ->first();

        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function findOrCreateForContact(int $contactId, string $channel = 'whatsapp', ?string $pageId = null): array
    {
        $channel  = strtolower(trim($channel)) ?: 'whatsapp';
        $existing = $this->findByContact($contactId, $channel);

        if ($existing !== null) {
            if ($pageId !== null && $pageId !== '' && empty($existing['page_id'])) {
                $this->update((int) $existing['id'], ['page_id' => $pageId]);
                $existing['page_id'] = $pageId;
            }

            return $existing;
        }

        $id = $this->insert([
            'contact_id'   => $contactId,
            'channel'      => $channel,
            'page_id'      => $pageId,
            'unread_count' => 0,
            'status'       => 'open',
        ]);

        $created = $this->find((int) $id);

        return is_array($created)
            ? $created
            : ['id' => $id, 'contact_id' => $contactId, 'channel' => $channel];
    }

    public function incrementUnread(int $conversationId): bool
    {
        return $this->db->table($this->table)
            ->where('id', $conversationId)
            ->set('unread_count', 'unread_count + 1', false)
            ->set('updated_at', date('Y-m-d H:i:s'))
            ->update();
    }

    public function resetUnread(int $conversationId): bool
    {
        return $this->update($conversationId, ['unread_count' => 0]);
    }
}
