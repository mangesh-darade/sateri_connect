<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Libraries\DataTablesQuery;
use App\Models\ActivityLogModel;
use App\Models\UserModel;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Controller for viewing, filtering, and exporting system-wide activity and audit logs.
 */
class ActivityLogs extends BaseController
{
    protected ActivityLogModel $activityLogs;

    public function initController(
        \CodeIgniter\HTTP\RequestInterface $request,
        \CodeIgniter\HTTP\ResponseInterface $response,
        \Psr\Log\LoggerInterface $logger
    ): void {
        parent::initController($request, $response, $logger);
        $this->activityLogs = model(ActivityLogModel::class);
    }

    public function index(): string|ResponseInterface
    {
        if ($denied = $this->requireAnyPermission(['reports.view', 'settings.view', 'users.view'])) {
            return $denied;
        }

        $filters = [
            'module'    => (string) ($this->request->getGet('module') ?: ''),
            'action'    => (string) ($this->request->getGet('action') ?: ''),
            'user_id'   => (string) ($this->request->getGet('user_id') ?: ''),
            'date_from' => (string) ($this->request->getGet('date_from') ?: ''),
            'date_to'   => (string) ($this->request->getGet('date_to') ?: ''),
            'search'    => (string) ($this->request->getGet('search') ?: ''),
        ];

        $modules = $this->activityLogs->getDistinctModules();

        $users = model(UserModel::class)
            ->select('id, name, email')
            ->orderBy('name', 'ASC')
            ->findAll();

        return $this->render('activity_logs/index', [
            'pageTitle'  => 'Activity & Audit Logs',
            'subtitle'   => 'Track all system changes, user actions, broadcasts, and security events.',
            'modules'    => $modules,
            'users'      => $users,
            'filters'    => $filters,
        ]);
    }

    /** DataTables JSON for the activity log (filters: module, action, user_id, date_from, date_to, q). */
    public function data(): ResponseInterface
    {
        if ($denied = $this->requireAnyPermission(['reports.view', 'settings.view', 'users.view'])) {
            return $denied;
        }

        $model = $this->activityLogs;

        return $this->response->setJSON(DataTablesQuery::respond($this->request, [
            'builder'  => static fn () => db_connect()->table('activity_logs')
                ->select('activity_logs.id, activity_logs.user_id, activity_logs.action, activity_logs.module, activity_logs.description, '
                    . 'activity_logs.ip_address, activity_logs.user_agent, activity_logs.metadata, activity_logs.created_at, '
                    . 'users.name AS user_name, users.email AS user_email')
                ->join('users', 'users.id = activity_logs.user_id', 'left')
                ->where(ActivityLogModel::HIDE_PLATFORM_ADMIN_SQL, null, false),
            'columns'  => [0 => 'activity_logs.created_at', 1 => 'users.name', 2 => 'activity_logs.module', 3 => 'activity_logs.action', 5 => 'activity_logs.ip_address'],
            'search'   => ['activity_logs.description', 'activity_logs.action', 'activity_logs.module', 'users.name', 'users.email'],
            'order'    => [0, 'desc'],
            'tiebreak' => 'activity_logs.id',
            'filters'  => static function (\CodeIgniter\Database\BaseBuilder $b, array $get) use ($model): void {
                $model->applyFilters($b, [
                    'module'    => (string) ($get['module'] ?? ''),
                    'action'    => (string) ($get['action'] ?? ''),
                    'user_id'   => (string) ($get['user_id'] ?? ''),
                    'date_from' => (string) ($get['date_from'] ?? ''),
                    'date_to'   => (string) ($get['date_to'] ?? ''),
                    'search'    => (string) ($get['q'] ?? ''),
                ]);
            },
            'map'      => static function (array $r): array {
                $meta    = is_string($r['metadata'] ?? null) ? json_decode($r['metadata'], true) : null;
                $meta    = is_array($meta) ? $meta : [];
                $changes = [];
                if (! empty($meta['changes']) && is_array($meta['changes'])) {
                    $changes = $meta['changes'];
                } elseif (! empty($meta['before']) && ! empty($meta['after']) && is_array($meta['before']) && is_array($meta['after'])) {
                    foreach ($meta['after'] as $k => $v) {
                        $oldV = $meta['before'][$k] ?? null;
                        if ($oldV != $v) {
                            $changes[$k] = ['old' => $oldV, 'new' => $v];
                        }
                    }
                }

                return [
                    'id'          => (int) $r['id'],
                    'action'      => (string) ($r['action'] ?? ''),
                    'module'      => (string) ($r['module'] ?? ''),
                    'user_name'   => (string) ($r['user_name'] ?? ''),
                    'user_email'  => (string) ($r['user_email'] ?? ''),
                    'created_at'  => format_app_datetime($r['created_at'] ?? null),
                    'description' => (string) ($r['description'] ?? ''),
                    'ip_address'  => (string) ($r['ip_address'] ?? ''),
                    'user_agent'  => (string) ($r['user_agent'] ?? ''),
                    'metadata'    => $meta === [] ? new \stdClass() : $meta,
                    'changes'     => $changes === [] ? new \stdClass() : $changes,
                    'change_count' => count($changes),
                ];
            },
        ]));
    }

    /**
     * Export filtered logs to CSV.
     */
    public function export(): ResponseInterface
    {
        if ($denied = $this->requireAnyPermission(['reports.view', 'settings.view', 'users.view'])) {
            return $denied;
        }

        $filters = [
            'module'    => (string) ($this->request->getGet('module') ?: ''),
            'action'    => (string) ($this->request->getGet('action') ?: ''),
            'user_id'   => (string) ($this->request->getGet('user_id') ?: ''),
            'date_from' => (string) ($this->request->getGet('date_from') ?: ''),
            'date_to'   => (string) ($this->request->getGet('date_to') ?: ''),
            'search'    => (string) ($this->request->getGet('search') ?: ''),
        ];

        // Fetch max 5000 records for export
        $logData = $this->activityLogs->getFilteredLogs($filters, 5000, 1);
        $rows    = $logData['data'];

        $filename = 'activity_logs_' . date('Ymd_His') . '.csv';

        $output = fopen('php://temp', 'w+');
        if ($output === false) {
            return $this->response->setStatusCode(500)->setBody('Unable to generate CSV export.');
        }

        // BOM for Excel UTF-8
        fwrite($output, "\xEF\xBB\xBF");
        fputcsv($output, ['ID', 'Date/Time (UTC)', 'User', 'Email', 'Action', 'Module', 'Description', 'IP Address', 'Metadata']);

        foreach ($rows as $row) {
            $metaString = '';
            if (! empty($row['metadata'])) {
                $metaString = is_array($row['metadata']) ? json_encode($row['metadata'], JSON_UNESCAPED_SLASHES) : (string) $row['metadata'];
            }

            fputcsv($output, array_map([\App\Libraries\ContactExportService::class, 'safeCell'], [
                $row['id'] ?? '',
                $row['created_at'] ?? '',
                $row['user_name'] ?? 'System',
                $row['user_email'] ?? '',
                $row['action'] ?? '',
                $row['module'] ?? '',
                $row['description'] ?? '',
                $row['ip_address'] ?? '',
                $metaString,
            ]));
        }

        rewind($output);
        $csvContent = stream_get_contents($output);
        fclose($output);

        $this->logActivity('export', 'activity_logs', 'Exported activity logs to CSV (' . count($rows) . ' rows)');

        return $this->response
            ->setHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->setHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->setBody((string) $csvContent);
    }
}
