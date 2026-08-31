<?php

declare(strict_types=1);

namespace App\Commands;

use App\Libraries\SettingsService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use PDO;

class DataHealthCheck extends BaseCommand
{
    protected $group       = 'WhatsApp';
    protected $name        = 'whatsapp:data-health';
    protected $description = 'Check webhooks, chat, notifications, reports data health.';

    public function run(array $params)
    {
        $db       = db_connect();
        $settings = new SettingsService();

        CLI::write('=== PROVIDER ===');
        CLI::write('provider=' . $settings->getWhatsAppProvider());
        CLI::write('public_base=' . $settings->get('webhook_public_base', ''));

        CLI::write('=== WEBHOOK LOGS (last 12) ===');
        $n    = 0;
        $rows = $db->query('SELECT id, event_type, signature_valid, processed, LEFT(COALESCE(error_message,""),70) e, created_at FROM webhook_logs ORDER BY id DESC LIMIT 12')->getResultArray();
        foreach ($rows as $r) {
            $n++;
            CLI::write("#{$r['id']} {$r['created_at']} {$r['event_type']} sig={$r['signature_valid']} proc={$r['processed']} {$r['e']}");
        }
        if ($n === 0) {
            CLI::write('(none)');
        }

        CLI::write('=== MESSAGES last 15 ===');
        $rows = $db->query('SELECT id, contact_id, direction, message_type, LEFT(content,45) c, status, created_at FROM messages ORDER BY id DESC LIMIT 15')->getResultArray();
        foreach ($rows as $r) {
            CLI::write("#{$r['id']} c={$r['contact_id']} {$r['direction']} {$r['message_type']} [{$r['c']}] {$r['status']} {$r['created_at']}");
        }

        CLI::write('=== INBOUND count today ===');
        $in  = (int) ($db->query("SELECT COUNT(*) c FROM messages WHERE direction='inbound' AND DATE(created_at)=CURDATE()")->getRow()->c ?? 0);
        $out = (int) ($db->query("SELECT COUNT(*) c FROM messages WHERE direction='outbound' AND DATE(created_at)=CURDATE()")->getRow()->c ?? 0);
        CLI::write("inbound_today={$in} outbound_today={$out}");

        CLI::write('=== CONVERSATIONS ===');
        $rows = $db->query('SELECT id, contact_id, status, last_message_at, unread_count FROM conversations ORDER BY updated_at DESC LIMIT 8')->getResultArray();
        foreach ($rows as $r) {
            CLI::write("#{$r['id']} contact={$r['contact_id']} {$r['status']} unread={$r['unread_count']} last={$r['last_message_at']}");
        }

        CLI::write('=== NOTIFICATIONS tables ===');
        foreach (['notifications', 'notification_logs', 'user_notifications', 'activity_logs'] as $t) {
            try {
                $c = (int) $db->query("SELECT COUNT(*) c FROM `{$t}`")->getRow()->c;
                CLI::write("{$t}={$c}");
            } catch (\Throwable $e) {
                CLI::write("{$t}=MISSING");
            }
        }

        try {
            CLI::write('=== notifications last 8 ===');
            $cols    = [];
            $colRows = $db->query('SHOW COLUMNS FROM notifications')->getResultArray();
            foreach ($colRows as $c) {
                $cols[] = $c['Field'];
            }
            CLI::write('cols=' . implode(',', $cols));
            $sel = 'id';
            foreach (['user_id', 'type', 'title', 'message', 'body', 'is_read', 'read_at', 'created_at'] as $f) {
                if (in_array($f, $cols, true)) {
                    $sel .= ', ' . $f;
                }
            }
            $rows = $db->query("SELECT {$sel} FROM notifications ORDER BY id DESC LIMIT 8")->getResultArray();
            foreach ($rows as $r) {
                CLI::write(json_encode($r, JSON_UNESCAPED_UNICODE));
            }
        } catch (\Throwable $e) {
            CLI::write('notifications err: ' . $e->getMessage());
        }

        CLI::write('=== REPORTS / STATS raw ===');
        try {
            $camp = (int) $db->query('SELECT COUNT(*) c FROM campaigns')->getRow()->c;
            $qRows = $db->query('SELECT status, COUNT(*) c FROM message_queue GROUP BY status')->getResultArray();
            $q     = array_column($qRows, 'c', 'status');
            CLI::write('campaigns=' . $camp);
            CLI::write('queue=' . json_encode($q));
        } catch (\Throwable $e) {
            CLI::write('queue: ' . $e->getMessage());
            try {
                $tables = $db->query("SHOW TABLES LIKE '%queue%'")->getResultArray();
                foreach ($tables as $t) {
                    CLI::write('table ' . json_encode($t));
                }
            } catch (\Throwable $e2) {
            }
        }

        try {
            $byStatusRows = $db->query('SELECT status, COUNT(*) c FROM messages GROUP BY status')->getResultArray();
            $byStatus     = array_column($byStatusRows, 'c', 'status');
            CLI::write('messages_by_status=' . json_encode($byStatus));
            $byDirRows = $db->query('SELECT direction, COUNT(*) c FROM messages GROUP BY direction')->getResultArray();
            $byDir     = array_column($byDirRows, 'c', 'direction');
            CLI::write('messages_by_direction=' . json_encode($byDir));
        } catch (\Throwable $e) {
            CLI::write($e->getMessage());
        }

        CLI::write('=== META WABA override ===');
        try {
            $api  = new \App\Libraries\MetaCloudAPI($settings);
            $waba = $settings->getMetaConfig()['waba_id'] ?? '';
            if ($waba) {
                $subs = $api->request('GET', $waba . '/subscribed_apps');
                CLI::write(json_encode($subs, JSON_UNESCAPED_SLASHES));
            }
        } catch (\Throwable $e) {
            CLI::error($e->getMessage());
        }
    }
}
