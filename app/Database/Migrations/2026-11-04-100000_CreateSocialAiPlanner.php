<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * AI Content Planner: a plan (category + goal for N days) and one item per day with its image prompt,
 * caption and generated image. Approving a plan turns the items into normal scheduled social posts.
 */
class CreateSocialAiPlanner extends Migration
{
    public function up(): void
    {
        if (! $this->db->tableExists('social_ai_plans')) {
            $this->forge->addField([
                'id'         => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
                'title'      => ['type' => 'VARCHAR', 'constraint' => 191],
                'category'   => ['type' => 'VARCHAR', 'constraint' => 191],
                'brief'      => ['type' => 'TEXT', 'null' => true],
                'days'       => ['type' => 'TINYINT', 'constraint' => 3, 'unsigned' => true, 'default' => 7],
                'start_date' => ['type' => 'DATE', 'null' => true],
                'post_time'  => ['type' => 'VARCHAR', 'constraint' => 5, 'default' => '10:00'],
                'account_ids' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
                'tone'       => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'friendly'],
                'language'   => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'English'],
                'status'     => ['type' => 'VARCHAR', 'constraint' => 16, 'default' => 'draft'],
                'created_by' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
                'created_at' => ['type' => 'DATETIME', 'null' => true],
                'updated_at' => ['type' => 'DATETIME', 'null' => true],
            ]);
            $this->forge->addKey('id', true);
            $this->forge->createTable('social_ai_plans', true, ['ENGINE' => 'InnoDB']);
        }

        if (! $this->db->tableExists('social_ai_items')) {
            $this->forge->addField([
                'id'           => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
                'plan_id'      => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
                'day_no'       => ['type' => 'TINYINT', 'constraint' => 3, 'unsigned' => true, 'default' => 1],
                'post_date'    => ['type' => 'DATE', 'null' => true],
                'topic'        => ['type' => 'VARCHAR', 'constraint' => 191],
                'image_prompt' => ['type' => 'TEXT', 'null' => true],
                'caption'      => ['type' => 'TEXT', 'null' => true],
                'hashtags'     => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
                'image_file'   => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
                'asset_id'     => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
                'post_id'      => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
                'status'       => ['type' => 'VARCHAR', 'constraint' => 16, 'default' => 'planned'],
                'error'        => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
                'created_at'   => ['type' => 'DATETIME', 'null' => true],
                'updated_at'   => ['type' => 'DATETIME', 'null' => true],
            ]);
            $this->forge->addKey('id', true);
            $this->forge->addKey('plan_id', false, false, 'idx_social_ai_items_plan');
            $this->forge->createTable('social_ai_items', true, ['ENGINE' => 'InnoDB']);
        }
    }

    public function down(): void
    {
        $this->forge->dropTable('social_ai_items', true);
        $this->forge->dropTable('social_ai_plans', true);
    }
}
