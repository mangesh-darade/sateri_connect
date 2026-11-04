<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Libraries\CartRecoveryService;
use App\Libraries\MasterTenantRepository;
use App\Libraries\QueueService;
use App\Libraries\TenantConnection;
use App\Libraries\TenantContext;
use CodeIgniter\HTTP\ResponseInterface;
use Throwable;

/**
 * Scheduler tick for the platform panel: while a platform admin has any platform page open, the browser calls
 * this about once a minute and the due cart reminders (and the message queue they land in) run for each client
 * of that admin. Client workspaces have their own tick (AutoSync); a real cron job is still the better choice.
 */
class PlatformSync extends BaseController
{
    public function tick(): ResponseInterface
    {
        session_write_close();
        ignore_user_abort(true);
        set_time_limit(180);

        $adminId = (int) session('platform_admin_id');
        $cache   = cache();
        $key     = 'platform_tick_' . $adminId;
        if ($cache->get($key . '_lock') || time() - (int) ($cache->get($key) ?? 0) < 50) {
            return $this->jsonResponse(true, ['ran' => false], 'Ran recently.');
        }
        $cache->save($key . '_lock', 1, 180);

        $out = ['ran' => true, 'clients' => 0, 'reminders' => 0, 'sent' => 0];
        try {
            $repo = new MasterTenantRepository();
            foreach ($repo->listActiveTenants($adminId) as $t) {
                $tenant = strtolower(trim((string) ($t['key'] ?? '')));
                if ($tenant === '') {
                    continue;
                }
                try {
                    if (! (new TenantConnection())->apply($tenant, 'platform')) {
                        continue;
                    }
                    TenantContext::set($tenant, 'platform');
                    $n = (new CartRecoveryService())->remindDue(20);
                    $out['clients']++;
                    $out['reminders'] += $n;
                    if ($n > 0) {
                        $stats = (new QueueService())->processBatch(50);
                        $out['sent'] += (int) ($stats['sent'] ?? 0);
                    }
                } catch (Throwable $e) {
                    log_message('error', 'PlatformSync::tick ' . $tenant . ' ' . $e::class . ': ' . $e->getMessage());
                }
            }
        } finally {
            $cache->save($key, time(), 3600);
            $cache->delete($key . '_lock');
        }

        return $this->jsonResponse(true, $out, 'OK');
    }
}
