<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Libraries\MasterTenantRepository;
use App\Libraries\SchemaRepairService;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Platform super-admin: database health of every client (missing tables / columns) + one-click repair.
 */
class PlatformSchema extends BaseController
{
    public function index(): RedirectResponse
    {
        return redirect()->to('/platform/settings#database-health');
    }

    /**
     * POST platform/schema/{key}/check — report what is missing, change nothing.
     */
    public function check(string $key): ResponseInterface
    {
        return $this->inspect($key, false);
    }

    /**
     * POST platform/schema/{key}/repair — create missing tables / columns / indexes.
     */
    public function repair(string $key): ResponseInterface
    {
        return $this->inspect($key, true);
    }

    protected function inspect(string $key, bool $repair): ResponseInterface
    {
        $repo = new MasterTenantRepository();
        if ($repo->findActiveTenant($key) === null || ! $repo->platformAdminOwnsTenant((int) session('platform_admin_id'), $key)) {
            return $this->response->setStatusCode(404)->setJSON(['success' => false, 'message' => 'Client not found or inactive.']);
        }

        $result = (new SchemaRepairService())->inspectTenant($key, $repair);

        return $this->response->setJSON(['success' => $result['state'] !== 'error', 'message' => $result['message'], 'data' => $result]);
    }
}
