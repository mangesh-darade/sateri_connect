<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

class SettingModel extends Model
{
    protected $table            = 'settings';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'key',
        'value',
        'group',
        'is_encrypted',
    ];

    protected bool $allowEmptyInserts = false;
    protected bool $updateOnlyChanged = true;

    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    protected $validationRules = [
        'key'   => 'required|max_length[191]|is_unique[settings.key,id,{id}]',
        'group' => 'permit_empty|max_length[100]',
    ];

    protected $validationMessages   = [];
    protected $skipValidation       = false;
    protected $cleanValidationRules = true;

    /**
     * Fetch a setting value by key (named to avoid clashing with Model::get()).
     */
    public function getValue(string $key, mixed $default = null): mixed
    {
        $row = $this->db->table($this->table)
            ->where('key', $key)
            ->get()
            ->getRowArray();

        if ($row === null) {
            return $default;
        }

        return $row['value'] ?? $default;
    }

    /**
     * Persist a setting value by key (named to avoid clashing with Model::set()).
     */
    public function setValue(string $key, mixed $value, ?string $group = null, bool $isEncrypted = false): bool
    {
        $existing = $this->db->table($this->table)
            ->where('key', $key)
            ->get()
            ->getRowArray();

        $data = [
            'value'        => is_scalar($value) || $value === null ? (string) ($value ?? '') : json_encode($value),
            'updated_at'   => date('Y-m-d H:i:s'),
            'is_encrypted' => $isEncrypted ? 1 : 0,
        ];

        if ($group !== null) {
            $data['group'] = $group;
        }

        if ($existing !== null) {
            return $this->db->table($this->table)
                ->where('key', $key)
                ->update($data);
        }

        $data['key']        = $key;
        $data['group']      = $group ?? 'general';
        $data['created_at'] = date('Y-m-d H:i:s');

        return $this->db->table($this->table)->insert($data);
    }

    /**
     * @return array<string, string|null>
     */
    public function getGroup(string $group): array
    {
        $rows = $this->db->table($this->table)
            ->where('group', $group)
            ->get()
            ->getResultArray();

        $settings = [];
        foreach ($rows as $row) {
            $settings[$row['key']] = $row['value'];
        }

        return $settings;
    }

    /**
     * @return array<string, string|null>
     */
    public function getAllKeyed(): array
    {
        $rows     = $this->db->table($this->table)->get()->getResultArray();
        $settings = [];

        foreach ($rows as $row) {
            $settings[$row['key']] = $row['value'];
        }

        return $settings;
    }
}
