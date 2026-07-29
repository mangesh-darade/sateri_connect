<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Cheerio-style inbox statuses + FRT / CTWA / intervene metadata.
 */
class ExpandConversationInboxStatuses extends Migration
{
    public function up(): void
    {
        if (! $this->db->tableExists('conversations')) {
            return;
        }

        // ENUM → VARCHAR so we can store open/pending/resolved/chatbot/intervened (+ legacy closed).
        if ($this->db->fieldExists('status', 'conversations')) {
            $this->forge->modifyColumn('conversations', [
                'status' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 32,
                    'default'    => 'open',
                    'null'       => false,
                ],
            ]);
        }

        $fields = [];
        if (! $this->db->fieldExists('frt_due_at', 'conversations')) {
            $fields['frt_due_at'] = [
                'type' => 'DATETIME',
                'null' => true,
                'after' => 'last_message_at',
            ];
        }
        if (! $this->db->fieldExists('intervened_at', 'conversations')) {
            $fields['intervened_at'] = [
                'type' => 'DATETIME',
                'null' => true,
                'after' => 'frt_due_at',
            ];
        }
        if (! $this->db->fieldExists('ctwa_referral', 'conversations')) {
            $fields['ctwa_referral'] = [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
                'after'      => 'intervened_at',
            ];
        }

        if ($fields !== []) {
            $this->forge->addColumn('conversations', $fields);
        }

        // Normalize legacy closed → resolved (keep closed readable via alias in app layer).
        $this->db->table('conversations')
            ->where('status', 'closed')
            ->update(['status' => 'resolved']);
    }

    public function down(): void
    {
        if (! $this->db->tableExists('conversations')) {
            return;
        }

        $this->db->table('conversations')
            ->whereIn('status', ['resolved', 'pending', 'chatbot', 'intervened'])
            ->update(['status' => 'closed']);

        $this->db->table('conversations')
            ->whereNotIn('status', ['open', 'closed'])
            ->update(['status' => 'open']);

        if ($this->db->fieldExists('ctwa_referral', 'conversations')) {
            $this->forge->dropColumn('conversations', 'ctwa_referral');
        }
        if ($this->db->fieldExists('intervened_at', 'conversations')) {
            $this->forge->dropColumn('conversations', 'intervened_at');
        }
        if ($this->db->fieldExists('frt_due_at', 'conversations')) {
            $this->forge->dropColumn('conversations', 'frt_due_at');
        }

        if ($this->db->fieldExists('status', 'conversations')) {
            $this->forge->modifyColumn('conversations', [
                'status' => [
                    'type'       => 'ENUM',
                    'constraint' => ['open', 'closed'],
                    'default'    => 'open',
                    'null'       => false,
                ],
            ]);
        }
    }
}
