<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\SelfHealingSchema;
use CodeIgniter\Model;

/**
 * A contact moving through a sequence.
 */
class SequenceEnrollmentModel extends Model
{
    use SelfHealingSchema;

    protected $table            = 'sequence_enrollments';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'sequence_id',
        'contact_id',
        'current_step',
        'status',
        'next_run_at',
        'last_sent_at',
    ];

    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
}
