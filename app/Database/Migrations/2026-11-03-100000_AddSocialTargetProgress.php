<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Live publishing progress (percent and a short stage label) shown on the social calendar.
 */
class AddSocialTargetProgress extends Migration
{
    public function up(): void
    {
        if (! $this->db->tableExists('social_post_targets')) {
            return;
        }
        $add = [];
        if (! $this->db->fieldExists('progress', 'social_post_targets')) {
            $add['progress'] = ['type' => 'TINYINT', 'constraint' => 3, 'unsigned' => true, 'default' => 0, 'after' => 'last_error'];
        }
        if (! $this->db->fieldExists('progress_stage', 'social_post_targets')) {
            $add['progress_stage'] = ['type' => 'VARCHAR', 'constraint' => 60, 'null' => true, 'after' => 'progress'];
        }
        if ($add !== []) {
            $this->forge->addColumn('social_post_targets', $add);
        }
    }

    public function down(): void
    {
        foreach (['progress_stage', 'progress'] as $col) {
            if ($this->db->tableExists('social_post_targets') && $this->db->fieldExists($col, 'social_post_targets')) {
                $this->forge->dropColumn('social_post_targets', $col);
            }
        }
    }
}
