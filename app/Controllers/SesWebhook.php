<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Libraries\SesNotificationService;
use App\Libraries\TenantResolver;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Public Amazon SNS endpoint for SES bounce / complaint / delivery notifications.
 * URL: /webhooks/ses/{tenantKey} (shown in Settings → Email → Amazon SES).
 */
class SesWebhook extends BaseController
{
    public function receive(?string $tenantKey = null): ResponseInterface
    {
        if (! TenantResolver::ensureFromPublicKey($tenantKey)) {
            return $this->response->setStatusCode(404)->setJSON(['status' => 'error', 'message' => 'Unknown client.']);
        }

        $result = (new SesNotificationService())->handle((string) $this->request->getBody());

        return $this->response
            ->setStatusCode($result['status'])
            ->setJSON(['status' => $result['status'] < 300 ? 'ok' : 'error', 'message' => $result['message']]);
    }
}
