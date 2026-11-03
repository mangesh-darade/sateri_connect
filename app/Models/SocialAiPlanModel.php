<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\SelfHealingSchema;
use CodeIgniter\Model;

class SocialAiPlanModel extends Model
{
    use SelfHealingSchema;

    protected $table            = 'social_ai_plans';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'title',
        'category',
        'brief',
        'days',
        'start_date',
        'post_time',
        'account_ids',
        'tone',
        'language',
        'status',
        'created_by',
    ];

    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    protected $validationRules = [
        'title'    => 'required|max_length[191]',
        'category' => 'required|max_length[191]',
        'status'   => 'permit_empty|in_list[draft,ready,approved]',
    ];
}
