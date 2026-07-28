<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Email Manager tables: builders, drips, HTML campaigns, senders/domains, verifier + send logs.
 */
class CreateEmailManagerTables extends Migration
{
    public function up(): void
    {
        // ---- email_builders ----
        $this->forge->addField([
            'id' => [
                'type'           => 'INT',
                'constraint'     => 11,
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'name' => [
                'type'       => 'VARCHAR',
                'constraint' => 191,
            ],
            'subject' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
            ],
            'html_content' => [
                'type' => 'LONGTEXT',
                'null' => true,
            ],
            'cheerio_builder_id' => [
                'type'       => 'VARCHAR',
                'constraint' => 191,
                'null'       => true,
            ],
            'status' => [
                'type'       => 'ENUM',
                'constraint' => ['draft', 'active', 'archived'],
                'default'    => 'draft',
            ],
            'created_by' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
                'null'       => true,
            ],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('status');
        $this->forge->addKey('cheerio_builder_id');
        $this->forge->createTable('email_builders', true);

        // ---- email_drips ----
        $this->forge->addField([
            'id' => [
                'type'           => 'INT',
                'constraint'     => 11,
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'name' => [
                'type'       => 'VARCHAR',
                'constraint' => 191,
            ],
            'description' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'trigger_type' => [
                'type'       => 'ENUM',
                'constraint' => ['manual', 'on_subscribe', 'on_tag'],
                'default'    => 'manual',
            ],
            'trigger_value' => [
                'type'       => 'VARCHAR',
                'constraint' => 191,
                'null'       => true,
            ],
            'status' => [
                'type'       => 'ENUM',
                'constraint' => ['draft', 'active', 'paused', 'archived'],
                'default'    => 'draft',
            ],
            'created_by' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
                'null'       => true,
            ],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('status');
        $this->forge->createTable('email_drips', true);

        // ---- email_drip_steps ----
        $this->forge->addField([
            'id' => [
                'type'           => 'INT',
                'constraint'     => 11,
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'drip_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
            ],
            'step_order' => [
                'type'       => 'INT',
                'constraint' => 11,
                'default'    => 1,
            ],
            'delay_hours' => [
                'type'       => 'INT',
                'constraint' => 11,
                'default'    => 0,
            ],
            'subject' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
            ],
            'html_content' => [
                'type' => 'LONGTEXT',
                'null' => true,
            ],
            'builder_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
                'null'       => true,
            ],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['drip_id', 'step_order']);
        $this->forge->addForeignKey('drip_id', 'email_drips', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('builder_id', 'email_builders', 'id', 'SET NULL', 'CASCADE');
        $this->forge->createTable('email_drip_steps', true);

        // ---- email_html_campaigns ----
        $this->forge->addField([
            'id' => [
                'type'           => 'INT',
                'constraint'     => 11,
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'name' => [
                'type'       => 'VARCHAR',
                'constraint' => 191,
            ],
            'subject' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
            ],
            'html_content' => [
                'type' => 'LONGTEXT',
                'null' => true,
            ],
            'builder_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
                'null'       => true,
            ],
            'cheerio_builder_id' => [
                'type'       => 'VARCHAR',
                'constraint' => 191,
                'null'       => true,
            ],
            'mode' => [
                'type'       => 'ENUM',
                'constraint' => ['recipients', 'label'],
                'default'    => 'recipients',
            ],
            'label_name' => [
                'type'       => 'VARCHAR',
                'constraint' => 191,
                'null'       => true,
            ],
            'recipients_json' => [
                'type' => 'LONGTEXT',
                'null' => true,
            ],
            'status' => [
                'type'       => 'ENUM',
                'constraint' => ['draft', 'queued', 'sending', 'sent', 'failed', 'cancelled'],
                'default'    => 'draft',
            ],
            'sent_count' => [
                'type'       => 'INT',
                'constraint' => 11,
                'default'    => 0,
            ],
            'failed_count' => [
                'type'       => 'INT',
                'constraint' => 11,
                'default'    => 0,
            ],
            'last_error' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'sent_at' => ['type' => 'DATETIME', 'null' => true],
            'created_by' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
                'null'       => true,
            ],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('status');
        $this->forge->addForeignKey('builder_id', 'email_builders', 'id', 'SET NULL', 'CASCADE');
        $this->forge->createTable('email_html_campaigns', true);

        // ---- email_senders (Sender ID + Domain ID) ----
        $this->forge->addField([
            'id' => [
                'type'           => 'INT',
                'constraint'     => 11,
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'type' => [
                'type'       => 'ENUM',
                'constraint' => ['sender', 'domain'],
                'default'    => 'sender',
            ],
            'name' => [
                'type'       => 'VARCHAR',
                'constraint' => 191,
            ],
            'email' => [
                'type'       => 'VARCHAR',
                'constraint' => 191,
                'null'       => true,
            ],
            'domain' => [
                'type'       => 'VARCHAR',
                'constraint' => 191,
                'null'       => true,
            ],
            'cheerio_id' => [
                'type'       => 'VARCHAR',
                'constraint' => 191,
                'null'       => true,
                'comment'    => 'External Cheerio Sender/Domain ID',
            ],
            'status' => [
                'type'       => 'ENUM',
                'constraint' => ['pending', 'verified', 'failed', 'disabled'],
                'default'    => 'pending',
            ],
            'dns_records' => [
                'type' => 'TEXT',
                'null' => true,
                'comment' => 'JSON SPF/DKIM/DMARC notes',
            ],
            'notes' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'is_default' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 0,
            ],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('type');
        $this->forge->addKey('status');
        $this->forge->createTable('email_senders', true);

        // ---- email_verifications ----
        $this->forge->addField([
            'id' => [
                'type'           => 'INT',
                'constraint'     => 11,
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'email' => [
                'type'       => 'VARCHAR',
                'constraint' => 191,
            ],
            'status' => [
                'type'       => 'ENUM',
                'constraint' => ['valid', 'invalid', 'risky', 'unknown'],
                'default'    => 'unknown',
            ],
            'syntax_ok' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 0,
            ],
            'mx_ok' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 0,
            ],
            'disposable' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 0,
            ],
            'checks_json' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'verified_at' => ['type' => 'DATETIME', 'null' => true],
            'created_at'  => ['type' => 'DATETIME', 'null' => true],
            'updated_at'  => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('email');
        $this->forge->addKey('status');
        $this->forge->createTable('email_verifications', true);

        // ---- email_logs (analytics) ----
        $this->forge->addField([
            'id' => [
                'type'           => 'INT',
                'constraint'     => 11,
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'kind' => [
                'type'       => 'ENUM',
                'constraint' => ['single', 'bulk', 'campaign', 'drip', 'test'],
                'default'    => 'single',
            ],
            'provider' => [
                'type'       => 'VARCHAR',
                'constraint' => 50,
                'null'       => true,
            ],
            'to_email' => [
                'type'       => 'VARCHAR',
                'constraint' => 191,
                'null'       => true,
            ],
            'subject' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
            ],
            'status' => [
                'type'       => 'ENUM',
                'constraint' => ['sent', 'failed', 'queued'],
                'default'    => 'sent',
            ],
            'builder_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
                'null'       => true,
            ],
            'html_campaign_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
                'null'       => true,
            ],
            'drip_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
                'null'       => true,
            ],
            'message' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'meta_json' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'created_by' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
                'null'       => true,
            ],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('status');
        $this->forge->addKey('kind');
        $this->forge->addKey('created_at');
        $this->forge->addKey('provider');
        $this->forge->createTable('email_logs', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('email_logs', true);
        $this->forge->dropTable('email_verifications', true);
        $this->forge->dropTable('email_senders', true);
        $this->forge->dropTable('email_html_campaigns', true);
        $this->forge->dropTable('email_drip_steps', true);
        $this->forge->dropTable('email_drips', true);
        $this->forge->dropTable('email_builders', true);
    }
}
