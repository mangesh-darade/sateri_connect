<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Libraries\CustomerPaymentLinks;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Inbox "Payment link": creates a Razorpay link for the open conversation's contact.
 * The text goes back to the composer; the agent reviews it and sends it like any message.
 */
class PaymentLinks extends BaseController
{
    public function create(): ResponseInterface
    {
        if ($denied = $this->requirePermission('chat.send')) {
            return $denied;
        }

        $input  = $this->requestInput();
        $result = (new CustomerPaymentLinks())->create(
            (int) ($input['contact_id'] ?? 0),
            (float) ($input['amount'] ?? 0),
            (string) ($input['description'] ?? ''),
            $this->userId()
        );

        if (! $result['ok']) {
            return $this->jsonResponse(false, null, $result['message'], [], $result['status']);
        }

        return $this->jsonResponse(true, $result['data'], $result['message']);
    }
}
