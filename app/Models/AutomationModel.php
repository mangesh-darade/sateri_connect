<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

class AutomationModel extends Model
{
    protected $table            = 'automations';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'name',
        'trigger_type',
        'trigger_config',
        'flow_graph',
        'is_active',
        'priority',
        'created_by',
    ];

    protected bool $allowEmptyInserts = false;
    protected bool $updateOnlyChanged = true;

    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    protected $validationRules = [
        'name'         => 'required|max_length[191]',
        'trigger_type' => 'required|max_length[100]',
        'is_active'    => 'permit_empty|in_list[0,1]',
        'priority'     => 'permit_empty|integer',
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
        if (isset($data['data']['trigger_config']) && is_array($data['data']['trigger_config'])) {
            $data['data']['trigger_config'] = json_encode($data['data']['trigger_config']);
        }
        if (isset($data['data']['flow_graph']) && is_array($data['data']['flow_graph'])) {
            $data['data']['flow_graph'] = json_encode($data['data']['flow_graph']);
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
            foreach (['trigger_config', 'flow_graph'] as $field) {
                if (isset($row[$field]) && is_string($row[$field])) {
                    $decoded = json_decode($row[$field], true);
                    $row[$field] = is_array($decoded) ? $decoded : ($field === 'flow_graph' ? null : null);
                }
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
    public function getActiveByTrigger(string $triggerType): array
    {
        return $this->where('is_active', 1)
            ->where('trigger_type', $triggerType)
            ->orderBy('priority', 'ASC')
            ->findAll();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getWithRules(int $automationId): ?array
    {
        $automation = $this->find($automationId);

        if (! is_array($automation)) {
            return null;
        }

        $automation['rules'] = $this->db->table('automation_rules')
            ->where('automation_id', $automationId)
            ->orderBy('step_order', 'ASC')
            ->get()
            ->getResultArray();

        return $automation;
    }
}
