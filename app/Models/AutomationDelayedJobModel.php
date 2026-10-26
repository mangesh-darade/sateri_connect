<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\SelfHealingSchema;
use CodeIgniter\Model;

/**
 * Automation steps waiting for a delay to pass (resumed by automations:process).
 */
class AutomationDelayedJobModel extends Model
{
    use SelfHealingSchema;

    protected $table            = 'automation_delayed_jobs';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'automation_id',
        'contact_id',
        'resume_rule_id',
        'context_json',
        'run_at',
        'status',
    ];

    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
}
