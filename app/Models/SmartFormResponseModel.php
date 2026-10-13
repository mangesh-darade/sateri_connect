<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\SelfHealingSchema;
use CodeIgniter\Model;

class SmartFormResponseModel extends Model
{
    use SelfHealingSchema;

    protected $table            = 'smart_form_responses';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $protectFields    = true;
    protected $allowedFields    = [
        'form_id',
        'contact_id',
        'payload',
        'created_at',
    ];

    protected $useTimestamps = false;

    /** @var array<string, string> */
    protected array $schemaColumns = [
        'id'         => 'INT UNSIGNED NOT NULL AUTO_INCREMENT',
        'form_id'    => 'INT UNSIGNED NOT NULL',
        'contact_id' => 'INT UNSIGNED NULL',
        'payload'    => 'TEXT NULL',
        'created_at' => 'DATETIME NULL',
    ];

    /** @var array<string, string> */
    protected array $schemaIndexes = [
        'PRIMARY'    => 'PRIMARY KEY (`id`)',
        'form_id'    => 'KEY `form_id` (`form_id`)',
        'contact_id' => 'KEY `contact_id` (`contact_id`)',
    ];

    protected string $schemaTableOptions = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci';
}
