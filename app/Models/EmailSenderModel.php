<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

class EmailSenderModel extends Model
{
    protected $table            = 'email_senders';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $protectFields    = true;
    protected $allowedFields    = [
        'type',
        'name',
        'email',
        'domain',
        'cheerio_id',
        'status',
        'dns_records',
        'notes',
        'is_default',
    ];
    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    protected $beforeInsert = ['encodeDns'];
    protected $beforeUpdate = ['encodeDns'];
    protected $afterFind    = ['decodeDns'];

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function encodeDns(array $data): array
    {
        if (isset($data['data']['dns_records']) && is_array($data['data']['dns_records'])) {
            $data['data']['dns_records'] = json_encode($data['data']['dns_records']);
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function decodeDns(array $data): array
    {
        if (! isset($data['data'])) {
            return $data;
        }

        $decode = static function (array &$row): void {
            if (isset($row['dns_records']) && is_string($row['dns_records'])) {
                $decoded = json_decode($row['dns_records'], true);
                $row['dns_records'] = is_array($decoded) ? $decoded : [];
            }
        };

        if (isset($data['data'][0]) && is_array($data['data'][0])) {
            foreach ($data['data'] as &$row) {
                $decode($row);
            }
            unset($row);
        } elseif (is_array($data['data'])) {
            $decode($data['data']);
        }

        return $data;
    }
}
