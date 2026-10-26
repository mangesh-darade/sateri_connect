<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Libraries\ActivityLogger;
use App\Libraries\DataTablesQuery;
use App\Models\MessageQueueModel;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Outbound message queue monitoring and management.
 */
class Queue extends BaseController
{
    public function index(): string|ResponseInterface
    {
        if ($denied = $this->requirePermission('queue.view')) {
            return $denied;
        }

        try {
            (new \App\Libraries\AutomationEngine())->processDelayedJobs();
        } catch (\Throwable) {
            // non-blocking
        }

        $status = (string) ($this->request->getGet('status') ?? '');

        return $this->render('queue/index', [
            'pageTitle'    => 'Message Queue',
            'filterStatus' => $status,
            'stats'        => $this->buildStats(),
        ]);
    }

    /**
     * Server-side DataTables feed for the queue list.
     */
    public function data(): ResponseInterface
    {
        if ($denied = $this->requirePermission('queue.view')) {
            return $denied;
        }

        $canManage = function_exists('can') && can('queue.manage');

        return $this->response->setJSON(DataTablesQuery::respond($this->request, [
            'builder'  => static fn () => db_connect()->table('message_queue q')
                ->select('q.id, q.contact_id, q.message_type, q.status, q.attempts, q.max_attempts, q.scheduled_at, q.error_message, q.payload, c.name AS contact_name, c.mobile, cp.name AS campaign_name')
                ->join('contacts c', 'c.id = q.contact_id', 'left')
                ->join('campaigns cp', 'cp.id = q.campaign_id', 'left'),
            'columns'  => [0 => 'q.id', 1 => 'c.name', 2 => 'q.message_type', 3 => 'q.status', 4 => 'q.attempts', 5 => 'q.scheduled_at', 6 => 'q.error_message'],
            'search'   => ['q.id', 'c.name', 'c.mobile', 'cp.name', 'q.message_type', 'q.status', 'q.error_message'],
            'filters'  => static function ($b, array $get): void {
                $st = (string) ($get['status'] ?? '');
                if (in_array($st, ['pending', 'processing', 'sent', 'failed', 'cancelled'], true)) {
                    $b->where('q.status', $st);
                }
            },
            'order'    => [0, 'desc'],
            'tiebreak' => 'q.id',
            'map'      => static function (array $r) use ($canManage): array {
                $max      = (int) ($r['max_attempts'] ?? 3);
                $attempts = (int) ($r['attempts'] ?? 0);
                $payload  = (string) ($r['payload'] ?? '');

                return [
                    'id'            => (int) $r['id'],
                    'contact_id'    => (int) ($r['contact_id'] ?? 0),
                    'contact_url'   => ! empty($r['contact_id']) ? site_url('contacts/' . (int) $r['contact_id']) : '',
                    'contact_label' => (string) (($r['contact_name'] ?? '') ?: (($r['mobile'] ?? '') ?: '#' . $r['contact_id'])),
                    'contact_name'  => (string) (($r['contact_name'] ?? '') ?: ($r['mobile'] ?? '')),
                    'campaign'      => (string) ($r['campaign_name'] ?? ''),
                    'type'          => (string) (($r['message_type'] ?? '') ?: 'text'),
                    'status'        => (string) ($r['status'] ?? ''),
                    'attempts'      => min($attempts, $max) . '/' . $max,
                    'exhausted'     => ($r['status'] ?? '') === 'failed' && $attempts >= $max,
                    'scheduled'     => format_app_datetime($r['scheduled_at'] ?? null),
                    'error'         => (string) ($r['error_message'] ?? ''),
                    'payload'       => $payload,
                    'retry_url'     => $canManage ? site_url('queue/' . (int) $r['id'] . '/retry') : '',
                    'cancel_url'    => $canManage ? site_url('queue/' . (int) $r['id'] . '/cancel') : '',
                ];
            },
        ]));
    }

    public function process(): ResponseInterface
    {
        if ($denied = $this->requirePermission('queue.manage')) {
            return $denied;
        }

        $service = new \App\Libraries\QueueService();
        $stats   = $service->processBatch(50);

        (new ActivityLogger())->log('process', 'queue', 'Queue batch processed manually', $stats);

        $msg = sprintf('Processed %d item(s): %d sent, %d failed.', $stats['processed'], $stats['sent'], $stats['failed']);

        return $this->request->isAJAX()
            ? $this->jsonResponse(true, $stats, $msg)
            : redirect()->to('/queue')->with('success', $msg);
    }

