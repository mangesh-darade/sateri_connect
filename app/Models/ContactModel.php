<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

class ContactModel extends Model
{
    protected $table            = 'contacts';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = true;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'channel',
        'external_id',
        'name',
        'mobile',
        'country',
        'email',
        'notes',
        'status',
        'last_message_at',
        'last_reply_at',
        'assigned_to',
        'birthday',
        'custom_fields',
    ];

    protected bool $allowEmptyInserts = false;
    protected bool $updateOnlyChanged = true;

    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';
    protected $deletedField  = 'deleted_at';

    protected $validationRules = [
        'name'        => 'permit_empty|max_length[150]',
        'channel'     => 'permit_empty|in_list[whatsapp,instagram,messenger]',
        'external_id' => 'permit_empty|max_length[191]',
        'mobile'      => 'permit_empty|max_length[30]',
        'email'       => 'permit_empty|valid_email|max_length[191]',
        'status'      => 'permit_empty|in_list[active,inactive,blocked]',
    ];

    protected $validationMessages   = [];
    protected $skipValidation       = false;
    protected $cleanValidationRules = true;

    protected $beforeInsert = ['encodeCustomFields'];
    protected $beforeUpdate = ['encodeCustomFields'];
    protected $afterFind    = ['decodeCustomFields'];

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function encodeCustomFields(array $data): array
    {
        if (isset($data['data']['custom_fields']) && is_array($data['data']['custom_fields'])) {
            $data['data']['custom_fields'] = json_encode($data['data']['custom_fields']);
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function decodeCustomFields(array $data): array
    {
        if (! isset($data['data'])) {
            return $data;
        }

        if ($data['singleton'] ?? false) {
            if (isset($data['data']['custom_fields']) && is_string($data['data']['custom_fields'])) {
                $decoded = json_decode($data['data']['custom_fields'], true);
                $data['data']['custom_fields'] = is_array($decoded) ? $decoded : [];
            }

            return $data;
        }

        foreach ($data['data'] as &$row) {
            if (isset($row['custom_fields']) && is_string($row['custom_fields'])) {
                $decoded = json_decode($row['custom_fields'], true);
                $row['custom_fields'] = is_array($decoded) ? $decoded : [];
            }
        }
        unset($row);

        return $data;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByMobile(string $mobile, bool $withDeleted = false): ?array
    {
        $normalized = preg_replace('/\D+/', '', $mobile) ?? $mobile;

        $builder = $this->groupStart()
            ->where('mobile', $mobile)
            ->orWhere('mobile', $normalized)
            ->groupEnd()
            ->where('channel', 'whatsapp');

        if ($withDeleted) {
            $builder->withDeleted();
        }

        $row = $builder->first();

        return is_array($row) ? $row : null;
    }

    /**
     * Find contact by channel + external identity (WA id / IGSID / PSID).
     *
     * @return array<string, mixed>|null
     */
    public function findByChannelExternalId(string $channel, string $externalId, bool $withDeleted = false): ?array
    {
        $channel    = strtolower(trim($channel));
        $externalId = trim($externalId);
        if ($channel === '' || $externalId === '') {
            return null;
        }

        $builder = $this->where('channel', $channel)
            ->where('external_id', $externalId);

        if ($withDeleted) {
            $builder->withDeleted();
        }

        $row = $builder->first();

        return is_array($row) ? $row : null;
    }

    /**
     * Clear the soft-delete flag so a previously removed contact becomes visible again.
     */
    public function restoreContact(int $id): bool
    {
        return $this->db->table($this->table)
            ->where('id', $id)
            ->update([
                $this->deletedField => null,
                $this->updatedField => date('Y-m-d H:i:s'),
            ]);
    }

    /**
     * Upsert contact for any inbox channel.
     *
     * Soft-deleted rows still hold the unique (channel, external_id) key, so they are
     * matched and revived instead of inserted again.
     *
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    public function findOrCreateForChannel(string $channel, string $externalId, array $extra = [], ?bool &$wasCreated = null): array
    {
        $channel    = strtolower(trim($channel)) ?: 'whatsapp';
        $externalId = trim($externalId);
        $wasCreated = false;

        // Legacy/manually added rows have no external_id, so mobile is the only way to reach them.
        $byMobile = $channel === 'whatsapp' && $externalId !== '';

        $existing = $this->findByChannelExternalId($channel, $externalId);
        if ($existing === null && $byMobile) {
            $existing = $this->findByMobile($externalId);
        }
        // Only fall back to soft-deleted rows once no live contact matches.
        if ($existing === null) {
            $existing = $this->findByChannelExternalId($channel, $externalId, true);
        }
        if ($existing === null && $byMobile) {
            $existing = $this->findByMobile($externalId, true);
        }

        if ($existing !== null) {
            $contactId = (int) $existing['id'];
            $revived   = ! empty($existing[$this->deletedField]);

            if ($revived) {
                $this->restoreContact($contactId);
                $existing[$this->deletedField] = null;
            }

            $updates = [];
            if (! empty($extra['name']) && empty($existing['name'])) {
                $updates['name'] = $extra['name'];
            }
            if (empty($existing['channel'])) {
                $updates['channel'] = $channel;
            }
            if (empty($existing['external_id']) && $externalId !== '') {
                $updates['external_id'] = $externalId;
            }
            if ($channel === 'whatsapp' && empty($existing['mobile'])) {
                $updates['mobile'] = $extra['mobile'] ?? $externalId;
            }
            if ($revived && ($existing['status'] ?? '') !== 'active') {
                $updates['status'] = 'active';
            }

            if ($updates !== []) {
                $this->update($contactId, $updates);
                $existing = array_merge($existing, $updates);
            }

            return $existing;
        }

        $row = array_merge([
            'channel'     => $channel,
            'external_id' => $externalId,
            'mobile'      => $channel === 'whatsapp' ? $externalId : ($extra['mobile'] ?? null),
            'name'        => $extra['name'] ?? null,
            'status'      => 'active',
        ], $extra);

        $id         = (int) $this->insert($row);
        $wasCreated = true;
        $created    = $this->find($id);

        return is_array($created) ? $created : array_merge($row, ['id' => $id]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function search(string $term, int $limit = 50, int $offset = 0): array
    {
        $builder = $this->builder();

        if ($term !== '') {
            $builder->groupStart()
                ->like('name', $term)
                ->orLike('mobile', $term)
                ->orLike('email', $term)
                ->orLike('notes', $term)
                ->groupEnd();
        }

        return $builder
            ->where('deleted_at', null)
            ->orderBy('name', 'ASC')
            ->limit($limit, $offset)
            ->get()
            ->getResultArray();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getWithTags(int $contactId): ?array
    {
        $contact = $this->find($contactId);

        if (! is_array($contact)) {
            return null;
        }

        $tags = $this->db->table('contact_tags')
            ->select('tags.*')
            ->join('tags', 'tags.id = contact_tags.tag_id')
            ->where('contact_tags.contact_id', $contactId)
            ->get()
            ->getResultArray();

        $contact['tags'] = $tags;

        return $contact;
    }

    /**
     * @param list<int> $tagIds
     */
    public function syncTags(int $contactId, array $tagIds): void
    {
        $this->db->table('contact_tags')->where('contact_id', $contactId)->delete();

        if ($tagIds === []) {
            return;
        }

        $rows = [];
        foreach (array_unique($tagIds) as $tagId) {
            $rows[] = [
                'contact_id' => $contactId,
                'tag_id'     => (int) $tagId,
            ];
        }

        $this->db->table('contact_tags')->insertBatch($rows);
    }
}
