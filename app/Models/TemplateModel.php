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
        'waba_id',
        'meta_id',
        'name',
        'language',
        'category',
        'template_type',
        'status',
        'rejected_reason',
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
        'name'          => 'required|max_length[191]',
        'language'      => 'permit_empty|max_length[20]',
        'template_type' => 'permit_empty|in_list[default,carousel]',
        'status'        => 'permit_empty|max_length[50]',
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
     * Unique mapping for sync: waba_id + template_id (meta_id).
     *
     * @return array<string, mixed>|null
     */
    public function findByWabaAndMetaId(string $wabaId, string $metaId): ?array
    {
        if ($metaId === '') {
            return null;
        }

        $builder = $this->where('meta_id', $metaId);
        if ($wabaId !== '' && $this->db->fieldExists('waba_id', $this->table)) {
            $builder->groupStart()
                ->where('waba_id', $wabaId)
                ->orWhere('waba_id', null)
                ->orWhere('waba_id', '')
                ->groupEnd();
        }

        $row = $builder->first();

        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByWabaNameLanguage(string $wabaId, string $name, string $language): ?array
    {
        $builder = $this->where('name', $name)->where('language', $language);
        if ($wabaId !== '' && $this->db->fieldExists('waba_id', $this->table)) {
            $builder->groupStart()
                ->where('waba_id', $wabaId)
                ->orWhere('waba_id', null)
                ->orWhere('waba_id', '')
                ->groupEnd();
        }

        $row = $builder->first();

        return is_array($row) ? $row : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getApproved(?string $wabaId = null): array
    {
        $builder = $this->whereIn('status', ['APPROVED', 'approved']);
        if ($wabaId !== null && $wabaId !== '' && $this->db->fieldExists('waba_id', $this->table)) {
            $builder->groupStart()
                ->where('waba_id', $wabaId)
                ->orWhere('waba_id', null)
                ->orWhere('waba_id', '')
                ->groupEnd();
        }

        return $builder->orderBy('name', 'ASC')->findAll();
    }

    /**
     * @return array{APPROVED: int, PENDING: int, REJECTED: int, DISABLED: int, OTHER: int}
     */
    public function countByStatus(?string $wabaId = null): array
    {
        $counts = [
            'APPROVED' => 0,
            'PENDING'  => 0,
            'REJECTED' => 0,
            'DISABLED' => 0,
            'OTHER'    => 0,
        ];

        $builder = $this->builder();
        if ($wabaId !== null && $wabaId !== '' && $this->db->fieldExists('waba_id', $this->table)) {
            // Strict: only this WABA (do not mix in legacy rows from a previous account).
            $builder->where('waba_id', $wabaId);
        }

        $rows = $builder->select('status')->get()->getResultArray();
        foreach ($rows as $row) {
            $status = strtoupper(trim((string) ($row['status'] ?? '')));
            if ($status === 'APPROVED') {
                $counts['APPROVED']++;
            } elseif (in_array($status, ['PENDING', 'IN_REVIEW', 'INREVIEW', 'IN_PROGRESS', 'INPROGRESS'], true)) {
                $counts['PENDING']++;
            } elseif ($status === 'REJECTED') {
                $counts['REJECTED']++;
            } elseif (in_array($status, ['DISABLED', 'DELETED', 'PAUSED'], true)) {
                $counts['DISABLED']++;
            } else {
                $counts['OTHER']++;
            }
        }

        return $counts;
    }

    /**
     * After a full provider sync, disable local templates for this WABA that the
     * provider did not return. Prevents stale rows (and bad waba_id backfills)
     * from appearing as active for the current account.
     *
     * @param list<string> $seenKeys Keys of "name|language" returned by the provider
     *
     * @return int Number of templates marked DISABLED
     */
    public function disableMissingFromSync(array $seenKeys, ?string $wabaId = null): int
    {
        $seen = [];
        foreach ($seenKeys as $key) {
            $seen[(string) $key] = true;
        }

        if ($seen === []) {
            return 0;
        }

        $builder = $this->builder();
        if ($wabaId !== null && $wabaId !== '' && $this->db->fieldExists('waba_id', $this->table)) {
            $builder->where('waba_id', $wabaId);
        }

        // Only touch rows that are still "active-ish" — leave already DISABLED alone
        // unless they belong to this WABA and were wrongly associated.
        $rows = $builder->get()->getResultArray();
        $disabled = 0;

        foreach ($rows as $row) {
            $status = strtoupper(trim((string) ($row['status'] ?? '')));
            $key = strtolower(trim((string) ($row['name'] ?? ''))) . '|' . strtolower(trim((string) ($row['language'] ?? '')));
            if ($key === '|' || isset($seen[$key])) {
                continue;
            }

            if ($status === 'DISABLED') {
                continue;
            }

            if ($this->update((int) $row['id'], ['status' => 'DISABLED'])) {
                $disabled++;
            }
        }

        return $disabled;
    }
}
