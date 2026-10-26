<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\SelfHealingSchema;
use CodeIgniter\Model;

/**
 * The single wallets row (id 1). Balance changes go through WalletService, which updates it atomically.
 */
class WalletModel extends Model
{
    use SelfHealingSchema;

    protected $table            = 'wallets';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'enabled',
        'balance',
        'currency',
        'low_balance_alert',
        'tax_percent',
        'markup_percent',
        'daily_cap',
        'monthly_cap',
        'auto_topup_amount',
        'last_low_alert_at',
        'updated_at',
    ];

    protected $useTimestamps = false;
}
