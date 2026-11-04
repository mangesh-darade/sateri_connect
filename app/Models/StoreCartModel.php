<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\SelfHealingSchema;
use CodeIgniter\Model;

/**
 * WooCommerce unpaid orders and Shopify checkouts waiting for a WhatsApp cart reminder.
 */
class StoreCartModel extends Model
{
    use SelfHealingSchema;

    protected $table            = 'store_carts';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'platform',
        'external_id',
        'phone',
        'customer_name',
        'items_text',
        'total',
        'amount',
        'checkout_url',
        'status',
        'reminders_sent',
        'queue_id',
        'last_error',
        'last_activity_at',
        'last_reminded_at',
        'recovered_at',
        'created_at',
    ];

    protected $useTimestamps = false;

    protected array $casts = [
        'amount'         => 'float',
        'reminders_sent' => 'int',
        'queue_id'       => '?int',
    ];

    protected $validationRules = [
        'platform'    => 'required|in_list[woocommerce,shopify]',
        'external_id' => 'required|max_length[80]',
        'phone'       => 'permit_empty|max_length[32]',
        'status'      => 'permit_empty|in_list[open,reminded,recovered,skipped]',
    ];

}
