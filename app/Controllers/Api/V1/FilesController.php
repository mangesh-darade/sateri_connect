<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Libraries\PublicFileService;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * POST /api/v1/files: upload a PDF, JPEG, PNG or MP4 (max 16 MB) and get a public https link, for example for a
 * workflow "Send media" node. Send file_url (a public https link) or file_base64 with filename.
 */
class FilesController extends BaseV1Controller
{
    public function upload(): ResponseInterface
    {
        $in  = $this->getJsonPayload();
        $svc = new PublicFileService();

        if (! empty($in['file_url'])) {
            $res = $svc->storeFromUrl((string) $in['file_url'], (string) ($in['filename'] ?? ''));
        } else {
            $raw   = (string) ($in['file_base64'] ?? '');
            $raw   = (string) preg_replace('#^data:[^;]+;base64,#', '', $raw);
            $bytes = base64_decode($raw, true);
            $res   = $svc->store($bytes === false ? '' : $bytes, (string) ($in['filename'] ?? ''));
        }

        return $res['ok']
            ? $this->respondSuccess($res['data'] ?? [], $res['message'])
            : $this->respondError($res['message'], $res['status']);
    }

    /** GET /api/v1/files: what is stored, what uses it, and how much space is left. */
    public function index(): ResponseInterface
    {
        $svc = new PublicFileService();
        $use = $svc->usage();

        return $this->respondSuccess([
            'files' => $svc->list(100),
            'usage' => $use + ['max_files' => PublicFileService::MAX_FILES, 'max_bytes' => PublicFileService::MAX_TOTAL],
        ], 'Files loaded.');
    }

    /** POST /api/v1/files/{name}/delete  {force?}: removes a file that nothing uses. */
    public function delete(string $name): ResponseInterface
    {
        $in  = $this->getJsonPayload();
        $res = (new PublicFileService())->delete($name, in_array($in['force'] ?? false, [true, 1, '1', 'true'], true));

        return $res['ok'] ? $this->respondSuccess(null, $res['message']) : $this->respondError($res['message'], $res['status']);
    }
}
