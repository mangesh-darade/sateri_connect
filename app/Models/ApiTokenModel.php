<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

class ApiTokenModel extends Model
{
    protected $table            = 'api_tokens';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'user_id',
        'name',
        'token_hash',
        'abilities',
        'last_used_at',
        'expires_at',
    ];

    protected bool $allowEmptyInserts = false;
    protected bool $updateOnlyChanged = true;

    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    protected $validationRules = [
        'user_id'    => 'required|is_natural_no_zero',
        'name'       => 'required|max_length[150]',
        'token_hash' => 'required|max_length[255]',
    ];

    protected $validationMessages   = [];
    protected $skipValidation       = false;
    protected $cleanValidationRules = true;

    protected $beforeInsert = ['encodeAbilities'];
    protected $beforeUpdate = ['encodeAbilities'];
    protected $afterFind    = ['decodeAbilities'];

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function encodeAbilities(array $data): array
    {
        if (isset($data['data']['abilities']) && is_array($data['data']['abilities'])) {
            $data['data']['abilities'] = json_encode($data['data']['abilities']);
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function decodeAbilities(array $data): array
    {
        if (! isset($data['data'])) {
            return $data;
        }

        $decode = static function (array &$row): void {
            if (isset($row['abilities']) && is_string($row['abilities'])) {
                $decoded = json_decode($row['abilities'], true);
                $row['abilities'] = is_array($decoded) ? $decoded : null;
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
     * @param list<string> $abilities
     * @return array{plain_text: string, token: array<string, mixed>|null}
     */
    public function createToken(int $userId, string $name, array $abilities = ['*'], ?string $expiresAt = null): array
    {
        $plainText = bin2hex(random_bytes(32));
        $hash      = hash('sha256', $plainText);

        $id = $this->insert([
            'user_id'    => $userId,
            'name'       => $name,
            'token_hash' => $hash,
            'abilities'  => $abilities,
            'expires_at' => $expiresAt,
        ]);

        $token = is_numeric($id) ? $this->find((int) $id) : null;

        return [
            'plain_text' => $plainText,
            'token'      => is_array($token) ? $token : null,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findValidByPlainText(string $plainText): ?array
    {
        $hash = hash('sha256', $plainText);
        $row  = $this->where('token_hash', $hash)->first();

        if (! is_array($row)) {
            return null;
        }

        if (! empty($row['expires_at']) && strtotime((string) $row['expires_at']) < time()) {
            return null;
        }

        $this->update((int) $row['id'], ['last_used_at' => date('Y-m-d H:i:s')]);

        return $row;
    }
}
