<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

class MediaModel extends Model
{
    protected $table            = 'media';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'filename',
        'original_name',
        'mime_type',
        'size',
        'path',
        'wa_media_id',
        'url',
        'uploaded_by',
    ];

    protected bool $allowEmptyInserts = false;
    protected bool $updateOnlyChanged = true;

    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    protected $validationRules = [
        'filename'      => 'required|max_length[255]',
        'original_name' => 'required|max_length[255]',
        'mime_type'     => 'required|max_length[100]',
        'path'          => 'required|max_length[500]',
    ];

    protected $validationMessages   = [];
    protected $skipValidation       = false;
    protected $cleanValidationRules = true;

    /**
     * @return array<string, mixed>|null
     */
    public function findByWaMediaId(string $waMediaId): ?array
    {
        $row = $this->where('wa_media_id', $waMediaId)->first();

        return is_array($row) ? $row : null;
    }
}
