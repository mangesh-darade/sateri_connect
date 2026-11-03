<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Libraries\TenantConnection;
use App\Libraries\TenantContext;
use App\Libraries\TenantResolver;
use App\Models\ApiTokenModel;
use CodeIgniter\HTTP\ResponseInterface;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Throwable;

/**
 * OAuth 2.1 (authorization code + PKCE S256) so URL-based MCP clients such as ChatGPT can connect without a key in the URL.
 * Stateless: the client id, the pending request and the one-minute code are signed tokens, so there is no table to keep.
 * The access token is a normal API key named "AI client: ...", listed under Settings > API Keys where it can be revoked.
 * Only https redirect URIs of the known AI clients are accepted.
 */
class OAuth extends BaseController
{
    private const REDIRECTS = [
        'https://chatgpt.com/connector',
        'https://chatgpt.com/connector_platform_oauth_redirect',
        'https://platform.openai.com/apps-manage/oauth',
        'https://claude.ai/api/mcp/auth_callback',
        'https://claude.com/api/mcp/auth_callback',
    ];
    private const CODE_TTL  = 60;
    private const TOKEN_TTL = 90 * 86400;

    /** GET /.well-known/oauth-protected-resource */
    public function protectedResource(): ResponseInterface
    {
        return $this->json([
            'resource'                 => site_url('api/v1/mcp'),
            'authorization_servers'    => [$this->issuer()],
            'bearer_methods_supported' => ['header'],
        ]);
    }

    /** GET /.well-known/oauth-authorization-server (also served as openid-configuration for clients that probe it) */
    public function metadata(): ResponseInterface
    {
        return $this->json([
            'issuer'                                => $this->issuer(),
            'authorization_endpoint'                => site_url('oauth/authorize'),
            'token_endpoint'                        => site_url('oauth/token'),
            'registration_endpoint'                 => site_url('oauth/register'),
            'response_types_supported'              => ['code'],
            'grant_types_supported'                 => ['authorization_code'],
            'code_challenge_methods_supported'      => ['S256'],
            'token_endpoint_auth_methods_supported' => ['none'],
        ]);
    }

    /** POST /oauth/register: dynamic client registration; the client id is a signed token holding the redirect URIs. */
    public function register(): ResponseInterface
    {
        $in   = $this->request->getJSON(true);
        $in   = is_array($in) ? $in : [];
        $uris = array_values(array_filter((array) ($in['redirect_uris'] ?? []), 'is_string'));
        if ($uris === [] || count($uris) > 5) {
            return $this->json(['error' => 'invalid_redirect_uri', 'error_description' => 'Send 1 to 5 redirect_uris.'], 400);
        }
        foreach ($uris as $u) {
            if (! $this->redirectAllowed($u)) {
                return $this->json(['error' => 'invalid_redirect_uri', 'error_description' => 'This redirect URI is not allowed.'], 400);
            }
        }
        $name = mb_substr(trim(strip_tags((string) ($in['client_name'] ?? 'AI client'))), 0, 60) ?: 'AI client';

        return $this->json([
            'client_id'                  => $this->sign(['typ' => 'client', 'uris' => $uris, 'name' => $name]),
            'client_name'                => $name,
            'redirect_uris'              => $uris,
            'grant_types'                => ['authorization_code'],
            'response_types'             => ['code'],
            'token_endpoint_auth_method' => 'none',
        ], 201);
    }

    /** GET /oauth/authorize: sign-in (if needed) then the consent screen. */
    public function authorize(): string|ResponseInterface
    {
        $client = $this->decode((string) $this->request->getGet('client_id'), 'client');
        $uri    = (string) $this->request->getGet('redirect_uri');
        if ($client === null || ! in_array($uri, (array) $client['uris'], true)) {
            return $this->fail('This connection request is not valid.');
        }
        $challenge = (string) $this->request->getGet('code_challenge');
        if ($this->request->getGet('response_type') !== 'code' || $this->request->getGet('code_challenge_method') !== 'S256' || preg_match('/^[A-Za-z0-9_-]{43}$/', $challenge) !== 1) {
            return $this->denyTo($uri, (string) $this->request->getGet('state'), 'invalid_request');
        }

        if (! session()->get('user_id') && session()->get('platform_admin_id')) {
            return $this->fail('You are signed in as a platform admin. Sign out (or use a private window) and sign in as a workspace user, then connect again.');
        }
        if (! session()->get('user_id')) {
            session()->set('oauth_return', current_url(true)->__toString() . '?' . $this->request->getUri()->getQuery());

            return redirect()->to('/login')->with('warning', 'Sign in to connect ' . $client['name'] . '.');
        }
        if (! TenantResolver::ensureFromSession() && (string) session()->get('tenant_key') !== '') {
            return $this->fail('Your workspace could not be opened.');
        }

        $this->response->setHeader('X-Frame-Options', 'DENY')->setHeader('Cache-Control', 'no-store');

        return view('oauth/consent', [
            'title'  => 'Connect ' . $client['name'],
            'client' => $client['name'],
            'user'   => (string) (session()->get('user_email') ?: session()->get('user_name') ?: ''),
            'req'    => $this->sign(['typ' => 'req', 'cid' => $this->request->getGet('client_id'), 'uri' => $uri, 'ch' => $challenge, 'state' => (string) $this->request->getGet('state'), 'uid' => (int) session()->get('user_id')], 600),
        ]);
    }

