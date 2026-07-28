<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Controllers\Webhooks as WebWebhooks;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Optional API-mounted webhook receiver — delegates to the public Webhooks controller.
 */
class Webhooks extends BaseApiController
{
    public function receive(): ResponseInterface
    {
        // Reuse the same processing pipeline as the public webhook endpoint
        $controller = new WebWebhooks();
        $controller->initController($this->request, $this->response, service('logger'));

        return $controller->index();
    }
}
