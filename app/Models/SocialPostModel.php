<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\SelfHealingSchema;
use CodeIgniter\Model;

class SocialPostModel extends Model
{
    use SelfHealingSchema;

    protected $table            = 'social_posts';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'asset_id',
        'type',
        'title',
        'caption',
        'hashtags',
        'status',
        'created_by',
        'approved_by',
    ];

    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    protected $validationRules = [
        'type'   => 'permit_empty|in_list[post,reel,short,story,carousel]',
        'status' => 'permit_empty|in_list[draft,pending_approval,scheduled,partial,published,failed]',
    ];
}
