<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddScheduledAtToEmailHtmlCampaigns extends Migration
{
    public function up(): void
    {
        if (! $this->db->tableExists('email_html_campaigns')) {
            return;
        }

        if (! $this->db->fieldExists('scheduled_at', 'email_html_campaigns')) {
            $this->forge->addColumn('email_html_campaigns', [
                'scheduled_at' => [
                    'type' => 'DATETIME',
                    'null' => true,
                    'after' => 'sent_at',
                ],
            ]);
        }
    }

    public function down(): void
    {
        if ($this->db->tableExists('email_html_campaigns') && $this->db->fieldExists('scheduled_at', 'email_html_campaigns')) {
            $this->forge->dropColumn('email_html_campaigns', 'scheduled_at');
        }
    }
}
