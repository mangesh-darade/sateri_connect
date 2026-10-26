<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\SelfHealingSchema;
use CodeIgniter\Model;

/**
 * Online wallet recharges (Razorpay). One row per order; the wallet is credited once when it is paid.
 */
class WalletPaymentModel extends Model
{
    use SelfHealingSchema;

    protected $table            = 'wallet_payments';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'order_id',
        'gateway',
        'gateway_ref',
        'gateway_txn',
        'payment_id',
        'source',
        'status',
        'currency',
        'credit_amount',
        'tax_percent',
        'tax_amount',
        'total_amount',
        'invoice_no',
        'created_by',
        'paid_at',
        'created_at',
        'updated_at',
    ];

    protected $useTimestamps = false;
}
