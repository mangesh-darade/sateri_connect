<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

class EmailVerificationModel extends Model
{
    protected $table            = 'email_verifications';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $protectFields    = true;
    protected $allowedFields    = [
        'email',
        'status',
        'syntax_ok',
        'mx_ok',
        'disposable',
        'checks_json',
        'verified_at',
    ];
    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    protected $beforeInsert = ['encodeChecks'];
    protected $beforeUpdate = ['encodeChecks'];
    protected $afterFind    = ['decodeChecks'];

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function encodeChecks(array $data): array
    {
        if (isset($data['data']['checks_json']) && is_array($data['data']['checks_json'])) {
            $data['data']['checks_json'] = json_encode($data['data']['checks_json']);
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function decodeChecks(array $data): array
    {
        if (! isset($data['data'])) {
            return $data;
        }

        $decode = static function (array &$row): void {
            if (isset($row['checks_json']) && is_string($row['checks_json'])) {
                $decoded = json_decode($row['checks_json'], true);
                $row['checks'] = is_array($decoded) ? $decoded : [];
            }
        };

        if ($data['data'] === [] || $data['data'] === null) {
            return $data;
        }

        $isCollection = array_is_list($data['data'])
            && (isset($data['data'][0]) ? is_array($data['data'][0]) : false);

        if ($isCollection) {
            foreach ($data['data'] as &$row) {
                if (is_array($row)) {
                    $decode($row);
                }
            }
            unset($row);
        } elseif (is_array($data['data'])) {
            $decode($data['data']);
        }

        return $data;
    }
}
