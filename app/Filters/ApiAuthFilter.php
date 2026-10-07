<?php

namespace App\Filters;

use App\Libraries\JwtService;
use App\Models\ApiTokenModel;
use App\Models\RoleModel;
use App\Models\UserModel;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * REST API Authentication Filter.
 * Supports:
 * 1. Permanent external developer API Keys (via X-API-Key header or Bearer token) matched against api_tokens table.
 * 2. Short-lived JWT Bearer tokens for authenticated frontend clients.
 */
class ApiAuthFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        // Multi-tenant resolution:
        // 1. If session tenant exists (e.g. testing from browser API docs console)
        \App\Libraries\TenantResolver::ensureFromSession();

        // 2. Explicit header X-Tenant-Key / X-Tenant
        $tenantHeader = trim((string) ($request->getHeaderLine('X-Tenant-Key') ?: $request->getHeaderLine('X-Tenant')));
        if ($tenantHeader !== '') {
            (new \App\Libraries\TenantConnection())->apply($tenantHeader, 'api_header');
        } elseif (\App\Libraries\MasterTenantRepository::masterConfigured() && ! \App\Libraries\TenantContext::has()) {
            $hostKey = \App\Libraries\SubdomainDatabase::detectSubdomain();
            if ($hostKey !== 'localhost' && $hostKey !== '') {
                (new \App\Libraries\TenantConnection())->apply($hostKey, 'subdomain');
            }
        }

        $apiKey = trim((string) $request->getHeaderLine('X-API-Key'));
        $header = (string) $request->getHeaderLine('Authorization');
        $bearerToken = '';

        if (preg_match('/Bearer\s+(\S+)/i', $header, $matches)) {
            $bearerToken = $matches[1];
        }

        $rawToken = $apiKey !== '' ? $apiKey : $bearerToken;

        if ($rawToken === '') {
            return service('response')
                ->setStatusCode(401)
                ->setJSON([
                    'status'  => 'error',
                    'success' => false,
                    'message' => 'Missing API authentication. Provide X-API-Key header or Authorization: Bearer token.',
                ]);
        }

        // Rate limiting: max 60 requests per minute per token & IP
        $throttler   = service('throttler');
        $throttleKey = 'api_throttle_' . md5($rawToken . '_' . $request->getIPAddress());
        if ($throttler->check($throttleKey, 60, MINUTE) === false) {
            return service('response')
                ->setStatusCode(429)
                ->setHeader('Retry-After', '60')
                ->setJSON([
                    'status'  => 'error',
                    'success' => false,
                    'message' => 'Rate limit exceeded. Maximum 60 requests per minute allowed.',
                    'data'    => [
                        'retry_after_seconds' => 60,
                        'limit_per_minute'    => 60,
                    ],
                ]);
        }

        // 1. Verify against api_tokens table (permanent external developer keys)
        $tokenModel  = model(ApiTokenModel::class);
        $apiTokenRow = $tokenModel->findValidByPlainText($rawToken);

        // If not found in currently active DB and master multi-tenant routing is active, scan active tenants
        if ($apiTokenRow === null && str_starts_with($rawToken, 'sc_live_') && \App\Libraries\MasterTenantRepository::masterConfigured()) {
            // Cross-tenant key scan is expensive: cap failed scans per IP so random keys cannot hammer every tenant DB.
            $scanFailKey = 'api_scan_fail_' . md5($request->getIPAddress());
            if ((int) cache($scanFailKey) >= 20) {
                return service('response')
                    ->setStatusCode(429)
                    ->setHeader('Retry-After', '60')
                    ->setJSON([
                        'status'  => 'error',
                        'success' => false,
                        'message' => 'Too many API key lookups. Try again in a minute.',
                    ]);
            }

            $master     = new \App\Libraries\MasterTenantRepository();
            $connection = new \App\Libraries\TenantConnection($master);
            foreach ($master->listActiveTenants() as $tenant) {
                $tKey = (string) ($tenant['key'] ?? '');
                if ($tKey !== '' && $connection->apply($tKey, 'api_token_lookup')) {
                    $candidateRow = model(ApiTokenModel::class)->findValidByPlainText($rawToken);
                    if ($candidateRow !== null) {
                        $apiTokenRow = $candidateRow;
                        break;
                    }
                }
            }

            if ($apiTokenRow === null) {
                cache()->save($scanFailKey, (int) cache($scanFailKey) + 1, MINUTE);
            }
        }

        if ($apiTokenRow !== null) {
            $userId = (int) ($apiTokenRow['user_id'] ?? 0);
            $user   = model(UserModel::class)->find($userId);

            if ($user === null || ($user['status'] ?? '') !== 'active') {
                return service('response')
                    ->setStatusCode(401)
                    ->setJSON([
                        'status'  => 'error',
                        'success' => false,
                        'message' => 'API Token owner account is inactive or not found.',
                    ]);
            }

            $role        = model(RoleModel::class)->find((int) ($user['role_id'] ?? 0));
            $permissions = [];
            $roleSlug    = '';

            if ($role !== null) {
                $roleSlug    = (string) ($role['slug'] ?? '');
                $permissions = array_values(array_filter(array_map(
                    static fn (array $p): string => (string) ($p['slug'] ?? ''),
                    model(RoleModel::class)->getPermissions((int) $role['id'])
                )));

                if (in_array($roleSlug, ['super-admin', 'super_admin'], true)) {
                    $permissions = ['*'];
                }
            }

            // If token has specific abilities, enforce least-privilege scope check
            $abilities = $apiTokenRow['abilities'] ?? ['*'];
            if (! is_array($abilities)) {
                $abilities = ['*'];
            }

            $requiredAbility = $this->resolveRequiredAbility($request);
            if ($requiredAbility !== '' && ! in_array('*', $abilities, true) && ! in_array($requiredAbility, $abilities, true)) {
                return service('response')
                    ->setStatusCode(403)
                    ->setJSON([
                        'status'  => 'error',
                        'success' => false,
                        'message' => "Forbidden: This API token lacks the required ability [{$requiredAbility}].",
                    ]);
            }

            if (! in_array('*', $abilities, true)) {
                $permissions = array_values(array_intersect($permissions, $abilities));
            }

            session()->set([
                'api_user_id'     => $userId,
                'api_role_id'     => $user['role_id'] ?? null,
                'api_role_slug'   => $roleSlug,
                'api_permissions' => $permissions,
                'api_token_id'    => (int) $apiTokenRow['id'],
                'api_user'        => [
                    'id'      => $userId,
                    'name'    => $user['name'] ?? '',
                    'email'   => $user['email'] ?? '',
                    'role_id' => $user['role_id'] ?? null,
                    'status'  => $user['status'] ?? null,
                ],
            ]);

            return null;
        }

        // 2. Fall back to JWT validation
        $jwt     = new JwtService();
        $payload = $jwt->validate($rawToken);

        if ($payload === null) {
            return service('response')
                ->setStatusCode(401)
                ->setJSON([
                    'status'  => 'error',
                    'success' => false,
                    'message' => 'Invalid or expired API token / JWT.',
                ]);
        }

        $tenantClaim = isset($payload->tenant) ? trim((string) $payload->tenant) : '';
        // A JWT without a tenant claim must never be replayed into a workspace chosen by header/host/session
        // (same numeric user id would resolve to a different user in another tenant DB).
        if ($tenantClaim === '' && \App\Libraries\TenantContext::has()) {
            return service('response')
                ->setStatusCode(401)
                ->setJSON([
                    'status'  => 'error',
                    'success' => false,
                    'message' => 'Token is not valid for this workspace. Log in again.',
                ]);
        }
        if ($tenantClaim !== '') {
            if (! \App\Libraries\TenantResolver::ensureFromJwtClaim($tenantClaim)) {
                return service('response')
                    ->setStatusCode(503)
                    ->setJSON([
                        'status'  => 'error',
                        'success' => false,
                        'message' => 'Unable to connect to workspace.',
                    ]);
            }
        }

        $userId = (int) ($payload->uid ?? $payload->sub ?? 0);
        if ($userId <= 0) {
            return service('response')
                ->setStatusCode(401)
                ->setJSON([
                    'status'  => 'error',
                    'success' => false,
                    'message' => 'Invalid token subject.',
                ]);
        }

        $user = model(UserModel::class)->find($userId);
        if ($user === null || ($user['status'] ?? '') !== 'active') {
            return service('response')
                ->setStatusCode(401)
                ->setJSON([
                    'status'  => 'error',
                    'success' => false,
                    'message' => 'User not found or inactive.',
                ]);
        }

        $role        = model(RoleModel::class)->find((int) ($user['role_id'] ?? 0));
        $permissions = [];
        $roleSlug    = '';
        if ($role !== null) {
            $roleSlug    = (string) ($role['slug'] ?? '');
            $permissions = array_values(array_filter(array_map(
                static fn (array $p): string => (string) ($p['slug'] ?? ''),
                model(RoleModel::class)->getPermissions((int) $role['id'])
            )));
            if (in_array($roleSlug, ['super-admin', 'super_admin'], true)) {
                $permissions = ['*'];
            }
        }

        session()->set([
            'api_user_id'     => $userId,
            'api_role_id'     => $user['role_id'] ?? null,
            'api_role_slug'   => $roleSlug,
            'api_permissions' => $permissions,
            'api_user'        => [
                'id'      => $userId,
                'name'    => $user['name'] ?? '',
                'email'   => $user['email'] ?? '',
                'role_id' => $user['role_id'] ?? null,
                'status'  => $user['status'] ?? null,
            ],
        ]);

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }

    /**
     * Map request endpoint and HTTP method to required token ability scope.
     */
    protected function resolveRequiredAbility(RequestInterface $request): string
    {
        $uri    = (string) $request->getUri()->getPath();
        $method = strtoupper($request->getMethod());

        if (str_contains($uri, 'contacts/upsert') && $method === 'POST') {
            return 'contacts:write';
        }
        if (str_contains($uri, 'contacts') && $method === 'GET') {
            return 'contacts:read';
        }
        if (str_contains($uri, 'messages/send') && $method === 'POST') {
            return 'messages:send';
        }
        if (str_contains($uri, 'messages') && $method === 'GET') {
            return 'messages:read';
        }
        if (str_contains($uri, 'templates') && $method === 'GET') {
            return 'templates:read';
        }
        if (str_contains($uri, 'automations/trigger') && $method === 'POST') {
            return 'automations:trigger';
        }
        if (str_contains($uri, 'automations') && $method === 'GET') {
            return 'automations:read';
        }
        if (str_contains($uri, 'email/identities')) {
            return $method === 'GET' ? 'email_identities:read' : 'email_identities:write';
        }
        if (str_contains($uri, 'account') && $method === 'GET') {
            return 'account:read';
        }

        return '';
    }
}
