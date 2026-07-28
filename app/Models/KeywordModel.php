<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

class KeywordModel extends Model
{
    protected $table            = 'keywords';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'keyword',
        'match_type',
        'response_type',
        'response_content',
        'response_payload',
        'parent_id',
        'menu_order',
        'is_active',
    ];

    protected bool $allowEmptyInserts = false;
    protected bool $updateOnlyChanged = true;

    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    protected $validationRules = [
        'keyword'       => 'required|max_length[191]',
        'match_type'    => 'required|in_list[exact,contains,starts_with]',
        'response_type' => 'permit_empty|max_length[50]',
        'is_active'     => 'permit_empty|in_list[0,1]',
    ];

    protected $validationMessages   = [];
    protected $skipValidation       = false;
    protected $cleanValidationRules = true;

    protected $beforeInsert = ['encodePayload'];
    protected $beforeUpdate = ['encodePayload'];
    protected $afterFind    = ['decodePayload'];

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function encodePayload(array $data): array
    {
        if (isset($data['data']['response_payload']) && is_array($data['data']['response_payload'])) {
            $data['data']['response_payload'] = json_encode($data['data']['response_payload']);
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function decodePayload(array $data): array
    {
        if (! isset($data['data'])) {
            return $data;
        }

        $decode = static function (array &$row): void {
            if (isset($row['response_payload']) && is_string($row['response_payload'])) {
                $decoded = json_decode($row['response_payload'], true);
                $row['response_payload'] = is_array($decoded) ? $decoded : null;
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
     * @return list<array<string, mixed>>
     */
    public function getActive(): array
    {
        return $this->where('is_active', 1)
            ->orderBy('menu_order', 'ASC')
            ->findAll();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function matchMessage(string $message): ?array
    {
        $message = trim($message);
        $keywords = $this->getActive();

        foreach ($keywords as $keyword) {
            $needle = (string) $keyword['keyword'];
            $type   = $keyword['match_type'] ?? 'exact';

            $matched = match ($type) {
                'exact'       => strcasecmp($message, $needle) === 0,
                'contains'    => stripos($message, $needle) !== false,
                'starts_with' => stripos($message, $needle) === 0,
                default       => false,
            };

            if ($matched) {
                return $keyword;
            }
        }

        return null;
    }
}
