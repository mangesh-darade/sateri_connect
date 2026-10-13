<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\SelfHealingSchema;
use CodeIgniter\Model;

class SmartFormModel extends Model
{
    use SelfHealingSchema;

    protected $table            = 'smart_forms';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $protectFields    = true;
    protected $allowedFields    = [
        'name',
        'slug',
        'status',
        'success_message',
        'fields',
        'created_by',
    ];

    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    /** @var array<string, string> */
    protected array $schemaColumns = [
        'id'              => 'INT UNSIGNED NOT NULL AUTO_INCREMENT',
        'name'            => 'VARCHAR(120) NOT NULL',
        'slug'            => 'VARCHAR(80) NOT NULL',
        'status'          => "VARCHAR(20) NOT NULL DEFAULT 'active'",
        'success_message' => "VARCHAR(255) NOT NULL DEFAULT 'Thank you. We have received your details.'",
        'fields'          => 'TEXT NULL',
        'created_by'      => 'INT UNSIGNED NULL',
        'created_at'      => 'DATETIME NULL',
        'updated_at'      => 'DATETIME NULL',
    ];

    /** @var array<string, string> */
    protected array $schemaIndexes = [
        'PRIMARY' => 'PRIMARY KEY (`id`)',
        'slug'    => 'UNIQUE KEY `slug` (`slug`)',
    ];

    protected string $schemaTableOptions = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci';

    /**
     * @param array<string, mixed> $row
     *
     * @return list<array{key:string,label:string,type:string,required:bool}>
     */
    public function fieldsOf(array $row): array
    {
        $raw = $row['fields'] ?? '[]';
        if (is_array($raw)) {
            $decoded = $raw;
        } else {
            $decoded = json_decode((string) $raw, true);
        }

        return is_array($decoded) ? array_values(array_filter($decoded, 'is_array')) : [];
    }

    public function findBySlug(string $slug): ?array
    {
        $row = $this->where('slug', $slug)->first();

        return is_array($row) ? $row : null;
    }
}