    /** POST /oauth/authorize: Allow or Deny. */
    public function decide(): ResponseInterface
    {
        $req = $this->decode((string) $this->request->getPost('req'), 'req');
        if ($req === null || (int) session()->get('user_id') !== (int) $req['uid']) {
            return $this->fail('This request expired. Start the connection again.');
        }
        if ($this->request->getPost('decision') !== 'allow') {
            return $this->denyTo((string) $req['uri'], (string) $req['state'], 'access_denied');
        }
        TenantResolver::ensureFromSession();

        $code = $this->sign([
            'typ' => 'code', 'jti' => bin2hex(random_bytes(16)), 'cid' => $req['cid'], 'uri' => $req['uri'],
            'ch' => $req['ch'], 'uid' => (int) $req['uid'], 'tenant' => (string) TenantContext::get(),
        ], self::CODE_TTL);
        log_activity('create', 'oauth', 'AI client authorised', ['client' => $this->decode((string) $req['cid'], 'client')['name'] ?? '']);

        return redirect()->to($this->withQuery((string) $req['uri'], ['code' => $code, 'state' => (string) $req['state']]));
    }

    /** POST /oauth/token: code + PKCE verifier for an access token (a revocable API key). */
    public function token(): ResponseInterface
    {
        $this->response->setHeader('Cache-Control', 'no-store')->setHeader('Pragma', 'no-cache');
        if ($this->request->getPost('grant_type') !== 'authorization_code') {
            return $this->json(['error' => 'unsupported_grant_type'], 400);
        }

        $code     = $this->decode((string) $this->request->getPost('code'), 'code');
        $verifier = (string) $this->request->getPost('code_verifier');
        $client   = $this->decode((string) $this->request->getPost('client_id'), 'client');
        $valid    = $code !== null && $client !== null
            && hash_equals((string) $code['cid'], (string) $this->request->getPost('client_id'))
            && hash_equals((string) $code['uri'], (string) $this->request->getPost('redirect_uri'))
            && preg_match('/^[A-Za-z0-9._~-]{43,128}$/', $verifier) === 1
            && hash_equals((string) $code['ch'], rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='));
        // A code works once: remember it until it would have expired anyway.
        if ($valid && cache()->get('oauth_code_' . $code['jti']) !== null) {
            $valid = false;
        }
        if (! $valid) {
            return $this->json(['error' => 'invalid_grant'], 400);
        }
        cache()->save('oauth_code_' . $code['jti'], 1, self::CODE_TTL * 2);

        try {
            if ((string) $code['tenant'] !== '') {
                (new TenantConnection())->apply((string) $code['tenant'], 'oauth');
            }
            $made = model(ApiTokenModel::class)->createToken((int) $code['uid'], 'AI client: ' . $client['name'], ['*'], date('Y-m-d H:i:s', time() + self::TOKEN_TTL));
        } catch (Throwable $e) {
            log_message('error', 'OAuth token issue failed: ' . $e->getMessage());

            return $this->json(['error' => 'server_error'], 500);
        }

        return $this->json(['access_token' => $made['plain_text'], 'token_type' => 'Bearer', 'expires_in' => self::TOKEN_TTL]);
    }

    private function issuer(): string
    {
        return rtrim(site_url('/'), '/');
    }

    private function redirectAllowed(string $uri): bool
    {
        $p = parse_url($uri);
        if (($p['scheme'] ?? '') !== 'https' || isset($p['user']) || isset($p['fragment'])) {
            return false;
        }
        foreach (self::REDIRECTS as $allowed) {
            if ($uri === $allowed || str_starts_with($uri, $allowed . '/') || str_starts_with($uri, $allowed . '?')) {
                return true;
            }
        }

        return false;
    }

    private function key(): string
    {
        return hash('sha256', 'oauth|' . (string) config('Encryption')->key, true);
    }

    /** @param array<string, mixed> $claims */
    private function sign(array $claims, int $ttl = 0): string
    {
        $claims['iat'] = time();
        if ($ttl > 0) {
            $claims['exp'] = time() + $ttl;
        }

        return JWT::encode($claims, $this->key(), 'HS256');
    }

    /** @return array<string, mixed>|null */
    private function decode(string $jwt, string $type): ?array
    {
        try {
            $c = (array) JWT::decode($jwt, new Key($this->key(), 'HS256'));
        } catch (Throwable) {
            return null;
        }

        return ($c['typ'] ?? '') === $type ? json_decode((string) json_encode($c), true) : null;
    }

    /** @param array<string, string> $q */
    private function withQuery(string $uri, array $q): string
    {
        return $uri . (str_contains($uri, '?') ? '&' : '?') . http_build_query(array_filter($q, static fn (string $v): bool => $v !== ''));
    }

    private function denyTo(string $uri, string $state, string $error): ResponseInterface
    {
        return redirect()->to($this->withQuery($uri, ['error' => $error, 'state' => $state]));
    }

    private function fail(string $message): ResponseInterface
    {
        return $this->response->setStatusCode(400)->setBody('<!doctype html><meta charset="utf-8"><title>Connection failed</title><p style="font:16px sans-serif;margin:3rem auto;max-width:28rem">' . esc($message) . '</p>');
    }

    /** @param array<string, mixed> $data */
    private function json(array $data, int $status = 200): ResponseInterface
    {
        return $this->response->setStatusCode($status)->setJSON($data);
    }
}
