<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\SelfHealingSchema;
use CodeIgniter\Model;

/**
 * Click-to-WhatsApp ad campaigns. Money columns are minor units (paise).
 */
class AdCampaignModel extends Model
{
    use SelfHealingSchema;

    public const STATUSES = ['draft', 'paused', 'active', 'ended', 'rejected', 'error'];

    protected $table            = 'ad_campaigns';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'name',
        'objective',
        'website_url',
        'brief',
        'audience',
        'special_category',
        'daily_budget_minor',
        'currency',
        'starts_at',
        'ends_at',
        'status',
        'is_mock',
        'ad_account_id',
        'meta_campaign_id',
        'meta_adset_id',
        'meta_ad_id',
        'source_target_id',
        'created_by',
        'approved_by',
        'last_error',
    ];

    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';

    protected $validationRules = [
        'name'               => 'required|max_length[150]',
        'objective'          => 'permit_empty|in_list[messages,traffic]',
        'daily_budget_minor' => 'permit_empty|is_natural',
        'status'             => 'permit_empty|in_list[draft,paused,active,ended,rejected,error]',
    ];
}
