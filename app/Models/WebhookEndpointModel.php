<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\SelfHealingSchema;
use CodeIgniter\Model;

/**
 * Client-configured outgoing webhook endpoints. The signing secret is stored encrypted.
 */
class WebhookEndpointModel extends Model
{
    use SelfHealingSchema;

    protected $table            = 'webhook_endpoints';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'name',
        'url',
        'secret',
        'events',
        'is_active',
        'created_by',
    ];

    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
}
