<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

class EmailDripModel extends Model
{
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
}
