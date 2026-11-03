<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\SelfHealingSchema;
use CodeIgniter\Model;

/**
 * Razorpay payment links sent to customers from the inbox, one row per link.
 */
class PaymentLinkModel extends Model
{
    use SelfHealingSchema;

    protected $table            = 'payment_links';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'contact_id',
        'link_id',
        'short_url',
        'amount',
        'currency',
        'description',
        'status',
        'payment_id',
        'created_by',
        'paid_at',
        'created_at',
        'updated_at',
    ];

    protected $useTimestamps = false;
}
