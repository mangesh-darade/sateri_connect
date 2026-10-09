<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\SelfHealingSchema;
use CodeIgniter\Model;

class ApiRequestLogModel extends Model
{
    use SelfHealingSchema;

    protected $table            = 'api_request_logs';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'token_id',
        'token_name',
        'user_id',
        'method',
        'endpoint',
        'status_code',
        'ip_address',
        'user_agent',
        'duration_ms',
        'created_at',
    ];

    protected $useTimestamps = false;

    /**
     * Per-key usage over the last $days days: total calls, failed calls, last call, distinct IPs.
     *
     * @return array<int, array<string, mixed>> keyed by token_id
     */
    public function usageByToken(int $days = 30): array
    {
        $rows = $this->db->table($this->table)
            ->select('token_id, COUNT(*) AS total, SUM(status_code >= 400) AS failed, MAX(created_at) AS last_call, COUNT(DISTINCT ip_address) AS ips')
            ->where('created_at >=', date('Y-m-d H:i:s', strtotime('-' . $days . ' days')))
            ->groupBy('token_id')
            ->get()
            ->getResultArray();

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['token_id']] = $row;
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function recent(int $limit = 50): array
    {
        return $this->orderBy('id', 'DESC')->findAll($limit);
    }
}
