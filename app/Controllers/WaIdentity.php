<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Libraries\WhatsAppIdentityService;
use CodeIgniter\HTTP\ResponseInterface;
use Throwable;

/**
 * Meta WhatsApp business identity (auto-refresh + secure local avatar).
 */
class WaIdentity extends BaseController
{
    public function refresh(): ResponseInterface
    {
        $uid = (int) ($this->currentUser['id'] ?? 0);
        if ($uid <= 0) {
            return $this->jsonResponse(false, null, 'Unauthorized.', [], 401);
        }

        $force = (string) ($this->request->getGet('force') ?? $this->request->getPost('force') ?? '0') === '1';
        $svc   = new WhatsAppIdentityService();

        if (! service('settingsService')->isMetaProvider()) {
            return $this->jsonResponse(true, $svc->getIdentity(), 'Provider is not Meta.');
        }

        // Session rate-limit (extra guard beyond service TTL).
        $lastAttempt = (int) ($this->session->get('wa_identity_refresh_at') ?? 0);
        if (! $force && $lastAttempt > 0 && (time() - $lastAttempt) < 45 && ! $svc->needsRefresh()) {
            return $this->jsonResponse(true, $svc->getIdentity(), 'Cached identity.');
        }

        try {
            $this->session->set('wa_identity_refresh_at', time());
            $identity = $svc->refreshFromMeta($force);

            return $this->jsonResponse(true, $identity, 'WhatsApp identity refreshed from Meta.');
        } catch (Throwable $e) {
            log_message('warning', 'WaIdentity refresh failed: {msg}', ['msg' => $e->getMessage()]);

            return $this->jsonResponse(false, $svc->getIdentity(), $e->getMessage(), [], 422);
        }
    }

    public function avatar(): ResponseInterface
    {
        $uid = (int) ($this->currentUser['id'] ?? 0);
        if ($uid <= 0) {
            return $this->response->setStatusCode(401)->setBody('Unauthorized');
        }

        $svc  = new WhatsAppIdentityService();
        $path = $svc->localAvatarPath();
        if ($path === '') {
            return $this->response->setStatusCode(404)->setBody('Not found');
        }

        $bin = file_get_contents($path);
        if ($bin === false || $bin === '') {
            return $this->response->setStatusCode(404)->setBody('Not found');
        }

        return $this->response
            ->setHeader('Content-Type', $svc->localAvatarMime())
            ->setHeader('Content-Length', (string) strlen($bin))
            ->setHeader('Cache-Control', 'private, max-age=3600')
            ->setHeader('X-Content-Type-Options', 'nosniff')
            ->setBody($bin);
    }
}
