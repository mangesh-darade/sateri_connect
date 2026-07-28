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
        $pdo = new PDO('mysql:host=localhost;dbname=apiwa;charset=utf8mb4', 'root', '');
        $settings = new SettingsService();

        CLI::write('=== PROVIDER ===');
        CLI::write('provider=' . $settings->getWhatsAppProvider());
        CLI::write('public_base=' . $settings->get('webhook_public_base', ''));

        CLI::write('=== WEBHOOK LOGS (last 12) ===');
        $n = 0;
        foreach ($pdo->query('SELECT id, event_type, signature_valid, processed, LEFT(COALESCE(error_message,""),70) e, created_at FROM webhook_logs ORDER BY id DESC LIMIT 12') as $r) {
            $n++;
            CLI::write("#{$r['id']} {$r['created_at']} {$r['event_type']} sig={$r['signature_valid']} proc={$r['processed']} {$r['e']}");
        }
        if ($n === 0) {
            CLI::write('(none)');
        }

        CLI::write('=== MESSAGES last 15 ===');
        foreach ($pdo->query('SELECT id, contact_id, direction, message_type, LEFT(content,45) c, status, created_at FROM messages ORDER BY id DESC LIMIT 15') as $r) {
            CLI::write("#{$r['id']} c={$r['contact_id']} {$r['direction']} {$r['message_type']} [{$r['c']}] {$r['status']} {$r['created_at']}");
        }

        CLI::write('=== INBOUND count today ===');
        $in = $pdo->query("SELECT COUNT(*) c FROM messages WHERE direction='inbound' AND DATE(created_at)=CURDATE()")->fetch()['c'];
        $out = $pdo->query("SELECT COUNT(*) c FROM messages WHERE direction='outbound' AND DATE(created_at)=CURDATE()")->fetch()['c'];
        CLI::write("inbound_today={$in} outbound_today={$out}");

        CLI::write('=== CONVERSATIONS ===');
        foreach ($pdo->query('SELECT id, contact_id, status, last_message_at, unread_count FROM conversations ORDER BY updated_at DESC LIMIT 8') as $r) {
            CLI::write("#{$r['id']} contact={$r['contact_id']} {$r['status']} unread={$r['unread_count']} last={$r['last_message_at']}");
        }

        CLI::write('=== NOTIFICATIONS tables ===');
        foreach (['notifications', 'notification_logs', 'user_notifications', 'activity_logs'] as $t) {
            try {
                $c = $pdo->query("SELECT COUNT(*) c FROM `{$t}`")->fetch()['c'];
                CLI::write("{$t}={$c}");
            } catch (\Throwable $e) {
                CLI::write("{$t}=MISSING");
            }
        }

        try {
            CLI::write('=== notifications last 8 ===');
            $cols = [];
            foreach ($pdo->query('SHOW COLUMNS FROM notifications') as $c) {
                $cols[] = $c['Field'];
            }
            CLI::write('cols=' . implode(',', $cols));
            $sel = 'id';
            foreach (['user_id', 'type', 'title', 'message', 'body', 'is_read', 'read_at', 'created_at'] as $f) {
                if (in_array($f, $cols, true)) {
                    $sel .= ', ' . $f;
                }
            }
            foreach ($pdo->query("SELECT {$sel} FROM notifications ORDER BY id DESC LIMIT 8") as $r) {
                CLI::write(json_encode($r, JSON_UNESCAPED_UNICODE));
            }
        } catch (\Throwable $e) {
            CLI::write('notifications err: ' . $e->getMessage());
        }

        CLI::write('=== REPORTS / STATS raw ===');
        try {
            $camp = $pdo->query('SELECT COUNT(*) c FROM campaigns')->fetch()['c'];
            $q = $pdo->query("SELECT status, COUNT(*) c FROM message_queue GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
            CLI::write('campaigns=' . $camp);
            CLI::write('queue=' . json_encode($q));
        } catch (\Throwable $e) {
            CLI::write('queue: ' . $e->getMessage());
            try {
                foreach ($pdo->query("SHOW TABLES LIKE '%queue%'") as $t) {
                    CLI::write('table ' . json_encode($t));
                }
            } catch (\Throwable $e2) {
            }
        }

        try {
            $byStatus = $pdo->query('SELECT status, COUNT(*) c FROM messages GROUP BY status')->fetchAll(PDO::FETCH_KEY_PAIR);
            CLI::write('messages_by_status=' . json_encode($byStatus));
            $byDir = $pdo->query('SELECT direction, COUNT(*) c FROM messages GROUP BY direction')->fetchAll(PDO::FETCH_KEY_PAIR);
            CLI::write('messages_by_direction=' . json_encode($byDir));
        } catch (\Throwable $e) {
            CLI::write($e->getMessage());
        }

        CLI::write('=== META WABA override ===');
        try {
            $api = new \App\Libraries\MetaCloudAPI($settings);
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
