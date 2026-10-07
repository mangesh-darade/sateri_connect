<?php

declare(strict_types=1);

namespace App\Libraries;

use CodeIgniter\HTTP\Files\UploadedFile;
use RuntimeException;

class EmailAttachmentHandler
{
    public const MAX_SIZE_BYTES = 5242880; // 5MB

    public const ALLOWED_EXTENSIONS = [
        'pdf', 'jpg', 'jpeg', 'png', 'webp', 'xlsx', 'csv', 'docx',
    ];

    public const ALLOWED_MIME_TYPES = [
        'application/pdf',
        'image/jpeg',
        'image/png',
        'image/webp',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'text/csv',
        'text/plain',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/octet-stream', // Some systems/browsers send docx/xlsx as octet-stream
    ];

    /**
     * Validates and saves an uploaded email attachment according to strict security guidelines.
     *
     * @return array{ok: bool, path?: string, relative_path?: string, name?: string, mime?: string, size?: int, error?: string}
     */
    public static function handleUpload(?UploadedFile $file): array
    {
        if ($file === null || ! $file->isValid() || $file->hasMoved()) {
            return ['ok' => false, 'error' => 'No valid file uploaded or file upload error (' . ($file ? $file->getErrorString() : 'empty') . ').'];
        }

        // 1. Max size check (5MB)
        $size = $file->getSize();
        if ($size > self::MAX_SIZE_BYTES) {
            return ['ok' => false, 'error' => 'File exceeds maximum allowed size of 5MB.'];
        }

        // 2. Extension check
        $clientName = $file->getClientName();
        $ext = strtolower($file->getClientExtension());
        if (! in_array($ext, self::ALLOWED_EXTENSIONS, true)) {
            return ['ok' => false, 'error' => 'File extension .' . $ext . ' is not allowed. Allowed: PDF, JPG, PNG, WEBP, XLSX, CSV, DOCX.'];
        }

        // 3. MIME validation via finfo_file
        $tmpPath = $file->getTempName();
        if (! file_exists($tmpPath)) {
            return ['ok' => false, 'error' => 'Uploaded temporary file not found.'];
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = $finfo ? finfo_file($finfo, $tmpPath) : false;
        if ($finfo) {
            finfo_close($finfo);
        }

        if (! $mime || str_contains($mime, 'svg') || (! in_array($mime, self::ALLOWED_MIME_TYPES, true) && ! str_starts_with($mime, 'image/'))) {
            return ['ok' => false, 'error' => 'Invalid file MIME type (' . ($mime ?: 'unknown') . '). File rejected.'];
        }

        // 4. PHP Tag scanning
        $contentSample = @file_get_contents($tmpPath);
        if ($contentSample !== false && (str_contains($contentSample, '<?php') || str_contains($contentSample, '<?=') || str_contains($contentSample, '<script'))) {
            return ['ok' => false, 'error' => 'Security check failed: executable code detected in file content.'];
        }

        // 5. Safe directory & auto-generated unique filename
        $targetDir = FCPATH . 'uploads/emails/attachments/';
        if (! is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }

        $randomName = $file->getRandomName();
        $file->move($targetDir, $randomName);

        $cleanClientName = preg_replace('/[^a-zA-Z0-9_\.\-]/', '_', $clientName);
        if ($cleanClientName === '' || $cleanClientName === '.') {
            $cleanClientName = 'attachment.' . $ext;
        }

        $fullPath = $targetDir . $randomName;
        $relPath = 'uploads/emails/attachments/' . $randomName;

        return [
            'ok'            => true,
            'path'          => $fullPath,
            'relative_path' => $relPath,
            'name'          => $cleanClientName,
            'mime'          => $mime,
            'size'          => $size,
        ];
    }
}
