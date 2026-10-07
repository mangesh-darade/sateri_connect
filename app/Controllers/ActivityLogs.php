<?php

declare(strict_types=1);

namespace App\Controllers;

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

        $page    = max(1, (int) ($this->request->getGet('page') ?: 1));
        $perPage = 25;

        $logData = $this->activityLogs->getFilteredLogs($filters, $perPage, $page);
        $modules = $this->activityLogs->getDistinctModules();

        $users = model(UserModel::class)
            ->select('id, name, email')
            ->orderBy('name', 'ASC')
            ->findAll();

        return $this->render('activity_logs/index', [
            'pageTitle'  => 'Activity & Audit Logs',
            'subtitle'   => 'Track all system changes, user actions, broadcasts, and security events.',
            'logs'       => $logData['data'],
            'total'      => $logData['total'],
            'page'       => $logData['page'],
            'perPage'    => $logData['per_page'],
            'totalPages' => $logData['total_pages'],
            'modules'    => $modules,
            'users'      => $users,
            'filters'    => $filters,
        ]);
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
