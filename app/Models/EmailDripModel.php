<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\SelfHealingSchema;
use CodeIgniter\Model;

class EmailDripModel extends Model
{
    use SelfHealingSchema;

    protected $table            = 'email_drips';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $protectFields    = true;
    protected $allowedFields    = [
        'name',
        'description',
        'trigger_type',
        'trigger_value',
        'status',
        'created_by',
    ];
    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    protected $validationRules = [
        'name'         => 'required|max_length[191]',
        'trigger_type' => 'permit_empty|in_list[manual,on_subscribe,on_tag]',
        'status'       => 'permit_empty|in_list[draft,active,paused,archived]',
    ];

    /**
     * @return list<array<string, mixed>>
     */
    public function withSteps(?int $limit = 100): array
    {
        $drips = $this->orderBy('id', 'DESC')->findAll($limit ?? 100);
        if ($drips === []) {
            return [];
        }

        $ids   = array_column($drips, 'id');
        $steps = model(EmailDripStepModel::class)
            ->whereIn('drip_id', $ids)
            ->orderBy('step_order', 'ASC')
            ->findAll();

        $byDrip = [];
        foreach ($steps as $step) {
            $byDrip[(int) $step['drip_id']][] = $step;
        }

        foreach ($drips as &$drip) {
            $drip['steps'] = $byDrip[(int) $drip['id']] ?? [];
        }
        unset($drip);

        return $drips;
    }

    /**
     * Copy a drip and its steps. The copy is saved as a draft.
     */
    public function duplicateRow(int $id, ?int $createdBy): ?int
    {
        $source = $this->find($id);
        if (! is_array($source)) {
            return null;
        }

        $steps = model(EmailDripStepModel::class)
            ->where('drip_id', $id)
            ->orderBy('step_order', 'ASC')
            ->findAll();

        $db = $this->db;
        $db->transStart();

        $newId = $this->insert([
            'name'          => $this->copyName((string) ($source['name'] ?? 'Email workflow')),
            'description'   => $source['description'] ?? null,
            'trigger_type'  => (string) ($source['trigger_type'] ?? 'manual'),
            'trigger_value' => $source['trigger_value'] ?? null,
            'status'        => 'draft',
            'created_by'    => $createdBy,
        ], true);

        if (! $newId) {
            $db->transRollback();

            return null;
        }

        $stepModel = model(EmailDripStepModel::class);
        foreach ($steps as $step) {
            if (! is_array($step)) {
                continue;
            }
            $stepModel->insert([
                'drip_id'      => (int) $newId,
                'step_order'   => (int) ($step['step_order'] ?? 1),
                'delay_hours'  => max(0, (int) ($step['delay_hours'] ?? 0)),
                'subject'      => (string) ($step['subject'] ?? ''),
                'html_content' => (string) ($step['html_content'] ?? ''),
                'builder_id'   => ! empty($step['builder_id']) ? (int) $step['builder_id'] : null,
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
            $name = 'Email workflow';
        }
        $suffix = ' (copy)';
        $max    = 191;
        if (mb_strlen($name . $suffix) > $max) {
            $name = mb_substr($name, 0, $max - mb_strlen($suffix));
        }

        return $name . $suffix;
    }
}
