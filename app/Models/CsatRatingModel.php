<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\SelfHealingSchema;
use CodeIgniter\Model;

/**
 * CSAT questions sent to customers and their 1-5 answers.
 */
class CsatRatingModel extends Model
{
    use SelfHealingSchema;

    protected $table            = 'csat_ratings';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'conversation_id',
        'contact_id',
        'agent_id',
        'rating',
        'asked_at',
        'answered_at',
    ];

    protected $useTimestamps = false;
}
