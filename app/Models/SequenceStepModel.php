<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\SelfHealingSchema;
use CodeIgniter\Model;

/**
 * Ordered steps of a sequence.
 */
class SequenceStepModel extends Model
{
    use SelfHealingSchema;

    protected $table            = 'sequence_steps';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'sequence_id',
        'step_order',
        'delay_minutes',
        'message_type',
        'template_name',
        'language',
        'body_text',
    ];

    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
}
