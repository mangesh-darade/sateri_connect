<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

class ActivityLogModel extends Model
{
    protected $table            = 'activity_logs';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'user_id',
        'action',
        'module',
        'description',
        'ip_address',
        'user_agent',
        'metadata',
        'created_at',
    ];

    protected bool $allowEmptyInserts = false;
    protected bool $updateOnlyChanged = true;

    protected $useTimestamps = false;

    protected $validationRules = [
        'action' => 'required|max_length[100]',
    ];

    protected $validationMessages   = [];
    protected $skipValidation       = false;
    protected $cleanValidationRules = true;

    protected $beforeInsert = ['encodeMetadata', 'setCreatedAt'];
    protected $afterFind    = ['decodeMetadata'];

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function setCreatedAt(array $data): array
    {
        if (! isset($data['data']['created_at'])) {
            $data['data']['created_at'] = date('Y-m-d H:i:s');
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function encodeMetadata(array $data): array
    {
        if (isset($data['data']['metadata']) && is_array($data['data']['metadata'])) {
            $data['data']['metadata'] = json_encode($data['data']['metadata']);
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function decodeMetadata(array $data): array
    {
        if (! isset($data['data'])) {
            return $data;
        }

        $decode = static function (array &$row): void {
            if (isset($row['metadata']) && is_string($row['metadata'])) {
                $decoded = json_decode($row['metadata'], true);
                $row['metadata'] = is_array($decoded) ? $decoded : null;
            }
        };

        if ($data['singleton'] ?? false) {
            $decode($data['data']);

            return $data;
        }

        foreach ($data['data'] as &$row) {
            $decode($row);
        }
        unset($row);

        return $data;
    }

    /**
     * @param array<string, mixed>|null $metadata
     */
    public function log(
        string $action,
        ?int $userId = null,
        ?string $module = null,
        ?string $description = null,
        ?array $metadata = null
    ): int|string|bool {
        $request = service('request');

        return $this->insert([
            'user_id'     => $userId,
            'action'      => $action,
            'module'      => $module,
            'description' => $description,
            'ip_address'  => $request->getIPAddress(),
            'user_agent'  => substr((string) $request->getUserAgent(), 0, 500),
            'metadata'    => $metadata,
            'created_at'  => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Fetch paginated and filtered activity logs.
     *
     * @param array<string, mixed> $filters
     * @return array{data: list<array<string, mixed>>, total: int, per_page: int, page: int, total_pages: int}
     */
    public function getFilteredLogs(array $filters = [], int $perPage = 25, int $page = 1): array
    {
        $perPage = max(1, min(100, $perPage));
        $page    = max(1, $page);
        $offset  = ($page - 1) * $perPage;

        $builder = $this->builder();
        $this->applyFilters($builder, $filters);

        $countBuilder = clone $builder;
        $total = (int) $countBuilder->countAllResults();

        $builder->select('activity_logs.*, users.name AS user_name, users.email AS user_email')
            ->join('users', 'users.id = activity_logs.user_id', 'left')
            ->orderBy('activity_logs.created_at', 'DESC')
            ->limit($perPage, $offset);

        $rows = $builder->get()->getResultArray();

        // Run afterFind to decode metadata
        $rows = $this->trigger('afterFind', ['data' => $rows, 'singleton' => false])['data'] ?? $rows;

        return [
            'data'        => $rows,
            'total'       => $total,
            'per_page'    => $perPage,
            'page'        => $page,
            'total_pages' => (int) ceil($total / $perPage),
        ];
    }

    /**
     * Get distinct module names for filtering.
     *
     * @return list<string>
     */
    public function getDistinctModules(): array
    {
        $rows = $this->builder()
            ->select('DISTINCT(module) AS module')
            ->where('module IS NOT NULL')
            ->where('module !=', '')
            ->orderBy('module', 'ASC')
            ->get()
            ->getResultArray();

        return array_values(array_filter(array_column($rows, 'module')));
    }

    /**
     * Apply search filters to Query Builder.
     */
    protected function applyFilters(\CodeIgniter\Database\BaseBuilder $builder, array $filters): void
    {
        if (! empty($filters['module'])) {
            $builder->where('activity_logs.module', (string) $filters['module']);
        }

        if (! empty($filters['action'])) {
            $builder->where('activity_logs.action', (string) $filters['action']);
        }

        if (! empty($filters['user_id'])) {
            $builder->where('activity_logs.user_id', (int) $filters['user_id']);
        }

        if (! empty($filters['date_from'])) {
            $builder->where('activity_logs.created_at >=', $filters['date_from'] . ' 00:00:00');
        }

        if (! empty($filters['date_to'])) {
            $builder->where('activity_logs.created_at <=', $filters['date_to'] . ' 23:59:59');
        }

        if (! empty($filters['search'])) {
            $term = trim((string) $filters['search']);
            $builder->groupStart()
                ->like('activity_logs.description', $term)
                ->orLike('activity_logs.action', $term)
                ->orLike('activity_logs.module', $term)
                ->groupEnd();
        }
    }
}
