<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\SelfHealingSchema;
use CodeIgniter\Model;

/**
 * Role <-> permission pivot (composite key role_id + permission_id).
 */
class RolePermissionModel extends Model
{
    use SelfHealingSchema;

    protected $table            = 'role_permissions';
    protected $primaryKey       = 'role_id';
    protected $useAutoIncrement = false;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'role_id',
        'permission_id',
    ];

    protected $useTimestamps = false;
}
