<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\SelfHealingSchema;
use CodeIgniter\Model;

/**
 * Append-only wallet ledger. Rows are written by WalletService only and never updated or deleted.
 */
class WalletTransactionModel extends Model
{
    use SelfHealingSchema;

    protected $table            = 'wallet_transactions';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'type',
        'amount',
        'currency',
        'balance_after',
        'category',
        'country_code',
        'reference',
        'description',
        'base_amount',
        'tax_amount',
        'created_by',
        'created_at',
    ];

    protected $useTimestamps = false;

    /**
     * @return list<array<string, mixed>>
     */
    public function recent(int $limit = 100): array
    {
        return $this->orderBy('id', 'DESC')->findAll(max(1, min(500, $limit)));
    }
}
