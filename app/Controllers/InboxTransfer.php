<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Libraries\ActivityLogger;
use App\Libraries\ChatBackupService;
use App\Libraries\ChatTransferService;
use CodeIgniter\HTTP\ResponseInterface;
use Throwable;

/**
 * Team Inbox → Export chats (CSV) / Import chats (CSV / XLSX, e.g. Cheerio chat export) / full backup ZIP + restore.
 */
class InboxTransfer extends BaseController
{
    public function export(): ResponseInterface
    {
        if ($denied = $this->requirePermission('chat.view') ?? $this->requirePermission('contacts.export')) {
            return $denied;
        }
        try {
            $file = (new ChatTransferService())->exportToFile($this->request->getGet() ?? []);
        } catch (Throwable $e) {
            return redirect()->to('/chat')->with('error', $e->getMessage());
        }
        (new ActivityLogger())->log('export', 'chat', 'Exported ' . $file['rows'] . ' chat messages', [
            'scope' => (string) ($this->request->getGet('scope') ?? 'all'),
            'rows'  => $file['rows'],
        ]);
        register_shutdown_function(static function () use ($file): void {
            if (is_file($file['path'])) {
                @unlink($file['path']);
            }
        });

        return $this->response->download($file['path'], null)->setFileName($file['filename'])->setContentType('text/csv; charset=UTF-8');
    }

    public function sample(): ResponseInterface
    {
        if ($denied = $this->requirePermission('chat.view')) {
            return $denied;
        }

        return $this->response
            ->setHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->setHeader('Content-Disposition', 'attachment; filename="chat_import_sample.csv"')
            ->setBody((new ChatTransferService())->sampleCsv());
    }

    public function preview(): ResponseInterface
    {
        if ($denied = $this->requirePermission('chat.view') ?? $this->requirePermission('contacts.import')) {
            return $denied;
        }
        $token = (string) ($this->request->getPost('token') ?? '');
        if ($token !== '') {
            try {
                $mapping = (array) ($this->request->getPost('mapping') ?? []);

                return $this->jsonResponse(true, ['stats' => (new ChatTransferService())->analyseStaged($token, array_map('strval', $mapping))]);
            } catch (Throwable $e) {
                return $this->jsonResponse(false, null, $e->getMessage(), [], 422);
            }
        }
        $file = $this->request->getFile('file');
        if ($file === null || ! $file->isValid()) {
            return $this->jsonResponse(false, null, 'Upload a CSV or XLSX file. ' . ($file !== null ? $file->getErrorString() : ''), [], 422);
        }
        $name = (string) $file->getClientName();
        if (! in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), ['csv', 'xlsx'], true)) {
            return $this->jsonResponse(false, null, 'Upload a CSV or XLSX file.', [], 422);
        }
        try {
            $preview = (new ChatTransferService())->preview($file->getTempName(), $name);
        } catch (Throwable $e) {
            return $this->jsonResponse(false, null, $e->getMessage(), [], 422);
        }

        return $this->jsonResponse(true, $preview, 'Check the column mapping, then import.');
    }

    public function commit(): ResponseInterface
    {
        if ($denied = $this->requirePermission('chat.view') ?? $this->requirePermission('contacts.import')) {
            return $denied;
        }
        $input   = $this->request->getPost() ?: ($this->request->getJSON(true) ?? []);
        $mapping = is_array($input['mapping'] ?? null) ? array_map('strval', $input['mapping']) : [];
        try {
            $result = (new ChatTransferService())->import(
                (string) ($input['token'] ?? ''),
                $mapping,
                filter_var($input['create_contacts'] ?? true, FILTER_VALIDATE_BOOLEAN)
            );
        } catch (Throwable $e) {
            return $this->jsonResponse(false, null, $e->getMessage(), [], 422);
        }

        $msg = $result['imported'] . ' message(s) imported';
        if ($result['duplicates'] > 0) {
            $msg .= ', ' . $result['duplicates'] . ' already imported';
        }
        if ($result['skipped'] > 0) {
            $msg .= ', ' . $result['skipped'] . ' skipped';
        }

        return $this->jsonResponse(true, $result, $msg . '.');
    }

    /** Full backup ZIP: chats + media, contacts, groups, conversation status, notes. */
    public function backup(): ResponseInterface
    {
        if ($denied = $this->requirePermission('chat.view') ?? $this->requirePermission('contacts.export')) {
            return $denied;
        }
        try {
            $file = (new ChatBackupService())->createBackup($this->request->getGet() ?? []);
        } catch (Throwable $e) {
            return redirect()->to('/chat')->with('error', $e->getMessage());
        }
        (new ActivityLogger())->log('export', 'chat', 'Downloaded inbox backup (' . $file['counts']['messages'] . ' messages)', $file['counts']);
        register_shutdown_function(static function () use ($file): void {
            if (is_file($file['path'])) {
                @unlink($file['path']);
            }
        });

        return $this->response->download($file['path'], null)->setFileName($file['filename'])->setContentType('application/zip');
    }

    /** One slice of a backup ZIP upload; the last slice returns the backup summary. */
    public function restoreUpload(): ResponseInterface
    {
        if ($denied = $this->requirePermission('chat.view') ?? $this->requirePermission('contacts.import')) {
            return $denied;
        }
        $chunk = $this->request->getFile('chunk');
        if ($chunk === null || ! $chunk->isValid()) {
            return $this->jsonResponse(false, null, 'Upload failed. ' . ($chunk !== null ? $chunk->getErrorString() : ''), [], 422);
        }
        try {
            $data = (new ChatBackupService())->receiveChunk(
                (string) ($this->request->getPost('upload_id') ?? ''),
                (int) ($this->request->getPost('offset') ?? 0),
                $chunk->getTempName(),
                filter_var($this->request->getPost('last'), FILTER_VALIDATE_BOOLEAN)
            );
        } catch (Throwable $e) {
            return $this->jsonResponse(false, null, $e->getMessage(), [], 422);
        }

        return $this->jsonResponse(true, $data);
    }

    public function restore(): ResponseInterface
    {
        if ($denied = $this->requirePermission('chat.view') ?? $this->requirePermission('contacts.import')) {
            return $denied;
        }
        try {
            $result = (new ChatBackupService())->restore(
                (string) ($this->request->getPost('token') ?? ''),
                (int) (session()->get('user_id') ?? 0)
            );
        } catch (Throwable $e) {
            return $this->jsonResponse(false, null, $e->getMessage(), [], 422);
        }

        return $this->jsonResponse(true, $result, 'Backup restored: ' . $result['messages_restored'] . ' message(s), '
            . $result['contacts_created'] . ' new contact(s), ' . $result['media_restored'] . ' media file(s).');
    }
}
