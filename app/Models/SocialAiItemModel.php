<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\SelfHealingSchema;
use CodeIgniter\Model;

class SocialAiItemModel extends Model
{
    use SelfHealingSchema;

    protected $table            = 'social_ai_items';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'plan_id',
        'day_no',
        'post_date',
        'topic',
        'image_prompt',
        'caption',
        'hashtags',
        'image_file',
        'asset_id',
        'post_id',
        'status',
        'error',
    ];

    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    protected $validationRules = [
        'topic'  => 'required|max_length[191]',
        'status' => 'permit_empty|in_list[planned,ready,failed,approved,rejected]',
    ];
}
