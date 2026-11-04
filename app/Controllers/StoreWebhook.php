<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Libraries\CartRecoveryService;
use App\Libraries\TenantResolver;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Public endpoint for WooCommerce and Shopify order / checkout events (abandoned cart reminders).
 * URL: /webhooks/store/{platform}/{tenantKey} (shown in Cart reminders). Signature checked inside.
 */
class StoreWebhook extends BaseController
{
    public function receive(string $platform = '', ?string $tenantKey = null): ResponseInterface
    {
        if (! TenantResolver::ensureFromPublicKey($tenantKey)) {
            return $this->response->setStatusCode(404)->setJSON(['status' => 'error', 'message' => 'Unknown client.']);
        }

        $headers = [];
        foreach ($this->request->headers() as $name => $header) {
            $headers[strtolower((string) $name)] = $header->getValueLine();
        }

        $res = (new CartRecoveryService())->handleWebhook(strtolower($platform), (string) $this->request->getBody(), $headers);

        return $this->response
            ->setStatusCode($res['status'])
            ->setJSON(['status' => $res['ok'] ? 'ok' : 'error', 'message' => $res['message']]);
    }
}
