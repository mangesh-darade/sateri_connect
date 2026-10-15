<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\SelfHealingSchema;
use CodeIgniter\Model;

class AutomationModel extends Model
{
    use SelfHealingSchema;

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

    /**
     * Copy a workflow and its steps. The copy stays off so it does not run beside the original.
     */
    public function duplicateRow(int $id, ?int $createdBy): ?int
    {
        $source = $this->getWithRules($id);
        if ($source === null) {
            return null;
        }

        $db = $this->db;
        $db->transStart();

        $newId = $this->insert([
            'name'           => $this->copyName((string) ($source['name'] ?? 'Workflow')),
            'trigger_type'   => (string) ($source['trigger_type'] ?? ''),
            'trigger_config' => is_array($source['trigger_config'] ?? null) ? $source['trigger_config'] : null,
            'flow_graph'     => is_array($source['flow_graph'] ?? null) ? $source['flow_graph'] : null,
            'is_active'      => 0,
            'priority'       => (int) ($source['priority'] ?? 10),
            'created_by'     => $createdBy,
        ], true);

        if (! $newId) {
            $db->transRollback();

            return null;
        }

        $ruleModel = model(AutomationRuleModel::class);
        foreach ($source['rules'] ?? [] as $rule) {
            if (! is_array($rule)) {
                continue;
            }
            $config = $rule['config'] ?? [];
            if (is_string($config)) {
                $decoded = json_decode($config, true);
                $config  = is_array($decoded) ? $decoded : [];
            }
            $ruleModel->insert([
                'automation_id' => (int) $newId,
                'step_order'    => (int) ($rule['step_order'] ?? 0),
                'rule_type'     => (string) ($rule['rule_type'] ?? 'action'),
                'action_type'   => $rule['action_type'] ?? null,
                'config'        => is_array($config) ? $config : [],
                'next_on_true'  => $rule['next_on_true'] ?? null,
                'next_on_false' => $rule['next_on_false'] ?? null,
            ]);
        }

        $db->transComplete();
        if (! $db->transStatus()) {
            return null;
        }

        return (int) $newId;
    }

    protected function copyName(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            $name = 'Workflow';
        }
        $suffix = ' (copy)';
        $max    = 191;
        if (mb_strlen($name . $suffix) > $max) {
            $name = mb_substr($name, 0, $max - mb_strlen($suffix));
        }

        return $name . $suffix;
    }
}
