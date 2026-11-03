<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Libraries\OutgoingWebhooks;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Outgoing webhook endpoints: where the client's own systems receive events. Needs the settings permissions.
 */
class WebhookEndpoints extends BaseController
{
    public function index(): string|ResponseInterface
    {
        if ($denied = $this->requirePermission('settings.view')) {
            return $denied;
        }

        $hooks = new OutgoingWebhooks();

        return $this->render('webhook_endpoints/index', [
            'pageTitle'  => 'Outgoing webhooks',
            'endpoints'  => $hooks->all(),
            'deliveries' => $hooks->recentDeliveries(20),
            'events'     => OutgoingWebhooks::EVENTS,
        ]);
    }

    public function store(): ResponseInterface
    {
        if ($denied = $this->requirePermission('settings.edit')) {
            return $denied;
        }

        $in  = $this->requestInput();
        $res = (new OutgoingWebhooks())->create(
            (string) ($in['name'] ?? ''),
            (string) ($in['url'] ?? ''),
            array_map('strval', (array) ($in['events'] ?? [])),
            $this->userId()
        );
        if (! $res['ok']) {
            return $this->jsonResponse(false, null, $res['message'], [], $res['status']);
        }

        $this->logActivity('create', 'webhooks', 'Outgoing webhook endpoint added', ['endpoint_id' => $res['data']['id']]);

        // The secret is shown once, here; only its encrypted form is stored.
        return $this->jsonResponse(true, $res['data'], $res['message']);
    }

    public function update(int $id): ResponseInterface
    {
        if ($denied = $this->requirePermission('settings.edit')) {
            return $denied;
        }

        $in  = $this->requestInput();
        $res = (new OutgoingWebhooks())->update(
            $id,
            (string) ($in['name'] ?? ''),
            (string) ($in['url'] ?? ''),
            array_map('strval', (array) ($in['events'] ?? [])),
            (string) ($in['is_active'] ?? '1') !== '0'
        );
        if (! $res['ok']) {
            return $this->jsonResponse(false, null, $res['message'], [], $res['status']);
        }

        $this->logActivity('update', 'webhooks', 'Outgoing webhook endpoint updated', ['endpoint_id' => $id]);

        return $this->jsonResponse(true, null, $res['message']);
    }

    public function rotate(int $id): ResponseInterface
    {
        if ($denied = $this->requirePermission('settings.edit')) {
            return $denied;
        }

        $res = (new OutgoingWebhooks())->rotateSecret($id);
        if (! $res['ok']) {
            return $this->jsonResponse(false, null, $res['message'], [], $res['status']);
        }

        $this->logActivity('update', 'webhooks', 'Outgoing webhook secret rotated', ['endpoint_id' => $id]);

        return $this->jsonResponse(true, $res['data'], $res['message']);
    }

    public function test(int $id): ResponseInterface
    {
        if ($denied = $this->requirePermission('settings.edit')) {
            return $denied;
        }

        $res = (new OutgoingWebhooks())->sendTest($id);

        return $this->jsonResponse($res['ok'], null, $res['message'], [], $res['ok'] ? 200 : $res['status']);
    }

    public function delete(int $id): ResponseInterface
    {
        if ($denied = $this->requirePermission('settings.edit')) {
            return $denied;
        }
        if (! (new OutgoingWebhooks())->delete($id)) {
            return $this->jsonResponse(false, null, 'Endpoint not found.', [], 404);
        }

        $this->logActivity('delete', 'webhooks', 'Outgoing webhook endpoint deleted', ['endpoint_id' => $id]);

        return $this->jsonResponse(true, null, 'Endpoint deleted.');
    }
}
