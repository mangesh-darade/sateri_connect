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
}
