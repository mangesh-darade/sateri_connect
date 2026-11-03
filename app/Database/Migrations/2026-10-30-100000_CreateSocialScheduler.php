<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Social scheduler core tables (see docs/SOCIAL_SCHEDULER.md): connected accounts, Google Drive
 * sources, scanned media, posts and one publish target per account.
 */
class CreateSocialScheduler extends Migration
{
    public function up(): void
    {
        $this->create('social_accounts', [
            'id'               => ['type' => 'INT', 'constraint' => 10, 'unsigned' => true, 'auto_increment' => true],
            'platform'         => ['type' => 'VARCHAR', 'constraint' => 20],
            'external_id'      => ['type' => 'VARCHAR', 'constraint' => 64],
            'name'             => ['type' => 'VARCHAR', 'constraint' => 191],
            'access_token'     => ['type' => 'TEXT', 'null' => true],
            'refresh_token'    => ['type' => 'TEXT', 'null' => true],
            'token_expires_at' => ['type' => 'DATETIME', 'null' => true],
            'scopes'           => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'status'           => ['type' => 'VARCHAR', 'constraint' => 16, 'default' => 'active'],
            'created_by'       => ['type' => 'INT', 'constraint' => 10, 'unsigned' => true, 'null' => true],
            'created_at'       => ['type' => 'DATETIME', 'null' => true],
            'updated_at'       => ['type' => 'DATETIME', 'null' => true],
        ], function (): void {
            $this->forge->addUniqueKey(['platform', 'external_id'], 'uniq_social_accounts_platform_ext');
        });

        $this->create('social_drive_sources', [
            'id'              => ['type' => 'INT', 'constraint' => 10, 'unsigned' => true, 'auto_increment' => true],
            'folder_id'       => ['type' => 'VARCHAR', 'constraint' => 128],
            'folder_name'     => ['type' => 'VARCHAR', 'constraint' => 191, 'null' => true],
            'mode'            => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'service_account'],
            'last_scanned_at' => ['type' => 'DATETIME', 'null' => true],
            'status'          => ['type' => 'VARCHAR', 'constraint' => 16, 'default' => 'active'],
            'created_at'      => ['type' => 'DATETIME', 'null' => true],
            'updated_at'      => ['type' => 'DATETIME', 'null' => true],
        ], function (): void {
            $this->forge->addUniqueKey('folder_id', 'uniq_social_drive_sources_folder');
        });

        $this->create('social_media_assets', [
            'id'            => ['type' => 'INT', 'constraint' => 10, 'unsigned' => true, 'auto_increment' => true],
            'source_id'     => ['type' => 'INT', 'constraint' => 10, 'unsigned' => true, 'null' => true],
            'drive_file_id' => ['type' => 'VARCHAR', 'constraint' => 128],
            'filename'      => ['type' => 'VARCHAR', 'constraint' => 255],
            'mime'          => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'size_bytes'    => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true, 'default' => 0],
            'duration_sec'  => ['type' => 'DECIMAL', 'constraint' => '8,2', 'null' => true],
            'width'         => ['type' => 'INT', 'constraint' => 10, 'unsigned' => true, 'null' => true],
            'height'        => ['type' => 'INT', 'constraint' => 10, 'unsigned' => true, 'null' => true],
            'checksum'      => ['type' => 'CHAR', 'constraint' => 64, 'null' => true],
            'validation'    => ['type' => 'TEXT', 'null' => true],
            'status'        => ['type' => 'VARCHAR', 'constraint' => 16, 'default' => 'new'],
            'created_at'    => ['type' => 'DATETIME', 'null' => true],
            'updated_at'    => ['type' => 'DATETIME', 'null' => true],
        ], function (): void {
            $this->forge->addUniqueKey('drive_file_id', 'uniq_social_media_assets_file');
            $this->forge->addKey('checksum', false, false, 'idx_social_media_assets_checksum');
        });

        $this->create('social_posts', [
            'id'          => ['type' => 'INT', 'constraint' => 10, 'unsigned' => true, 'auto_increment' => true],
            'asset_id'    => ['type' => 'INT', 'constraint' => 10, 'unsigned' => true, 'null' => true],
            'type'        => ['type' => 'VARCHAR', 'constraint' => 16, 'default' => 'post'],
            'title'       => ['type' => 'VARCHAR', 'constraint' => 191, 'null' => true],
            'caption'     => ['type' => 'TEXT', 'null' => true],
            'hashtags'    => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'status'      => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'draft'],
            'created_by'  => ['type' => 'INT', 'constraint' => 10, 'unsigned' => true, 'null' => true],
            'approved_by' => ['type' => 'INT', 'constraint' => 10, 'unsigned' => true, 'null' => true],
            'created_at'  => ['type' => 'DATETIME', 'null' => true],
            'updated_at'  => ['type' => 'DATETIME', 'null' => true],
        ], function (): void {
            $this->forge->addKey('status', false, false, 'idx_social_posts_status');
        });

        $this->create('social_post_targets', [
            'id'               => ['type' => 'INT', 'constraint' => 10, 'unsigned' => true, 'auto_increment' => true],
            'post_id'          => ['type' => 'INT', 'constraint' => 10, 'unsigned' => true],
            'account_id'       => ['type' => 'INT', 'constraint' => 10, 'unsigned' => true],
            'caption_override' => ['type' => 'TEXT', 'null' => true],
            'scheduled_at'     => ['type' => 'DATETIME', 'null' => true],
            'status'           => ['type' => 'VARCHAR', 'constraint' => 16, 'default' => 'scheduled'],
            'external_id'      => ['type' => 'VARCHAR', 'constraint' => 128, 'null' => true],
            'external_url'     => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'attempts'         => ['type' => 'TINYINT', 'constraint' => 3, 'unsigned' => true, 'default' => 0],
            'last_error'       => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'published_at'     => ['type' => 'DATETIME', 'null' => true],
            'created_at'       => ['type' => 'DATETIME', 'null' => true],
            'updated_at'       => ['type' => 'DATETIME', 'null' => true],
        ], function (): void {
            $this->forge->addKey(['status', 'scheduled_at'], false, false, 'idx_social_targets_due');
            $this->forge->addKey('post_id', false, false, 'idx_social_targets_post');
            $this->forge->addUniqueKey(['post_id', 'account_id'], 'uniq_social_targets_post_account');
        });
    }

    public function down(): void
    {
        foreach (['social_post_targets', 'social_posts', 'social_media_assets', 'social_drive_sources', 'social_accounts'] as $table) {
            $this->forge->dropTable($table, true);
        }
    }

    /**
     * @param array<string, array<string, mixed>> $fields
     */
    private function create(string $table, array $fields, callable $keys): void
    {
        if ($this->db->tableExists($table)) {
            return;
        }
        $this->forge->addField($fields);
        $this->forge->addKey('id', true);
        $keys();
        $this->forge->createTable($table, true, ['ENGINE' => 'InnoDB', 'DEFAULT CHARSET' => 'utf8mb4']);
    }
}
