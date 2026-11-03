<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\SelfHealingSchema;
use CodeIgniter\Model;

class SocialPostTargetModel extends Model
{
    use SelfHealingSchema;

    protected $table            = 'social_post_targets';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'post_id',
        'account_id',
        'caption_override',
        'options',
        'scheduled_at',
        'status',
        'external_id',
        'external_url',
        'attempts',
        'last_error',
        'progress',
        'progress_stage',
        'published_at',
    ];

    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    protected $validationRules = [
        'post_id'    => 'required|is_natural_no_zero',
        'account_id' => 'required|is_natural_no_zero',
        'status'     => 'permit_empty|in_list[scheduled,publishing,published,failed,skipped]',
    ];
}
