<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

class PasswordResetModel extends Model
{
    protected $table            = 'password_resets';
    protected $primaryKey       = 'email';
    protected $useAutoIncrement = false;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'email',
        'token',
        'created_at',
    ];

    protected bool $allowEmptyInserts = false;
    protected bool $updateOnlyChanged = true;

    protected $useTimestamps = false;

    protected $validationRules = [
        'email' => 'required|valid_email|max_length[191]',
        'token' => 'required|max_length[255]',
    ];

    protected $validationMessages   = [];
    protected $skipValidation       = false;
    protected $cleanValidationRules = true;

    public function createToken(string $email): string
    {
        $token = bin2hex(random_bytes(32));

        $this->db->table($this->table)->where('email', $email)->delete();

        $this->db->table($this->table)->insert([
            'email'      => $email,
            'token'      => hash('sha256', $token),
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        return $token;
    }

    public function verifyToken(string $email, string $token, int $expiryMinutes = 60): bool
    {
        $row = $this->db->table($this->table)
            ->where('email', $email)
            ->where('token', hash('sha256', $token))
            ->get()
            ->getRowArray();

        if ($row === null) {
            return false;
        }

        $createdAt = strtotime((string) $row['created_at']);

        if ($createdAt === false) {
            return false;
        }

        return (time() - $createdAt) <= ($expiryMinutes * 60);
    }

    public function deleteByEmail(string $email): bool
    {
        return $this->db->table($this->table)->where('email', $email)->delete();
    }
}
