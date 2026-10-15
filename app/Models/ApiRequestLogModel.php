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
     * Day + actual API totals for the last $days days.
     *
     * @return list<array<string, mixed>>
     */
    public function dailyApis(int $days = 30): array
    {
        $since = date('Y-m-d 00:00:00', strtotime('-' . max(1, $days - 1) . ' days'));

        return $this->db->table($this->table)
            ->select("DATE(created_at) AS day, method, endpoint, COUNT(*) AS total, SUM(CASE WHEN status_code >= 400 THEN 1 ELSE 0 END) AS failed", false)
            ->where('created_at >=', $since)
            ->groupBy('DATE(created_at)', false)
            ->groupBy('method')
            ->groupBy('endpoint')
            ->orderBy('DATE(created_at)', 'DESC', false)
            ->orderBy('total', 'DESC')
            ->get()
            ->getResultArray();
    }

    /**
     * One page of real calls for a day, optionally one API and one outcome.
     *
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function callsFor(string $day, string $outcome = 'all', string $method = '', string $endpoint = '', int $limit = 30, int $offset = 0): array
    {
        $builder = $this->db->table($this->table)
            ->where('created_at >=', $day . ' 00:00:00')
            ->where('created_at <=', $day . ' 23:59:59');

        if ($outcome === 'failed') {
            $builder->where('status_code >=', 400);
        } elseif ($outcome === 'ok') {
            $builder->where('status_code <', 400);
        }
        if ($method !== '') {
            $builder->where('method', $method);
        }
        if ($endpoint !== '') {
            $builder->where('endpoint', $endpoint);
        }

        $total = (int) $builder->countAllResults(false);
        $rows  = $builder->orderBy('id', 'DESC')->limit($limit, $offset)->get()->getResultArray();

        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function recent(int $limit = 50): array
    {
        return $this->orderBy('id', 'DESC')->findAll($limit);
    }
}
