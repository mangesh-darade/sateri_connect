<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

class WebhookLogModel extends Model
{
    protected $table            = 'webhook_logs';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'event_type',
        'payload',
        'headers',
        'signature_valid',
        'processed',
        'error_message',
        'created_at',
    ];

    protected bool $allowEmptyInserts = false;
    protected bool $updateOnlyChanged = true;

    protected $useTimestamps = false;

    protected $validationRules      = [];
    protected $validationMessages   = [];
    protected $skipValidation       = false;
    protected $cleanValidationRules = true;

    protected $beforeInsert = ['encodeJsonFields', 'setCreatedAt'];
    protected $beforeUpdate = ['encodeJsonFields'];
    protected $afterFind    = ['decodeJsonFields'];

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
    protected function encodeJsonFields(array $data): array
    {
        if (isset($data['data']['payload']) && (is_array($data['data']['payload']) || is_object($data['data']['payload']))) {
            $data['data']['payload'] = json_encode($data['data']['payload']);
        }

        if (isset($data['data']['headers']) && is_array($data['data']['headers'])) {
            $data['data']['headers'] = json_encode($data['data']['headers']);
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function decodeJsonFields(array $data): array
    {
        if (! isset($data['data'])) {
            return $data;
        }

        $decode = static function (array &$row): void {
            if (isset($row['payload']) && is_string($row['payload'])) {
                $decoded = json_decode($row['payload'], true);
                $row['payload'] = $decoded ?? $row['payload'];
            }

            if (isset($row['headers']) && is_string($row['headers'])) {
                $decoded = json_decode($row['headers'], true);
                $row['headers'] = is_array($decoded) ? $decoded : null;
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

    public function markProcessed(int $id, ?string $errorMessage = null): bool
    {
        return $this->update($id, [
            'processed'     => 1,
            'error_message' => $errorMessage,
        ]);
    }
}
