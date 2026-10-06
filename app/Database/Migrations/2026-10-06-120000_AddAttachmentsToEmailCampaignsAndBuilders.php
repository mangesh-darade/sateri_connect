<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddAttachmentsToEmailCampaignsAndBuilders extends Migration
{
    public function up(): void
    {
        if ($this->db->tableExists('email_builders')) {
            $fields = [];
            if (! $this->db->fieldExists('attachment_path', 'email_builders')) {
                $fields['attachment_path'] = [
                    'type'       => 'VARCHAR',
                    'constraint' => 255,
                    'null'       => true,
                    'after'      => 'html_content',
                ];
            }
            if (! $this->db->fieldExists('attachment_name', 'email_builders')) {
                $fields['attachment_name'] = [
                    'type'       => 'VARCHAR',
                    'constraint' => 191,
                    'null'       => true,
                    'after'      => 'attachment_path',
                ];
            }
            if ($fields !== []) {
                $this->forge->addColumn('email_builders', $fields);
            }
        }

        if ($this->db->tableExists('email_html_campaigns')) {
            $fields = [];
            if (! $this->db->fieldExists('attachment_path', 'email_html_campaigns')) {
                $fields['attachment_path'] = [
                    'type'       => 'VARCHAR',
                    'constraint' => 255,
                    'null'       => true,
                    'after'      => 'html_content',
                ];
            }
            if (! $this->db->fieldExists('attachment_name', 'email_html_campaigns')) {
                $fields['attachment_name'] = [
                    'type'       => 'VARCHAR',
                    'constraint' => 191,
                    'null'       => true,
                    'after'      => 'attachment_path',
                ];
            }
            if ($fields !== []) {
                $this->forge->addColumn('email_html_campaigns', $fields);
            }
        }
    }

    public function down(): void
    {
        if ($this->db->tableExists('email_builders')) {
            if ($this->db->fieldExists('attachment_name', 'email_builders')) {
                $this->forge->dropColumn('email_builders', 'attachment_name');
            }
            if ($this->db->fieldExists('attachment_path', 'email_builders')) {
                $this->forge->dropColumn('email_builders', 'attachment_path');
            }
        }

        if ($this->db->tableExists('email_html_campaigns')) {
            if ($this->db->fieldExists('attachment_name', 'email_html_campaigns')) {
                $this->forge->dropColumn('email_html_campaigns', 'attachment_name');
            }
            if ($this->db->fieldExists('attachment_path', 'email_html_campaigns')) {
                $this->forge->dropColumn('email_html_campaigns', 'attachment_path');
            }
        }
    }
}
