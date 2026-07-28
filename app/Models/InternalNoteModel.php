<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

class InternalNoteModel extends Model
{
    protected $table            = 'internal_notes';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'contact_id',
        'user_id',
        'note',
        'is_internal',
    ];

    protected bool $allowEmptyInserts = false;
    protected bool $updateOnlyChanged = true;

    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    protected $validationRules = [
        'contact_id'  => 'required|is_natural_no_zero',
        'user_id'     => 'required|is_natural_no_zero',
        'note'        => 'required',
        'is_internal' => 'permit_empty|in_list[0,1]',
    ];

    protected $validationMessages   = [];
    protected $skipValidation       = false;
    protected $cleanValidationRules = true;

    /**
     * @return list<array<string, mixed>>
     */
    public function getForContact(int $contactId): array
    {
        return $this->db->table($this->table)
            ->select('internal_notes.*, users.name AS user_name')
            ->join('users', 'users.id = internal_notes.user_id', 'left')
            ->where('internal_notes.contact_id', $contactId)
            ->orderBy('internal_notes.created_at', 'DESC')
            ->get()
            ->getResultArray();
    }
}
