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
     * Log an application activity event.
     *
     * @param array<string, mixed> $metadata
     */
    public function log(string $action, string $module, string $description, array $metadata = []): bool
    {
        $request = service('request');
        $session = session();

        $data = [
            'user_id'     => $session->get('user_id') ?: null,
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
            return (bool) $this->logs->insert($data);
        } catch (\Throwable $e) {
            log_message('error', 'ActivityLogger failed: {msg}', ['msg' => $e->getMessage()]);

            return false;
        }
    }
}
