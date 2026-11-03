<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Per-target settings (JSON). WhatsApp broadcast targets keep their template and audience here.
 */
class AddSocialTargetOptions extends Migration
{
    public function up(): void
    {
        if ($this->db->tableExists('social_post_targets') && ! $this->db->fieldExists('options', 'social_post_targets')) {
            $this->forge->addColumn('social_post_targets', [
                'options' => ['type' => 'TEXT', 'null' => true, 'after' => 'caption_override'],
            ]);
        }
    }

    public function down(): void
    {
        if ($this->db->tableExists('social_post_targets') && $this->db->fieldExists('options', 'social_post_targets')) {
            $this->forge->dropColumn('social_post_targets', 'options');
        }
    }
}
