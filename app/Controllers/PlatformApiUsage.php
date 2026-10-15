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

            $days = $this->groupDailyApis($logModel->dailyApis(30));
            $calls = array_sum(array_column($days, 'total'));
            $failed = array_sum(array_column($days, 'failed'));
            $last = '';
            foreach ($keys as $keyRow) {
                $used = (string) ($keyRow['last_used_at'] ?? '');
                if ($used !== '' && $used > $last) {
                    $last = $used;
                }
            }

            return $this->response->setJSON([
                'success' => true,
                'data'    => [
                    'keys'   => $keys,
                    'calls'  => $calls,
                    'failed' => $failed,
                    'ok'     => $calls - $failed,
                    'last'   => $last,
                    'days'   => $days,
                ],
            ]);
        } catch (Throwable $e) {
            log_message('error', 'Platform API usage for {key} failed: {msg}', ['key' => $key, 'msg' => $e->getMessage()]);

            return $this->response->setStatusCode(500)->setJSON(['success' => false, 'message' => 'Cannot read this client database.']);
        }
    }

    /**
     * GET platform/api-usage/{key}/calls — one page of real calls for a day and API.
     */
    public function calls(string $key): ResponseInterface
    {
        if ((new MasterTenantRepository())->findActiveTenant($key) === null
            || ! (new TenantConnection())->apply($key, 'platform')) {
            return $this->response->setStatusCode(404)->setJSON(['success' => false, 'message' => 'Client not found or inactive.']);
        }

        $date = (string) $this->request->getGet('date');
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return $this->response->setStatusCode(422)->setJSON(['success' => false, 'message' => 'Pick a valid day.']);
        }

        $status   = (string) ($this->request->getGet('status') ?: 'all');
        $status   = in_array($status, ['all', 'ok', 'failed'], true) ? $status : 'all';
        $method   = strtoupper(substr((string) $this->request->getGet('method'), 0, 10));
        $endpoint = substr((string) $this->request->getGet('endpoint'), 0, 255);
        $page     = max(1, (int) $this->request->getGet('page'));
        $perPage  = 30;

        try {
            $result = (new ApiRequestLogModel())->callsFor($date, $status, $method, $endpoint, $perPage, ($page - 1) * $perPage);
            $rows   = array_map(static function (array $r): array {
                $code = (int) ($r['status_code'] ?? 0);

                return [
                    'created_at'  => $r['created_at'] ?? '',
                    'key'         => (string) (($r['token_name'] ?? '') !== '' ? $r['token_name'] : ('#' . ($r['token_id'] ?? ''))),
                    'method'      => (string) ($r['method'] ?? ''),
                    'endpoint'    => (string) ($r['endpoint'] ?? ''),
                    'status_code' => $code,
                    'outcome'     => $code >= 400 ? 'failed' : 'ran',
                    'ip_address'  => (string) ($r['ip_address'] ?? ''),
                    'duration_ms' => (int) ($r['duration_ms'] ?? 0),
                ];
            }, $result['rows']);

            return $this->response->setJSON([
                'success' => true,
                'data'    => [
                    'rows'  => $rows,
                    'total' => $result['total'],
                    'page'  => $page,
                    'pages' => max(1, (int) ceil($result['total'] / $perPage)),
                ],
            ]);
        } catch (Throwable $e) {
            log_message('error', 'Platform API call list for {key} failed: {msg}', ['key' => $key, 'msg' => $e->getMessage()]);

            return $this->response->setStatusCode(500)->setJSON(['success' => false, 'message' => 'Cannot read this client database.']);
        }
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function groupDailyApis(array $rows): array
    {
        $days = [];
        foreach ($rows as $row) {
            $day = (string) ($row['day'] ?? '');
            if ($day === '') {
                continue;
            }
            if (! isset($days[$day])) {
                $days[$day] = ['date' => $day, 'total' => 0, 'failed' => 0, 'ok' => 0, 'apis' => []];
            }
            $total  = (int) ($row['total'] ?? 0);
            $failed = (int) ($row['failed'] ?? 0);
            $ok     = max(0, $total - $failed);
            $days[$day]['total']  += $total;
            $days[$day]['failed'] += $failed;
            $days[$day]['ok']     += $ok;
            $days[$day]['apis'][] = [
                'method'   => (string) ($row['method'] ?? ''),
                'endpoint' => (string) ($row['endpoint'] ?? ''),
                'total'    => $total,
                'failed'   => $failed,
                'ok'       => $ok,
            ];
        }

        return array_values($days);
    }
}
