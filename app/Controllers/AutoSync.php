<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Libraries\AutoSyncService;
use CodeIgniter\HTTP\ResponseInterface;

class AutoSync extends BaseController
{
    /**
     * Runs the due work that cron normally does (scheduled social posts, scheduled campaigns, the message queue)
     * while someone has the app open. The browser calls this about once a minute; a cache lock and a 50 second
     * throttle per client database keep several tabs or users from running it twice.
     * A real cron job still works and is the better choice on a live server.
     */
    public function tick(): ResponseInterface
    {
        session_write_close();
        ignore_user_abort(true);
        set_time_limit(180);

        $out   = ['ran' => false, 'social' => 0, 'campaigns' => 0, 'queue_sent' => 0, 'errors' => []];
        $cache = cache();
        $key   = 'scheduler_tick_' . md5((string) db_connect()->getDatabase());
        $lock  = $key . '_lock';

        if ($cache->get($lock) || time() - (int) ($cache->get($key) ?? 0) < 50) {
            return $this->jsonResponse(true, $out, 'Ran recently.');
        }
        $cache->save($lock, 1, 180);
        $out['ran'] = true;

        try {
            try {
                // Publishing downloads media and calls the platform APIs (can take minutes), so it runs in its own
                // background process and never holds up this request or the user's next page.
                $out['social'] = $this->startSocialPublisher() ? 1 : 0;
            } catch (\Throwable $e) {
                log_message('error', 'AutoSync::tick social ' . $e::class . ': ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine());
                $out['errors'][] = 'Social posts could not be checked.';
            }

            try {
                (new \App\Libraries\CartRecoveryService())->remindDue(20);
            } catch (\Throwable $e) {
                log_message('error', 'AutoSync::tick carts ' . $e::class);
            }

            try {
                $out['campaigns'] = (new \App\Libraries\CampaignService())->processScheduled();
            } catch (\Throwable $e) {
                log_message('error', 'AutoSync::tick campaigns ' . $e::class);
                $out['errors'][] = 'Scheduled campaigns could not be checked.';
            }

            try {
                $stats = (new \App\Libraries\QueueService())->processBatch(50);
                $out['queue_sent'] = (int) ($stats['sent'] ?? 0);
            } catch (\Throwable $e) {
                log_message('error', 'AutoSync::tick queue ' . $e::class);
                $out['errors'][] = 'The message queue could not be processed.';
            }
        } finally {
            $cache->save($key, time(), 3600);
            $cache->delete($lock);
        }

        return $this->jsonResponse($out['errors'] === [], $out, $out['errors'] === [] ? 'OK' : implode(' ', $out['errors']));
    }

    /**
     * Starts `spark social:publish` detached when a post is due. One indexed query; nothing is cached.
     */
    private function startSocialPublisher(): bool
    {
        $due = db_connect()->table('social_post_targets')
            ->select('id')
            ->where('status', 'scheduled')
            ->where('scheduled_at <=', date('Y-m-d H:i:s'))
            ->limit(1)
            ->get()
            ->getRowArray();
        if ($due === null) {
            return false;
        }

        // A detached background process can fail silently on shared hosts (no CLI `php` in PATH, open_basedir, disabled exec),
        // and we could not tell. So only local Windows (WAMP) spawns; everywhere else the publisher runs inside this request
        // (a few posts at a time). This request is the background AJAX tick, so nobody waits on it.
        $windows  = DIRECTORY_SEPARATOR === '\\';
        $disabled = array_map('trim', explode(',', strtolower((string) ini_get('disable_functions'))));
        if (! $windows || ! function_exists('popen') || in_array('popen', $disabled, true)) {
            set_time_limit(170);
            (new \App\Libraries\SocialPublishService())->publishDue(3);

            return true;
        }

        $php = PHP_BINARY;
        if (! preg_match('/php(\.exe)?$/i', $php)) {
            // Under Apache PHP_BINARY is the web server; use the CLI binary next to the loaded PHP.
            $exe     = $windows ? 'php.exe' : 'php';
            $php     = 'php';
            // WAMP: extension_dir is <php folder>/ext/, so the CLI binary sits one level up.
            foreach ([dirname(rtrim((string) ini_get('extension_dir'), '\\/')), rtrim(PHP_BINDIR, '\\/')] as $dir) {
                // open_basedir (Plesk) makes is_file() warn for paths outside the site, so ask quietly.
                if (@is_file($dir . DIRECTORY_SEPARATOR . $exe)) {
                    $php = $dir . DIRECTORY_SEPARATOR . $exe;
                    break;
                }
            }
        }
        $tenant = strtolower(trim((string) \App\Libraries\TenantContext::get()));
        $args   = escapeshellarg(ROOTPATH . 'spark') . ' social:publish' . ($tenant !== '' ? ' -tenant ' . escapeshellarg($tenant) : '');

        if (DIRECTORY_SEPARATOR === '\\') {
            pclose(popen('start /B "" ' . escapeshellarg($php) . ' ' . $args . ' > NUL 2>&1', 'r'));
        } else {
            exec(escapeshellarg($php) . ' ' . $args . ' > /dev/null 2>&1 &');
        }

        return true;
    }

    public function run(): ResponseInterface
    {
        $job        = (string) $this->request->getPost('job');
        $permission = AutoSyncService::permissionFor($job);
        if ($permission === null) {
            return $this->jsonResponse(false, null, 'Unknown sync job.', [], 422);
        }
        if ($denied = $this->requirePermission($permission)) {
            return $denied;
        }
        $force = (string) $this->request->getPost('force') === '1';

        // Release the session lock so parallel jobs and the user's next page are never blocked.
        session_write_close();
        ignore_user_abort(true);
        set_time_limit(300);

        $result = (new AutoSyncService())->runJob($job, $force);

        return $this->jsonResponse($result['ok'], $result, $result['message']);
    }
}
