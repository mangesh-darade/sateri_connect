<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Libraries\AutoSyncService;
use CodeIgniter\HTTP\ResponseInterface;

class AutoSync extends BaseController
{
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
