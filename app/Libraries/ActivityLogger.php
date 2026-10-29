<?php

namespace App\Libraries;

use App\Models\ActivityLogModel;

/**
 * Writes structured activity / audit log entries.
 */
class ActivityLogger
{
    protected ActivityLogModel $logs;

    public function __construct(?ActivityLogModel $logs = null)
    {
        $this->logs = $logs ?? model(ActivityLogModel::class);
    }

    /**
     * Keep only the last ActivityLogModel::RETENTION_DAYS days. Runs at most once a day per client on the
     * first write after the day changes, so it works without the logs:cleanup cron.
     */
    protected function pruneOncePerDay(): void
    {
        try {
            $key   = 'activity_prune_' . preg_replace('/[^A-Za-z0-9_]/', '_', (string) (\App\Libraries\TenantContext::get() ?? 'default'));
            $cache = cache();
            if ($cache->get($key) !== null) {
                return;
            }
            $cache->save($key, 1, 86400);
            $this->logs->pruneExpired();
        } catch (\Throwable) {
            // retention must never break the action being logged
        }
    }

    /**
     * Log an application activity event.
     *
     * @param array<string, mixed> $metadata
     */
    public function log(string $action, string $module, string $description, array $metadata = [], ?int $userId = null): bool
    {
        $request = service('request');
        $session = session();

        $resolvedUserId = $userId ?? ($session->get('user_id') ?: $session->get('api_user_id') ?: null);

        // A platform admin inside a client workspace acts as that client's admin user. Tag the row so the
        // client's own activity log can leave it out (see ActivityLogModel::HIDE_PLATFORM_ADMIN_SQL).
        // Covers both modes: working inside the workspace and editing the client from the platform screens.
        if ((int) $session->get('platform_admin_id') > 0) {
            $metadata['by_platform_admin'] = (int) $session->get('platform_admin_id');
        }

        $data = [
            'user_id'     => $resolvedUserId,
            'action'      => $action,
            'module'      => $module,
            'description' => $description,
            'ip_address'  => null,
            'user_agent'  => null,
            'metadata'    => $metadata === [] ? null : json_encode($metadata),
            'created_at'  => date('Y-m-d H:i:s'),
        ];

        try {
            $data['ip_address'] = $request?->getIPAddress();
        } catch (\Throwable) {
            // CLI / non-HTTP
        }

        try {
            if ($request !== null && method_exists($request, 'getUserAgent')) {
                $ua = $request->getUserAgent();
                $data['user_agent'] = $ua ? $ua->getAgentString() : null;
            }
        } catch (\Throwable) {
            // CLIRequest has no user agent
        }

        try {
            $saved = (bool) $this->logs->insert($data);
            $this->pruneOncePerDay();

            return $saved;
        } catch (\Throwable $e) {
            log_message('error', 'ActivityLogger failed: {msg}', ['msg' => $e->getMessage()]);

            return false;
        }
    }
}
