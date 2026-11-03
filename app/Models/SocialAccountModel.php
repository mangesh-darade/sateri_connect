<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\SelfHealingSchema;
use CodeIgniter\Model;

class SocialAccountModel extends Model
{
    use SelfHealingSchema;

    protected $table            = 'social_accounts';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'platform',
        'external_id',
        'name',
        'access_token',
        'refresh_token',
        'token_expires_at',
        'scopes',
        'status',
        'created_by',
    ];

    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    protected $validationRules = [
        'platform'    => 'required|in_list[facebook,instagram,youtube,linkedin,whatsapp]',
        'external_id' => 'required|max_length[64]',
        'name'        => 'required|max_length[191]',
    ];
}
