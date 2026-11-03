<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Libraries\SocialMediaDelivery;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Public, token-signed download of a staged Drive file so Facebook/Instagram can fetch it while publishing.
 * No session and no tenant: the signed, expiring token is the only credential (see SocialMediaDelivery).
 */
class SocialMedia extends BaseController
{
    public function show(string $token = ''): ResponseInterface
    {
        $path = (new SocialMediaDelivery())->resolve($token);
        if ($path === null) {
            return $this->response->setStatusCode(404)->setBody('Not found');
        }

        // DownloadResponse streams the file from disk, so large videos do not load into memory.
        $dl = $this->response->download($path, null);
        $dl->setFileName(basename($path));

        return $dl->setHeader('Cache-Control', 'private, no-store');
    }
}
