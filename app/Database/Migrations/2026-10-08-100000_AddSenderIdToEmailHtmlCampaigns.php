<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Per-campaign From address (email_senders.id). NULL = provider default sender.
 */
class AddSenderIdToEmailHtmlCampaigns extends Migration
{
    public function up(): void
    {
        if (! $this->db->tableExists('email_html_campaigns') || $this->db->fieldExists('sender_id', 'email_html_campaigns')) {
            return;
        }

        $this->forge->addColumn('email_html_campaigns', [
            'sender_id' => [
                'type'     => 'INT',
                'unsigned' => true,
                'null'     => true,
                'default'  => null,
                'after'    => 'cheerio_builder_id',
            ],
        ]);
    }

    public function down(): void
    {
        if ($this->db->fieldExists('sender_id', 'email_html_campaigns')) {
            $this->forge->dropColumn('email_html_campaigns', 'sender_id');
        }
    }
}
