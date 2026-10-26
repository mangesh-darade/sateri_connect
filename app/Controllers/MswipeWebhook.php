<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Libraries\MswipeService;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Mswipe "back-posting" URL. Mswipe does not sign it, so nothing in the body is trusted: it only names the order
 * to re-check, and the wallet is credited only after Mswipe's own status API confirms the payment.
 */
class MswipeWebhook extends BaseController
{
    public function receive(): ResponseInterface
    {
        $body = json_decode((string) $this->request->getBody(), true);
        if (! is_array($body)) {
            $body = $this->request->getPost() ?: [];
        }

        $res = (new MswipeService())->handleBackPost($body);

        // 5xx makes Mswipe retry (if it does); an unknown or ignored order is a 200.
        return $this->response->setStatusCode($res['status'] >= 500 ? 500 : 200)
            ->setJSON(['status' => $res['ok'] ? 'ok' : 'error', 'message' => $res['message']]);
    }
}
