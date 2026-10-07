<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\SelfHealingSchema;
use CodeIgniter\Model;

class EmailSenderModel extends Model
{
    use SelfHealingSchema;

    public const PURPOSE_TRANSACTIONAL = 'transactional';
    public const PURPOSE_MARKETING     = 'marketing';

    protected $table            = 'email_senders';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $protectFields    = true;
    protected $allowedFields    = [
        'type',
        'purpose',
        'provider',
        'name',
        'email',
        'domain',
        'mail_from_domain',
        'cheerio_id',
        'status',
        'dns_records',
        'notes',
        'is_default',
        'last_checked_at',
    ];
    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    public static function normalizePurpose(?string $purpose): string
    {
        return $purpose === self::PURPOSE_MARKETING ? self::PURPOSE_MARKETING : self::PURPOSE_TRANSACTIONAL;
    }

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
