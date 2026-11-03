<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Ads (Click-to-WhatsApp): campaigns (AdCampaignModel), their creatives (AdCreativeModel)
 * and the daily numbers synced from Meta (AdMetricDailyModel). Money is stored in minor units.
 */
class CreateAds extends Migration
{
    public function up(): void
    {
        if (! $this->db->tableExists('ad_campaigns')) {
            $this->forge->addField([
                'id'                 => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true, 'auto_increment' => true],
                'name'               => ['type' => 'VARCHAR', 'constraint' => 150],
                'objective'          => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'messages'],
                'website_url'        => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
                'brief'              => ['type' => 'MEDIUMTEXT', 'null' => true],
                'audience'           => ['type' => 'TEXT', 'null' => true],
                'special_category'   => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true],
                'daily_budget_minor' => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true, 'default' => 0],
                'currency'           => ['type' => 'VARCHAR', 'constraint' => 3, 'default' => 'INR'],
                'starts_at'          => ['type' => 'DATETIME', 'null' => true],
                'ends_at'            => ['type' => 'DATETIME', 'null' => true],
                'status'             => ['type' => 'VARCHAR', 'constraint' => 12, 'default' => 'draft'],
                'is_mock'            => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
                'ad_account_id'      => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true],
                'meta_campaign_id'   => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true],
                'meta_adset_id'      => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true],
                'meta_ad_id'         => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true],
                'source_target_id'   => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
                'created_by'         => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
                'approved_by'        => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
                'last_error'         => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
                'created_at'         => ['type' => 'DATETIME', 'null' => true],
                'updated_at'         => ['type' => 'DATETIME', 'null' => true],
            ]);
            $this->forge->addKey('id', true);
            $this->forge->addKey('status', false, false, 'idx_ad_campaigns_status');
            $this->forge->addKey('meta_ad_id', false, false, 'idx_ad_campaigns_meta_ad');
            $this->forge->createTable('ad_campaigns', true, ['ENGINE' => 'InnoDB']);
        }

        if (! $this->db->tableExists('ad_creatives')) {
            $this->forge->addField([
                'id'               => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true, 'auto_increment' => true],
                'campaign_id'      => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true],
                'headline'         => ['type' => 'VARCHAR', 'constraint' => 100],
                'primary_text'     => ['type' => 'VARCHAR', 'constraint' => 500],
                'cta'              => ['type' => 'VARCHAR', 'constraint' => 30, 'default' => 'WHATSAPP_MESSAGE'],
                'media_asset_id'   => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
                'post_external_id' => ['type' => 'VARCHAR', 'constraint' => 80, 'null' => true],
                'is_selected'      => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
                'ai_generated'     => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
                'created_at'       => ['type' => 'DATETIME', 'null' => true],
                'updated_at'       => ['type' => 'DATETIME', 'null' => true],
            ]);
            $this->forge->addKey('id', true);
            $this->forge->addKey('campaign_id', false, false, 'idx_ad_creatives_campaign');
            $this->forge->createTable('ad_creatives', true, ['ENGINE' => 'InnoDB']);
        }

        if (! $this->db->tableExists('ad_metrics_daily')) {
            $this->forge->addField([
                'id'                          => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true, 'auto_increment' => true],
                'campaign_id'                 => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true],
                'metric_date'                 => ['type' => 'DATE'],
                'spend_minor'                 => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true, 'default' => 0],
                'impressions'                 => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'default' => 0],
                'reach'                       => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'default' => 0],
                'clicks'                      => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'default' => 0],
                'conversations'               => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'default' => 0],
                'cost_per_conversation_minor' => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true, 'default' => 0],
                'created_at'                  => ['type' => 'DATETIME', 'null' => true],
                'updated_at'                  => ['type' => 'DATETIME', 'null' => true],
            ]);
            $this->forge->addKey('id', true);
            $this->forge->addUniqueKey(['campaign_id', 'metric_date'], 'uniq_ad_metrics_campaign_date');
            $this->forge->createTable('ad_metrics_daily', true, ['ENGINE' => 'InnoDB']);
        }
    }

    public function down(): void
    {
        $this->forge->dropTable('ad_metrics_daily', true);
        $this->forge->dropTable('ad_creatives', true);
        $this->forge->dropTable('ad_campaigns', true);
    }
}
