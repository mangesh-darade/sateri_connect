<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\SelfHealingSchema;
use CodeIgniter\Model;

/**
 * Contact <-> tag pivot (composite key contact_id + tag_id).
 */
class ContactTagModel extends Model
{
    use SelfHealingSchema;

    protected $table            = 'contact_tags';
    protected $primaryKey       = 'contact_id';
    protected $useAutoIncrement = false;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'contact_id',
        'tag_id',
    ];

    protected $useTimestamps = false;
}
