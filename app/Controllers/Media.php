<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Libraries\ActivityLogger;
use App\Models\MediaModel;
use CodeIgniter\HTTP\ResponseInterface;
use Throwable;

/**
 * Local media upload and secure file serving.
 */
class Media extends BaseController
{
    protected string $uploadPath = WRITEPATH . 'uploads/media/';

    public function upload(): ResponseInterface
    {
        if ($denied = $this->requirePermission('chat.send')) {
            return $denied;
        }

        $file = $this->request->getFile('file') ?? $this->request->getFile('media');
        if ($file === null || ! $file->isValid()) {
            return $this->jsonResponse(false, null, 'No valid file uploaded.', [], 422);
        }

        $allowed = [
            'image/jpeg', 'image/png', 'image/webp', 'image/gif',
            'application/pdf',
            'audio/mpeg', 'audio/ogg', 'audio/aac', 'audio/mp4',
            'video/mp4', 'video/3gpp',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/msword',
            'text/plain', 'text/csv',
        ];

        $mime = $file->getMimeType();
        if (! in_array($mime, $allowed, true)) {
            return $this->jsonResponse(false, null, 'File type not allowed: ' . $mime, [], 422);
        }

        $maxBytes = 16 * 1024 * 1024; // 16MB
        if ($file->getSize() > $maxBytes) {
            return $this->jsonResponse(false, null, 'File exceeds 16MB limit.', [], 422);
        }

        if (! is_dir($this->uploadPath)) {
            mkdir($this->uploadPath, 0755, true);
        }

        $newName = $file->getRandomName();
        try {
            $file->move($this->uploadPath, $newName);
        } catch (Throwable $e) {
            return $this->jsonResponse(false, null, 'Upload failed: ' . $e->getMessage(), [], 500);
        }

        $relative = 'uploads/media/' . $newName;
        $fullPath = $this->uploadPath . $newName;

        $waMediaId = null;
        $uploadToCheerio = (string) (
            $this->request->getPost('upload_to_cheerio')
            ?? $this->request->getPost('upload_to_meta')
            ?? '0'
        ) === '1';

        if ($uploadToCheerio) {
            try {
                $result = service('whatsApp')->uploadMedia($fullPath, $mime);
                $waMediaId = $result['id'] ?? null;
            } catch (Throwable $e) {
                log_message('warning', 'Cheerio media upload failed: {msg}', ['msg' => $e->getMessage()]);
            }
        }

        $id = model(MediaModel::class)->insert([
            'filename'      => $newName,
            'original_name' => $file->getClientName(),
            'mime_type'     => $mime,
            'size'          => $file->getSize(),
            'path'          => $relative,
            'wa_media_id'   => $waMediaId,
            'url'           => site_url('media/serve/' . $newName),
            'uploaded_by'   => $this->userId(),
        ]);

        if (! $id) {
            return $this->jsonResponse(false, null, 'Failed to save media record.', model(MediaModel::class)->errors(), 500);
        }

        $media = model(MediaModel::class)->find((int) $id);
        (new ActivityLogger())->log('upload', 'media', 'Media uploaded', ['media_id' => $id]);

        return $this->jsonResponse(true, $media, 'File uploaded.');
    }

    public function serve(string $filename): ResponseInterface
    {
        if ($denied = $this->requirePermission('chat.view')) {
            return $denied;
        }

        $filename = basename($filename);
        $path     = $this->uploadPath . $filename;

        if (! is_file($path)) {
            // Fallback: lookup by DB
            $media = model(MediaModel::class)->where('filename', $filename)->first();
            if ($media === null) {
                return $this->response->setStatusCode(404)->setBody('Not found');
            }
            $path = WRITEPATH . ltrim(str_replace(['../', '..\\'], '', (string) $media['path']), '/');
            if (! is_file($path)) {
                return $this->response->setStatusCode(404)->setBody('Not found');
            }
            $mime = (string) ($media['mime_type'] ?? 'application/octet-stream');
        } else {
            $media = model(MediaModel::class)->where('filename', $filename)->first();
            $mime  = is_array($media) ? (string) ($media['mime_type'] ?? mime_content_type($path)) : (mime_content_type($path) ?: 'application/octet-stream');
        }

        return $this->response
            ->setHeader('Content-Type', $mime)
            ->setHeader('Content-Length', (string) filesize($path))
            ->setHeader('Cache-Control', 'private, max-age=86400')
            ->setBody(file_get_contents($path) ?: '');
    }
}
