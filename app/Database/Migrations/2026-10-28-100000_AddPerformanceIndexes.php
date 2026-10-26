<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use App\Libraries\PerformanceIndexes;
use CodeIgniter\Database\Migration;

/**
 * Composite indexes for the hottest read paths (dashboard / platform aggregates, notification badge).
 * The list lives in PerformanceIndexes, which the screens also call to self-heal tenants that
 * have not migrated yet.
 */
class AddPerformanceIndexes extends Migration
{
    public function up(): void
    {
        PerformanceIndexes::ensure($this->db);
    }

    public function down(): void
    {
        PerformanceIndexes::drop($this->db);
    }
}
