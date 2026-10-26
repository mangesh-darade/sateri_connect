<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\SelfHealingSchema;
use CodeIgniter\Model;

/**
 * Fixed-window request counters (purged by logs:cleanup).
 */
class RateLimitModel extends Model
{
    use SelfHealingSchema;

    protected $table            = 'rate_limits';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'key',
        'hits',
        'window_start',
    ];

    protected $useTimestamps = false;
}
