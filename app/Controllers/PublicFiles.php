<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Libraries\PublicFileService;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Public download of a file uploaded through the API (catalogue PDF, picture, MP4), so WhatsApp can fetch it.
 * The name is 40 random hex characters, so it cannot be guessed. Only the four accepted types are ever served.
 */
class PublicFiles extends BaseController
{
    public function show(string $name = ''): ResponseInterface
    {
        if (preg_match('/^[a-f0-9]{40}\.(pdf|jpg|png|mp4)$/', $name, $m) !== 1) {
            return $this->response->setStatusCode(404)->setBody('Not found');
        }

        $path = PublicFileService::dir() . $name;
        if (! is_file($path)) {
            return $this->response->setStatusCode(404)->setBody('Not found');
        }

        $mime = array_search($m[1], PublicFileService::TYPES, true) ?: 'application/octet-stream';

        return $this->response
            ->setHeader('Content-Type', (string) $mime)
            ->setHeader('Content-Length', (string) filesize($path))
            ->setHeader('X-Content-Type-Options', 'nosniff')
            ->setHeader('Cache-Control', 'public, max-age=86400')
            ->setBody((string) file_get_contents($path));
    }
}
