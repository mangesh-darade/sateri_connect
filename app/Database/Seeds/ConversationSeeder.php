<?php

declare(strict_types=1);

namespace App\Database\Seeds;

use App\Libraries\InboxStatus;
use CodeIgniter\Database\Seeder;

/**
 * Dummy inbox conversations for Team Inbox 2.0 QA.
 * Safe to re-run: uses mobile prefix 91999900xxxx.
 */
class ConversationSeeder extends Seeder
{
    public function run(): void
    {
        if (! $this->db->tableExists('contacts') || ! $this->db->tableExists('conversations')) {
            return;
        }

        $now = date('Y-m-d H:i:s');
        $agentId = null;
        if ($this->db->tableExists('users')) {
            $agent = $this->db->table('users')
                ->select('id')
                ->where('status', 'active')
                ->orderBy('id', 'ASC')
                ->get()
                ->getRowArray();
            $agentId = isset($agent['id']) ? (int) $agent['id'] : null;
        }

        $samples = [
            [
                'name' => 'Demo Open Customer',
                'mobile' => '919999001001',
                'status' => InboxStatus::OPEN,
                'assigned_to' => $agentId,
                'unread' => 2,
                'within_24h' => true,
                'message' => 'Hi, I need help with my order.',
                'ctwa' => null,
                'frt_minutes' => 10,
            ],
            [
                'name' => 'Demo Pending Customer',
                'mobile' => '919999001002',
                'status' => InboxStatus::PENDING,
                'assigned_to' => $agentId,
                'unread' => 1,
                'within_24h' => true,
                'message' => 'Waiting for a reply…',
                'ctwa' => null,
                'frt_minutes' => -15,
            ],
            [
                'name' => 'Demo Chatbot Lead',
                'mobile' => '919999001003',
                'status' => InboxStatus::CHATBOT,
                'assigned_to' => null,
                'unread' => 0,
                'within_24h' => true,
                'message' => 'KEYWORD: PRICE',
                'ctwa' => null,
                'frt_minutes' => null,
            ],
            [
                'name' => 'Demo Intervened Chat',
                'mobile' => '919999001004',
                'status' => InboxStatus::INTERVENED,
                'assigned_to' => $agentId,
                'unread' => 0,
                'within_24h' => true,
                'message' => 'Agent took over from bot.',
                'ctwa' => null,
                'frt_minutes' => null,
                'intervened' => true,
            ],
            [
                'name' => 'Demo Resolved Ticket',
                'mobile' => '919999001005',
                'status' => InboxStatus::RESOLVED,
                'assigned_to' => $agentId,
                'unread' => 0,
                'within_24h' => false,
                'message' => 'Thanks, issue fixed!',
                'ctwa' => null,
                'frt_minutes' => null,
            ],
            [
                'name' => 'Demo CTWA Ad Lead',
                'mobile' => '919999001006',
                'status' => InboxStatus::OPEN,
                'assigned_to' => null,
                'unread' => 3,
                'within_24h' => true,
                'message' => 'Saw your Meta ad — interested.',
                'ctwa' => 'demo_ctwa_campaign_001',
                'frt_minutes' => -2,
            ],
            [
                'name' => 'Demo Expired Window',
                'mobile' => '919999001007',
                'status' => InboxStatus::OPEN,
                'assigned_to' => null,
                'unread' => 0,
                'within_24h' => false,
                'message' => 'Last reply was 2 days ago.',
                'ctwa' => null,
                'frt_minutes' => null,
            ],
        ];

        $hasChannel = $this->db->fieldExists('channel', 'conversations');
        $hasFrt = $this->db->fieldExists('frt_due_at', 'conversations');
        $hasCtwa = $this->db->fieldExists('ctwa_referral', 'conversations');
        $hasIntervened = $this->db->fieldExists('intervened_at', 'conversations');
        $hasContactChannel = $this->db->fieldExists('channel', 'contacts');
        $hasMessages = $this->db->tableExists('messages');

        foreach ($samples as $sample) {
            $existing = $this->db->table('contacts')->where('mobile', $sample['mobile'])->get()->getRowArray();
            if ($existing) {
                $contactId = (int) $existing['id'];
                $this->db->table('contacts')->where('id', $contactId)->update([
                    'name' => $sample['name'],
                    'status' => 'active',
                    'assigned_to' => $sample['assigned_to'],
                    'last_reply_at' => $sample['within_24h']
                        ? date('Y-m-d H:i:s', time() - 3600)
                        : date('Y-m-d H:i:s', time() - 172800),
                    'updated_at' => $now,
                ]);
            } else {
                $contactData = [
                    'name' => $sample['name'],
                    'mobile' => $sample['mobile'],
                    'status' => 'active',
                    'assigned_to' => $sample['assigned_to'],
                    'last_reply_at' => $sample['within_24h']
                        ? date('Y-m-d H:i:s', time() - 3600)
                        : date('Y-m-d H:i:s', time() - 172800),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
                if ($hasContactChannel) {
                    $contactData['channel'] = 'whatsapp';
                }
                $this->db->table('contacts')->insert($contactData);
                $contactId = (int) $this->db->insertID();
            }

            $messageId = null;
            if ($hasMessages) {
                $msgData = [
                    'contact_id' => $contactId,
                    'direction' => 'inbound',
                    'message_type' => 'text',
                    'content' => $sample['message'],
                    'status' => 'delivered',
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
                if ($this->db->fieldExists('channel', 'messages')) {
                    $msgData['channel'] = 'whatsapp';
                }
                $this->db->table('messages')->insert($msgData);
                $messageId = (int) $this->db->insertID();
            }

            $convWhere = ['contact_id' => $contactId];
            if ($hasChannel) {
                $convWhere['channel'] = 'whatsapp';
            }
            $conv = $this->db->table('conversations')->where($convWhere)->get()->getRowArray();

            $lastAt = $sample['within_24h']
                ? date('Y-m-d H:i:s', time() - 1800)
                : date('Y-m-d H:i:s', time() - 172800);

            $convData = [
                'contact_id' => $contactId,
                'last_message_id' => $messageId,
                'unread_count' => (int) $sample['unread'],
                'assigned_to' => $sample['assigned_to'],
                'status' => $sample['status'],
                'last_message_at' => $lastAt,
                'updated_at' => $now,
            ];
            if ($hasChannel) {
                $convData['channel'] = 'whatsapp';
            }
            if ($hasFrt) {
                if ($sample['frt_minutes'] === null) {
                    $convData['frt_due_at'] = null;
                } else {
                    $convData['frt_due_at'] = date('Y-m-d H:i:s', time() + ((int) $sample['frt_minutes'] * 60));
                }
            }
            if ($hasCtwa) {
                $convData['ctwa_referral'] = $sample['ctwa'];
            }
            if ($hasIntervened) {
                $convData['intervened_at'] = ! empty($sample['intervened']) ? $now : null;
            }

            if ($conv) {
                $this->db->table('conversations')->where('id', (int) $conv['id'])->update($convData);
            } else {
                $convData['created_at'] = $now;
                $this->db->table('conversations')->insert($convData);
            }
        }
    }
}
