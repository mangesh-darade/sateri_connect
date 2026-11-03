<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\SelfHealingSchema;
use CodeIgniter\Model;

class SocialMediaAssetModel extends Model
{
    use SelfHealingSchema;

    protected $table            = 'social_media_assets';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'source_id',
        'drive_file_id',
        'filename',
        'mime',
        'size_bytes',
        'duration_sec',
        'width',
        'height',
        'checksum',
        'validation',
        'status',
    ];

    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    protected $validationRules = [
        'drive_file_id' => 'required|max_length[128]',
        'filename'      => 'required|max_length[255]',
    ];
}
