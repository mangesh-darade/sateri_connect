<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

class AutomationRuleModel extends Model
{
    protected $table            = 'automation_rules';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'automation_id',
        'step_order',
        'rule_type',
        'action_type',
        'config',
        'next_on_true',
        'next_on_false',
    ];

    protected bool $allowEmptyInserts = false;
    protected bool $updateOnlyChanged = true;

    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    protected $validationRules = [
        'automation_id' => 'required|is_natural_no_zero',
        'step_order'    => 'required|integer',
        'rule_type'     => 'required|in_list[condition,action]',
    ];

    protected $validationMessages   = [];
    protected $skipValidation       = false;
    protected $cleanValidationRules = true;

    protected $beforeInsert = ['encodeConfig'];
    protected $beforeUpdate = ['encodeConfig'];
    protected $afterFind    = ['decodeConfig'];

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function encodeConfig(array $data): array
    {
        if (isset($data['data']['config']) && is_array($data['data']['config'])) {
            $data['data']['config'] = json_encode($data['data']['config']);
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function decodeConfig(array $data): array
    {
        if (! isset($data['data'])) {
            return $data;
        }

        $decode = static function (array &$row): void {
            if (isset($row['config']) && is_string($row['config'])) {
                $decoded = json_decode($row['config'], true);
                $row['config'] = is_array($decoded) ? $decoded : null;
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
    public function getByAutomation(int $automationId): array
    {
        return $this->where('automation_id', $automationId)
            ->orderBy('step_order', 'ASC')
            ->findAll();
    }
}
