<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\SelfHealingSchema;
use CodeIgniter\Model;

/**
 * One row per campaign per day, synced from Meta by `ads:sync`. Unique (campaign_id, metric_date).
 */
class AdMetricDailyModel extends Model
{
    use SelfHealingSchema;

    protected $table            = 'ad_metrics_daily';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'campaign_id',
        'metric_date',
        'spend_minor',
        'impressions',
        'reach',
        'clicks',
        'conversations',
        'cost_per_conversation_minor',
    ];

    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
}
