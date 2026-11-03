<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Libraries\SocialAccountService;
use App\Libraries\SocialOAuthService;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Connect / disconnect Facebook Pages and Instagram accounts (Facebook Login OAuth).
 */
class SocialAccounts extends BaseController
{
    private const STATE_KEY = 'social_oauth_state';

    public function index(): string|ResponseInterface
    {
        if ($denied = $this->requirePermission('social.accounts')) {
            return $denied;
        }

        $svc = new SocialAccountService();

        return view('social/accounts', [
            'pageTitle'   => 'Social Accounts',
            'accounts'    => $svc->list(),
            'configured'  => $svc->isConfigured(),
            'redirectUri' => $svc->redirectUri(),
            'oauth'       => ['youtube' => ($o = new SocialOAuthService())->isConfigured('youtube'), 'linkedin' => $o->isConfigured('linkedin')],
            'redirectBase' => site_url('social/accounts/callback'),
        ]);
    }

    public function connect(string $provider = 'facebook'): ResponseInterface
    {
        if ($denied = $this->requirePermission('social.accounts')) {
            return $denied;
        }

        $back = redirect()->to(site_url('social/accounts'));
        if ($provider === 'facebook') {
            $svc = new SocialAccountService();
            $url = fn (string $state): string => $svc->authUrl($state);
            $ok  = $svc->isConfigured();
        } elseif (in_array($provider, SocialOAuthService::PROVIDERS, true)) {
            $svc = new SocialOAuthService();
            $url = fn (string $state): string => $svc->authUrl($provider, $state);
            $ok  = $svc->isConfigured($provider);
        } else {
            return $back->with('error', 'Unknown platform.');
        }
        if (! $ok) {
            return $back->with('error', 'This login is not set up yet. Ask the administrator to add the app credentials.');
        }

        $state = bin2hex(random_bytes(16));
        session()->set(self::STATE_KEY, $state);

        return redirect()->to($url($state));
    }

    public function callback(string $provider = 'facebook'): ResponseInterface
    {
        if ($denied = $this->requirePermission('social.accounts')) {
            return $denied;
        }

        $back     = redirect()->to(site_url('social/accounts'));
        $expected = (string) session()->get(self::STATE_KEY);
        session()->remove(self::STATE_KEY);

        $state = (string) $this->request->getGet('state');
        if ($expected === '' || ! hash_equals($expected, $state)) {
            return $back->with('error', 'The login session expired. Please try connecting again.');
        }
        $code = (string) $this->request->getGet('code');
        if ($this->request->getGet('error') !== null || $code === '') {
            return $back->with('error', 'The login was cancelled.');
        }

        $userId = session()->get('user_id') !== null ? (int) session()->get('user_id') : null;
        if ($provider === 'facebook') {
            $r = (new SocialAccountService())->handleCallback($code, $userId);
        } elseif (in_array($provider, SocialOAuthService::PROVIDERS, true)) {
            $r = (new SocialOAuthService())->handleCallback($provider, $code, $userId);
        } else {
            return $back->with('error', 'Unknown platform.');
        }

        return $back->with($r['ok'] ? 'success' : 'error', $r['message']);
    }
    public function connectToken(): ResponseInterface
    {
        if ($denied = $this->requirePermission('social.accounts')) {
            return $denied;
        }

        $userId = session()->get('user_id') !== null ? (int) session()->get('user_id') : null;
        $r      = (new SocialAccountService())->connectWithToken(
            (string) $this->request->getPost('token'),
            (string) $this->request->getPost('page_id'),
            $userId
        );

        return $this->jsonResponse((bool) $r['ok'], null, (string) $r['message'], [], $r['ok'] ? 200 : max(400, (int) $r['status']));
    }

    public function connectYouTubeToken(): ResponseInterface
    {
        if ($denied = $this->requirePermission('social.accounts')) {
            return $denied;
        }

        $userId = session()->get('user_id') !== null ? (int) session()->get('user_id') : null;
        $r      = (new SocialOAuthService())->connectYouTubeWithRefreshToken((string) $this->request->getPost('refresh_token'), $userId);

        return $this->jsonResponse((bool) $r['ok'], null, (string) $r['message'], [], $r['ok'] ? 200 : max(400, (int) $r['status']));
    }

    public function disconnect(string|int $id): ResponseInterface
    {
        $id = (int) $id;
        if ($denied = $this->requirePermission('social.accounts')) {
            return $denied;
        }

        $r = (new SocialAccountService())->disconnect($id);

        return $this->jsonResponse((bool) $r['ok'], null, (string) $r['message'], [], $r['ok'] ? 200 : max(400, (int) $r['status']));
    }
}
