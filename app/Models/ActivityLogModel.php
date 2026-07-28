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
}
