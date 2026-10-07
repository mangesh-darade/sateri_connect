<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Libraries\SchemaRepairService;

/**
 * Model-owned schema: on first use per database the missing tables / columns / indexes are created
 * (see SchemaRepairService). Silent for users — changes and problems go to the log only.
 *
 * The table definition comes from app/Database/Schema/tables.php (`php spark schema:snapshot`).
 * A model whose table is not in the snapshot can define it inline:
 *
 *   protected array $schemaColumns = ['id' => 'INT UNSIGNED NOT NULL AUTO_INCREMENT', ...];
 *   protected array $schemaIndexes = ['PRIMARY' => 'PRIMARY KEY (`id`)', ...];
 *   protected string $schemaTableOptions = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci';
 */
trait SelfHealingSchema
{
    protected function initialize(): void
    {
        parent::initialize();
        $this->ensureSchema();
    }

    /**
     * @return array<string, list<string>> table => changes made
     */
    public function ensureSchema(bool $force = false): array
    {
        $extra = [];
        if (! empty($this->schemaColumns)) {
            $extra[$this->table] = [
                'columns' => $this->schemaColumns,
                'indexes' => $this->schemaIndexes ?? [],
                'options' => $this->schemaTableOptions ?? '',
            ];
        }

        try {
            return (new SchemaRepairService())->repairDatabase($this->db, $extra, $force);
        } catch (\Throwable $e) {
            log_message('error', 'Schema auto-repair skipped for {table}: {msg}', ['table' => $this->table, 'msg' => $e->getMessage()]);

            return [];
        }
    }
}
