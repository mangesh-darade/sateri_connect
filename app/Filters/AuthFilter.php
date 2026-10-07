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
            $session->destroy();

            if ($request->isAJAX() || str_starts_with($request->getPath(), 'api/')) {
                return service('response')
                    ->setStatusCode(401)
                    ->setJSON(['success' => false, 'message' => 'Your password was changed. Please log in again.']);
            }

            return redirect()->to('/login')->with('error', 'Your password was changed. Please log in again.');
        }

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }
}
