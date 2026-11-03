<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\SelfHealingSchema;
use CodeIgniter\Model;

/**
 * Queue and log of outgoing webhook calls (pending, sent or failed after retries).
 */
class WebhookDeliveryModel extends Model
{
    use SelfHealingSchema;

    protected $table            = 'webhook_deliveries';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'endpoint_id',
        'event',
        'payload',
        'status',
        'attempts',
        'next_attempt_at',
        'last_http_status',
        'last_error',
        'created_at',
        'sent_at',
    ];

    protected $useTimestamps = false;
}
