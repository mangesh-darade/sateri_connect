<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

class TemplateModel extends Model
{
    protected $table            = 'templates';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'meta_id',
        'name',
        'language',
        'category',
        'status',
        'header_type',
        'header_content',
        'body',
        'footer',
        'buttons',
        'variables',
        'raw_payload',
        'synced_at',
    ];

    protected bool $allowEmptyInserts = false;
    protected bool $updateOnlyChanged = true;

    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    protected $validationRules = [
        'name'     => 'required|max_length[191]',
        'language' => 'permit_empty|max_length[20]',
        'status'   => 'permit_empty|max_length[50]',
    ];

    protected $validationMessages   = [];
    protected $skipValidation       = false;
    protected $cleanValidationRules = true;

    protected $beforeInsert = ['encodeJsonFields'];
    protected $beforeUpdate = ['encodeJsonFields'];
    protected $afterFind    = ['decodeJsonFields'];

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function encodeJsonFields(array $data): array
    {
        foreach (['buttons', 'variables', 'raw_payload'] as $field) {
            if (isset($data['data'][$field]) && is_array($data['data'][$field])) {
                $data['data'][$field] = json_encode($data['data'][$field]);
            }
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

        $decodeRow = static function (array &$row): void {
            foreach (['buttons', 'variables', 'raw_payload'] as $field) {
                if (isset($row[$field]) && is_string($row[$field])) {
                    $decoded = json_decode($row[$field], true);
                    $row[$field] = is_array($decoded) ? $decoded : null;
                }
            }
        };

        if ($data['singleton'] ?? false) {
            $decodeRow($data['data']);

            return $data;
        }

        foreach ($data['data'] as &$row) {
            $decodeRow($row);
        }
        unset($row);

        return $data;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByMetaId(string $metaId): ?array
    {
        $row = $this->where('meta_id', $metaId)->first();

        return is_array($row) ? $row : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getApproved(): array
    {
        return $this->whereIn('status', ['APPROVED', 'approved'])
            ->orderBy('name', 'ASC')
            ->findAll();
    }
}
