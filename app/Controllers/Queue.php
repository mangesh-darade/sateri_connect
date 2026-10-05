<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Libraries\ActivityLogger;
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

        $status = (string) ($this->request->getGet('status') ?? '');
        $model  = model(MessageQueueModel::class);

        if ($status !== '') {
            $model->where('status', $status);
        }

        $items = $model
            ->select('message_queue.*, contacts.name AS contact_name, contacts.mobile, campaigns.name AS campaign_name')
            ->join('contacts', 'contacts.id = message_queue.contact_id', 'left')
            ->join('campaigns', 'campaigns.id = message_queue.campaign_id', 'left')
            ->orderBy('message_queue.priority', 'ASC')
            ->orderBy('message_queue.id', 'DESC')
            ->findAll(100);

        return $this->render('queue/index', [
            'pageTitle'    => 'Message Queue',
            'items'        => $items,
            'filterStatus' => $status,
            'stats'        => $this->buildStats(),
        ]);
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
