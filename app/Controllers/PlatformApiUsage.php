<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Libraries\MasterTenantRepository;
use App\Libraries\TenantConnection;
use App\Models\ApiRequestLogModel;
use App\Models\ApiTokenModel;
use CodeIgniter\HTTP\ResponseInterface;
use Throwable;

/**
 * Platform super-admin: which external systems call each client's API (keys, call counts, recent calls).
 */
class PlatformApiUsage extends BaseController
{
    public function index(): string
    {
        return view('platform/api_usage', [
            'pageTitle'    => 'API Usage',
            'navActive'    => 'api-usage',
            'platformName' => (string) session('platform_admin_name'),
            'clients'      => (new MasterTenantRepository())->listActiveTenants(),
        ]);
    }

    /**
     * GET platform/api-usage/{key} — API keys + 30-day usage + latest calls of one client.
     */
    public function client(string $key): ResponseInterface
    {
        if ((new MasterTenantRepository())->findActiveTenant($key) === null
            || ! (new TenantConnection())->apply($key, 'platform')) {
            return $this->response->setStatusCode(404)->setJSON(['success' => false, 'message' => 'Client not found or inactive.']);
        }

        try {
            $logModel = new ApiRequestLogModel();
            $usage    = $logModel->usageByToken(30);
            $keys     = [];
            foreach ((new ApiTokenModel())->orderBy('id', 'DESC')->findAll(50) as $tk) {
                $use    = $usage[(int) $tk['id']] ?? [];
                $keys[] = [
                    'name'         => (string) $tk['name'],
                    'created_at'   => $tk['created_at'] ?? null,
                    'last_used_at' => $tk['last_used_at'] ?? null,
                    'calls'        => (int) ($use['total'] ?? 0),
                    'failed'       => (int) ($use['failed'] ?? 0),
                    'ips'          => (int) ($use['ips'] ?? 0),
                ];
            }

            $recent = array_map(static fn (array $r): array => [
                'created_at'  => $r['created_at'],
                'key'         => (string) ($r['token_name'] ?: '#' . $r['token_id']),
                'method'      => $r['method'],
                'endpoint'    => $r['endpoint'],
                'status_code' => (int) $r['status_code'],
                'ip_address'  => $r['ip_address'],
                'user_agent'  => $r['user_agent'],
                'duration_ms' => (int) $r['duration_ms'],
            ], $logModel->recent(20));

            return $this->response->setJSON([
                'success' => true,
                'data'    => [
                    'keys'   => $keys,
                    'calls'  => array_sum(array_column($keys, 'calls')),
                    'failed' => array_sum(array_column($keys, 'failed')),
                    'recent' => $recent,
                ],
            ]);
        } catch (Throwable $e) {
            log_message('error', 'Platform API usage for {key} failed: {msg}', ['key' => $key, 'msg' => $e->getMessage()]);

            return $this->response->setStatusCode(500)->setJSON(['success' => false, 'message' => 'Cannot read this client database.']);
        }
    }
}
