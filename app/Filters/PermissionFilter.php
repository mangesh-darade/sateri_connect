<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Checks role permissions stored in session against filter arguments (permission slugs).
 *
 * Usage: 'permission:contacts.view,contacts.edit'
 */
class PermissionFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $session = session();

        if (! $session->get('user_id') && ! $session->get('api_user_id')) {
            if ($request->isAJAX() || str_starts_with(ltrim($request->getPath(), '/'), 'api/')) {
                return service('response')
                    ->setStatusCode(401)
                    ->setJSON(['success' => false, 'message' => 'Unauthenticated.']);
            }

            return redirect()->to('/login')->with('error', 'Please log in to continue.');
        }

        $required = is_array($arguments) ? $arguments : [];
        if ($required === []) {
            return null;
        }

        $permissions = $session->get('permissions');
        if (! is_array($permissions) || $permissions === []) {
            $permissions = $session->get('api_permissions');
        }
        if (! is_array($permissions)) {
            $permissions = [];
        }

        // Super admin bypass
        $roleSlug = (string) ($session->get('role_slug') ?: $session->get('api_role_slug') ?: '');
        if (in_array($roleSlug, ['super-admin', 'super_admin'], true) || in_array('*', $permissions, true)) {
            return null;
        }

        foreach ($required as $slug) {
            $slug = trim((string) $slug);
            if ($slug === '') {
                continue;
            }
            if (! in_array($slug, $permissions, true)) {
                if ($request->isAJAX() || str_starts_with($request->getPath(), 'api/')) {
                    return service('response')
                        ->setStatusCode(403)
                        ->setJSON(['success' => false, 'message' => 'Permission denied: ' . $slug]);
                }

                return redirect()->to('/dashboard')->with('error', 'You do not have permission to access this resource.');
            }
        }

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }
}
