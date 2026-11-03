<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\SelfHealingSchema;
use CodeIgniter\Model;

/**
 * Ad text variants of a campaign (AI-suggested or edited). One variant is selected to run.
 */
class AdCreativeModel extends Model
{
    use SelfHealingSchema;

    protected $table            = 'ad_creatives';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'campaign_id',
        'headline',
        'primary_text',
        'cta',
        'media_asset_id',
        'post_external_id',
        'is_selected',
        'ai_generated',
    ];

    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';

    protected $validationRules = [
        'campaign_id'  => 'required|is_natural_no_zero',
        'headline'     => 'required|max_length[100]',
        'primary_text' => 'required|max_length[500]',
    ];
}
