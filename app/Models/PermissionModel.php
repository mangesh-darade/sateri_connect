<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

class PermissionModel extends Model
{
    protected $table            = 'permissions';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'name',
        'slug',
        'module',
        'description',
    ];

    protected bool $allowEmptyInserts = false;
    protected bool $updateOnlyChanged = true;

    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    protected $validationRules = [
        'name'   => 'required|min_length[2]|max_length[150]',
        'slug'   => 'required|min_length[2]|max_length[150]|is_unique[permissions.slug,id,{id}]',
        'module' => 'required|max_length[100]',
    ];

    protected $validationMessages   = [];
    protected $skipValidation       = false;
    protected $cleanValidationRules = true;

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    public function getGroupedByModule(): array
    {
        $permissions = $this->orderBy('module', 'ASC')->orderBy('name', 'ASC')->findAll();
        $grouped     = [];

        foreach ($permissions as $permission) {
            $module = $permission['module'] ?? 'general';
            $grouped[$module][] = $permission;
        }

        return $grouped;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findBySlug(string $slug): ?array
    {
        $row = $this->where('slug', $slug)->first();

        return is_array($row) ? $row : null;
    }
}
