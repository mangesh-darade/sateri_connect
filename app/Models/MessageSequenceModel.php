<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\SelfHealingSchema;
use CodeIgniter\Model;

/**
 * Drip sequence definitions.
 */
class MessageSequenceModel extends Model
{
    use SelfHealingSchema;

    protected $table            = 'message_sequences';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'name',
        'channel',
        'is_active',
        'exit_on_reply',
        'created_by',
    ];

    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
}
