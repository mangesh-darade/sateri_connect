<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Libraries\MasterTenantRepository;
use App\Libraries\SchemaRepairService;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Platform super-admin: database health of every client (missing tables / columns) + one-click repair.
 */
class PlatformSchema extends BaseController
{
    public function index(): string
    {
        $snapshotFile = SchemaRepairService::SNAPSHOT_FILE;

        return view('platform/schema', [
            'pageTitle'     => 'Database Health',
            'navActive'     => 'schema',
            'platformName'  => (string) session('platform_admin_name'),
            'clients'       => (new MasterTenantRepository())->listActiveTenants(),
            'tableCount'    => count(SchemaRepairService::snapshot()),
            'snapshotAt'    => is_file($snapshotFile) ? date('d M Y, h:i A', (int) filemtime($snapshotFile)) : null,
        ]);
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
        if ((new MasterTenantRepository())->findActiveTenant($key) === null) {
            return $this->response->setStatusCode(404)->setJSON(['success' => false, 'message' => 'Client not found or inactive.']);
        }

        $result = (new SchemaRepairService())->inspectTenant($key, $repair);

        return $this->response->setJSON(['success' => $result['state'] !== 'error', 'message' => $result['message'], 'data' => $result]);
    }
}