    public function retryAll(): ResponseInterface
    {
        if ($denied = $this->requirePermission('queue.manage')) {
            return $denied;
        }

        $model = model(MessageQueueModel::class);
        $count = $model->where('status', 'failed')
            ->set([
                'status'        => 'pending',
                'scheduled_at'  => date('Y-m-d H:i:s'),
                'error_message' => null,
                'processed_at'  => null,
                'attempts'      => 0,
            ])
            ->update();

        $service = new \App\Libraries\QueueService();
        $stats   = $service->processBatch(50);

        (new ActivityLogger())->log('retry_all', 'queue', 'All failed queue items retried', ['reset' => $count, 'stats' => $stats]);

        $msg = sprintf('Retried %d failed item(s). %d sent, %d failed.', $count, $stats['sent'], $stats['failed']);

        return $this->request->isAJAX()
            ? $this->jsonResponse(true, ['reset' => $count, 'stats' => $stats], $msg)
            : redirect()->to('/queue')->with('success', $msg);
    }

    public function retry(int $id): ResponseInterface
    {
        if ($denied = $this->requirePermission('queue.manage')) {
            return $denied;
        }

        $model = model(MessageQueueModel::class);
        $item  = $model->find($id);

        if ($item === null) {
            return $this->fail('Queue item not found.', 404);
        }

        if (! in_array($item['status'], ['failed', 'cancelled'], true)) {
            return $this->fail('Only failed or cancelled items can be retried.');
        }

        $model->update($id, [
            'status'        => 'pending',
            'scheduled_at'  => date('Y-m-d H:i:s'),
            'error_message' => null,
            'processed_at'  => null,
            'attempts'      => 0,
        ]);

        $service = new \App\Libraries\QueueService();
        $batch   = $service->processBatch(10);

        (new ActivityLogger())->log('retry', 'queue', 'Queue item retried', ['queue_id' => $id, 'batch' => $batch]);

        $updated = $model->find($id);
        if ($updated && $updated['status'] === 'sent') {
            return $this->ok('Queue item #' . $id . ' sent successfully!');
        }
        if ($updated && $updated['status'] === 'failed') {
            return $this->fail('Retry failed: ' . ($updated['error_message'] ?? 'Unknown error'));
        }

        return $this->ok('Queue item #' . $id . ' queued and processed.');
    }

    public function cancel(int $id): ResponseInterface
    {
        if ($denied = $this->requirePermission('queue.manage')) {
            return $denied;
        }

        $model = model(MessageQueueModel::class);
        $item  = $model->find($id);

        if ($item === null) {
            return $this->fail('Queue item not found.', 404);
        }

        if (! in_array($item['status'], ['pending', 'processing'], true)) {
            return $this->fail('Only pending or processing items can be cancelled.');
        }

        $model->update($id, [
            'status'       => 'cancelled',
            'processed_at' => date('Y-m-d H:i:s'),
        ]);

        (new ActivityLogger())->log('cancel', 'queue', 'Queue item cancelled', ['queue_id' => $id]);

        return $this->ok('Queue item cancelled.');
    }

    public function stats(): ResponseInterface
    {
        if ($denied = $this->requirePermission('queue.view')) {
            return $denied;
        }

        return $this->jsonResponse(true, $this->buildStats());
    }

    /**
     * @return array<string, int>
     */
    protected function buildStats(): array
    {
        $rows = db_connect()->table('message_queue')
            ->select('status, COUNT(*) AS total')
            ->groupBy('status')
            ->get()
            ->getResultArray();

        $stats = [
            'pending'    => 0,
            'processing' => 0,
            'sent'       => 0,
            'failed'     => 0,
            'cancelled'  => 0,
            'total'      => 0,
        ];

        foreach ($rows as $row) {
            $status = (string) $row['status'];
            $count  = (int) $row['total'];
            $stats[$status] = $count;
            $stats['total'] += $count;
        }

        return $stats;
    }

    protected function ok(string $message): ResponseInterface
    {
        if ($this->request->isAJAX()) {
            return $this->jsonResponse(true, null, $message);
        }

        return redirect()->to('/queue')->with('success', $message);
    }

    protected function fail(string $message, int $status = 400): ResponseInterface
    {
        if ($this->request->isAJAX()) {
            return $this->jsonResponse(false, null, $message, [], $status);
        }

        return redirect()->to('/queue')->with('error', $message);
    }
}
