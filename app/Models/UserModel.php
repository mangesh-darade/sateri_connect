<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

class UserModel extends Model
{
    protected $table            = 'users';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = true;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'role_id',
        'name',
        'email',
        'password',
        'email_verification_token',
        'email_verification_sent_at',
        'email_verified_at',
        'phone',
        'avatar',
        'status',
        'last_login',
        'remember_token',
    ];

    protected bool $allowEmptyInserts = false;
    protected bool $updateOnlyChanged = true;

    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';
    protected $deletedField  = 'deleted_at';

    protected $validationRules = [
        'role_id'  => 'required|is_natural_no_zero',
        'name'     => 'required|min_length[2]|max_length[150]',
        'email'    => 'required|valid_email|max_length[191]|is_unique[users.email,id,{id}]',
        'password' => 'permit_empty|min_length[8]|max_length[255]',
        'phone'    => 'permit_empty|max_length[30]',
        'status'   => 'permit_empty|in_list[active,inactive]',
    ];

    protected $validationMessages = [];
    protected $skipValidation     = false;
    protected $cleanValidationRules = true;

    protected $beforeInsert = ['hashPassword'];
    protected $beforeUpdate = ['hashPassword'];

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function hashPassword(array $data): array
    {
        if (! isset($data['data']['password']) || $data['data']['password'] === '') {
            unset($data['data']['password']);

            return $data;
        }

        if (password_get_info($data['data']['password'])['algo'] === null) {
            $data['data']['password'] = password_hash($data['data']['password'], PASSWORD_DEFAULT);
        }

        return $data;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByEmail(string $email): ?array
    {
        $row = $this->where('email', $email)->first();

        return is_array($row) ? $row : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getActiveUsers(): array
    {
        return $this->where('status', 'active')->orderBy('name', 'ASC')->findAll();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByVerificationToken(string $token): ?array
    {
        $row = $this->where('email_verification_token', $token)->first();

        return is_array($row) ? $row : null;
    }
}
