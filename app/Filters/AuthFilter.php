<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Requires an authenticated session user for panel routes.
 */
class AuthFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $session = session();

        if (! $session->get('user_id')) {
            if ($request->isAJAX() || str_starts_with($request->getPath(), 'api/')) {
                return service('response')
                    ->setStatusCode(401)
                    ->setJSON(['success' => false, 'message' => 'Unauthenticated.']);
            }

            return redirect()->to('/login')->with('error', 'Please log in to continue.');
        }

        // If the session's workspace can't be connected (suspended / removed), never fall through to
        // the default DB with the same numeric user_id — that would expose another workspace's data.
        if (! \App\Libraries\TenantResolver::ensureFromSession() && (string) $session->get('tenant_key') !== '') {
            if ($session->get('platform_impersonating') && $session->get('platform_admin_id')) {
                $this->endPlatformImpersonation($session);

                return redirect()->to('/platform/clients')->with('error', 'Cannot open that workspace. You are still signed in as platform admin.');
            }

            $session->destroy();

            if ($request->isAJAX() || str_starts_with($request->getPath(), 'api/')) {
                return service('response')
                    ->setStatusCode(401)
                    ->setJSON(['success' => false, 'message' => 'Workspace unavailable. Please log in again.']);
            }

            return redirect()->to('/login');
        }

        $changedAt = (int) cache(\App\Models\UserModel::passwordChangedCacheKey((int) $session->get('user_id')));
        if ($changedAt > 0 && (int) $session->get('login_at') < $changedAt) {
            // Opening a workspace starts a new session. An earlier client password change
            // must not sign the platform admin out.
            if ($session->get('platform_impersonating') && $session->get('platform_admin_id')) {
                $session->set('login_at', time());

                return null;
            }

            $session->destroy();

            if ($request->isAJAX() || str_starts_with($request->getPath(), 'api/')) {
                return service('response')
                    ->setStatusCode(401)
                    ->setJSON(['success' => false, 'message' => 'Your password was changed. Please log in again.']);
            }

            return redirect()->to('/login')->with('error', 'Your password was changed. Please log in again.');
        }

        // A platform admin who opened this workspace is governed by the platform session instead.
        if (! $session->get('platform_impersonating') && ! $this->userStillValid($session)) {
            $session->destroy();

            if ($request->isAJAX() || str_starts_with($request->getPath(), 'api/')) {
                return service('response')
                    ->setStatusCode(401)
                    ->setJSON(['success' => false, 'message' => 'Your account is no longer active. Please log in again.']);
            }

            return redirect()->to('/login')->with('error', 'Your account is no longer active.');
        }

        return null;
    }

    /**
     * Sessions hold a snapshot taken at login. At most once a minute, re-read the user so deactivated or
     * deleted accounts are signed out and role/permission changes take effect without a new login.
     */
    protected function userStillValid(object $session): bool
    {
        $checkedAt = (int) $session->get('user_checked_at');
        if ($checkedAt > 0 && (time() - $checkedAt) < 60) {
            return true;
        }

        try {
            $user = model(\App\Models\UserModel::class, false)->find((int) $session->get('user_id'));
            if ($user === null || ($user['status'] ?? '') !== 'active') {
                return false;
            }

            $roleModel   = model(\App\Models\RoleModel::class, false);
            $role        = $roleModel->find((int) ($user['role_id'] ?? 0));
            $permissions = [];
            if ($role !== null) {
                $permissions = array_values(array_filter(array_map(
                    static fn (array $p): string => (string) ($p['slug'] ?? ''),
                    $roleModel->getPermissions((int) $role['id'])
                )));
                if (in_array($role['slug'] ?? '', ['super-admin', 'super_admin'], true)) {
                    $permissions = ['*'];
                }
            }

            $session->set([
                'role_id'         => $user['role_id'] ?? null,
                'role_name'       => $role['name'] ?? null,
                'role_slug'       => $role['slug'] ?? null,
                'permissions'     => $permissions,
                'user_checked_at' => time(),
            ]);
        } catch (\Throwable $e) {
            // A transient DB problem must not sign everyone out.
            log_message('warning', 'AuthFilter user revalidation skipped: {msg}', ['msg' => $e->getMessage()]);
        }

        return true;
    }

    /**
     * Drop the opened workspace, keep the platform admin signed in.
     */
    protected function endPlatformImpersonation(object $session): void
    {
        $session->remove([
            'user_id',
            'user_name',
            'user_email',
            'user_avatar',
            'role_id',
            'role_name',
            'role_slug',
            'permissions',
            'login_at',
            'tenant_key',
            'platform_impersonating',
        ]);
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }
}
