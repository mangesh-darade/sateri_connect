<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Add channel / external_id fields for WhatsApp + Instagram + Messenger Team Inbox.
 */
class AddOmnichannelInboxFields extends Migration
{
    public function up(): void
    {
        // --- contacts ---
        if (! $this->db->fieldExists('channel', 'contacts')) {
            $this->forge->addColumn('contacts', [
                'channel' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 20,
                    'default'    => 'whatsapp',
                    'null'       => false,
                    'after'      => 'id',
                ],
            ]);
        }

        if (! $this->db->fieldExists('external_id', 'contacts')) {
            $this->forge->addColumn('contacts', [
                'external_id' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 191,
                    'null'       => true,
                    'after'      => 'channel',
                ],
            ]);
        }

        // Drop unique mobile so IG/Messenger contacts can exist without phone.
        try {
            $this->db->query('ALTER TABLE `contacts` DROP INDEX `mobile`');
        } catch (\Throwable $e) {
            // Index may already be gone or named differently.
        }

        $this->forge->modifyColumn('contacts', [
            'mobile' => [
                'type'       => 'VARCHAR',
                'constraint' => 30,
                'null'       => true,
            ],
        ]);

        $this->db->query(
            "UPDATE contacts SET channel = 'whatsapp' WHERE channel IS NULL OR channel = ''"
        );
        $this->db->query(
            "UPDATE contacts SET external_id = mobile WHERE (external_id IS NULL OR external_id = '') AND mobile IS NOT NULL AND mobile != ''"
        );

        try {
            $this->db->query('ALTER TABLE `contacts` ADD UNIQUE KEY `channel_external_id` (`channel`, `external_id`)');
        } catch (\Throwable $e) {
            // Already exists
        }
        try {
            $this->db->query('ALTER TABLE `contacts` ADD KEY `channel` (`channel`)');
        } catch (\Throwable $e) {
            // Already exists
        }
        try {
            $this->db->query('ALTER TABLE `contacts` ADD KEY `mobile` (`mobile`)');
        } catch (\Throwable $e) {
            // Already exists
        }

        // --- conversations ---
        if (! $this->db->fieldExists('channel', 'conversations')) {
            $this->forge->addColumn('conversations', [
                'channel' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 20,
                    'default'    => 'whatsapp',
                    'null'       => false,
                    'after'      => 'contact_id',
                ],
            ]);
        }

        if (! $this->db->fieldExists('page_id', 'conversations')) {
            $this->forge->addColumn('conversations', [
                'page_id' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 64,
                    'null'       => true,
                    'after'      => 'channel',
                ],
            ]);
        }

        try {
            $this->db->query('ALTER TABLE `conversations` DROP INDEX `contact_id`');
        } catch (\Throwable $e) {
            // Already dropped
        }

        $this->db->query(
            "UPDATE conversations SET channel = 'whatsapp' WHERE channel IS NULL OR channel = ''"
        );

        try {
            $this->db->query('ALTER TABLE `conversations` ADD UNIQUE KEY `contact_channel` (`contact_id`, `channel`)');
        } catch (\Throwable $e) {
            // Already exists
        }
        try {
            $this->db->query('ALTER TABLE `conversations` ADD KEY `channel` (`channel`)');
        } catch (\Throwable $e) {
            // Already exists
        }

        // --- messages ---
        if (! $this->db->fieldExists('channel', 'messages')) {
            $this->forge->addColumn('messages', [
                'channel' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 20,
                    'default'    => 'whatsapp',
                    'null'       => false,
                    'after'      => 'conversation_id',
                ],
            ]);
        }

        if (! $this->db->fieldExists('external_message_id', 'messages')) {
            $this->forge->addColumn('messages', [
                'external_message_id' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 191,
                    'null'       => true,
                    'after'      => 'wamid',
                ],
            ]);
        }

        $this->db->query(
            "UPDATE messages SET channel = 'whatsapp' WHERE channel IS NULL OR channel = ''"
        );
        $this->db->query(
            "UPDATE messages SET external_message_id = COALESCE(NULLIF(wamid, ''), NULLIF(wa_message_id, ''))
             WHERE (external_message_id IS NULL OR external_message_id = '')"
        );

        try {
            $this->db->query('ALTER TABLE `messages` ADD KEY `channel` (`channel`)');
        } catch (\Throwable $e) {
            // Already exists
        }
        try {
            $this->db->query('ALTER TABLE `messages` ADD KEY `external_message_id` (`external_message_id`)');
        } catch (\Throwable $e) {
            // Already exists
        }
    }

    public function down(): void
    {
        try {
            $this->db->query('ALTER TABLE `messages` DROP INDEX `external_message_id`');
        } catch (\Throwable $e) {
        }
        try {
            $this->db->query('ALTER TABLE `messages` DROP INDEX `channel`');
        } catch (\Throwable $e) {
        }

        if ($this->db->fieldExists('external_message_id', 'messages')) {
            $this->forge->dropColumn('messages', 'external_message_id');
        }
        if ($this->db->fieldExists('channel', 'messages')) {
            $this->forge->dropColumn('messages', 'channel');
        }

        try {
            $this->db->query('ALTER TABLE `conversations` DROP INDEX `contact_channel`');
        } catch (\Throwable $e) {
        }
        try {
            $this->db->query('ALTER TABLE `conversations` DROP INDEX `channel`');
        } catch (\Throwable $e) {
        }

        if ($this->db->fieldExists('page_id', 'conversations')) {
            $this->forge->dropColumn('conversations', 'page_id');
        }
        if ($this->db->fieldExists('channel', 'conversations')) {
            $this->forge->dropColumn('conversations', 'channel');
        }

        try {
            $this->db->query('ALTER TABLE `conversations` ADD UNIQUE KEY `contact_id` (`contact_id`)');
        } catch (\Throwable $e) {
        }

        try {
            $this->db->query('ALTER TABLE `contacts` DROP INDEX `channel_external_id`');
        } catch (\Throwable $e) {
        }
        try {
            $this->db->query('ALTER TABLE `contacts` DROP INDEX `channel`');
        } catch (\Throwable $e) {
        }

        if ($this->db->fieldExists('external_id', 'contacts')) {
            $this->forge->dropColumn('contacts', 'external_id');
        }
        if ($this->db->fieldExists('channel', 'contacts')) {
            $this->forge->dropColumn('contacts', 'channel');
        }

        try {
            $this->db->query('ALTER TABLE `contacts` ADD UNIQUE KEY `mobile` (`mobile`)');
        } catch (\Throwable $e) {
        }
    }
}
