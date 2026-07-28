<?php

namespace App\Filters;

use App\Libraries\JwtService;
use App\Models\RoleModel;
use App\Models\UserModel;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Bearer JWT authentication for REST API routes.
 * Uses api_* session keys only — never web panel user_id (prevents session fixation).
 */
class ApiAuthFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $header = $request->getHeaderLine('Authorization');
        $token  = '';

        if (preg_match('/Bearer\s+(\S+)/i', $header, $matches)) {
            $token = $matches[1];
        }

        if ($token === '') {
            return service('response')
                ->setStatusCode(401)
                ->setJSON(['success' => false, 'message' => 'Missing Bearer token.']);
        }

        $jwt     = new JwtService();
        $payload = $jwt->validate($token);

        if ($payload === null) {
            return service('response')
                ->setStatusCode(401)
                ->setJSON(['success' => false, 'message' => 'Invalid or expired token.']);
        }

        $userId = (int) ($payload->uid ?? $payload->sub ?? 0);
        if ($userId <= 0) {
            return service('response')
                ->setStatusCode(401)
                ->setJSON(['success' => false, 'message' => 'Invalid token subject.']);
        }

        $user = model(UserModel::class)->find($userId);
        if ($user === null || ($user['status'] ?? '') !== 'active') {
            return service('response')
                ->setStatusCode(401)
                ->setJSON(['success' => false, 'message' => 'User not found or inactive.']);
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
}
