<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Libraries\RazorpayService;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Razorpay webhook (payment.captured, order.paid, payment_link.paid). Public route: trust comes from the
 * signature, which is checked over the raw body before anything is read from it.
 */
class RazorpayWebhook extends BaseController
{
    public function receive(): ResponseInterface
    {
        $raw = (string) $this->request->getBody();
        $sig = (string) $this->request->getHeaderLine('X-Razorpay-Signature');

        $svc = new RazorpayService();
        if (! RazorpayService::verifyWebhookSignature($raw, $sig, $svc->config()['razorpay_webhook_secret'])) {
            log_message('warning', 'Razorpay webhook rejected: bad signature');

            return $this->response->setStatusCode(400)->setJSON(['status' => 'error', 'message' => 'Invalid signature']);
        }

        $event = json_decode($raw, true);
        if (! is_array($event)) {
            return $this->response->setStatusCode(400)->setJSON(['status' => 'error', 'message' => 'Invalid payload']);
        }

        $res = $svc->handleWebhook($event);

        // 5xx makes Razorpay retry; business "ignored" outcomes are 200.
        return $this->response->setStatusCode($res['status'] >= 500 ? 500 : 200)->setJSON(['status' => $res['ok'] ? 'ok' : 'error', 'message' => $res['message']]);
    }
}
