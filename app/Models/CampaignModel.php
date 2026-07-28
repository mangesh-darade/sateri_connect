<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

class CampaignModel extends Model
{
    protected $table            = 'campaigns';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'name',
        'template_id',
        'status',
        'message_type',
        'payload',
        'variables',
        'scheduled_at',
        'started_at',
        'completed_at',
        'total_contacts',
        'sent_count',
        'delivered_count',
        'read_count',
        'failed_count',
        'reply_count',
        'created_by',
    ];

    protected bool $allowEmptyInserts = false;
    protected bool $updateOnlyChanged = true;

    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    protected $validationRules = [
        'name'   => 'required|max_length[191]',
        'status' => 'permit_empty|in_list[draft,scheduled,running,paused,completed,cancelled]',
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
        foreach (['payload', 'variables'] as $field) {
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
            foreach (['payload', 'variables'] as $field) {
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

    public function updateStats(int $campaignId): bool
    {
        $stats = $this->db->table('campaign_contacts')
            ->select("
                COUNT(*) AS total_contacts,
                SUM(CASE WHEN status IN ('sent','delivered','read') THEN 1 ELSE 0 END) AS sent_count,
                SUM(CASE WHEN status IN ('delivered','read') THEN 1 ELSE 0 END) AS delivered_count,
                SUM(CASE WHEN status = 'read' THEN 1 ELSE 0 END) AS read_count,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed_count
            ", false)
            ->where('campaign_id', $campaignId)
            ->get()
            ->getRowArray();

        if ($stats === null) {
            return false;
        }

        $replyCount = $this->db->table('messages')
            ->where('campaign_id', $campaignId)
            ->where('direction', 'inbound')
            ->countAllResults();

        return $this->update($campaignId, [
            'total_contacts'  => (int) ($stats['total_contacts'] ?? 0),
            'sent_count'      => (int) ($stats['sent_count'] ?? 0),
            'delivered_count' => (int) ($stats['delivered_count'] ?? 0),
            'read_count'      => (int) ($stats['read_count'] ?? 0),
            'failed_count'    => (int) ($stats['failed_count'] ?? 0),
            'reply_count'     => $replyCount,
        ]);
    }
}
