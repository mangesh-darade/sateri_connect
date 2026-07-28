<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

class MessageQueueModel extends Model
{
    protected $table            = 'message_queue';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'campaign_id',
        'contact_id',
        'message_type',
        'payload',
        'priority',
        'status',
        'attempts',
        'max_attempts',
        'scheduled_at',
        'processed_at',
        'wa_message_id',
        'error_message',
    ];

    protected bool $allowEmptyInserts = false;
    protected bool $updateOnlyChanged = true;

    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    protected $validationRules = [
        'contact_id'   => 'required|is_natural_no_zero',
        'message_type' => 'required|max_length[50]',
        'status'       => 'permit_empty|in_list[pending,processing,sent,failed,cancelled]',
        'priority'     => 'permit_empty|integer',
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
        if (isset($data['data']['payload']) && is_array($data['data']['payload'])) {
            $data['data']['payload'] = json_encode($data['data']['payload']);
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
            if (isset($row['payload']) && is_string($row['payload'])) {
                $decoded = json_decode($row['payload'], true);
                $row['payload'] = is_array($decoded) ? $decoded : null;
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
    public function getPending(int $limit = 50): array
    {
        $now = date('Y-m-d H:i:s');

        return $this->db->table($this->table)
            ->where('status', 'pending')
            ->groupStart()
                ->where('scheduled_at', null)
                ->orWhere('scheduled_at <=', $now)
            ->groupEnd()
            ->orderBy('priority', 'ASC')
            ->orderBy('id', 'ASC')
            ->limit($limit)
            ->get()
            ->getResultArray();
    }

    /**
     * Atomically claim pending rows (and reclaim stuck processing) for one worker.
     *
     * @return list<array<string, mixed>>
     */
    public function claimPending(int $limit = 50, int $stuckSeconds = 900): array
    {
        $limit = max(1, min(500, $limit));
        $now   = date('Y-m-d H:i:s');

        // Reclaim jobs stuck in processing (worker crash / timeout)
        $this->db->table($this->table)
            ->where('status', 'processing')
            ->where('updated_at <', date('Y-m-d H:i:s', time() - max(60, $stuckSeconds)))
            ->update([
                'status'     => 'pending',
                'updated_at' => $now,
            ]);

        $candidates = $this->getPending($limit);
        $claimed    = [];

        foreach ($candidates as $row) {
            $id      = (int) ($row['id'] ?? 0);
            $attempts = ((int) ($row['attempts'] ?? 0)) + 1;

            $this->db->table($this->table)
                ->where('id', $id)
                ->where('status', 'pending')
                ->update([
                    'status'     => 'processing',
                    'attempts'   => $attempts,
                    'updated_at' => $now,
                ]);

            if ($this->db->affectedRows() < 1) {
                continue;
            }

            $row['status']   = 'processing';
            $row['attempts'] = $attempts;
            $claimed[]       = $row;
        }

        return $claimed;
    }

    public function markProcessing(int $id): bool
    {
        $row = $this->find($id);

        if (! is_array($row)) {
            return false;
        }

        return $this->update($id, [
            'status'   => 'processing',
            'attempts' => ((int) ($row['attempts'] ?? 0)) + 1,
        ]);
    }

    public function markSent(int $id, ?string $waMessageId = null): bool
    {
        $data = [
            'status'       => 'sent',
            'processed_at' => date('Y-m-d H:i:s'),
            'error_message'=> null,
        ];

        if ($waMessageId !== null) {
            $data['wa_message_id'] = $waMessageId;
        }

        return $this->update($id, $data);
    }

    public function markFailed(int $id, string $errorMessage, ?string $status = null, ?int $attempts = null): bool
    {
        $row = $this->find($id);

        if (! is_array($row)) {
            return false;
        }

        $attempts    = $attempts ?? (((int) ($row['attempts'] ?? 0)) + 1);
        $maxAttempts = (int) ($row['max_attempts'] ?? 3);
        $status      = $status ?? ($attempts >= $maxAttempts ? 'failed' : 'pending');

        return $this->update($id, [
            'status'        => $status,
            'attempts'      => $attempts,
            'error_message' => $errorMessage,
            'processed_at'  => $status === 'failed' ? date('Y-m-d H:i:s') : null,
        ]);
    }
}
