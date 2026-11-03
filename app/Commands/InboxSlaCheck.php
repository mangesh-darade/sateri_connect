<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\EachTenant;
use App\Libraries\InboxRules;
use App\Libraries\InboxStatus;
use App\Models\NotificationModel;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Every 5 minutes: tell the assigned agent (or the team when nobody owns it) about open chats whose
 * first-response target has passed. One alert per chat per wait; a reply clears the timer.
 *
 *   php spark inbox:sla-check              every active client
 *   php spark inbox:sla-check -tenant acme one client
 */
class InboxSlaCheck extends BaseCommand
{
    use EachTenant;

    protected $group       = 'WhatsApp';
    protected $name        = 'inbox:sla-check';
    protected $description = 'Alert on chats that waited longer than the first-response target.';
    protected $usage       = 'inbox:sla-check [-tenant <key>]';
    protected $options     = ['-tenant' => 'Only this client key (default: all active clients)'];

    public function run(array $params)
    {
        $this->eachTenant(static function (string $key): void {
            $sla = (new InboxRules())->slaMinutes();
            if ($sla <= 0) {
                return;
            }

            $db  = db_connect();
            $now = date('Y-m-d H:i:s');
            // Older than a day is stale, not a live breach.
            $rows = $db->table('conversations cv')
                ->select('cv.id, cv.contact_id, cv.assigned_to, cv.frt_due_at, c.name, c.mobile')
                ->join('contacts c', 'c.id = cv.contact_id')
                ->where('cv.frt_due_at <', $now)
                ->where('cv.frt_due_at >', date('Y-m-d H:i:s', time() - 86400))
                ->whereIn('cv.status', [InboxStatus::OPEN, InboxStatus::PENDING])
                ->limit(200)
                ->get()
                ->getResultArray();

            $cache = service('cache');
            $tenant = md5((string) $db->getDatabase());
            $sent  = 0;
            foreach ($rows as $r) {
                $marker = 'sla_alert_' . $tenant . '_' . $r['id'] . '_' . strtotime((string) $r['frt_due_at']);
                if ($cache->get($marker) !== null) {
                    continue;
                }
                $cache->save($marker, 1, 86400);

                $name    = trim((string) ($r['name'] ?? '')) ?: (string) ($r['mobile'] ?? 'A customer');
                $waiting = max(1, (int) round((time() - strtotime((string) $r['frt_due_at'])) / 60) + $sla);
                model(NotificationModel::class)->notifyChatUsers(
                    'Reply overdue',
                    $name . ' has waited ' . $waiting . ' min for a reply (target ' . $sla . ' min).',
                    site_url('chat?contact_id=' . (int) $r['contact_id']),
                    ! empty($r['assigned_to']) ? (int) $r['assigned_to'] : null,
                    'warning'
                );
                $sent++;
            }

            if ($sent > 0) {
                CLI::write(($key !== '' ? $key . ': ' : '') . $sent . ' overdue alert(s)', 'yellow');
            }
        });

        return EXIT_SUCCESS;
    }
}
