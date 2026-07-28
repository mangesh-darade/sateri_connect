<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

class TagModel extends Model
{
    protected $table            = 'tags';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'name',
        'color',
    ];

    protected bool $allowEmptyInserts = false;
    protected bool $updateOnlyChanged = true;

    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    protected $validationRules = [
        'name'  => 'required|min_length[1]|max_length[100]|is_unique[tags.name,id,{id}]',
        'color' => 'permit_empty|max_length[20]',
    ];

    protected $validationMessages   = [];
    protected $skipValidation       = false;
    protected $cleanValidationRules = true;

    /**
     * @return list<array<string, mixed>>
     */
    public function getForContact(int $contactId): array
    {
        return $this->db->table('contact_tags')
            ->select('tags.*')
            ->join('tags', 'tags.id = contact_tags.tag_id')
            ->where('contact_tags.contact_id', $contactId)
            ->get()
            ->getResultArray();
    }

    /**
     * Customer groups (tags) with member counts for the list page.
     *
     * @return list<array<string, mixed>>
     */
    public function listWithContactCounts(?string $search = null): array
    {
        $builder = $this->db->table('tags t')
            ->select('t.id, t.name, t.color, t.created_at, t.updated_at, COUNT(DISTINCT ct.contact_id) AS contact_count')
            ->join('contact_tags ct', 'ct.tag_id = t.id', 'left')
            ->join('contacts c', 'c.id = ct.contact_id AND c.deleted_at IS NULL', 'left')
            ->groupBy('t.id');

        if ($search !== null && $search !== '') {
            $builder->like('t.name', $search);
        }

        return $builder
            ->orderBy('t.created_at', 'DESC')
            ->orderBy('t.name', 'ASC')
            ->get()
            ->getResultArray();
    }

    /**
     * Active contacts belonging to a customer group.
     *
     * @return list<array<string, mixed>>
     */
    public function getContacts(int $tagId): array
    {
        return $this->db->table('contact_tags ct')
            ->select('c.id, c.name, c.mobile, c.email, c.status, c.created_at')
            ->join('contacts c', 'c.id = ct.contact_id')
            ->where('ct.tag_id', $tagId)
            ->where('c.deleted_at', null)
            ->orderBy('c.name', 'ASC')
            ->orderBy('c.id', 'ASC')
            ->get()
            ->getResultArray();
    }

    public function contactCount(int $tagId): int
    {
        return (int) $this->db->table('contact_tags ct')
            ->join('contacts c', 'c.id = ct.contact_id')
            ->where('ct.tag_id', $tagId)
            ->where('c.deleted_at', null)
            ->countAllResults();
    }

    public function attachContact(int $tagId, int $contactId): bool
    {
        $exists = $this->db->table('contact_tags')
            ->where('tag_id', $tagId)
            ->where('contact_id', $contactId)
            ->countAllResults();

        if ($exists > 0) {
            return false;
        }

        $this->db->table('contact_tags')->insert([
            'tag_id'     => $tagId,
            'contact_id' => $contactId,
        ]);

        return true;
    }

    public function detachContact(int $tagId, int $contactId): void
    {
        $this->db->table('contact_tags')
            ->where('tag_id', $tagId)
            ->where('contact_id', $contactId)
            ->delete();
    }

    /**
     * Find an existing group by name or create it.
     *
     * @return array<string, mixed>
     */
    public function findOrCreateByName(string $name, string $color = '#6B7280'): array
    {
        $name = trim($name);
        $existing = $this->where('name', $name)->first();
        if (is_array($existing)) {
            return $existing;
        }

        $id = (int) $this->insert([
            'name'  => $name,
            'color' => $color,
        ]);

        $row = $this->find($id);

        return is_array($row) ? $row : ['id' => $id, 'name' => $name, 'color' => $color];
    }
}
