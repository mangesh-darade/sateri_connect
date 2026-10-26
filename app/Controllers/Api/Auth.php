<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Libraries\ActivityLogger;
use App\Libraries\LoginThrottle;
use App\Models\RoleModel;
use App\Models\UserModel;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * API authentication — JWT login and current user profile.
 */
class Auth extends BaseApiController
{
    public function login(): ResponseInterface
    {
        $input = $this->getJsonInput();

        $email    = trim((string) ($input['email'] ?? ''));
        $password = (string) ($input['password'] ?? '');

        if ($email === '' || $password === '') {
            return $this->respondValidationError([
                'email'    => 'Email is required.',
                'password' => 'Password is required.',
            ]);
        }

        // Same per email+IP / per-account limits as the web login (the route only rate-limits by IP).
        $ip = (string) ($this->request->getIPAddress() ?: 'unknown');
        if (LoginThrottle::blocked($email, $ip)) {
            return $this->respondError('Too many login attempts. Try again in 15 minutes.', [], 429);
        }

        $tenantKey = null;
        if (\App\Libraries\MasterTenantRepository::masterConfigured()) {
            $tenantKey = (new \App\Libraries\MasterTenantRepository())->findTenantKeyByEmail($email);
            if ($tenantKey !== null) {
                if (! (new \App\Libraries\TenantConnection())->apply($tenantKey, 'api_login')) {
                    return $this->respondError('Unable to connect to your workspace.', [], 503);
                }
            }
        }

        $user = model(UserModel::class, false)->findByEmail($email);

        if (! LoginThrottle::verify($password, is_array($user) ? (string) ($user['password'] ?? '') : null)) {
            LoginThrottle::fail($email, $ip, 'api');

            return $this->respondError('Invalid credentials.', [], 401);
        }

        if (($user['status'] ?? '') !== 'active') {
            return $this->respondError('Account is inactive.', [], 403);
        }

        LoginThrottle::clear($email, $ip);

        $role = model(RoleModel::class)->find((int) ($user['role_id'] ?? 0));
        $permissions = [];
        if ($role !== null) {
            $permissions = array_values(array_filter(array_map(
                static fn (array $p): string => (string) ($p['slug'] ?? ''),
                model(RoleModel::class)->getPermissions((int) $role['id'])
            )));
            if (in_array($role['slug'] ?? '', ['super-admin', 'super_admin'], true)) {
                $permissions = ['*'];
            }
        }

        $tenantKey = $tenantKey ?? \App\Libraries\TenantContext::get();
        $claims    = [
            'email' => $user['email'],
            'role'  => $role['slug'] ?? null,
        ];
        if ($tenantKey !== null && $tenantKey !== '') {
            $claims['tenant'] = strtolower($tenantKey);
        }

        $token = service('jwtService')->generate((int) $user['id'], $claims);

        model(UserModel::class)->update((int) $user['id'], ['last_login' => date('Y-m-d H:i:s')]);
        (new ActivityLogger())->log('api_login', 'auth', 'API login', ['user_id' => $user['id']]);

        return $this->respondSuccess([
            'token'      => $token,
            'token_type' => 'Bearer',
            'expires_in' => (int) config('Jwt')->ttl,
            'tenant'     => $tenantKey,
            'user'       => [
                'id'          => (int) $user['id'],
                'name'        => $user['name'],
                'email'       => $user['email'],
                'role_id'     => $user['role_id'],
                'role_slug'   => $role['slug'] ?? null,
                'permissions' => $permissions,
            ],
        ], 'Authenticated.');
    }

    public function me(): ResponseInterface
    {
        $userId = $this->apiUserId();
        $user   = model(UserModel::class)->find($userId);

        if ($user === null) {
            return $this->respondError('User not found.', [], 404);
        }

        unset($user['password'], $user['remember_token']);

        $role = model(RoleModel::class)->find((int) ($user['role_id'] ?? 0));
        $permissions = [];
        if ($role !== null) {
            $permissions = array_map(
                static fn (array $p): string => (string) ($p['slug'] ?? ''),
                model(RoleModel::class)->getPermissions((int) $role['id'])
            );
            if (in_array($role['slug'] ?? '', ['super-admin', 'super_admin'], true)) {
                $permissions = ['*'];
            }
        }

        $user['role']        = $role;
        $user['permissions'] = $permissions;

        return $this->respondSuccess($user);
    }
}
