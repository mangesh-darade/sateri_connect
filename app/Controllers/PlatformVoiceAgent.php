<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Libraries\MasterTenantRepository;
use App\Libraries\SettingsService;
use App\Libraries\TenantConnection;
use App\Libraries\TenantContext;
use App\Libraries\VoiceAgentAdminService;
use App\Libraries\VoiceAgentLeadService;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Platform super admin: AI voice agent connection, server settings and the agent's own settings, in one place.
 * Main platform admin only (these are infrastructure secrets that every client's calls use).
 */
class PlatformVoiceAgent extends BaseController
{
    public function index(): string|ResponseInterface
    {
        if ($denied = $this->denyUnlessPrimary()) {
            return $denied;
        }

        return view('platform/voice_agent', [
            'pageTitle'    => 'AI voice agent',
            'navActive'    => 'voice-agent',
            'platformName' => (string) session('platform_admin_name'),
            'cfg'          => (new VoiceAgentAdminService())->config(),
            'clients'      => array_map(static fn (array $t): array => ['key' => (string) $t['key'], 'name' => (string) ($t['name'] ?? $t['key'])], (new MasterTenantRepository())->listActiveTenants((int) session('platform_admin_id'))),
        ]);
    }

    public function saveConnection(): ResponseInterface
    {
        if ($denied = $this->denyUnlessPrimaryJson()) {
            return $denied;
        }
        $res = (new VoiceAgentAdminService())->saveConnection($this->requestInput());

        return $this->respond($res, 'Voice agent connection updated');
    }

    public function ping(): ResponseInterface
    {
        if ($denied = $this->denyUnlessPrimaryJson()) {
            return $denied;
        }

        return $this->respond((new VoiceAgentAdminService())->ping());
    }

    public function load(): ResponseInterface
    {
        if ($denied = $this->denyUnlessPrimaryJson()) {
            return $denied;
        }
        $res = (new VoiceAgentAdminService())->load();

        return $this->jsonResponse($res['ok'], $res['data'] ?? null, $res['message'], [], $res['ok'] ? 200 : max(400, (int) $res['status']));
    }

    public function saveSettings(): ResponseInterface
    {
        if ($denied = $this->denyUnlessPrimaryJson()) {
            return $denied;
        }
        $in = $this->requestInput();

        return $this->respond((new VoiceAgentAdminService())->saveServerSettings(is_array($in['values'] ?? null) ? $in['values'] : []), 'Voice agent server settings updated');
    }

    public function saveAgent(): ResponseInterface
    {
        if ($denied = $this->denyUnlessPrimaryJson()) {
            return $denied;
        }

        return $this->respond((new VoiceAgentAdminService())->saveAgent($this->requestInput()), 'Voice agent script and voice updated');
    }

    /**
     * Gives one client its own voice agent account and leads key, and saves the address and key in that client's own
     * Settings. Calling is NOT switched on: the client still turns it on and confirms consent itself.
     */
    public function createAccount(): ResponseInterface
    {
        if ($denied = $this->denyUnlessPrimaryJson()) {
            return $denied;
        }
        $in   = $this->requestInput();
        $key  = strtolower(trim((string) ($in['client'] ?? '')));
        $repo = new MasterTenantRepository();
        $tenant = $repo->findActiveTenant($key);
        if ($tenant === null || ! $repo->platformAdminOwnsTenant((int) session('platform_admin_id'), $key)) {
            return $this->jsonResponse(false, null, 'Client not found.', [], 404);
        }

        $admin = new VoiceAgentAdminService();
        $res   = $admin->createClientAccount($key, (string) ($tenant['name'] ?? $key));
        if (! $res['ok']) {
            return $this->jsonResponse(false, null, $res['message'], [], max(400, (int) $res['status']));
        }

        // Save the address and the new key in the client's own Settings (never switch calling on here).
        $platformUrl = $admin->url();
        $conn        = new TenantConnection();
        $previous    = $conn->capture();
        try {
            if (! $conn->apply($key, 'platform')) {
                return $this->jsonResponse(false, $res['data'], 'The account was made, but the client database could not be reached to save the key. Paste the key in the client Settings by hand.', [], 503);
            }
            TenantContext::set($key, 'platform');
            \Config\Services::injectMock('settingsService', new SettingsService());
            (new VoiceAgentLeadService())->setConfig(['url' => $platformUrl, 'api_key' => $res['data']['key'], 'use_platform' => false]);
            log_activity('update', 'platform', 'Voice agent account created for a client', ['client' => $key, 'platform_admin_id' => (int) session('platform_admin_id')]);
        } finally {
            $conn->restoreIfChanged($previous);
        }

        return $this->jsonResponse(true, $res['data'], $res['message'] . ' Its address and key are saved in the client\'s Settings (calling is still off until the client turns it on).');
    }

    public function generateKey(): ResponseInterface
    {
        if ($denied = $this->denyUnlessPrimaryJson()) {
            return $denied;
        }

        return $this->respond((new VoiceAgentAdminService())->generateLeadsKey(), 'Voice agent leads key created');
    }

    public function sipSetup(): ResponseInterface
    {
        if ($denied = $this->denyUnlessPrimaryJson()) {
            return $denied;
        }

        return $this->respond((new VoiceAgentAdminService())->sipSetup(), 'Voice agent SIP trunks created');
    }

    public function testWebhook(): ResponseInterface
    {
        if ($denied = $this->denyUnlessPrimaryJson()) {
            return $denied;
        }
        $in = $this->requestInput();

        return $this->respond((new VoiceAgentAdminService())->testWebhook((string) ($in['key'] ?? '')));
    }

    /**
     * @param array{ok: bool, status: int, message: string} $res
     */
    private function respond(array $res, string $log = ''): ResponseInterface
    {
        if ($res['ok'] && $log !== '') {
            log_activity('update', 'platform', $log, ['platform_admin_id' => (int) session('platform_admin_id')]);
        }

        return $this->jsonResponse($res['ok'], null, $res['message'], [], $res['ok'] ? 200 : max(400, (int) $res['status']));
    }

    protected function denyUnlessPrimary(): ?ResponseInterface
    {
        if ((new MasterTenantRepository())->isPrimaryPlatformAdmin((int) session('platform_admin_id'))) {
            return null;
        }

        return redirect()->to('/platform/clients')->with('error', 'Only the main platform super admin can change the voice agent settings.');
    }

    protected function denyUnlessPrimaryJson(): ?ResponseInterface
    {
        if ((new MasterTenantRepository())->isPrimaryPlatformAdmin((int) session('platform_admin_id'))) {
            return null;
        }

        return $this->jsonResponse(false, null, 'Only the main platform super admin can change the voice agent settings.', [], 403);
    }
}
